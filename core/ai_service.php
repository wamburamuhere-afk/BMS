<?php
/**
 * core/ai_service.php
 * -------------------
 * Provider-agnostic AI completion layer for the AI Assistant (plan: ai_assistant.md).
 *
 * One internal message format — [['role'=>'system|user|assistant','content'=>'…'], …] —
 * mapped to each provider's chat API. Default is OpenAI-compatible (also covers
 * OpenRouter and most self-host gateways via ai_base_url); Anthropic and Gemini
 * have thin adapters. Config (provider/model/key/cap) lives in system_settings;
 * the API key is decrypted only here, never returned to the client.
 *
 * Design rules:
 *   - NEVER throws to the caller. Returns ['ok'=>bool,'text'=>string,'usage'=>[],'error'=>?].
 *   - Logs every call to ai_usage_log (tokens + est. cost).
 *   - Enforces the monthly cost cap (ai_monthly_cost_cap) BEFORE calling out.
 *   - If unconfigured/disabled, aiConfigured() is false and callers degrade gracefully.
 *
 * Public API:
 *   aiConfigured(): bool
 *   aiComplete(array $messages, array $opts = []): array
 *   aiMonthSpend(): float
 *   aiCapInfo(): array  (cap, spent, exceeded)
 */

require_once __DIR__ . '/crypto.php';
// Platform AI (platform_ai_plan.md): which key a tenant uses, platform caps + metering.
require_once __DIR__ . '/ai_platform.php';

if (!function_exists('aiT')) {
    /** Translate a user-facing AI message when the i18n helper is loaded. */
    function aiT(string $s): string
    {
        return function_exists('t') ? (string)t($s) : $s;
    }
}

if (!function_exists('aiOwnSettings')) {
    /** The tenant's OWN AI config from its system_settings (key decrypted). */
    function aiOwnSettings(): array
    {
        $enc = getSetting('ai_api_key_enc', '');
        return [
            'enabled'   => getSetting('ai_enabled', '0') === '1',
            'provider'  => getSetting('ai_provider', 'openai'),
            'model'     => trim(getSetting('ai_model', 'gpt-4o-mini')),
            'api_key'   => $enc !== '' ? (decryptSecret($enc) ?? '') : '',
            'base_url'  => trim(getSetting('ai_base_url', '')),
            'cost_cap'  => (float)getSetting('ai_monthly_cost_cap', '0'),
            'temperature' => (float)getSetting('ai_temperature', '0.4'),
        ];
    }
}

if (!function_exists('aiSettings')) {
    /**
     * Decoded AI config (key decrypted) for THIS request, plus 'source'
     * (own | platform | off) and 'tenant_id'. With no tenant (single-tenant
     * install, CLI) this is exactly the tenant's own config, as before.
     */
    function aiSettings(): array
    {
        $own = aiOwnSettings();
        $tid = function_exists('bmsCurrentTenantId') ? bmsCurrentTenantId() : null;
        $own['source'] = 'own';
        $own['tenant_id'] = $tid;
        if ($tid === null) return $own;

        $ownWorks = $own['enabled'] && $own['model'] !== '' && $own['api_key'] !== '';
        $src = aiResolveSource($tid, $ownWorks);
        if ($src === 'own') return $own;
        if ($src === 'off') {
            return ['enabled' => false, 'provider' => $own['provider'], 'model' => '', 'api_key' => '', 'base_url' => '',
                    'cost_cap' => 0.0, 'temperature' => $own['temperature'], 'source' => 'off', 'tenant_id' => $tid];
        }
        $p = aiPlatformSettings(true);
        return ['enabled' => $p['enabled'], 'provider' => $p['provider'], 'model' => $p['model'], 'api_key' => $p['api_key'],
                'base_url' => $p['base_url'], 'cost_cap' => aiPlatformTenantCap($tid), 'temperature' => $p['temperature'],
                'source' => 'platform', 'tenant_id' => $tid];
    }
}

