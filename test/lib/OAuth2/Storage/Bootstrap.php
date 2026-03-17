<?php

namespace OAuth2\Storage;

class Bootstrap
{
    protected static $instance;
    private $mysql;
    private $sqlite;
    private $postgres;
    private $mongoDb;
    private $redis;
    private $cassandra;
    private $configDir;
    private $dynamodb;
    private $couchbase;

    public function __construct()
    {
        $this->configDir = __DIR__.'/../../../config';
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getSqlitePdo()
    {
        if (!$this->sqlite) {
            $this->removeSqliteDb();
            $pdo = new \PDO(sprintf('sqlite:%s', $this->getSqliteDir()));
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->createSqliteDb($pdo);

            $this->sqlite = new Pdo($pdo);
        }

        return $this->sqlite;
    }

    public function getPostgresPdo()
    {
        if (!$this->postgres) {
            if (in_array('pgsql', \PDO::getAvailableDrivers())) {
                $this->removePostgresDb();
                $this->createPostgresDb();
                if ($pdo = $this->getPostgresDriver()) {
                    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                    $this->populatePostgresDb($pdo);
                    $this->postgres = new Pdo($pdo);
                }
            } else {
                $this->postgres = new NullStorage('Postgres', 'Missing postgres PDO extension.');
            }
        }

        return $this->postgres;
    }

    public function getPostgresDriver()
    {
        try {
            $pgHost = $this->getEnvVar('POSTGRES_HOST', 'localhost');
            $pdo = new \PDO("pgsql:host={$pgHost};dbname=oauth2_server_php", 'postgres', 'postgres');

            return $pdo;
        } catch (\PDOException $e) {
            $this->postgres = new NullStorage('Postgres', $e->getMessage());
        }
    }

    public function getMemoryStorage()
    {
        return new Memory(json_decode(file_get_contents($this->configDir. '/storage.json'), true));
    }

    public function getRedisStorage()
    {
        if (!$this->redis) {
            if (class_exists('Predis\Client')) {
                $redisHost = $this->getEnvVar('REDIS_HOST', '127.0.0.1');
                $redis = new \Predis\Client(['host' => $redisHost]);
                if ($this->testRedisConnection($redis)) {
                    $redis->flushdb();
                    $this->redis = new Redis($redis);
                    $this->createRedisDb($this->redis);
                } else {
                    $this->redis = new NullStorage('Redis', 'Unable to connect to redis server on port 6379');
                }
            } else {
                $this->redis = new NullStorage('Redis', 'Missing redis library. Please run "composer.phar require predis/predis:dev-master"');
            }
        }

        return $this->redis;
    }

    private function testRedisConnection(\Predis\Client $redis)
    {
        try {
            $redis->connect();
        } catch (\Predis\CommunicationException $exception) {
            // we were unable to connect to the redis server
            return false;
        }

        return true;
    }

    public function getMysqlPdo()
    {
        if (!$this->mysql) {
            $pdo = null;
            try {
                $mysqlHost = $this->getEnvVar('MYSQL_HOST', '127.0.0.1');
                $pdo = new \PDO("mysql:host={$mysqlHost};", 'root', 'root');
            } catch (\PDOException $e) {
                $this->mysql = new NullStorage('MySQL', "Unable to connect to MySQL on root@{$mysqlHost}");
            }

            if ($pdo) {
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $this->removeMysqlDb($pdo);
                $this->createMysqlDb($pdo);

                $this->mysql = new Pdo($pdo);
            }
        }

        return $this->mysql;
    }

    public function getMongoDb()
    {
        if (!$this->mongoDb) {
            if (extension_loaded('mongodb') && class_exists('MongoDB\Client')) {
                $mongoHost = $this->getEnvVar('MONGODB_HOST', 'localhost');
                $mongoDb = new \MongoDB\Client("mongodb://{$mongoHost}:27017");
                if ($this->testMongoDBConnection($mongoDb)) {
                    $db = $mongoDb->oauth2_server_php;
                    $this->removeMongoDb($db);
                    $this->createMongoDb($db);

                    $this->mongoDb = new MongoDB($db);
                } else {
                    $this->mongoDb = new NullStorage('MongoDB', 'Unable to connect to mongo server on "localhost:27017"');
                }
            } else {
                $this->mongoDb = new NullStorage('MongoDB', 'Missing MongoDB php extension. Please install mongodb.so');
            }
        }

        return $this->mongoDb;
    }

    private function testMongoDBConnection(\MongoDB\Client $mongo)
    {
        return true;
    }

    public function getCouchbase()
    {
        if (!$this->couchbase) {
            if ($this->getEnvVar('SKIP_COUCHBASE_TESTS')) {
                $this->couchbase = new NullStorage('Couchbase', 'Skipping Couchbase tests');
            } elseif (!extension_loaded('couchbase') || !class_exists(\Couchbase\ClusterOptions::class)) {
                $this->couchbase = new NullStorage('Couchbase', 'Missing Couchbase SDK. Install ext-couchbase and couchbase/couchbase ^4.4');
            } else {
                try {
                    $options = new \Couchbase\ClusterOptions();
                    $options->credentials(
                        $this->getEnvVar('CB_USERNAME', 'Administrator'),
                        $this->getEnvVar('CB_PASSWORD', 'password')
                    );
                    $cluster = new \Couchbase\Cluster(
                        $this->getEnvVar('CB_CONNECTION_STRING', 'couchbase://localhost'),
                        $options
                    );
                    $bucket = $cluster->bucket($this->getEnvVar('CB_BUCKET', 'default'));
                    $collection = $bucket->defaultCollection();

                    $this->clearCouchbase($collection);
                    $this->createCouchbaseDB($collection);

                    $this->couchbase = new CouchbaseDB($collection);
                } catch (\Exception $e) {
                    $this->couchbase = new NullStorage('Couchbase', 'Unable to connect to Couchbase: ' . $e->getMessage());
                }
            }
        }

        return $this->couchbase;
    }

    public function getCassandraStorage()
    {
        if (!$this->cassandra) {
            if (!class_exists('Cassandra\Connection')) {
                $this->cassandra = new NullStorage('Cassandra', 'Missing cassandra library. Please run "composer require mroosz/php-cassandra"');

                return $this->cassandra;
            }

            try {
                $cassandraHost = $this->getEnvVar('CASSANDRA_HOST', '127.0.0.1');
                $conn = new \Cassandra\Connection([
                    new \Cassandra\Connection\StreamNodeConfig(
                        host: $cassandraHost,
                        port: 9042,
                    ),
                ]);
                $conn->connect();

                // recreate keyspace
                $conn->query("DROP KEYSPACE IF EXISTS oauth2_test");
                $conn->query("CREATE KEYSPACE oauth2_test WITH replication = {'class': 'SimpleStrategy', 'replication_factor': 1}");
                $conn->query("CREATE TABLE oauth2_test.oauth_data (key text PRIMARY KEY, value text)");

                $conn->setKeyspace('oauth2_test');

                $this->cassandra = new Cassandra($conn);
                $this->createCassandraDb($this->cassandra, $conn);
            } catch (\Exception $e) {
                $this->cassandra = new NullStorage('Cassandra', $e->getMessage());
            }
        }

        return $this->cassandra;
    }

    private function createCassandraDb(Cassandra $storage, \Cassandra\Connection $conn)
    {
        $storage->setClientDetails("oauth_test_client", "testpass", "http://example.com", 'implicit password');
        $storage->setAccessToken("testtoken", "Some Client", '', time() + 1000);
        $storage->setAuthorizationCode("testcode", "Some Client", '', '', time() + 1000);

        $storage->setScope('supportedscope1 supportedscope2 supportedscope3 supportedscope4');
        $storage->setScope('defaultscope1 defaultscope2', null, 'default');

        $storage->setScope('clientscope1 clientscope2', 'Test Client ID');
        $storage->setScope('clientscope1 clientscope2', 'Test Client ID', 'default');

        $storage->setScope('clientscope1 clientscope2 clientscope3', 'Test Client ID 2');
        $storage->setScope('clientscope1 clientscope2', 'Test Client ID 2', 'default');

        $storage->setScope('clientscope1 clientscope2', 'Test Default Scope Client ID');
        $storage->setScope('clientscope1 clientscope2', 'Test Default Scope Client ID', 'default');

        $storage->setScope('clientscope1 clientscope2 clientscope3', 'Test Default Scope Client ID 2');
        $storage->setScope('clientscope3', 'Test Default Scope Client ID 2', 'default');

        $storage->setClientKey('oauth_test_client', $this->getTestPublicKey(), 'test_subject');

        // insert public keys and user directly
        $table = 'oauth_data';
        $conn->query("INSERT INTO $table (key, value) VALUES (?, ?)", [
            'oauth_public_keys:ClientID_One',
            json_encode(array("public_key" => "client_1_public", "private_key" => "client_1_private", "encryption_algorithm" => "RS256")),
        ]);
        $conn->query("INSERT INTO $table (key, value) VALUES (?, ?)", [
            'oauth_public_keys:ClientID_Two',
            json_encode(array("public_key" => "client_2_public", "private_key" => "client_2_private", "encryption_algorithm" => "RS256")),
        ]);
        $conn->query("INSERT INTO $table (key, value) VALUES (?, ?)", [
            'oauth_public_keys:',
            json_encode(array("public_key" => $this->getTestPublicKey(), "private_key" => $this->getTestPrivateKey(), "encryption_algorithm" => "RS256")),
        ]);
        $conn->query("INSERT INTO $table (key, value) VALUES (?, ?)", [
            'oauth_users:testuser',
            json_encode(array("password" => "password", "email" => "testuser@test.com", "email_verified" => true)),
        ]);
    }

    private function createSqliteDb(\PDO $pdo)
    {
        $this->runPdoSql($pdo);
    }

    private function removeSqliteDb()
    {
        if (file_exists($this->getSqliteDir())) {
            unlink($this->getSqliteDir());
        }
    }

    private function createMysqlDb(\PDO $pdo)
    {
        $pdo->exec('CREATE DATABASE oauth2_server_php');
        $pdo->exec('USE oauth2_server_php');
        $this->runPdoSql($pdo);
    }

    private function removeMysqlDb(\PDO $pdo)
    {
        $pdo->exec('DROP DATABASE IF EXISTS oauth2_server_php');
    }

    private function createPostgresDb()
    {
        try {
            $pgHost = $this->getEnvVar('POSTGRES_HOST', 'localhost');
            $pdo = new \PDO("pgsql:host={$pgHost}", 'postgres', 'postgres');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = 'oauth2_server_php'")->fetchColumn();
            if (!$exists) {
                $pdo->exec('CREATE DATABASE oauth2_server_php');
            }
        } catch (\PDOException $e) {
            // connection failed — will be caught later in getPostgresPdo
        }
    }

    private function populatePostgresDb(\PDO $pdo)
    {
        $this->runPdoSql($pdo);
    }

    private function removePostgresDb()
    {
        try {
            $pgHost = $this->getEnvVar('POSTGRES_HOST', 'localhost');
            $pdo = new \PDO("pgsql:host={$pgHost}", 'postgres', 'postgres');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            // terminate existing connections before dropping
            $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'oauth2_server_php' AND pid <> pg_backend_pid()");
            $pdo->exec('DROP DATABASE IF EXISTS oauth2_server_php');
        } catch (\PDOException $e) {
            // connection failed — will be caught later
        }
    }

    public function runPdoSql(\PDO $pdo)
    {
        $storage = new Pdo($pdo);
        foreach (explode(';', $storage->getBuildSql()) as $statement) {
            $result = $pdo->exec($statement);
        }

        // set up scopes
        $sql = 'INSERT INTO oauth_scopes (scope) VALUES (?)';
        foreach (explode(' ', 'supportedscope1 supportedscope2 supportedscope3 supportedscope4 clientscope1 clientscope2 clientscope3') as $supportedScope) {
            $pdo->prepare($sql)->execute(array($supportedScope));
        }

        $sql = 'INSERT INTO oauth_scopes (scope, is_default) VALUES (?, ?)';
        foreach (array('defaultscope1', 'defaultscope2') as $defaultScope) {
            $pdo->prepare($sql)->execute(array($defaultScope, true));
        }

        // set up clients
        $sql = 'INSERT INTO oauth_clients (client_id, client_secret, scope, grant_types) VALUES (?, ?, ?, ?)';
        $pdo->prepare($sql)->execute(array('Test Client ID', 'TestSecret', 'clientscope1 clientscope2', null));
        $pdo->prepare($sql)->execute(array('Test Client ID 2', 'TestSecret', 'clientscope1 clientscope2 clientscope3', null));
        $pdo->prepare($sql)->execute(array('Test Default Scope Client ID', 'TestSecret', 'clientscope1 clientscope2', null));
        $pdo->prepare($sql)->execute(array('oauth_test_client', 'testpass', null, 'implicit password'));

        // set up misc
        $sql = 'INSERT INTO oauth_access_tokens (access_token, client_id, expires, user_id) VALUES (?, ?, ?, ?)';
        $pdo->prepare($sql)->execute(array('testtoken', 'Some Client', date('Y-m-d H:i:s', strtotime('+1 hour')), null));
        $pdo->prepare($sql)->execute(array('accesstoken-openid-connect', 'Some Client', date('Y-m-d H:i:s', strtotime('+1 hour')), 'testuser'));

        $sql = 'INSERT INTO oauth_authorization_codes (authorization_code, client_id, expires) VALUES (?, ?, ?)';
        $pdo->prepare($sql)->execute(array('testcode', 'Some Client', date('Y-m-d H:i:s', strtotime('+1 hour'))));

        $sql = 'INSERT INTO oauth_users (username, password, email, email_verified) VALUES (?, ?, ?, ?)';
        $pdo->prepare($sql)->execute(array('testuser', 'password', 'testuser@test.com', true));

        $sql = 'INSERT INTO oauth_public_keys (client_id, public_key, private_key, encryption_algorithm) VALUES (?, ?, ?, ?)';
        $pdo->prepare($sql)->execute(array('ClientID_One', 'client_1_public', 'client_1_private', 'RS256'));
        $pdo->prepare($sql)->execute(array('ClientID_Two', 'client_2_public', 'client_2_private', 'RS256'));

        $sql = 'INSERT INTO oauth_public_keys (client_id, public_key, private_key, encryption_algorithm) VALUES (?, ?, ?, ?)';
        $pdo->prepare($sql)->execute(array(null, $this->getTestPublicKey(), $this->getTestPrivateKey(), 'RS256'));

        $sql = 'INSERT INTO oauth_jwt (client_id, subject, public_key) VALUES (?, ?, ?)';
        $pdo->prepare($sql)->execute(array('oauth_test_client', 'test_subject', $this->getTestPublicKey()));
    }

    public function getSqliteDir()
    {
        return $this->configDir. '/test.sqlite';
    }

    public function getConfigDir()
    {
        return $this->configDir;
    }

    private function createCouchbaseDB(\Couchbase\Collection $collection)
    {
        $collection->upsert('oauth_clients-oauth_test_client', [
            'client_id' => 'oauth_test_client',
            'client_secret' => 'testpass',
            'redirect_uri' => 'http://example.com',
            'grant_types' => 'implicit password',
        ]);

        $collection->upsert('oauth_access_tokens-testtoken', [
            'access_token' => 'testtoken',
            'client_id' => 'Some Client',
        ]);

        $collection->upsert('oauth_authorization_codes-testcode', [
            'access_token' => 'testcode',
            'client_id' => 'Some Client',
        ]);

        $collection->upsert('oauth_users-testuser', [
            'username' => 'testuser',
            'password' => 'password',
            'email' => 'testuser@test.com',
            'email_verified' => true,
        ]);

        $collection->upsert('oauth_jwt-oauth_test_client', [
            'client_id' => 'oauth_test_client',
            'key' => $this->getTestPublicKey(),
            'subject' => 'test_subject',
        ]);
    }

    private function clearCouchbase(\Couchbase\Collection $collection)
    {
        $keys = [
            'oauth_authorization_codes-new-openid-code',
            'oauth_access_tokens-newtoken',
            'oauth_authorization_codes-newcode',
            'oauth_refresh_tokens-refreshtoken',
        ];
        foreach ($keys as $key) {
            try {
                $collection->remove($key);
            } catch (\Couchbase\Exception\DocumentNotFoundException) {
                // ignore
            }
        }
    }

    private function createMongoDB(\MongoDB\Database $db)
    {
        $db->oauth_clients->insertOne(array(
            'client_id' => "oauth_test_client",
            'client_secret' => "testpass",
            'redirect_uri' => "http://example.com",
            'grant_types' => 'implicit password'
        ));

        $db->oauth_access_tokens->insertOne(array(
            'access_token' => "testtoken",
            'client_id' => "Some Client"
        ));

        $db->oauth_authorization_codes->insertOne(array(
            'authorization_code' => "testcode",
            'client_id' => "Some Client"
        ));

        $db->oauth_users->insertOne(array(
            'username' => 'testuser',
            'password' => 'password',
            'email' => 'testuser@test.com',
            'email_verified' => true,
        ));

        $db->oauth_keys->insertOne(array(
            'client_id'   => null,
            'public_key' => $this->getTestPublicKey(),
            'private_key' => $this->getTestPrivateKey(),
            'encryption_algorithm' => 'RS256'
        ));

        $db->oauth_jwt->insertOne(array(
            'client_id' => 'oauth_test_client',
            'key' => $this->getTestPublicKey(),
            'subject'   => 'test_subject',
        ));
    }

    public function removeMongoDB(\MongoDB\Database $db)
    {
        $db->drop();
    }

    private function createRedisDb(Redis $storage)
    {
        $storage->setClientDetails("oauth_test_client", "testpass", "http://example.com", 'implicit password');
        $storage->setAccessToken("testtoken", "Some Client", '', time() + 1000);
        $storage->setAuthorizationCode("testcode", "Some Client", '', '', time() + 1000);
        $storage->setUser("testuser", "password");

        $storage->setScope('supportedscope1 supportedscope2 supportedscope3 supportedscope4');
        $storage->setScope('defaultscope1 defaultscope2', null, 'default');

        $storage->setScope('clientscope1 clientscope2', 'Test Client ID');
        $storage->setScope('clientscope1 clientscope2', 'Test Client ID', 'default');

        $storage->setScope('clientscope1 clientscope2 clientscope3', 'Test Client ID 2');
        $storage->setScope('clientscope1 clientscope2', 'Test Client ID 2', 'default');

        $storage->setScope('clientscope1 clientscope2', 'Test Default Scope Client ID');
        $storage->setScope('clientscope1 clientscope2', 'Test Default Scope Client ID', 'default');

        $storage->setScope('clientscope1 clientscope2 clientscope3', 'Test Default Scope Client ID 2');
        $storage->setScope('clientscope3', 'Test Default Scope Client ID 2', 'default');

        $storage->setClientKey('oauth_test_client', $this->getTestPublicKey(), 'test_subject');
    }

    public function getTestPublicKey()
    {
        return file_get_contents(__DIR__.'/../../../config/keys/id_rsa.pub');
    }

    private function getTestPrivateKey()
    {
        return file_get_contents(__DIR__.'/../../../config/keys/id_rsa');
    }

    public function getDynamoDbStorage()
    {
        if (!$this->dynamodb) {
            try {
                $this->initDynamoDbStorage();
            } catch (\Exception $e) {
                $this->dynamodb = new NullStorage('DynamoDb', $e->getMessage());
            }
        }

        return $this->dynamodb;
    }

    private function initDynamoDbStorage()
    {
        if (!class_exists('\Aws\DynamoDb\DynamoDbClient')) {
            $this->dynamodb = new NullStorage('DynamoDb', 'Missing DynamoDB library. Please run "composer require aws/aws-sdk-php:^3.0"');

            return;
        }

        $endpoint = $this->getEnvVar('DYNAMODB_ENDPOINT', 'http://localhost:8000');

        try {
            $client = new \Aws\DynamoDb\DynamoDbClient([
                'region' => 'us-east-1',
                'version' => 'latest',
                'endpoint' => $endpoint,
                'credentials' => [
                    'key' => 'fake',
                    'secret' => 'fake',
                ],
            ]);

            // verify DynamoDB Local is reachable
            $client->listTables();
        } catch (\Exception $e) {
            $this->dynamodb = new NullStorage('DynamoDb', 'Unable to connect to DynamoDB Local at ' . $endpoint . ': ' . $e->getMessage());

            return;
        }

        $prefix = 'test_';
        $this->deleteDynamoDb($client, $prefix);
        $this->createDynamoDb($client, $prefix);
        $this->populateDynamoDb($client, $prefix);

        $config = [
            'client_table' => $prefix.'oauth_clients',
            'access_token_table' => $prefix.'oauth_access_tokens',
            'refresh_token_table' => $prefix.'oauth_refresh_tokens',
            'code_table' => $prefix.'oauth_authorization_codes',
            'user_table' => $prefix.'oauth_users',
            'jwt_table'  => $prefix.'oauth_jwt',
            'scope_table'  => $prefix.'oauth_scopes',
            'public_key_table'  => $prefix.'oauth_public_keys',
        ];
        $this->dynamodb = new DynamoDB($client, $config);
    }

    private function deleteDynamoDb(\Aws\DynamoDb\DynamoDbClient $client, $prefix = null)
    {
        $tablesList = explode(' ', 'oauth_access_tokens oauth_authorization_codes oauth_clients oauth_jwt oauth_public_keys oauth_refresh_tokens oauth_scopes oauth_users');

        foreach ($tablesList as $table) {
            try {
                $client->deleteTable(['TableName' => $prefix.$table]);
                $client->waitUntil('TableNotExists', ['TableName' => $prefix.$table]);
            } catch (\Aws\DynamoDb\Exception\DynamoDbException $e) {
                // Table does not exist
            }
        }
    }

    private function createDynamoDb(\Aws\DynamoDb\DynamoDbClient $client, $prefix = null)
    {
        $client->createTable([
            'TableName' => $prefix.'oauth_access_tokens',
            'AttributeDefinitions' => [
                ['AttributeName' => 'access_token', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [['AttributeName' => 'access_token', 'KeyType' => 'HASH']],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);

        $client->createTable([
            'TableName' => $prefix.'oauth_authorization_codes',
            'AttributeDefinitions' => [
                ['AttributeName' => 'authorization_code', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [['AttributeName' => 'authorization_code', 'KeyType' => 'HASH']],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);

        $client->createTable([
            'TableName' => $prefix.'oauth_clients',
            'AttributeDefinitions' => [
                ['AttributeName' => 'client_id', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [['AttributeName' => 'client_id', 'KeyType' => 'HASH']],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);

        $client->createTable([
            'TableName' => $prefix.'oauth_jwt',
            'AttributeDefinitions' => [
                ['AttributeName' => 'client_id', 'AttributeType' => 'S'],
                ['AttributeName' => 'subject', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [
                ['AttributeName' => 'client_id', 'KeyType' => 'HASH'],
                ['AttributeName' => 'subject', 'KeyType' => 'RANGE'],
            ],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);

        $client->createTable([
            'TableName' => $prefix.'oauth_public_keys',
            'AttributeDefinitions' => [
                ['AttributeName' => 'client_id', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [['AttributeName' => 'client_id', 'KeyType' => 'HASH']],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);

        $client->createTable([
            'TableName' => $prefix.'oauth_refresh_tokens',
            'AttributeDefinitions' => [
                ['AttributeName' => 'refresh_token', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [['AttributeName' => 'refresh_token', 'KeyType' => 'HASH']],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);

        $client->createTable([
            'TableName' => $prefix.'oauth_scopes',
            'AttributeDefinitions' => [
                ['AttributeName' => 'scope', 'AttributeType' => 'S'],
                ['AttributeName' => 'is_default', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [['AttributeName' => 'scope', 'KeyType' => 'HASH']],
            'GlobalSecondaryIndexes' => [
                [
                    'IndexName' => 'is_default-index',
                    'KeySchema' => [['AttributeName' => 'is_default', 'KeyType' => 'HASH']],
                    'Projection' => ['ProjectionType' => 'ALL'],
                ],
            ],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);

        $client->createTable([
            'TableName' => $prefix.'oauth_users',
            'AttributeDefinitions' => [
                ['AttributeName' => 'username', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [['AttributeName' => 'username', 'KeyType' => 'HASH']],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);

        // Wait for all tables to become active
        $tablesList = explode(' ', 'oauth_access_tokens oauth_authorization_codes oauth_clients oauth_jwt oauth_public_keys oauth_refresh_tokens oauth_scopes oauth_users');
        foreach ($tablesList as $table) {
            $client->waitUntil('TableExists', ['TableName' => $prefix.$table]);
        }
    }

    private function populateDynamoDb($client, $prefix = null)
    {
        // set up scopes
        foreach (explode(' ', 'supportedscope1 supportedscope2 supportedscope3 supportedscope4 clientscope1 clientscope2 clientscope3') as $supportedScope) {
            $client->putItem(array(
                'TableName' => $prefix.'oauth_scopes',
                'Item' => array('scope' => array('S' => $supportedScope))
            ));
        }

        foreach (array('defaultscope1', 'defaultscope2') as $defaultScope) {
            $client->putItem(array(
                'TableName' => $prefix.'oauth_scopes',
                'Item' => array('scope' => array('S' => $defaultScope), 'is_default' => array('S' => "true"))
            ));
        }

        $client->putItem(array(
            'TableName' => $prefix.'oauth_clients',
            'Item' => array(
                'client_id' => array('S' => 'Test Client ID'),
                'client_secret' => array('S' => 'TestSecret'),
                'scope' => array('S' => 'clientscope1 clientscope2')
            )
        ));

        $client->putItem(array(
            'TableName' => $prefix.'oauth_clients',
            'Item' => array(
                'client_id' => array('S' => 'Test Client ID 2'),
                'client_secret' => array('S' => 'TestSecret'),
                'scope' => array('S' => 'clientscope1 clientscope2 clientscope3')
            )
        ));

        $client->putItem(array(
            'TableName' => $prefix.'oauth_clients',
            'Item' => array(
                'client_id' => array('S' => 'Test Default Scope Client ID'),
                'client_secret' => array('S' => 'TestSecret'),
                'scope' => array('S' => 'clientscope1 clientscope2')
            )
        ));

        $client->putItem(array(
            'TableName' => $prefix.'oauth_clients',
            'Item' => array(
                'client_id' => array('S' => 'oauth_test_client'),
                'client_secret' => array('S' => 'testpass'),
                'grant_types' => array('S' => 'implicit password')
            )
        ));

        $client->putItem(array(
            'TableName' => $prefix.'oauth_access_tokens',
            'Item' => array(
                'access_token' => array('S' => 'testtoken'),
                'client_id' => array('S' => 'Some Client'),
            )
        ));

        $client->putItem(array(
            'TableName' => $prefix.'oauth_access_tokens',
            'Item' => array(
                 'access_token' => array('S' => 'accesstoken-openid-connect'),
                 'client_id' => array('S' => 'Some Client'),
                 'user_id' => array('S' => 'testuser'),
            )
        ));

        $client->putItem(array(
            'TableName' => $prefix.'oauth_authorization_codes',
            'Item' => array(
                'authorization_code' => array('S' => 'testcode'),
                'client_id' => array('S' => 'Some Client'),
            )
        ));

        $client->putItem(array(
            'TableName' => $prefix.'oauth_users',
            'Item' => array(
                'username' => array('S' => 'testuser'),
                'password' => array('S' => 'password'),
                'email' => array('S' => 'testuser@test.com'),
                'email_verified' => array('S' => 'true'),
            )
        ));

        $client->putItem(array(
            'TableName' => $prefix.'oauth_public_keys',
            'Item' => array(
                'client_id' => array('S' => 'ClientID_One'),
                'public_key' => array('S' => 'client_1_public'),
                'private_key' => array('S' => 'client_1_private'),
                'encryption_algorithm' => array('S' => 'RS256'),
            )
        ));

        $client->putItem(array(
            'TableName' => $prefix.'oauth_public_keys',
            'Item' => array(
                'client_id' => array('S' => 'ClientID_Two'),
                'public_key' => array('S' => 'client_2_public'),
                'private_key' => array('S' => 'client_2_private'),
                'encryption_algorithm' => array('S' => 'RS256'),
            )
        ));

        $client->putItem(array(
            'TableName' => $prefix.'oauth_public_keys',
            'Item' => array(
                'client_id' => array('S' => '0'),
                'public_key' => array('S' => $this->getTestPublicKey()),
                'private_key' => array('S' => $this->getTestPrivateKey()),
                'encryption_algorithm' => array('S' => 'RS256'),
            )
        ));

        $client->putItem(array(
            'TableName' => $prefix.'oauth_jwt',
            'Item' => array(
                'client_id' => array('S' => 'oauth_test_client'),
                'subject' => array('S' => 'test_subject'),
                'public_key' => array('S' => $this->getTestPublicKey()),
            )
        ));
    }

    private function getEnvVar($var, $default = null)
    {
        return isset($_SERVER[$var]) ? $_SERVER[$var] : (getenv($var) ?: $default);
    }
}
