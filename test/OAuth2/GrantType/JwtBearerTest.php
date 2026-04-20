<?php

namespace OAuth2\GrantType;

use OAuth2\Storage\Bootstrap;
use OAuth2\Server;
use OAuth2\Request\TestRequest;
use OAuth2\Response;
use OAuth2\Encryption\Jwt;
use PHPUnit\Framework\TestCase;

class JwtBearerTest extends TestCase
{
    private $privateKey;

    public function setUp(): void
    {
        $this->privateKey = <<<EOD
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC+vIoGiA/K/k/y
NhF1kpvzwSD6AG6XUUXm+R+3OxUIW7wG8zmnrjDk3hwArpG//rYxdEGL6wiF8hDr
zYLSsr1fyRskuW7pCW6+h8HWvaoHkAh/yU7MmXMqUyAc3io/hVx0H3dB7JWbIvV0
AM8bmEik+DI/eAxb5rtBWvUER/OpPbq81mbh7SfS/72IbVb5D3OMUiNDPX/4Ez4X
zIgwrvjsWnP6wxguWUsAl0SWdOCFPwT7dqbptVUNwdEoGkZ4jPl/ydxVtbAdbBGo
2MRVFRMHFeQtVDDiziC9T4eAkeSEiFvCH68QOlTWhjzlqM7o1G4epltQ81zisBxw
FLIn7N4hAgMBAAECggEAC3/kBXlKFHfJO6XxXwiQEP3tnk0M3eAnSgHunNY50idv
um6LJRYuOfo/L3ZW8L+rXedvtT8eJC9AQGtDTi87Fi2SjqAEdRXdwKyALhGA0RRo
wsWRE+pThHN/DeaCHxLMDG9COi4IphIRQOV0lyoTBSk2pFd5TUgntYzFlXS+Fs+4
Uexmg+anuBNHUUF8eyyL7hNsyPA0jQ/DTjqtGqLJog8daekJRXrPVmimDi8QeC7g
axRLCCj4LDQPTfoW+s+mctwWYxJCIfjMS+4RgH7YBvVFGaB/MWCUcWOmAJ5gAYa8
g5DBUuNgVp03A3bNhFefPpgsleXSDwWjYJsnC4sU8QKBgQD8vAw+ssF2zmba97mo
r74yV1tUYKdaLLnV1C1jBdxX8uz1BLqUdII+oGHbmf85vJzbSxPqwq4dm6xG/fLh
GSXCK2RnCbfro7102XqixZ/SaRQf7LopcpuFyW3fF55MBT8jg/qfBhENd7vQJAD5
u5ssGvEAbxNsG1ET47yvHTUjGQKBgQDBM2y31fG+3jWiX2jhzMo59W5aoQUiCRLL
kioACe7Pof6Hftb8wH3tBQjJ36tFa9k2FTePynGYhY33hvSm/qny5R4oTN9qoS0E
EeYxl3gy6S0O+khPAa5p6K7i0ROPsTT4Z8l9XOacnirv2L+xVq4VieB1+dY5lIPK
tPcHOWw8SQKBgQDG1I5xmSI4/KLQq8nFWxWv9yfj1vJyL/O3tOhMGiVCj9w52xGK
j6qT6It0P9AaNTfWElfF/okKxBkh9NHqo2UgQBEKOwwV90iqsBoaCo309DQf9ZZz
2zVdaJ3mwGcJ+aq1nzRBfX1W8haw5lJaJm0qorttkvVdvJPpqOYdgkX2qQKBgEQK
wWpJPfeTuN3zrjN/9WTOLExc0zr2aRkq5AHZfbLAgazkngCsJm1YTY0TafVsEza5
6DSK/tDRkHsxm25I2D/EM4fL8w9RrlH1n9WtW9bKSmUw/lBc7jk8ioM1USdVKKun
mc297zYPel24P2LMfUj2owfJsona5UN50lpH/feJAoGBAPRhKcK5rInc+5JBJMYv
5WEXWMJZ99MT4HBLnnyIKaL2LzIdf/IKUd43Sy4mCE036UPeYZQrH5jGZY2nZMlK
/u5kI3t+pRXbiitgIDhEE/gtLxVyE37bwYBrnvr5JpTt6gwtM3S8IVcY8o5wdxB3
frqJlY+bZ3RMCFNuElUk+G9Z
-----END PRIVATE KEY-----
EOD;
    }