if (!function_exists('aiTenantAiMode')) {
    /**
     * What the tenant AI Settings page should show (never includes a key):
     * source own|platform|off, the superadmin policy, whether the company may
     * use its own key, and whether the platform key is ready.
     */
    function aiTenantAiMode(): array
    {
        $tid = function_exists('bmsCurrentTenantId') ? bmsCurrentTenantId() : null;
        $s = aiSettings();
        $policy = $tid === null ? 'none' : aiTenantPolicy($tid)['mode'];
        return [
            'tenant_id'      => $tid,
            'source'         => $s['source'],
            'policy'         => $policy,
            'own_allowed'    => $tid === null || in_array($policy, ['auto', 'own'], true),
            'platform_ready' => $s['source'] === 'platform' && $s['enabled'] && $s['model'] !== '' && $s['api_key'] !== '',
        ];
    }
}

if (!function_exists('aiConfigured')) {
    /**
     * True when this company is entitled to the AI module (superadmin) AND the
     * resolved key source is enabled with a model + decryptable key.
     */
    function aiConfigured(): bool
    {
        if (function_exists('tenantFeatureEnabled') && !tenantFeatureEnabled('ai_assistant')) return false;
        $s = aiSettings();
        return $s['enabled'] && $s['model'] !== '' && $s['api_key'] !== '';
    }
}

if (!function_exists('aiMonthSpend')) {
    /** Total est_cost logged in the current calendar month on the tenant's OWN key. */
    function aiMonthSpend(): float
    {
        global $pdo;
        try {
            $q = $pdo->query("SELECT COALESCE(SUM(est_cost),0) FROM ai_usage_log
                               WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
                                 AND (provider IS NULL OR provider <> 'platform')");
            return (float)$q->fetchColumn();
        } catch (Throwable $e) { return 0.0; }
    }
}

if (!function_exists('aiCapInfo')) {
    /**
     * ['cap'=>float,'spent'=>float,'exceeded'=>bool,'source'=>own|platform]. cap 0 = unlimited.
     * On the platform key the figures are the superadmin-set limit and the
     * metered spend from the control DB ('exceeded' also covers the global cap
     * and an unreachable meter, which both block calls).
     */
    function aiCapInfo(): array
    {
        $s = aiSettings();
        if ($s['source'] === 'platform') {
            $p = aiPlatformCapInfo((int)$s['tenant_id']);
            return ['cap' => $p['cap'], 'spent' => $p['spent'], 'exceeded' => $p['blocked'], 'source' => 'platform'];
        }
        $cap = (float)getSetting('ai_monthly_cost_cap', '0');
        $spent = aiMonthSpend();
        return ['cap' => $cap, 'spent' => $spent, 'exceeded' => ($cap > 0 && $spent >= $cap), 'source' => $s['source']];
    }
}

if (!function_exists('aiRateLimited')) {
    /**
     * Simple per-user rate limit: true if the current user has made >= $perMinute
     * AI calls in the last 60 seconds. Protects against runaway loops / abuse.
     */
    function aiRateLimited(int $perMinute = 12): bool
    {
        global $pdo;
        $uid = $_SESSION['user_id'] ?? null;
        if ($uid === null) return false;
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM ai_usage_log WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 60 SECOND)");
            $s->execute([$uid]);
            return (int)$s->fetchColumn() >= $perMinute;
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('aiLogUsage')) {
    function aiLogUsage(string $feature, string $provider, string $model, int $pt, int $ct, float $cost, string $status, ?string $error = null): void
    {
        global $pdo;
        try {
            $uid = $_SESSION['user_id'] ?? null;
            $pdo->prepare("INSERT INTO ai_usage_log (user_id, feature, provider, model, prompt_tokens, completion_tokens, est_cost, status, error)
                           VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$uid, $feature, $provider, $model, $pt, $ct, $cost, $status, $error ? substr($error, 0, 255) : null]);
        } catch (Throwable $e) { /* logging must never break the call */ }
    }
}

