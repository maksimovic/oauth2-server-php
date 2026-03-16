<?php

namespace OAuth2\Storage;

use Couchbase\Cluster;
use Couchbase\ClusterOptions;
use Couchbase\Collection;
use Couchbase\Exception\DocumentNotFoundException;
use OAuth2\OpenID\Storage\AuthorizationCodeInterface as OpenIDAuthorizationCodeInterface;

/**
 * Simple Couchbase storage for all storage types
 *
 * Uses Couchbase SDK 4.x (ext-couchbase ^4.0).
 *
 * This class should be extended or overridden as required
 *
 * NOTE: Passwords are stored in plaintext, which is never
 * a good idea.  Be sure to override this for your application
 *
 * @author Tom Park <tom@raucter.com>
 */
class CouchbaseDB implements AuthorizationCodeInterface,
    AccessTokenInterface,
    ClientCredentialsInterface,
    UserCredentialsInterface,
    RefreshTokenInterface,
    JwtBearerInterface,
    OpenIDAuthorizationCodeInterface
{
    protected Collection $collection;
    protected array $config;

    /**
     * @param Collection|array $connection A Couchbase\Collection instance or a config array
     *        with keys: connection_string, username, password, bucket, scope (optional), collection (optional)
     * @param array $config Table name overrides
     */
    public function __construct($connection, array $config = [])
    {
        if ($connection instanceof Collection) {
            $this->collection = $connection;
        } else {
            if (!is_array($connection)) {
                throw new \InvalidArgumentException(
                    'First argument to OAuth2\Storage\CouchbaseDB must be a Couchbase\Collection or a configuration array'
                );
            }

            $options = new ClusterOptions();
            $options->credentials($connection['username'] ?? '', $connection['password'] ?? '');
            $cluster = new Cluster($connection['connection_string'], $options);
            $bucket = $cluster->bucket($connection['bucket']);
            $scope = $bucket->scope($connection['scope'] ?? '_default');
            $this->collection = $scope->collection($connection['collection'] ?? '_default');
        }

        $this->config = array_merge([
            'client_table' => 'oauth_clients',
            'access_token_table' => 'oauth_access_tokens',
            'refresh_token_table' => 'oauth_refresh_tokens',
            'code_table' => 'oauth_authorization_codes',
            'user_table' => 'oauth_users',
            'jwt_table' => 'oauth_jwt',
        ], $config);
    }

    /**
     * Build a document key from a table name config key and an id.
     */
    protected function buildKey(string $name, string $id): string
    {
        return $this->config[$name] . '-' . $id;
    }

    protected function getObjectByType(string $name, string $id): ?array
    {
        try {
            $result = $this->collection->get($this->buildKey($name, $id));
            return $result->content();
        } catch (DocumentNotFoundException) {
            return null;
        }
    }

    protected function setObjectByType(string $name, string $id, array $data): void
    {
        $data['type'] = $name;
        $this->collection->upsert($this->buildKey($name, $id), $data);
    }

    protected function deleteObjectByType(string $name, string $id): void
    {
        try {
            $this->collection->remove($this->buildKey($name, $id));
        } catch (DocumentNotFoundException) {
            // already gone
        }
    }

    /* ClientCredentialsInterface */
    public function checkClientCredentials($client_id, $client_secret = null)
    {
        if ($result = $this->getObjectByType('client_table', $client_id)) {
            return $result['client_secret'] == $client_secret;
        }

        return false;
    }

    public function isPublicClient($client_id)
    {
        if (!$result = $this->getObjectByType('client_table', $client_id)) {
            return false;
        }

        return empty($result['client_secret']);
    }

    /* ClientInterface */
    public function getClientDetails($client_id)
    {
        $result = $this->getObjectByType('client_table', $client_id);

        return is_null($result) ? false : $result;
    }

    public function setClientDetails($client_id, $client_secret = null, $redirect_uri = null, $grant_types = null, $scope = null, $user_id = null)
    {
        $this->setObjectByType('client_table', $client_id, [
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri'  => $redirect_uri,
            'grant_types'   => $grant_types,
            'scope'         => $scope,
            'user_id'       => $user_id,
        ]);

        return true;
    }

    public function checkRestrictedGrantType($client_id, $grant_type)
    {
        $details = $this->getClientDetails($client_id);
        if (isset($details['grant_types'])) {
            $grant_types = explode(' ', $details['grant_types']);

            return in_array($grant_type, $grant_types);
        }

        // if grant_types are not defined, then none are restricted
        return true;
    }

    /* AccessTokenInterface */
    public function getAccessToken($access_token)
    {
        $token = $this->getObjectByType('access_token_table', $access_token);

        return is_null($token) ? false : $token;
    }

    public function setAccessToken($access_token, $client_id, $user_id, $expires, $scope = null)
    {
        $this->setObjectByType('access_token_table', $access_token, [
            'access_token' => $access_token,
            'client_id' => $client_id,
            'expires' => $expires,
            'user_id' => $user_id,
            'scope' => $scope,
        ]);

        return true;
    }

    /* AuthorizationCodeInterface */
    public function getAuthorizationCode($code)
    {
        $code = $this->getObjectByType('code_table', $code);

        return is_null($code) ? false : $code;
    }

    public function setAuthorizationCode($code, $client_id, $user_id, $redirect_uri, $expires, $scope = null, $id_token = null, $code_challenge = null, $code_challenge_method = null)
    {
        $this->setObjectByType('code_table', $code, [
            'authorization_code' => $code,
            'client_id' => $client_id,
            'user_id' => $user_id,
            'redirect_uri' => $redirect_uri,
            'expires' => $expires,
            'scope' => $scope,
            'id_token' => $id_token,
            'code_challenge' => $code_challenge,
            'code_challenge_method' => $code_challenge_method,
        ]);

        return true;
    }

    public function expireAuthorizationCode($code)
    {
        $this->deleteObjectByType('code_table', $code);

        return true;
    }

    /* UserCredentialsInterface */
    public function checkUserCredentials($username, $password)
    {
        if ($user = $this->getUser($username)) {
            return $this->checkPassword($user, $password);
        }

        return false;
    }

    public function getUserDetails($username)
    {
        if ($user = $this->getUser($username)) {
            $user['user_id'] = $user['username'];
        }

        return $user;
    }

    /* RefreshTokenInterface */
    public function getRefreshToken($refresh_token)
    {
        $token = $this->getObjectByType('refresh_token_table', $refresh_token);

        return is_null($token) ? false : $token;
    }

    public function setRefreshToken($refresh_token, $client_id, $user_id, $expires, $scope = null)
    {
        $this->setObjectByType('refresh_token_table', $refresh_token, [
            'refresh_token' => $refresh_token,
            'client_id' => $client_id,
            'user_id' => $user_id,
            'expires' => $expires,
            'scope' => $scope,
        ]);

        return true;
    }

    public function unsetRefreshToken($refresh_token)
    {
        $this->deleteObjectByType('refresh_token_table', $refresh_token);

        return true;
    }

    // plaintext passwords are bad!  Override this for your application
    protected function checkPassword($user, $password)
    {
        return $user['password'] == $password;
    }

    public function getUser($username)
    {
        $result = $this->getObjectByType('user_table', $username);

        return is_null($result) ? false : $result;
    }

    public function setUser($username, $password, $firstName = null, $lastName = null)
    {
        $this->setObjectByType('user_table', $username, [
            'username' => $username,
            'password' => $password,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        return true;
    }

    public function getClientKey($client_id, $subject)
    {
        if (!$jwt = $this->getObjectByType('jwt_table', $client_id)) {
            return false;
        }

        if (isset($jwt['subject']) && $jwt['subject'] == $subject) {
            return $jwt['key'];
        }

        return false;
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
        //TODO: Needs couchbase implementation.
        throw new \Exception('getJti() for the Couchbase driver is currently unimplemented.');
    }

    public function setJti($client_id, $subject, $audience, $expiration, $jti)
    {
        //TODO: Needs couchbase implementation.
        throw new \Exception('setJti() for the Couchbase driver is currently unimplemented.');
    }
}
