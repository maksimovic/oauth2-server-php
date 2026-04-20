<?php

namespace OAuth2\Storage;

use Cassandra\Connection;
use Cassandra\Connection\StreamNodeConfig;
use OAuth2\OpenID\Storage\UserClaimsInterface;
use OAuth2\OpenID\Storage\AuthorizationCodeInterface as OpenIDAuthorizationCodeInterface;
use InvalidArgumentException;

/**
 * Cassandra storage for all storage types
 *
 * To use, install "mroosz/php-cassandra" via composer:
 * <code>
 *     composer require mroosz/php-cassandra
 * </code>
 *
 * Once this is done, instantiate the connection:
 * <code>
 *     $cassandra = new \Cassandra\Connection([
 *         new \Cassandra\Connection\StreamNodeConfig(host: '127.0.0.1', port: 9042),
 *     ], keyspace: 'oauth2');
 *     $cassandra->connect();
 * </code>
 *
 * Then, register the storage client:
 * <code>
 *     $storage = new OAuth2\Storage\Cassandra($cassandra);
 *     $storage->setClientDetails($client_id, $client_secret, $redirect_uri);
 * </code>
 */
class Cassandra implements AuthorizationCodeInterface,
    AccessTokenInterface,
    ClientCredentialsInterface,
    UserCredentialsInterface,
    RefreshTokenInterface,
    JwtBearerInterface,
    ScopeInterface,
    PublicKeyInterface,
    UserClaimsInterface,
    OpenIDAuthorizationCodeInterface
{
    private $cache;

    protected Connection $connection;

    protected array $config;

    /**
     * @param Connection|array $connection A Connection instance or a configuration array
     * @param array $config
     *
     * @throws InvalidArgumentException
     */
    public function __construct($connection, array $config = array())
    {
        if ($connection instanceof Connection) {
            $this->connection = $connection;
        } elseif (is_array($connection)) {
            $connection = array_merge(array(
                'host' => '127.0.0.1',
                'port' => 9042,
                'keyspace' => 'oauth2',
                'username' => null,
                'password' => null,
            ), $connection);

            $nodeConfig = new StreamNodeConfig(
                host: $connection['host'],
                port: $connection['port'],
                username: $connection['username'],
                password: $connection['password'],
            );

            $this->connection = new Connection([$nodeConfig], keyspace: $connection['keyspace']);
            $this->connection->connect();
        } else {
            throw new InvalidArgumentException(
                'First argument to OAuth2\Storage\Cassandra must be an instance of Cassandra\Connection or a configuration array'
            );
        }

        $this->config = array_merge(array(
            'table' => 'oauth_data',

            // key prefixes
            'client_key' => 'oauth_clients:',
            'access_token_key' => 'oauth_access_tokens:',
            'refresh_token_key' => 'oauth_refresh_tokens:',
            'code_key' => 'oauth_authorization_codes:',
            'user_key' => 'oauth_users:',
            'jwt_key' => 'oauth_jwt:',
            'scope_key' => 'oauth_scopes:',
            'public_key_key' => 'oauth_public_keys:',
        ), $config);
    }

    protected function getValue($key)
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        try {
            $result = $this->connection->query(
                sprintf('SELECT value FROM %s WHERE key = ?', $this->config['table']),
                [$key]
            )->asRowsResult();

            foreach ($result as $row) {
                return json_decode($row['value'], true);
            }
        } catch (\Exception $e) {
            return false;
        }

        return false;
    }

    protected function setValue($key, $value, $expire = 0)
    {
        $this->cache[$key] = $value;

        $str = json_encode($value);

        try {
            if ($expire > 0) {
                $seconds = $expire - time();
                if ($seconds < 1) {
                    $seconds = 1;
                }
                $this->connection->query(
                    sprintf('INSERT INTO %s (key, value) VALUES (?, ?) USING TTL %d', $this->config['table'], $seconds),
                    [$key, $str]
                );
            } else {
                $this->connection->query(
                    sprintf('INSERT INTO %s (key, value) VALUES (?, ?)', $this->config['table']),
                    [$key, $str]
                );
            }
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    protected function expireValue($key)
    {
        unset($this->cache[$key]);

        try {
            // check if the key exists before deleting
            $result = $this->connection->query(
                sprintf('SELECT key FROM %s WHERE key = ?', $this->config['table']),
                [$key]
            )->asRowsResult();

            $found = false;
            foreach ($result as $row) {
                $found = true;
                break;
            }

            if (!$found) {
                return false;
            }

            $this->connection->query(
                sprintf('DELETE FROM %s WHERE key = ?', $this->config['table']),
                [$key]
            );
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    public function getAuthorizationCode($code)
    {
        return $this->getValue($this->config['code_key'] . $code);
    }

    public function setAuthorizationCode($authorization_code, $client_id, $user_id, $redirect_uri, $expires, $scope = null, $id_token = null, $code_challenge = null, $code_challenge_method = null)
    {
        return $this->setValue(
            $this->config['code_key'] . $authorization_code,
            compact('authorization_code', 'client_id', 'user_id', 'redirect_uri', 'expires', 'scope', 'id_token', 'code_challenge', 'code_challenge_method'),
            $expires
        );
    }

    public function expireAuthorizationCode($code)
    {
        $key = $this->config['code_key'] . $code;
        unset($this->cache[$key]);

        return $this->expireValue($key);
    }

    public function checkUserCredentials($username, $password)
    {
        if ($user = $this->getUser($username)) {
            return $this->checkPassword($user, $password);
        }

        return false;
    }

    protected function checkPassword($user, $password)
    {
        return $user['password'] == $this->hashPassword($password);
    }

    protected function hashPassword($password)
    {
        return sha1($password);
    }

    public function getUserDetails($username)
    {
        return $this->getUser($username);
    }

    public function getUser($username)
    {
        if (!$userInfo = $this->getValue($this->config['user_key'] . $username)) {
            return false;
        }

        return array_merge(array(
            'user_id' => $username,
        ), $userInfo);
    }

    public function setUser($username, $password, $first_name = null, $last_name = null)
    {
        $password = $this->hashPassword($password);

        return $this->setValue(
            $this->config['user_key'] . $username,
            compact('username', 'password', 'first_name', 'last_name')
        );
    }

    public function checkClientCredentials($client_id, $client_secret = null)
    {
        if (!$client = $this->getClientDetails($client_id)) {
            return false;
        }

        return isset($client['client_secret'])
            && $client['client_secret'] == $client_secret;
    }

    public function isPublicClient($client_id)
    {
        if (!$client = $this->getClientDetails($client_id)) {
            return false;
        }

        return empty($client['client_secret']);
    }

    public function getClientDetails($client_id)
    {
        return $this->getValue($this->config['client_key'] . $client_id);
    }

    public function setClientDetails($client_id, $client_secret = null, $redirect_uri = null, $grant_types = null, $scope = null, $user_id = null)
    {
        return $this->setValue(
            $this->config['client_key'] . $client_id,
            compact('client_id', 'client_secret', 'redirect_uri', 'grant_types', 'scope', 'user_id')
        );
    }

    public function checkRestrictedGrantType($client_id, $grant_type)
    {
        $details = $this->getClientDetails($client_id);
        if (isset($details['grant_types'])) {
            $grant_types = explode(' ', $details['grant_types']);

            return in_array($grant_type, (array) $grant_types);
        }

        return true;
    }

    public function getRefreshToken($refresh_token)
    {
        return $this->getValue($this->config['refresh_token_key'] . $refresh_token);
    }

    public function setRefreshToken($refresh_token, $client_id, $user_id, $expires, $scope = null)
    {
        return $this->setValue(
            $this->config['refresh_token_key'] . $refresh_token,
            compact('refresh_token', 'client_id', 'user_id', 'expires', 'scope'),
            $expires
        );
    }

    public function unsetRefreshToken($refresh_token)
    {
        return $this->expireValue($this->config['refresh_token_key'] . $refresh_token);
    }

    public function getAccessToken($access_token)
    {
        return $this->getValue($this->config['access_token_key'] . $access_token);
    }

    public function setAccessToken($access_token, $client_id, $user_id, $expires, $scope = null)
    {
        return $this->setValue(
            $this->config['access_token_key'] . $access_token,
            compact('access_token', 'client_id', 'user_id', 'expires', 'scope'),
            $expires
        );
    }

    public function unsetAccessToken($access_token)
    {
        return $this->expireValue($this->config['access_token_key'] . $access_token);
    }

    public function scopeExists($scope)
    {
        $scope = explode(' ', $scope);

        $result = $this->getValue($this->config['scope_key'] . 'supported:global');

        $supportedScope = explode(' ', (string) $result);

        return (count(array_diff($scope, $supportedScope)) == 0);
    }

    public function getDefaultScope($client_id = null)
    {
        if (is_null($client_id) || !$result = $this->getValue($this->config['scope_key'] . 'default:' . $client_id)) {
            $result = $this->getValue($this->config['scope_key'] . 'default:global');
        }

        return $result;
    }

    public function setScope($scope, $client_id = null, $type = 'supported')
    {
        if (!in_array($type, array('default', 'supported'))) {
            throw new \InvalidArgumentException('"$type" must be one of "default", "supported"');
        }

        if (is_null($client_id)) {
            $key = $this->config['scope_key'] . $type . ':global';
        } else {
            $key = $this->config['scope_key'] . $type . ':' . $client_id;
        }

        return $this->setValue($key, $scope);
    }

    public function getClientKey($client_id, $subject)
    {
        if (!$jwt = $this->getValue($this->config['jwt_key'] . $client_id)) {
            return false;
        }

        if (isset($jwt['subject']) && $jwt['subject'] == $subject) {
            return $jwt['key'];
        }

        return null;
    }

    public function setClientKey($client_id, $key, $subject = null)
    {
        return $this->setValue($this->config['jwt_key'] . $client_id, array(
            'key' => $key,
            'subject' => $subject,
        ));
    }

    public function getClientScope($client_id)
    {
        if (!$clientDetails = $this->getClientDetails($client_id)) {
            return false;
        }

        if (isset($clientDetails['scope'])) {
            return $clientDetails['scope'];
        }

        return null;
    }

    public function getJti($client_id, $subject, $audience, $expiration, $jti)
    {
        throw new \Exception('getJti() for the Cassandra driver is currently unimplemented.');
    }

    public function setJti($client_id, $subject, $audience, $expiration, $jti)
    {
        throw new \Exception('setJti() for the Cassandra driver is currently unimplemented.');
    }

    public function getPublicKey($client_id = '')
    {
        $public_key = $this->getValue($this->config['public_key_key'] . $client_id);
        if (is_array($public_key)) {
            return $public_key['public_key'];
        }
        $public_key = $this->getValue($this->config['public_key_key']);
        if (is_array($public_key)) {
            return $public_key['public_key'];
        }
    }

    public function getPrivateKey($client_id = '')
    {
        $public_key = $this->getValue($this->config['public_key_key'] . $client_id);
        if (is_array($public_key)) {
            return $public_key['private_key'];
        }
        $public_key = $this->getValue($this->config['public_key_key']);
        if (is_array($public_key)) {
            return $public_key['private_key'];
        }
    }

    public function getEncryptionAlgorithm($client_id = null)
    {
        $public_key = $this->getValue($this->config['public_key_key'] . $client_id);
        if (is_array($public_key)) {
            return $public_key['encryption_algorithm'];
        }
        $public_key = $this->getValue($this->config['public_key_key']);
        if (is_array($public_key)) {
            return $public_key['encryption_algorithm'];
        }

        return 'RS256';
    }

    public function getUserClaims($user_id, $claims)
    {
        $userDetails = $this->getUserDetails($user_id);
        if (!is_array($userDetails)) {
            return false;
        }

        $claims = explode(' ', trim($claims));
        $userClaims = array();

        $validClaims = explode(' ', self::VALID_CLAIMS);
        foreach ($validClaims as $validClaim) {
            if (in_array($validClaim, $claims)) {
                if ($validClaim == 'address') {
                    $userClaims['address'] = $this->getUserClaim($validClaim, $userDetails['address'] ?: $userDetails);
                } else {
                    $userClaims = array_merge($userClaims, $this->getUserClaim($validClaim, $userDetails));
                }
            }
        }

        return $userClaims;
    }

    protected function getUserClaim($claim, $userDetails)
    {
        $userClaims = array();
        $claimValuesString = constant(sprintf('self::%s_CLAIM_VALUES', strtoupper($claim)));
        $claimValues = explode(' ', $claimValuesString);

        foreach ($claimValues as $value) {
            if ($value == 'email_verified') {
                $userClaims[$value] = $userDetails[$value] == 'true' ? true : false;
            } else {
                $userClaims[$value] = isset($userDetails[$value]) ? $userDetails[$value] : null;
            }
        }

        return $userClaims;
    }
}