if (!function_exists('aiEstimateCost')) {
    /** Very rough USD estimate per 1K tokens (input+output blended). Unknown → 0. */
    function aiEstimateCost(string $model, int $pt, int $ct): float
    {
        $m = strtolower($model);
        // blended $/1K tokens — conservative ballparks; admins watch the real bill at the provider
        $rate = 0.0;
        if (strpos($m, 'gpt-4o-mini') !== false)      $rate = 0.0004;
        elseif (strpos($m, 'gpt-4o') !== false)        $rate = 0.005;
        elseif (strpos($m, 'gpt-4') !== false)         $rate = 0.01;
        elseif (strpos($m, 'haiku') !== false)         $rate = 0.0008;
        elseif (strpos($m, 'sonnet') !== false)        $rate = 0.006;
        elseif (strpos($m, 'opus') !== false)          $rate = 0.02;
        elseif (strpos($m, 'flash') !== false)         $rate = 0.0003;
        elseif (strpos($m, 'gemini') !== false)        $rate = 0.002;
        elseif (strpos($m, 'gpt-3.5') !== false)       $rate = 0.0008;
        return round((($pt + $ct) / 1000.0) * $rate, 6);
    }
}

if (!function_exists('aiComplete')) {
    /**
     * Run a chat completion. $opts: temperature, max_tokens, feature (for logging),
     * json (bool — request a JSON-only response where the provider supports it).
     * Returns ['ok'=>bool,'text'=>string,'usage'=>['prompt'=>int,'completion'=>int],'error'=>?string].
     */
    function aiComplete(array $messages, array $opts = []): array
    {
        $feature = $opts['feature'] ?? 'generate';
        if (function_exists('tenantFeatureEnabled') && !tenantFeatureEnabled('ai_assistant')) {
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => aiT('AI is not available for this company.')];
        }
        $s = aiSettings();

        if (!$s['enabled'] || $s['model'] === '' || $s['api_key'] === '') {
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => 'AI is not configured.'];
        }

        $isPlatform = $s['source'] === 'platform';
        $tid        = $s['tenant_id'];
        $uid        = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $secrets    = [$s['api_key']];
        // Platform calls: the tenant's own usage log never names BJP's provider/model.
        $logProvider = $isPlatform ? 'platform' : $s['provider'];
        $logModel    = $isPlatform ? 'platform' : $s['model'];
        $unavailable = ['ok' => false, 'text' => '', 'usage' => [], 'error' => aiT('AI is temporarily unavailable. Please try again later.')];

        if ($isPlatform) {
            // Metering must work before a platform call — fail closed, never unmetered.
            $pc = aiPlatformCapInfo((int)$tid);
            if (!$pc['available']) {
                aiLogUsage($feature, $logProvider, $logModel, 0, 0, 0, 'blocked', 'platform metering unavailable');
                return $unavailable;
            }
            if ($pc['exceeded'] || $pc['global_exceeded']) {
                $why = $pc['exceeded'] ? 'tenant monthly cap reached' : 'platform monthly cap reached';
                aiLogUsage($feature, $logProvider, $logModel, 0, 0, 0, 'blocked', $why);
                aiPlatformLogUsage($tid, $uid, $feature, $s['provider'], $s['model'], 0, 0, 0, 'blocked', $why);
                return ['ok' => false, 'text' => '', 'usage' => [], 'error' => aiT("This month's AI limit has been reached. Please contact support.")];
            }
            $rl = aiPlatformTenantRateLimited((int)$tid);
            if ($rl === null) return $unavailable;
            if ($rl) {
                return ['ok' => false, 'text' => '', 'usage' => [], 'error' => aiT('The AI is busy right now. Please wait a moment and try again.')];
            }
        } else {
            $cap = aiCapInfo();
            if ($cap['exceeded']) {
                aiLogUsage($feature, $s['provider'], $s['model'], 0, 0, 0, 'blocked', 'monthly cost cap reached');
                return ['ok' => false, 'text' => '', 'usage' => [], 'error' => 'Monthly AI cost cap reached. Ask an admin to raise it in AI Settings.'];
            }
        }

        // SSRF guard on a custom base URL (only the OpenAI-compatible adapter uses it).
        // Enforced for platform calls and any tenant request; single-tenant installs
        // keep their existing behaviour (e.g. a local gateway).
        if ($s['base_url'] !== '' && !in_array($s['provider'], ['anthropic', 'gemini'], true) && ($isPlatform || $tid !== null)) {
            $g = aiSafeBaseUrl($s['base_url']);
            if (!$g['ok']) {
                aiLogUsage($feature, $logProvider, $logModel, 0, 0, 0, 'error', 'base URL refused: ' . $g['error']);
                if ($isPlatform) {
                    aiPlatformLogUsage($tid, $uid, $feature, $s['provider'], $s['model'], 0, 0, 0, 'error', 'base URL refused: ' . $g['error']);
                    return $unavailable;
                }
                return ['ok' => false, 'text' => '', 'usage' => [], 'error' => 'The AI base URL is not allowed: ' . $g['error']];
            }
            $s['_pin'] = $g;
        }

        $temperature = $opts['temperature'] ?? $s['temperature'];
        $maxTokens   = (int)($opts['max_tokens'] ?? 800);
        $wantJson    = !empty($opts['json']);

        try {
            switch ($s['provider']) {
                case 'anthropic':
                    $res = _aiCallAnthropic($s, $messages, $temperature, $maxTokens);
                    break;
                case 'gemini':
                    $res = _aiCallGemini($s, $messages, $temperature, $maxTokens);
                    break;
                case 'openai':
                case 'openrouter':
                default:
                    $res = _aiCallOpenAI($s, $messages, $temperature, $maxTokens, $wantJson);
                    break;
            }
        } catch (Throwable $e) {
            $err = aiSanitizeError($e->getMessage(), $secrets);
            aiLogUsage($feature, $logProvider, $logModel, 0, 0, 0, 'error', $err);
            if ($isPlatform) aiPlatformLogUsage($tid, $uid, $feature, $s['provider'], $s['model'], 0, 0, 0, 'error', $err);
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => 'AI request failed.'];
        }

        if (!$res['ok']) {
            $err = aiSanitizeError($res['error'] ?? 'unknown', $secrets);
            aiLogUsage($feature, $logProvider, $logModel, 0, 0, 0, 'error', $err);
            if ($isPlatform) {
                // Tenants never see BJP's provider errors (quota, billing, key) — only a neutral message.
                aiPlatformLogUsage($tid, $uid, $feature, $s['provider'], $s['model'], 0, 0, 0, 'error', $err);
                if (!empty($res['rate_limited'])) {
                    return ['ok' => false, 'text' => '', 'usage' => [], 'error' => aiT('The AI is busy right now. Please wait a moment and try again.')];
                }
                return $unavailable;
            }
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => $err !== '' ? $err : 'AI request failed.'];
        }

        $pt = (int)($res['usage']['prompt'] ?? 0);
        $ct = (int)($res['usage']['completion'] ?? 0);
        $cost = aiEstimateCost($s['model'], $pt, $ct);
        aiLogUsage($feature, $logProvider, $logModel, $pt, $ct, $cost, 'ok');
        if ($isPlatform) aiPlatformLogUsage($tid, $uid, $feature, $s['provider'], $s['model'], $pt, $ct, $cost, 'ok');

        return ['ok' => true, 'text' => $res['text'], 'usage' => ['prompt' => $pt, 'completion' => $ct, 'cost' => $cost], 'error' => null];
    }
}

