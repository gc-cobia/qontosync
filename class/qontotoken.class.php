<?php
/*
 * QontoSync dolibarr module
 * @file       class/qontotoken.class.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    http_response_code(403);
    exit('Access Forbidden');
}

/**
 * Stateless HMAC-SHA256 token signer and verifier bound to Dolibarr entity and scope.
 */
class QontoToken
{
    /** @var int Default payload token lifetime in seconds (2 hours) */
    const DEFAULT_TTL = 7200;

    /**
     * Retrieve the Dolibarr instance secret used to derive HMAC keys.
     *
     * @return string Instance unique ID, or empty string if unavailable
     */
    private static function getInstanceSecret(): string
    {
        global $conf;

        return !empty($conf->file->instance_unique_id) ? (string) $conf->file->instance_unique_id : '';
    }

    /**
     * Encode binary/string data using URL-safe Base64 without padding.
     *
     * @param  string $data Raw input string
     * @return string       Base64url-encoded string
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode URL-safe Base64 data strictly.
     *
     * @param  string       $data Base64url-encoded string
     * @return string|false       Decoded string, or false on invalid input
     */
    private static function base64UrlDecode(string $data)
    {
        $padded = strtr($data, '-_', '+/');
        $mod = strlen($padded) % 4;
        if ($mod !== 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }

        return base64_decode($padded, true);
    }

    /**
     * Generate a stateless HMAC-SHA256 signature binding an identifier to
     * the current Dolibarr entity, domain, and scope.
     *
     * @param  string $domain Logical domain separator (e.g. 'supp_inv', 'supp_att')
     * @param  string $scope  Context scope identifier (e.g. user ID or bank account ID)
     * @param  string $id     Target object identifier
     * @return string         Hexadecimal HMAC signature, or empty string if invalid
     */
    public static function signId(string $domain, string $scope, string $id): string
    {
        global $conf;

        $domain = trim($domain);
        $scope  = trim($scope);
        $id     = trim($id);
        $secret = self::getInstanceSecret();

        if ($domain === '' || $scope === '' || $id === '' || $secret === '') {
            return '';
        }

        return hash_hmac(
            'sha256',
            ((int) $conf->entity) . '|' . $scope . '|' . $id,
            'qontosync:' . $domain . ':' . $secret
        );
    }

    /**
     * Verify a stateless HMAC-SHA256 ID signature in constant time.
     *
     * @param  string $domain Logical domain separator
     * @param  string $scope  Context scope identifier
     * @param  string $id     Target object identifier
     * @param  string $sig    Client-supplied HMAC signature
     * @return bool           True if valid, false otherwise
     */
    public static function verifyId(string $domain, string $scope, string $id, string $sig): bool
    {
        $id = trim($id);
        if ($id === '' || $sig === '') {
            return false;
        }

        $expected = self::signId($domain, $scope, $id);
        return $expected !== '' && hash_equals($expected, $sig);
    }

    /**
     * Generate a stateless HMAC-SHA256 signed token embedding an arbitrary payload
     * and expiration timestamp, bound to the current Dolibarr entity, domain, and scope.
     *
     * @param  string $domain  Logical domain separator (e.g. 'trx_v2')
     * @param  string $scope   Context scope identifier (e.g. bank account ID)
     * @param  string $id      Target object identifier
     * @param  array  $payload Associative payload array to embed
     * @param  int    $ttl     Token lifetime in seconds
     * @return string          Signed token (<base64url>.<hmac>), or empty string if invalid
     */
    public static function signPayload(string $domain, string $scope, string $id, array $payload, int $ttl = self::DEFAULT_TTL): string
    {
        global $conf;

        $domain = trim($domain);
        $scope  = trim($scope);
        $id     = trim($id);
        $secret = self::getInstanceSecret();

        if ($domain === '' || $scope === '' || $id === '' || $secret === '') {
            return '';
        }

        $payload['_id']  = $id;
        $payload['_exp'] = time() + $ttl;

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }

        $payloadB64 = self::base64UrlEncode($json);
        $mac = hash_hmac(
            'sha256',
            ((int) $conf->entity) . '|' . $scope . '|' . $id . '|' . $payloadB64,
            'qontosync:' . $domain . ':' . $secret
        );

        return $payloadB64 . '.' . $mac;
    }

    /**
     * Verify a signed payload token in constant time before decoding its content.
     *
     * @param  string     $domain Logical domain separator
     * @param  string     $scope  Context scope identifier
     * @param  string     $id     Target object identifier
     * @param  string     $token  Client-supplied signed token
     * @return array|null         ['payload' => array, 'expired' => bool] if authentic, null otherwise
     */
    public static function verifyPayload(string $domain, string $scope, string $id, string $token): ?array
    {
        global $conf;

        $domain = trim($domain);
        $scope  = trim($scope);
        $id     = trim($id);
        $secret = self::getInstanceSecret();

        if ($domain === '' || $scope === '' || $id === '' || $token === '' || $secret === '') {
            return null;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        $payloadB64 = $parts[0];
        $sig        = $parts[1];

        $expectedMac = hash_hmac(
            'sha256',
            ((int) $conf->entity) . '|' . $scope . '|' . $id . '|' . $payloadB64,
            'qontosync:' . $domain . ':' . $secret
        );

        if (!hash_equals($expectedMac, $sig)) {
            return null;
        }

        $json = self::base64UrlDecode($payloadB64);
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['_id']) || (string) $decoded['_id'] !== $id) {
            return null;
        }

        $expired = (!isset($decoded['_exp']) || (int) $decoded['_exp'] < time());
        unset($decoded['_id'], $decoded['_exp']);

        return [
            'payload' => $decoded,
            'expired' => $expired,
        ];
    }
}
