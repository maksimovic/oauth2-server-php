<?php

namespace OAuth2\Storage;

use OAuth2\Encryption\Jwt;
use PHPUnit\Framework\Attributes\DataProvider;

class JwtAccessTokenTest extends BaseTest
{
    #[DataProvider('provideStorage')]
    public function testSetAccessToken($storage)
    {
        if (!$storage instanceof PublicKey) {
            $this->markTestSkipped('Incompatible storage: PublicKey required');

            return;
        }

        $crypto = new jwtAccessToken($storage);

        $publicKeyStorage = Bootstrap::getInstance()->getMemoryStorage();
        $encryptionUtil = new Jwt();

        $jwtAccessToken = array(
            'access_token' => rand(),
            'expires' => time() + 100,
            'scope'   => 'foo',
        );

        $token = $encryptionUtil->encode($jwtAccessToken, $storage->getPrivateKey(), $storage->getEncryptionAlgorithm());

        $this->assertNotNull($token);

        $tokenData = $crypto->getAccessToken($token);

        $this->assertTrue(is_array($tokenData));

        /* assert the decoded token is the same */
        $this->assertEquals($tokenData['access_token'], $jwtAccessToken['access_token']);
        $this->assertEquals($tokenData['expires'], $jwtAccessToken['expires']);
        $this->assertEquals($tokenData['scope'], $jwtAccessToken['scope']);
    }
}