// ── HTTP helper ──────────────────────────────────────────────────────────────
if (!function_exists('_aiHttp')) {
    /**
     * $pin (from aiSafeBaseUrl) pins the connection to the validated public IPs
     * (no DNS rebinding), forces https and disables redirects.
     */
    function _aiHttp(string $url, array $headers, array $body, ?array $pin = null): array
    {
        $fake = PHP_SAPI === 'cli' ? (string)getenv('AI_TEST_FAKE_PROVIDER') : '';
        if ($fake !== '') {
            // Test hook (CLI only): never reach a real provider from the test suite.
            $capture = (string)getenv('AI_TEST_FAKE_CAPTURE');
            if ($capture !== '') {
                file_put_contents($capture, json_encode(['url' => $url, 'headers' => $headers, 'body' => $body, 'pin' => $pin]) . "\n", FILE_APPEND | LOCK_EX);
            }
            $f    = json_decode((string)@file_get_contents($fake), true) ?: [];
            $raw  = !empty($f['network_error']) ? false : json_encode($f['body'] ?? []);
            $code = (int)($f['status'] ?? 200);
            $err  = (string)($f['network_error'] ?? '');
        } else {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => json_encode($body),
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
            ];
            // Ensure TLS verification has a CA bundle even when php.ini's curl.cainfo
            // is unset/wrong (common on Windows/WAMP). We ship one in includes/.
            $caBundle = __DIR__ . '/../includes/cacert.pem';
            if (is_file($caBundle)) {
                $opts[CURLOPT_CAINFO] = $caBundle;
            }
            if ($pin && !empty($pin['ips'])) {
                $opts[CURLOPT_FOLLOWLOCATION] = false;
                $opts[CURLOPT_PROTOCOLS]      = CURLPROTO_HTTPS;
                if (!filter_var($pin['host'], FILTER_VALIDATE_IP)) {
                    $opts[CURLOPT_RESOLVE] = [$pin['host'] . ':' . (int)$pin['port'] . ':' . $pin['ips'][0]];
                }
            }
            curl_setopt_array($ch, $opts);          // apply POST/body/headers/cert
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
        }

        if ($raw === false) return ['ok' => false, 'error' => 'Network error: ' . $err];
        $json = json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
            // Provider throttling/overload → a clean, friendly message (not the raw quota dump).
            if (in_array($code, [429, 503], true)) {
                $detail = strtolower(json_encode($json));
                if (strpos($detail, 'perday') !== false || strpos($detail, 'per day') !== false) {
                    // Daily free-tier cap — waiting within the day won't help.
                    $msg = 'Your AI plan\'s DAILY free limit is used up. The free tier allows very few requests per day. '
                         . 'It resets tomorrow — but for real use, enable billing on your AI provider (pay-as-you-go costs only cents) to remove the limit.';
                } elseif ($code === 503) {
                    $msg = 'The AI provider is temporarily overloaded. Please try again in a moment.';
                } else {
                    $msg = 'The AI is busy right now (per-minute rate limit). Please wait a few seconds and try again.';
                }
                return ['ok' => false, 'rate_limited' => true, 'error' => $msg];
            }
            $msg = $json['error']['message'] ?? $json['error'] ?? ('HTTP ' . $code);
            return ['ok' => false, 'error' => is_string($msg) ? $msg : ('HTTP ' . $code)];
        }
        return ['ok' => true, 'json' => $json];
    }
}

