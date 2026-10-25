<?php
/**
 * core/ai_platform.php — Platform AI (platform_ai_plan.md).
 *
 * One BJP-held AI key, configured in superadmin, that every entitled tenant uses
 * by default. Lives entirely in the control DB:
 *   - platform_settings  ai_platform_*      provider / model / encrypted key / caps
 *   - tenant_ai_policy                       per-tenant key source + cap override
 *   - platform_ai_usage                      metering for every platform-key call
 *
 * Tenants can never read or change any of it. core/ai_service.php asks this file
 * which key a request should use (aiResolveSource) and whether the platform caps
 * still allow a call (aiPlatformCapInfo).
 *
 * Security rules (plan §2): the key is encrypted with core/crypto.php and never
 * returned; saving it requires the on-disk app secret; base URLs must be public
 * https; provider error text is sanitised; metering failures fail CLOSED.
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/platform_settings.php';
require_once __DIR__ . '/crypto.php';

if (!function_exists('aiPlatformSchema')) {
    /** Idempotent: control-DB tables for platform AI. Returns the table names. */
    function aiPlatformSchema(PDO $pdo, string $db): array
    {
        $q = fn(string $t) => "`{$db}`.`{$t}`";
        $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
        $ddl = [
            'platform_ai_usage' => "CREATE TABLE IF NOT EXISTS {$q('platform_ai_usage')} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NULL, user_id INT NULL,
                feature VARCHAR(40) NOT NULL DEFAULT 'generate',
                provider VARCHAR(30) NULL, model VARCHAR(100) NULL,
                prompt_tokens INT NOT NULL DEFAULT 0, completion_tokens INT NOT NULL DEFAULT 0,
                est_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'ok',
                error VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_tenant_created (tenant_id, created_at),
                KEY idx_created (created_at)) {$opt}",
            'tenant_ai_policy' => "CREATE TABLE IF NOT EXISTS {$q('tenant_ai_policy')} (
                tenant_id INT NOT NULL PRIMARY KEY,
                mode ENUM('auto','platform','own','off') NOT NULL DEFAULT 'auto',
                monthly_cap DECIMAL(12,2) NULL,
                updated_by INT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) {$opt}",
        ];
        foreach ($ddl as $sql) {
            $pdo->exec($sql);
        }
        return array_keys($ddl);
    }
}

