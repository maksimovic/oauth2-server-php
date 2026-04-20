<?php

namespace OAuth2\Storage;

use Aws\Result;

class DynamoDBTest extends BaseTest
{
    public function testGetDefaultScope()
    {
        $client = $this->getMockBuilder('\Aws\DynamoDb\DynamoDbClient')
            ->disableOriginalConstructor()
            ->addMethods(array('query'))
            ->getMock();

        $data = new Result(array(
            'Items' => array(),
            'Count' => 0,
            'ScannedCount'=> 0
        ));

        // should return null default scope if none is set in database
        $client->expects($this->once())
            ->method('query')
            ->willReturn($data);

        $storage = new DynamoDB($client);
        $this->assertNull($storage->getDefaultScope());
    }
}
