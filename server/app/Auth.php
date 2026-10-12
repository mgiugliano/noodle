<?php
declare(strict_types=1);

/**
 * HMAC request signing for the management API.
 *
 * signature = hex(HMAC-SHA256(secret, "noodle-v1\n" . timestamp . "\n" . nonce . "\n" . sha256(body)))
 * Headers: X-Noodle-Timestamp, X-Noodle-Nonce, X-Noodle-Signature.
 * The secret never leaves the Mac / the server config. Nonces are single use; timestamps are valid +-5 min.
 */
final class Auth
{
    public const SKEW = 300;

    public static function sign(string $secret, int $ts, string $nonce, string $body): string
    {
        return hash_hmac('sha256', "noodle-v1\n$ts\n$nonce\n" . hash('sha256', $body), $secret);
    }

    public static function verify(array $server, string $body, string $secret, Store $store, int $now): bool
    {
        $ts = $server['HTTP_X_NOODLE_TIMESTAMP'] ?? '';
        $nonce = $server['HTTP_X_NOODLE_NONCE'] ?? '';
        $sig = $server['HTTP_X_NOODLE_SIGNATURE'] ?? '';
        if (!is_string($ts) || preg_match('/^\d{9,11}$/D', $ts) !== 1
            || !is_string($nonce) || preg_match('/^[A-Za-z0-9]{16,64}$/D', $nonce) !== 1
            || !is_string($sig) || preg_match('/^[0-9a-f]{64}$/D', $sig) !== 1) {
            return false;
        }
        if (abs($now - (int)$ts) > self::SKEW) {
            return false;
        }
        if (!hash_equals(self::sign($secret, (int)$ts, $nonce, $body), $sig)) {
            return false;
        }
        // Only burn the nonce after the signature is proven valid.
        return $store->claimNonce($nonce, $now);
    }
}