// ── Provider adapters ────────────────────────────────────────────────────────
if (!function_exists('_aiCallOpenAI')) {
    function _aiCallOpenAI(array $s, array $messages, float $temp, int $max, bool $json): array
    {
        $base = $s['base_url'] !== '' ? rtrim($s['base_url'], '/') : 'https://api.openai.com/v1';
        $body = ['model' => $s['model'], 'messages' => $messages, 'temperature' => $temp, 'max_tokens' => $max];
        if ($json) $body['response_format'] = ['type' => 'json_object'];
        $r = _aiHttp($base . '/chat/completions',
            ['Content-Type: application/json', 'Authorization: Bearer ' . $s['api_key']], $body, $s['_pin'] ?? null);
        if (!$r['ok']) return $r;
        $j = $r['json'];
        return ['ok' => true,
                'text' => (string)($j['choices'][0]['message']['content'] ?? ''),
                'usage' => ['prompt' => $j['usage']['prompt_tokens'] ?? 0, 'completion' => $j['usage']['completion_tokens'] ?? 0]];
    }
}

if (!function_exists('_aiCallAnthropic')) {
    function _aiCallAnthropic(array $s, array $messages, float $temp, int $max): array
    {
        // Split a leading system message out (Anthropic takes it as a top-level field).
        $system = '';
        $msgs = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') { $system .= ($system ? "\n" : '') . $m['content']; continue; }
            $msgs[] = ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $m['content']];
        }
        $body = ['model' => $s['model'], 'max_tokens' => $max, 'temperature' => $temp, 'messages' => $msgs];
        if ($system !== '') $body['system'] = $system;
        $r = _aiHttp('https://api.anthropic.com/v1/messages',
            ['Content-Type: application/json', 'x-api-key: ' . $s['api_key'], 'anthropic-version: 2023-06-01'], $body);
        if (!$r['ok']) return $r;
        $j = $r['json'];
        $text = '';
        foreach (($j['content'] ?? []) as $blk) { if (($blk['type'] ?? '') === 'text') $text .= $blk['text']; }
        return ['ok' => true, 'text' => $text,
                'usage' => ['prompt' => $j['usage']['input_tokens'] ?? 0, 'completion' => $j['usage']['output_tokens'] ?? 0]];
    }
}