    public function testMalformedJWT()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', // valid grant type
        ));

        //Get the jwt and break it
        $jwt = $this->getJWT();
        $jwt = substr_replace($jwt, 'broken', 3, 6);

        $request->request['assertion'] = $jwt;

        $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_request');
        $this->assertEquals($response->getParameter('error_description'), 'JWT is malformed');
    }

    public function testBrokenSignature()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', // valid grant type
        ));

        //Get the jwt and break signature
        $jwt = $this->getJWT() . 'notSupposeToBeHere';
        $request->request['assertion'] = $jwt;

        $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_grant');
        $this->assertEquals($response->getParameter('error_description'), 'JWT failed signature verification');
    }

    public function testExpiredJWT()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', // valid grant type
        ));

        //Get an expired JWT
        $jwt = $this->getJWT(1234);
        $request->request['assertion'] = $jwt;

        $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_grant');
        $this->assertEquals($response->getParameter('error_description'), 'JWT has expired');
    }

    public function testBadExp()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', // valid grant type
        ));

        //Get an expired JWT
        $jwt = $this->getJWT('badtimestamp');
        $request->request['assertion'] = $jwt;

        $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_grant');
        $this->assertEquals($response->getParameter('error_description'), 'Expiration (exp) time must be a unix time stamp');
    }

    public function testNoAssert()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', // valid grant type
        ));

        //Do not pass the assert (JWT)

        $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_request');
        $this->assertEquals($response->getParameter('error_description'), 'Missing parameters: "assertion" required');
    }

    public function testNotBefore()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', // valid grant type
        ));

        //Get a future NBF
        $jwt = $this->getJWT(null, time() + 10000);
        $request->request['assertion'] = $jwt;

        $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_grant');
        $this->assertEquals($response->getParameter('error_description'), 'JWT cannot be used before the Not Before (nbf) time');
    }

    public function testBadNotBefore()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', // valid grant type
        ));

        //Get a non timestamp nbf
        $jwt = $this->getJWT(null, 'notatimestamp');
        $request->request['assertion'] = $jwt;

        $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_grant');
        $this->assertEquals($response->getParameter('error_description'), 'Not Before (nbf) time must be a unix time stamp');
    }

    public function testNonMatchingAudience()
    {
        $server = $this->getTestServer('http://google.com/oauth/o/auth');
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', // valid grant type
            'assertion' => $this->getJWT(),
        ));

        $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_grant');
        $this->assertEquals($response->getParameter('error_description'), 'Invalid audience (aud)');
    }

    public function testBadClientID()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',  // valid grant type
            'assertion' => $this->getJWT(null, null, null, 'bad_client_id'),
        ));

        $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_grant');
        $this->assertEquals($response->getParameter('error_description'), 'Invalid issuer (iss) or subject (sub) provided');
    }

    public function testBadSubject()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',  // valid grant type
            'assertion' => $this->getJWT(null, null, 'anotheruser@ourdomain,com'),
        ));

        $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_grant');
        $this->assertEquals($response->getParameter('error_description'), 'Invalid issuer (iss) or subject (sub) provided');
    }

    public function testMissingKey()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',  // valid grant type
            'assertion' => $this->getJWT(null, null, null, 'Missing Key Cli,nt'),
        ));

        $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_grant');
        $this->assertEquals($response->getParameter('error_description'), 'Invalid issuer (iss) or subject (sub) provided');
    }

    public function testValidJwt()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',  // valid grant type
            'assertion' => $this->getJWT(), // valid assertion
        ));

        $token = $server->grantAccessToken($request, new Response());
        $this->assertNotNull($token);
        $this->assertArrayHasKey('access_token', $token);
    }

    public function testValidJwtWithScope()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',  // valid grant type
            'assertion'  => $this->getJWT(null, null, null, 'Test Client ID'), // valid assertion
            'scope'      => 'scope1', // valid scope
        ));
        $token = $server->grantAccessToken($request, new Response());

        $this->assertNotNull($token);
        $this->assertArrayHasKey('access_token', $token);
        $this->assertArrayHasKey('scope', $token);
        $this->assertEquals($token['scope'], 'scope1');
    }

    public function testValidJwtInvalidScope()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',  // valid grant type
            'assertion'  => $this->getJWT(null, null, null, 'Test Client ID'), // valid assertion
            'scope'      => 'invalid-scope', // invalid scope
        ));
        $token = $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_scope');
        $this->assertEquals($response->getParameter('error_description'), 'An unsupported scope was requested');
    }

    public function testValidJti()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',  // valid grant type
                'assertion' => $this->getJWT(null, null, 'testuser@ourdomain.com', 'Test Client ID', 'unused_jti'), // valid assertion with invalid scope
        ));
        $token = $server->grantAccessToken($request, $response = new Response());

        $this->assertNotNull($token);
        $this->assertArrayHasKey('access_token', $token);
    }

    public function testInvalidJti()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',  // valid grant type
                'assertion' => $this->getJWT(99999999900, null, 'testuser@ourdomain.com', 'Test Client ID', 'used_jti'), // valid assertion with invalid scope
        ));
        $token = $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_grant');
        $this->assertEquals($response->getParameter('error_description'), 'JSON Token Identifier (jti) has already been used');
    }

    public function testJtiReplayAttack()
    {
        $server = $this->getTestServer();
        $request = TestRequest::createPost(array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',  // valid grant type
                'assertion' => $this->getJWT(99999999900, null, 'testuser@ourdomain.com', 'Test Client ID', 'totally_new_jti'), // valid assertion with invalid scope
        ));
        $token = $server->grantAccessToken($request, $response = new Response());

        $this->assertNotNull($token);
        $this->assertArrayHasKey('access_token', $token);

        //Replay the same request
        $token = $server->grantAccessToken($request, $response = new Response());

        $this->assertEquals($response->getStatusCode(), 400);
        $this->assertEquals($response->getParameter('error'), 'invalid_grant');
        $this->assertEquals($response->getParameter('error_description'), 'JSON Token Identifier (jti) has already been used');
    }

    /**
     * Generates a JWT
     * @param $exp The expiration date. If the current time is greater than the exp, the JWT is invalid.
     * @param $nbf The "not before" time. If the current time is less than the nbf, the JWT is invalid.
     * @param $sub The subject we are acting on behalf of. This could be the email address of the user in the system.
     * @param $iss The issuer, usually the client_id.
     * @return string
     */
    private function getJWT($exp = null, $nbf = null, $sub = null, $iss = 'Test Client ID', $jti = null)
    {
        if (!$exp) {
            $exp = time() + 1000;
        }

        if (!$sub) {
            $sub = "testuser@ourdomain.com";
        }

        $params = array(
            'iss' => $iss,
            'exp' => $exp,
            'iat' => time(),
            'sub' => $sub,
            'aud' => 'http://myapp.com/oauth/auth',
        );

        if ($nbf) {
            $params['nbf'] = $nbf;
        }

        if ($jti) {
            $params['jti'] = $jti;
        }

        $jwtUtil = new Jwt();

        return $jwtUtil->encode($params, $this->privateKey, 'RS256');
    }

    private function getTestServer($audience = 'http://myapp.com/oauth/auth')
    {
        $storage = Bootstrap::getInstance()->getMemoryStorage();
        $server = new Server($storage);
        $server->addGrantType(new JwtBearer($storage, $audience, new Jwt()));

        return $server;
    }
}
