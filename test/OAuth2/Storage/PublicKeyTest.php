<?php

namespace OAuth2\Storage;

use PHPUnit\Framework\Attributes\DataProvider;

class PublicKeyTest extends BaseTest
{
    #[DataProvider('provideStorage')]
    public function testSetAccessToken($storage)
    {
        if ($storage instanceof NullStorage) {
            $this->markTestSkipped('Skipped Storage: ' . $storage->getMessage());

            return;
        }

        if (!$storage instanceof PublicKeyInterface) {
            $this->markTestSkipped('Incompatible storage: PublicKeyInterface required');

            return;
        }

        $configDir = Bootstrap::getInstance()->getConfigDir();
        $globalPublicKey  = file_get_contents($configDir.'/keys/id_rsa.pub');
        $globalPrivateKey = file_get_contents($configDir.'/keys/id_rsa');

        /* assert values from storage */
        $this->assertEquals($storage->getPublicKey(), $globalPublicKey);
        $this->assertEquals($storage->getPrivateKey(), $globalPrivateKey);
    }
}
