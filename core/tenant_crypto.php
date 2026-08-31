<?php
/**
 * core/tenant_crypto.php
 * ----------------------
 * Encrypts tenant MySQL passwords at rest in `bms_control.tenants`.
 *
 * AES-256-GCM via OpenSSL — authenticated encryption, so a tampered ciphertext
 * fails to decrypt rather than silently returning garbage that would then be
 * handed to MySQL as a password.
 *
 * This is deliberately SEPARATE from core/crypto.php, which encrypts the AI
 * provider API key. Two reasons they must not share a key:
 *
 *   1. Different blast radius. core/crypto.php's key is per-environment and
 *      disposable — lose it and an admin re-enters one API key. This key opens
 *      every tenant database on the platform.
 *   2. Different failure policy. core/crypto.php's aiAppSecret() AUTO-GENERATES
 *      a key on first use, which is right for a disposable secret and
 *      catastrophic here: silently minting a new key would orphan every stored
 *      tenant credential at once, locking the platform out of every tenant
 *      database with no error until the next connection attempt. So this file
 *      NEVER generates a key. A missing key is a fatal, loud, immediate error.
 *
 * The distinct "tenc:v1:" token prefix means a value encrypted under one key
 * domain can never be mistaken for the other.
 *
 * Key resolution order (see docs/MULTI_TENANCY_CONVENTIONS.md §2):
 *   1. Environment variable TENANT_CRED_KEY   — preferred in production
 *   2. includes/tenant_cred_key.php           — local/WAMP, gitignored
 *
 * Public API:
 *   tenantCredKeyAvailable(): bool          → true if a usable key is present
 *   encryptTenantSecret(string): string     → "tenc:v1:…"
 *   decryptTenantSecret(string): ?string    → plaintext, or null if invalid
 *   isEncryptedTenantSecret(?string): bool
 */

if (!function_exists('tenantCredKeyRaw')) {
    /**
     * Return the raw 32-byte master key.
     *
     * @throws RuntimeException if no valid key is configured. Never generates one.
     */
    function tenantCredKeyRaw(): string
    {
        static $key = null;
        if ($key !== null) return $key;

        $hex = getenv('TENANT_CRED_KEY');
        $src = 'environment variable TENANT_CRED_KEY';

        if (!is_string($hex) || $hex === '') {
            $file = __DIR__ . '/../includes/tenant_cred_key.php';
            if (is_file($file)) {
                require_once $file;
                if (defined('TENANT_CRED_KEY')) {
                    $hex = (string)TENANT_CRED_KEY;
                    $src = 'includes/tenant_cred_key.php';
                }
            }
        }

        if (!is_string($hex) || $hex === '') {
            throw new RuntimeException(
                'TENANT_CRED_KEY is not configured. Set the environment variable, or place '
                . 'includes/tenant_cred_key.php on this server. It is intentionally never '
                . 'auto-generated: minting a new key would make every stored tenant database '
                . 'password undecryptable. See docs/MULTI_TENANCY_CONVENTIONS.md §2.'
            );
        }

        $hex = strtolower(trim($hex));
        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            throw new RuntimeException(
                'TENANT_CRED_KEY (from ' . $src . ') is malformed: expected 64 hex characters '
                . '(32 bytes), got ' . strlen($hex) . ' characters. Refusing to proceed rather '
                . 'than encrypt tenant credentials under a weak or truncated key.'
            );
        }

        return $key = hex2bin($hex);
    }
}

if (!function_exists('tenantCredKeyAvailable')) {
    /**
     * True if a valid key is configured. Lets callers (installers, health checks,
     * the superadmin panel) report a clear "key missing" state instead of a fatal.
     */
    function tenantCredKeyAvailable(): bool
    {
        try {
            tenantCredKeyRaw();
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }
}

if (!function_exists('encryptTenantSecret')) {
    /** Encrypt a tenant DB password → "tenc:v1:base64(iv|tag|ciphertext)". */
    function encryptTenantSecret(string $plain): string
    {
        if ($plain === '') return '';
        $iv  = random_bytes(12);                  // GCM standard nonce length
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', tenantCredKeyRaw(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            // Never fall back to storing plaintext — a silent downgrade here would
            // put a live database password in the clear inside the control DB.
            throw new RuntimeException('Failed to encrypt tenant secret (openssl_encrypt returned false).');
        }
        return 'tenc:v1:' . base64_encode($iv . $tag . $ct);
    }
}

if (!function_exists('decryptTenantSecret')) {
    /** Decrypt a "tenc:v1:…" token → plaintext, or null if invalid/tampered. */
    function decryptTenantSecret(string $token): ?string
    {
        if (!isEncryptedTenantSecret($token)) return null;
        $raw = base64_decode(substr($token, 8), true);
        if ($raw === false || strlen($raw) < 29) return null;   // 12 iv + 16 tag + >=1
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', tenantCredKeyRaw(), OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? null : $pt;
    }
}

if (!function_exists('isEncryptedTenantSecret')) {
    /** True if the value looks like an encryptTenantSecret() token. */
    function isEncryptedTenantSecret(?string $v): bool
    {
        return is_string($v) && strncmp($v, 'tenc:v1:', 8) === 0;
    }
}
