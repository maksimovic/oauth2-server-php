<?php

namespace OAuth2\Storage;

use PHPUnit\Framework\Attributes\DataProvider;

class ClientCredentialsTest extends BaseTest
{
    #[DataProvider('provideStorage')]
    public function testCheckClientCredentials(ClientCredentialsInterface $storage)
    {
        if ($storage instanceof NullStorage) {
            $this->markTestSkipped('Skipped Storage: ' . $storage->getMessage());

            return;
        }

        // nonexistant client_id
        $pass = $storage->checkClientCredentials('fakeclient', 'testpass');
        $this->assertFalse($pass);

        // invalid password
        $pass = $storage->checkClientCredentials('oauth_test_client', 'invalidcredentials');
        $this->assertFalse($pass);

        // valid credentials
        $pass = $storage->checkClientCredentials('oauth_test_client', 'testpass');
        $this->assertTrue($pass);
    }
}