if (!function_exists('_aiCallGemini')) {
    function _aiCallGemini(array $s, array $messages, float $temp, int $max): array
    {
        $contents = [];
        $sys = '';
        foreach ($messages as $m) {
            if ($m['role'] === 'system') { $sys .= ($sys ? "\n" : '') . $m['content']; continue; }
            $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]];
        }
        $body = ['contents' => $contents, 'generationConfig' => [
            'temperature' => $temp,
            'maxOutputTokens' => $max,
            // Gemini 2.5 models "think" by default and can consume the whole token
            // budget on reasoning, returning empty text. We want direct business
            // answers, so disable the thinking budget. Ignored by non-2.5 models.
            'thinkingConfig' => ['thinkingBudget' => 0],
        ]];
        if ($sys !== '') $body['systemInstruction'] = ['parts' => [['text' => $sys]]];
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($s['model']) . ':generateContent?key=' . rawurlencode($s['api_key']);
        $r = _aiHttp($url, ['Content-Type: application/json'], $body);
        if (!$r['ok']) return $r;
        $j = $r['json'];
        $text = '';
        foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) { $text .= $p['text'] ?? ''; }
        $um = $j['usageMetadata'] ?? [];
        return ['ok' => true, 'text' => $text,
                'usage' => ['prompt' => $um['promptTokenCount'] ?? 0, 'completion' => $um['candidatesTokenCount'] ?? 0]];
    }
}

if (!function_exists('aiPlatformTest')) {
    /**
     * Superadmin "Test connection" for the platform key. Runs outside any tenant,
     * meters the call as feature 'test' (tenant NULL) and returns a sanitised
     * result — the key never leaves this function.
     * @return array{ok:bool,message:string}
     */
    function aiPlatformTest(?int $superadminId = null): array
    {
        $s = aiPlatformSettings(true);
        if ($s['model'] === '' || $s['api_key'] === '') {
            return ['ok' => false, 'message' => 'Save a model and an API key first.'];
        }
        try {
            $st = getControlPdo()->query("SELECT COUNT(*) FROM platform_ai_usage WHERE tenant_id IS NULL AND feature = 'test' AND created_at >= (NOW() - INTERVAL 60 SECOND)");
            if ((int)$st->fetchColumn() >= 10) return ['ok' => false, 'message' => 'Too many tests in the last minute. Please wait.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'AI usage metering is not available (control database).'];
        }
        if ($s['base_url'] !== '' && !in_array($s['provider'], ['anthropic', 'gemini'], true)) {
            $g = aiSafeBaseUrl($s['base_url']);
            if (!$g['ok']) return ['ok' => false, 'message' => 'Base URL refused: ' . $g['error']];
            $s['_pin'] = $g;
        }
        $messages = [['role' => 'system', 'content' => 'You are a connection test. Reply with the single word OK.'],
                     ['role' => 'user', 'content' => 'Test']];
        try {
            switch ($s['provider']) {
                case 'anthropic': $res = _aiCallAnthropic($s, $messages, 0.0, 10); break;
                case 'gemini':    $res = _aiCallGemini($s, $messages, 0.0, 10); break;
                default:          $res = _aiCallOpenAI($s, $messages, 0.0, 10, false); break;
            }
        } catch (Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!$res['ok']) {
            $err = aiSanitizeError($res['error'] ?? 'unknown', [$s['api_key']]);
            aiPlatformLogUsage(null, $superadminId, 'test', $s['provider'], $s['model'], 0, 0, 0, 'error', $err);
            return ['ok' => false, 'message' => 'The provider said: ' . ($err !== '' ? $err : 'no answer')];
        }
        $pt = (int)($res['usage']['prompt'] ?? 0);
        $ct = (int)($res['usage']['completion'] ?? 0);
        aiPlatformLogUsage(null, $superadminId, 'test', $s['provider'], $s['model'], $pt, $ct, aiEstimateCost($s['model'], $pt, $ct), 'ok');
        return ['ok' => true, 'message' => 'Connected. ' . $s['provider'] . ' / ' . $s['model'] . ' answered.'];
    }
}
