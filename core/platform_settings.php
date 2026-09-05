<?php
/**
 * core/platform_settings.php
 * ---------------------------
 * The control database's general-purpose settings store — the platform-level
 * counterpart to a tenant's own `system_settings` (helpers.php's get_setting()/
 * save_setting()). Same key-value shape, same caching model, deliberately
 * scoped to `bms_control.platform_settings` instead of any one tenant's
 * database, so a value here is never readable or editable by a tenant's own
 * admin — see docs/MULTI_TENANCY_CONVENTIONS.md and why that boundary matters
 * for smtp_password_enc specifically.
 *
 * The SMTP password is stored encrypted (`smtp_password_enc`), reusing
 * core/crypto.php's encryptSecret()/decryptSecret() — the SAME utility the AI
 * provider key and Zoom's client secret already use for exactly this class of
 * risk (a re-enterable service credential). Deliberately NOT
 * core/tenant_crypto.php: that key's own docblock is explicit it must never be
 * shared outside "opens every tenant database" secrets, and this isn't one —
 * losing this key costs re-entering an SMTP password, not orphaning the fleet.
 *
 * Public API:
 *   getPlatformSetting(string $key, string $default = ''): string
 *   setPlatformSetting(string $key, ?string $value, ?int $updatedBy = null): void
 *   getAllPlatformSettings(): array
 *   platformMailerOpts(): array   → ['configured'=>bool, 'opts'=>array for sendEmail()]
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/crypto.php';

if (!function_exists('getAllPlatformSettings')) {
    /** All platform settings as [key => value], cached per request in $GLOBALS (not a function-local static, so setPlatformSetting() can invalidate it within the same request). */
    function getAllPlatformSettings(): array
    {
        if (!isset($GLOBALS['__bms_platform_settings_cache'])) {
            $cache = [];
            try {
                $st = getControlPdo()->query("SELECT setting_key, setting_value FROM platform_settings");
                foreach ($st as $row) {
                    $cache[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Throwable $e) {
                error_log('getAllPlatformSettings: ' . $e->getMessage());
            }
            $GLOBALS['__bms_platform_settings_cache'] = $cache;
        }
        return $GLOBALS['__bms_platform_settings_cache'];
    }
}

if (!function_exists('getPlatformSetting')) {
    function getPlatformSetting(string $key, string $default = ''): string
    {
        $all = getAllPlatformSettings();
        return array_key_exists($key, $all) && $all[$key] !== null ? (string)$all[$key] : $default;
    }
}

if (!function_exists('setPlatformSetting')) {
    /** Upsert one setting and keep the request-local cache correct immediately after. */
    function setPlatformSetting(string $key, ?string $value, ?int $updatedBy = null): void
    {
        getControlPdo()->prepare("
            INSERT INTO platform_settings (setting_key, setting_value, updated_by)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
        ")->execute([$key, $value, $updatedBy]);

        getAllPlatformSettings();   // ensure the cache is loaded before mutating it
        $GLOBALS['__bms_platform_settings_cache'][$key] = $value;
    }
}

if (!function_exists('platformMailerOpts')) {
    /**
     * Resolve platform SMTP config into the exact $opts shape sendEmail()
     * (core/mailer.php) accepts as a per-call override — so platform-originated
     * mail (welcome emails, broadcasts) never touches a tenant's own
     * system_settings or the global $pdo the superadmin panel deliberately
     * never opens.
     *
     * @return array{configured: bool, opts: array}
     */
    function platformMailerOpts(): array
    {
        $host = trim(getPlatformSetting('smtp_host'));
        $user = trim(getPlatformSetting('smtp_username'));
        $pass = '';
        $encPass = getPlatformSetting('smtp_password_enc');
        if ($encPass !== '') {
            $pass = decryptSecret($encPass) ?? '';
        }
        $port = (int)getPlatformSetting('smtp_port', '587');
        $enc  = getPlatformSetting('smtp_encryption', 'tls');
        $fromEmail = trim(getPlatformSetting('from_email'));
        $fromName  = trim(getPlatformSetting('from_name', getPlatformSetting('platform_name', 'BMS Platform')));

        $configured = $host !== '' && $user !== '';

        return [
            'configured' => $configured,
            'opts' => [
                'smtp' => [
                    'host'       => $host,
                    'port'       => $port,
                    'username'   => $user,
                    'password'   => $pass,
                    'encryption' => $enc,
                    'from_email' => $fromEmail,
                    'from_name'  => $fromName,
                ],
                'from_email' => $fromEmail,
                'from_name'  => $fromName,
                'wrap'       => true,
                'wrap_brand' => getPlatformSetting('platform_name', 'BMS Platform'),
            ],
        ];
    }
}