if (!function_exists('aiPlatformProviders')) {
    function aiPlatformProviders(): array
    {
        return ['openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'gemini' => 'Google Gemini', 'openrouter' => 'OpenRouter'];
    }
}

if (!function_exists('aiPlatformSettings')) {
    /**
     * Platform AI config. The decrypted key is included only when $withKey is true
     * (the engine); pages get 'has_key' instead.
     */
    function aiPlatformSettings(bool $withKey = false): array
    {
        $enc = getPlatformSetting('ai_platform_api_key_enc', '');
        $out = [
            'enabled'     => getPlatformSetting('ai_platform_enabled', '0') === '1',
            'provider'    => getPlatformSetting('ai_platform_provider', 'openai'),
            'model'       => trim(getPlatformSetting('ai_platform_model', '')),
            'base_url'    => trim(getPlatformSetting('ai_platform_base_url', '')),
            'temperature' => (float)getPlatformSetting('ai_platform_temperature', '0.4'),
            'tenant_cap'  => (float)getPlatformSetting('ai_platform_tenant_cap', '5'),
            'global_cap'  => (float)getPlatformSetting('ai_platform_global_cap', '0'),
            'has_key'     => $enc !== '',
        ];
        if (!isset(aiPlatformProviders()[$out['provider']])) $out['provider'] = 'openai';
        if ($withKey) {
            $out['api_key'] = $enc !== '' ? (decryptSecret($enc) ?? '') : '';
        }
        return $out;
    }
}

if (!function_exists('aiPlatformConfigured')) {
    /** Enabled, has a model, and the stored key decrypts on this server. */
    function aiPlatformConfigured(): bool
    {
        $s = aiPlatformSettings(true);
        return $s['enabled'] && $s['model'] !== '' && $s['api_key'] !== '';
    }
}

if (!function_exists('aiAppSecretOnDisk')) {
    /**
     * True when the encryption secret comes from includes/ai_app_secret.php. When
     * that file can't be written, crypto.php falls back to a key derived from
     * DB_NAME — which differs per tenant, so a platform key encrypted under it
     * would not decrypt for other tenants. Platform secrets refuse that fallback.
     */
    function aiAppSecretOnDisk(): bool
    {
        aiAppSecret();   // generates + persists the file on first use when possible
        $file = __DIR__ . '/../includes/ai_app_secret.php';
        return is_file($file) && defined('AI_APP_SECRET') && strlen((string)AI_APP_SECRET) === 64
            && hash_equals(hex2bin((string)AI_APP_SECRET), aiAppSecret());
    }
}

if (!function_exists('aiIpIsPublic')) {
    function aiIpIsPublic(string $ip): bool
    {
        if (strpos($ip, ':') !== false && strpos($ip, '.') !== false) return false;   // IPv4-mapped IPv6
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            $deny = [['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['224.0.0.0', 4]];
            foreach ($deny as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($n & $mask) === (ip2long($net) & $mask)) return false;
            }
        }
        return true;
    }
}

if (!function_exists('aiSafeBaseUrl')) {
    /**
     * Validate an AI base URL against SSRF: https only, no credentials, and every
     * resolved address public. Empty = provider default (ok).
     * @return array{ok:bool,url:string,host:string,port:int,ips:array,error:?string}
     */
    function aiSafeBaseUrl(string $url): array
    {
        $url = trim($url);
        $out = ['ok' => true, 'url' => '', 'host' => '', 'port' => 443, 'ips' => [], 'error' => null];
        if ($url === '') return $out;
        $fail = function (string $why) use ($out) { $out['ok'] = false; $out['error'] = $why; return $out; };
        if (strlen($url) > 300 || preg_match('/[\s\x00-\x1f]/', $url)) return $fail('Base URL is not valid.');
        $p = parse_url($url);
        if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return $fail('Base URL must start with https://');
        if (isset($p['user']) || isset($p['pass'])) return $fail('Base URL must not contain a username or password.');
        if (isset($p['fragment'])) return $fail('Base URL is not valid.');
        $host = strtolower(trim($p['host'], '[]'));
        $port = (int)($p['port'] ?? 443);
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            if (!preg_match('/^[a-z0-9.-]+$/', $host)) return $fail('Base URL host is not valid.');
            $ips = @gethostbynamel($host) ?: [];
            if (!$ips) return $fail('Base URL host cannot be found.');
        }
        foreach ($ips as $ip) {
            if (!aiIpIsPublic($ip)) return $fail('Base URL must point to a public internet address.');
        }
        $out['url'] = rtrim($url, '/'); $out['host'] = $host; $out['port'] = $port; $out['ips'] = $ips;
        return $out;
    }
}

if (!function_exists('aiSanitizeError')) {
    /** Strip secrets (keys, bearer tokens, ?key= params) from provider/network error text. */
    function aiSanitizeError(?string $msg, array $secrets = []): string
    {
        $m = (string)$msg;
        foreach ($secrets as $s) {
            $s = (string)$s;
            if (strlen($s) >= 4) {
                $m = str_replace([$s, rawurlencode($s)], '[redacted]', $m);
            }
        }
        $m = preg_replace('/(Bearer|x-api-key:?)\s+[^\s"\',]+/i', '$1 [redacted]', $m);
        $m = preg_replace('/([?&](?:key|api_key|apikey)=)[^&\s"\']+/i', '$1[redacted]', $m);
        $m = preg_replace('/\b(sk|rk|pk)-[A-Za-z0-9_\-]{8,}/', '[redacted]', $m);
        $m = preg_replace('/\bAIza[0-9A-Za-z_\-]{20,}/', '[redacted]', $m);
        $m = trim(preg_replace('/\s+/', ' ', $m));
        return mb_substr($m, 0, 255);
    }
}

