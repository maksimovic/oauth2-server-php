<?php

namespace OAuth2\Encryption;

use OAuth2\Storage\Bootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JwtTest extends TestCase
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

    #[DataProvider('provideClientCredentials')]
    public function testJwtUtil($client_id, $client_key)
    {
        $jwtUtil = new Jwt();

        $params = array(
            'iss' => $client_id,
            'exp' => time() + 1000,
            'iat' => time(),
            'sub' => 'testuser@ourdomain.com',
            'aud' => 'http://myapp.com/oauth/auth',
            'scope' => null,
        );

        $encoded = $jwtUtil->encode($params, $this->privateKey, 'RS256');

        // test BC behaviour of trusting the algorithm in the header
        $payload = $jwtUtil->decode($encoded, $client_key);
        $this->assertEquals($params, $payload);

        // test BC behaviour of not verifying by passing false
        $payload = $jwtUtil->decode($encoded, $client_key, false);
        $this->assertEquals($params, $payload);

        // test the new restricted algorithms header
        $payload = $jwtUtil->decode($encoded, $client_key, array('RS256'));
        $this->assertEquals($params, $payload);
    }

    public function testInvalidJwt()
    {
        $jwtUtil = new Jwt();

        $this->assertFalse($jwtUtil->decode('goob'));
        $this->assertFalse($jwtUtil->decode('go.o.b'));
    }

    #[DataProvider('provideClientCredentials')]
    public function testInvalidJwtHeader($client_id, $client_key)
    {
        $jwtUtil = new Jwt();

        $params = array(
            'iss' => $client_id,
            'exp' => time() + 1000,
            'iat' => time(),
            'sub' => 'testuser@ourdomain.com',
            'aud' => 'http://myapp.com/oauth/auth',
            'scope' => null,
        );

        // testing for algorithm tampering when only RSA256 signing is allowed
        // @see https://auth0.com/blog/2015/03/31/critical-vulnerabilities-in-json-web-token-libraries/
        $tampered = $jwtUtil->encode($params, $client_key, 'HS256');

        $payload = $jwtUtil->decode($tampered, $client_key, array('RS256'));

        $this->assertFalse($payload);
    }

    public static function provideClientCredentials()
    {
        $storage = Bootstrap::getInstance()->getMemoryStorage();
        $client_id  = 'Test Client ID';
        $client_key = $storage->getClientKey($client_id, "testuser@ourdomain.com");

        return array(
            array($client_id, $client_key),
        );
    }
}
