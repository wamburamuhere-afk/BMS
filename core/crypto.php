<?php
/**
 * core/crypto.php
 * ---------------
 * Small symmetric-encryption helper for secrets at rest (currently the AI
 * provider API key). AES-256-GCM via OpenSSL — authenticated encryption, so a
 * tampered ciphertext fails to decrypt rather than returning garbage.
 *
 * The encryption key is an APP SECRET stored in a file OUTSIDE the database
 * (so a DB dump never reveals it) and OUTSIDE version control (each environment
 * generates its own on first use). Because the secret is per-environment,
 * encrypted values do not transfer between installs — which is intentional:
 * after a deploy the admin simply re-enters the API key on the new server.
 *
 * Public API:
 *   encryptSecret(string $plain): string   → opaque base64 token ("enc:v1:…")
 *   decryptSecret(string $token): ?string   → plaintext, or null if invalid
 *   isEncryptedSecret(string $v): bool
 */

if (!function_exists('aiAppSecret')) {
    /**
     * Return the raw 32-byte app secret, generating + persisting it on first use.
     * Stored in includes/ai_app_secret.php as a hex constant (not web-served as
     * data; it only defines a constant). Falls back to a derived key if the file
     * can't be written, so encryption still works (degraded) in read-only envs.
     */
    function aiAppSecret(): string
    {
        static $key = null;
        if ($key !== null) return $key;

        $file = __DIR__ . '/../includes/ai_app_secret.php';

        if (is_file($file)) {
            require_once $file;
            if (defined('AI_APP_SECRET') && strlen((string)AI_APP_SECRET) === 64) {
                return $key = hex2bin(AI_APP_SECRET);
            }
        }

        // Generate a fresh 32-byte (256-bit) secret and try to persist it.
        $hex = bin2hex(random_bytes(32));
        $php = "<?php\n// Auto-generated app secret for core/crypto.php — DO NOT commit, DO NOT share.\n"
             . "// Encrypts secrets at rest (AI provider API key). Per-environment.\n"
             . "if (!defined('AI_APP_SECRET')) define('AI_APP_SECRET', '" . $hex . "');\n";
        if (@file_put_contents($file, $php, LOCK_EX) !== false) {
            @chmod($file, 0600);
            if (!defined('AI_APP_SECRET')) define('AI_APP_SECRET', $hex);
            return $key = hex2bin($hex);
        }

        // Could not write a file — derive a stable key from DB credentials so the
        // app still functions (less ideal, but never blocks the feature).
        $seed = (defined('DB_NAME') ? DB_NAME : 'bms') . '|' . (defined('DB_PASSWORD') ? DB_PASSWORD : '') . '|bms-ai';
        return $key = hash('sha256', $seed, true);
    }
}

if (!function_exists('encryptSecret')) {
    /** Encrypt a plaintext secret → "enc:v1:base64(iv|tag|ciphertext)". */
    function encryptSecret(string $plain): string
    {
        if ($plain === '') return '';
        $key = aiAppSecret();
        $iv  = random_bytes(12);                 // GCM standard nonce length
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) return '';
        return 'enc:v1:' . base64_encode($iv . $tag . $ct);
    }
}

if (!function_exists('decryptSecret')) {
    /** Decrypt an "enc:v1:…" token → plaintext, or null if invalid/tampered. */
    function decryptSecret(string $token): ?string
    {
        if (!isEncryptedSecret($token)) return null;
        $raw = base64_decode(substr($token, 7), true);
        if ($raw === false || strlen($raw) < 29) return null;   // 12 iv + 16 tag + ≥1
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', aiAppSecret(), OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? null : $pt;
    }
}

if (!function_exists('isEncryptedSecret')) {
    /** True if the value looks like an encryptSecret() token. */
    function isEncryptedSecret(?string $v): bool
    {
        return is_string($v) && strncmp($v, 'enc:v1:', 7) === 0;
    }
}