if (!function_exists('aiPlatformValidate')) {
    /**
     * Validate a superadmin settings form. Returns [clean, errors]. The key is
     * handled separately by aiPlatformSave (replace-only).
     */
    function aiPlatformValidate(array $in): array
    {
        $e = []; $c = [];
        $c['ai_platform_enabled'] = !empty($in['enabled']) && (string)$in['enabled'] === '1' ? '1' : '0';
        $prov = (string)($in['provider'] ?? '');
        if (!isset(aiPlatformProviders()[$prov])) $e['provider'] = 'Choose a provider.';
        $c['ai_platform_provider'] = $prov;
        $model = trim((string)($in['model'] ?? ''));
        if ($model === '' || !preg_match('#^[A-Za-z0-9._:/@+\-]{1,100}$#', $model)) $e['model'] = 'Enter a valid model id.';
        $c['ai_platform_model'] = $model;
        $bu = aiSafeBaseUrl((string)($in['base_url'] ?? ''));
        if (!$bu['ok']) $e['base_url'] = $bu['error'];
        $c['ai_platform_base_url'] = $bu['url'];
        foreach (['temperature' => [0, 1], 'tenant_cap' => [0, 100000], 'global_cap' => [0, 1000000]] as $k => [$min, $max]) {
            $v = trim((string)($in[$k] ?? ''));
            if ($v === '' || !is_numeric($v) || (float)$v < $min || (float)$v > $max) {
                $e[$k] = "Enter a number from $min to $max.";
            }
            $c['ai_platform_' . $k] = is_numeric($v) ? (string)round((float)$v, $k === 'temperature' ? 2 : 2) : '';
        }
        if ($c['ai_platform_enabled'] === '1' && !isset($e['model'])) {
            $hasKey = getPlatformSetting('ai_platform_api_key_enc', '') !== '';
            $newKey = trim((string)($in['api_key'] ?? ''));
            if (!$hasKey && $newKey === '') $e['api_key'] = 'Enter the API key before enabling AI.';
        }
        return [$c, $e];
    }
}

if (!function_exists('aiPlatformSave')) {
    /**
     * Save settings (+ optional new key / key removal). Never stores the plain key.
     * @return array{ok:bool,errors:array,key_changed:bool}
     */
    function aiPlatformSave(array $in, ?int $by = null): array
    {
        [$clean, $errors] = aiPlatformValidate($in);
        $newKey = trim((string)($in['api_key'] ?? ''));
        $removeKey = !empty($in['remove_key']) && (string)$in['remove_key'] === '1';
        if ($newKey !== '') {
            if (strlen($newKey) < 8 || strlen($newKey) > 512 || preg_match('/[\s\x00-\x1f\x7f]/', $newKey)) {
                $errors['api_key'] = 'The API key is not valid.';
            } elseif (!aiAppSecretOnDisk()) {
                $errors['api_key'] = 'The server encryption secret file (includes/ai_app_secret.php) is missing or not writable. Fix that first, then save the key.';
            }
        }
        if ($removeKey && $clean['ai_platform_enabled'] === '1') {
            $errors['api_key'] = 'Disable AI before removing the key.';
        }
        if ($errors) return ['ok' => false, 'errors' => $errors, 'key_changed' => false];

        foreach ($clean as $k => $v) setPlatformSetting($k, $v, $by);
        $changed = false;
        if ($newKey !== '') {
            $enc = encryptSecret($newKey);
            if (decryptSecret($enc) !== $newKey) {
                return ['ok' => false, 'errors' => ['api_key' => 'The key could not be encrypted on this server.'], 'key_changed' => false];
            }
            setPlatformSetting('ai_platform_api_key_enc', $enc, $by);
            $changed = true;
        } elseif ($removeKey) {
            setPlatformSetting('ai_platform_api_key_enc', '', $by);
            $changed = true;
        }
        return ['ok' => true, 'errors' => [], 'key_changed' => $changed];
    }
}

if (!function_exists('aiTenantPolicy')) {
    /** ['mode'=>auto|platform|own|off, 'monthly_cap'=>?float]. Cached per request. */
    function aiTenantPolicy(int $tenantId): array
    {
        if (isset($GLOBALS['__bms_ai_policy'][$tenantId])) return $GLOBALS['__bms_ai_policy'][$tenantId];
        $p = ['mode' => 'auto', 'monthly_cap' => null];
        try {
            $st = getControlPdo()->prepare('SELECT mode, monthly_cap FROM tenant_ai_policy WHERE tenant_id = ?');
            $st->execute([$tenantId]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $p = ['mode' => (string)$r['mode'], 'monthly_cap' => $r['monthly_cap'] === null ? null : (float)$r['monthly_cap']];
            }
        } catch (Throwable $e) {
            error_log('aiTenantPolicy: ' . $e->getMessage());
        }
        return $GLOBALS['__bms_ai_policy'][$tenantId] = $p;
    }
}

if (!function_exists('aiSetTenantPolicy')) {
    /** Superadmin: set a tenant's key source and cap ('' = platform default, '0' = unlimited). */
    function aiSetTenantPolicy(int $tenantId, string $mode, ?string $cap, ?int $by = null): array
    {
        if (!in_array($mode, ['auto', 'platform', 'own', 'off'], true)) return ['ok' => false, 'error' => 'Choose a valid key source.'];
        $cap = trim((string)$cap);
        if ($cap !== '' && (!is_numeric($cap) || (float)$cap < 0 || (float)$cap > 100000)) {
            return ['ok' => false, 'error' => 'Monthly limit must be a number from 0 to 100000, or empty for the default.'];
        }
        $c = getControlPdo();
        $st = $c->prepare("SELECT 1 FROM tenants WHERE id = ? AND status <> 'deleted'");
        $st->execute([$tenantId]);
        if (!$st->fetchColumn()) return ['ok' => false, 'error' => 'Company not found.'];
        $c->prepare("INSERT INTO tenant_ai_policy (tenant_id, mode, monthly_cap, updated_by) VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE mode = VALUES(mode), monthly_cap = VALUES(monthly_cap), updated_by = VALUES(updated_by)")
          ->execute([$tenantId, $mode, $cap === '' ? null : round((float)$cap, 2), $by]);
        unset($GLOBALS['__bms_ai_policy'][$tenantId]);
        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('aiResolveSource')) {
    /**
     * Which key this tenant uses: 'platform' | 'own' | 'off'.
     * No tenant (single-tenant install, CLI, platform host) → 'own', exactly the
     * behaviour before platform AI existed. 'auto' keeps a tenant's own working
     * key (so existing set-ups never break) and otherwise uses the platform key.
     */
    function aiResolveSource(?int $tenantId, bool $tenantHasOwnKey): string
    {
        if ($tenantId === null) return 'own';
        $mode = aiTenantPolicy($tenantId)['mode'];
        if ($mode === 'platform' || $mode === 'own' || $mode === 'off') return $mode;
        return $tenantHasOwnKey ? 'own' : 'platform';
    }
}

if (!function_exists('aiPlatformMonthSpend')) {
    /** This calendar month's platform spend for one tenant (or all when null). null = metering unreachable. */
    function aiPlatformMonthSpend(?int $tenantId): ?float
    {
        try {
            $sql = "SELECT COALESCE(SUM(est_cost),0) FROM platform_ai_usage WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
            if ($tenantId === null) return (float)getControlPdo()->query($sql)->fetchColumn();
            $st = getControlPdo()->prepare($sql . ' AND tenant_id = ?');
            $st->execute([$tenantId]);
            return (float)$st->fetchColumn();
        } catch (Throwable $e) {
            error_log('aiPlatformMonthSpend: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('aiPlatformTenantCap')) {
    /** Effective monthly USD cap for a tenant on the platform key (0 = unlimited). */
    function aiPlatformTenantCap(int $tenantId): float
    {
        $p = aiTenantPolicy($tenantId);
        return $p['monthly_cap'] !== null ? (float)$p['monthly_cap'] : aiPlatformSettings()['tenant_cap'];
    }
}

if (!function_exists('aiPlatformCapInfo')) {
    /**
     * ['available'=>bool (metering reachable), 'cap','spent','exceeded' (tenant),
     *  'global_cap','global_spent','global_exceeded', 'blocked'=>bool].
     */
    function aiPlatformCapInfo(int $tenantId): array
    {
        $cap = aiPlatformTenantCap($tenantId);
        $gcap = aiPlatformSettings()['global_cap'];
        $spent = aiPlatformMonthSpend($tenantId);
        $gspent = $gcap > 0 ? aiPlatformMonthSpend(null) : 0.0;
        $available = $spent !== null && $gspent !== null;
        $exceeded = $available && $cap > 0 && $spent >= $cap;
        $gexceeded = $available && $gcap > 0 && $gspent >= $gcap;
        return [
            'available' => $available,
            'cap' => $cap, 'spent' => (float)$spent, 'exceeded' => $exceeded,
            'global_cap' => $gcap, 'global_spent' => (float)$gspent, 'global_exceeded' => $gexceeded,
            'blocked' => !$available || $exceeded || $gexceeded,
        ];
    }
}

if (!function_exists('aiPlatformTenantRateLimited')) {
    /** True when the tenant made >= $perMinute platform calls in the last minute; null if unreachable. */
    function aiPlatformTenantRateLimited(int $tenantId, int $perMinute = 60): ?bool
    {
        try {
            $st = getControlPdo()->prepare("SELECT COUNT(*) FROM platform_ai_usage WHERE tenant_id = ? AND created_at >= (NOW() - INTERVAL 60 SECOND)");
            $st->execute([$tenantId]);
            return (int)$st->fetchColumn() >= $perMinute;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('aiPlatformLogUsage')) {
    /** Meter one platform-key call. Returns false if the row could not be written. */
    function aiPlatformLogUsage(?int $tenantId, ?int $userId, string $feature, string $provider, string $model,
                                int $pt, int $ct, float $cost, string $status, ?string $error = null): bool
    {
        try {
            getControlPdo()->prepare("INSERT INTO platform_ai_usage
                (tenant_id, user_id, feature, provider, model, prompt_tokens, completion_tokens, est_cost, status, error)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$tenantId, $userId, mb_substr($feature, 0, 40), mb_substr($provider, 0, 30), mb_substr($model, 0, 100),
                           $pt, $ct, $cost, mb_substr($status, 0, 20), $error === null ? null : mb_substr($error, 0, 255)]);
            return true;
        } catch (Throwable $e) {
            error_log('aiPlatformLogUsage: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('aiPlatformCompanies')) {
    /**
     * Superadmin Companies tab: every non-deleted company with its AI module state,
     * key source, cap and this month's platform spend. One query, control DB only
     * (no tenant database is opened). A missing features row counts as ON, the same
     * fail-open rule as bmsPrimeTenantFeatures().
     * Filters: q (name/subdomain), mode (auto|platform|own|off), module (on|off).
     */
    function aiPlatformCompanies(array $f = []): array
    {
        $where = ["t.status <> 'deleted'"]; $args = [];
        $q = mb_substr(trim((string)($f['q'] ?? '')), 0, 100);
        if ($q !== '') {
            $where[] = '(t.company_name LIKE ? OR t.subdomain LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $args[] = $like; $args[] = $like;
        }
        $mode = (string)($f['mode'] ?? '');
        if (in_array($mode, ['auto', 'platform', 'own', 'off'], true)) {
            $where[] = "COALESCE(p.mode, 'auto') = ?";
            $args[] = $mode;
        }
        $sql = "SELECT t.id, t.company_name, t.subdomain, t.status,
                       CASE WHEN f.feature_key IS NULL THEN 1
                            ELSE (f.is_available = 1 AND COALESCE(tf.is_enabled, f.default_enabled) = 1) END AS ai_module,
                       COALESCE(p.mode, 'auto') AS mode, p.monthly_cap,
                       COALESCE(u.spent, 0) AS spent, COALESCE(u.calls, 0) AS calls
                FROM tenants t
                LEFT JOIN features f ON f.feature_key = 'ai_assistant'
                LEFT JOIN tenant_features tf ON tf.tenant_id = t.id AND tf.feature_key = 'ai_assistant'
                LEFT JOIN tenant_ai_policy p ON p.tenant_id = t.id
                LEFT JOIN (SELECT tenant_id, SUM(est_cost) spent, SUM(status = 'ok') calls
                             FROM platform_ai_usage WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') AND tenant_id IS NOT NULL
                            GROUP BY tenant_id) u ON u.tenant_id = t.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY spent DESC, t.company_name
                LIMIT 1000";
        try {
            $st = getControlPdo()->prepare($sql);
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('aiPlatformCompanies: ' . $e->getMessage());
            return [];
        }
        $default = aiPlatformSettings()['tenant_cap'];
        $module = (string)($f['module'] ?? '');
        $out = [];
        foreach ($rows as $r) {
            $r['ai_module'] = (int)$r['ai_module'] === 1;
            if ($module === 'on' && !$r['ai_module']) continue;
            if ($module === 'off' && $r['ai_module']) continue;
            $r['monthly_cap'] = $r['monthly_cap'] === null ? null : (float)$r['monthly_cap'];
            $r['effective_cap'] = $r['monthly_cap'] ?? $default;
            $r['spent'] = (float)$r['spent'];
            $r['calls'] = (int)$r['calls'];
            $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('aiPlatformUsageSummary')) {
    /** Superadmin Usage tab: month totals, by company, by feature, recent problems. */
    function aiPlatformUsageSummary(): array
    {
        $out = ['month' => ['cost' => 0.0, 'calls' => 0, 'blocked' => 0, 'errors' => 0], 'last_month' => ['cost' => 0.0, 'calls' => 0],
                'by_company' => [], 'by_feature' => [], 'problems' => []];
        try {
            $c = getControlPdo();
            $m = $c->query("SELECT COALESCE(SUM(est_cost),0) cost, COALESCE(SUM(status='ok'),0) calls,
                                   COALESCE(SUM(status='blocked'),0) blocked, COALESCE(SUM(status='error'),0) errors
                              FROM platform_ai_usage WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetch(PDO::FETCH_ASSOC);
            $out['month'] = ['cost' => (float)$m['cost'], 'calls' => (int)$m['calls'], 'blocked' => (int)$m['blocked'], 'errors' => (int)$m['errors']];
            $l = $c->query("SELECT COALESCE(SUM(est_cost),0) cost, COALESCE(SUM(status='ok'),0) calls FROM platform_ai_usage
                             WHERE created_at >= DATE_FORMAT(NOW() - INTERVAL 1 MONTH, '%Y-%m-01')
                               AND created_at < DATE_FORMAT(NOW(), '%Y-%m-01')")->fetch(PDO::FETCH_ASSOC);
            $out['last_month'] = ['cost' => (float)$l['cost'], 'calls' => (int)$l['calls']];
            $out['by_company'] = $c->query("SELECT u.tenant_id, t.company_name, t.subdomain, SUM(u.est_cost) cost, SUM(u.status='ok') calls,
                                                   SUM(u.prompt_tokens + u.completion_tokens) tokens
                                              FROM platform_ai_usage u LEFT JOIN tenants t ON t.id = u.tenant_id
                                             WHERE u.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') AND u.tenant_id IS NOT NULL
                                             GROUP BY u.tenant_id, t.company_name, t.subdomain ORDER BY cost DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            $out['by_feature'] = $c->query("SELECT feature, SUM(est_cost) cost, SUM(status='ok') calls FROM platform_ai_usage
                                             WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
                                             GROUP BY feature ORDER BY cost DESC")->fetchAll(PDO::FETCH_ASSOC);
            $out['problems'] = $c->query("SELECT u.created_at, u.tenant_id, t.company_name, u.feature, u.status, u.error
                                            FROM platform_ai_usage u LEFT JOIN tenants t ON t.id = u.tenant_id
                                           WHERE u.status <> 'ok' ORDER BY u.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('aiPlatformUsageSummary: ' . $e->getMessage());
        }
        return $out;
    }
}
