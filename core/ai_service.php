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

if (!function_exists('aiSettings')) {
    /** Decoded AI config (key decrypted). */
    function aiSettings(): array
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

if (!function_exists('aiConfigured')) {
    /** True when the assistant is enabled AND has a model + decryptable key. */
    function aiConfigured(): bool
    {
        $s = aiSettings();
        return $s['enabled'] && $s['model'] !== '' && $s['api_key'] !== '';
    }
}

if (!function_exists('aiMonthSpend')) {
    /** Total est_cost logged in the current calendar month. */
    function aiMonthSpend(): float
    {
        global $pdo;
        try {
            $q = $pdo->query("SELECT COALESCE(SUM(est_cost),0) FROM ai_usage_log
                               WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
            return (float)$q->fetchColumn();
        } catch (Throwable $e) { return 0.0; }
    }
}

if (!function_exists('aiCapInfo')) {
    /** ['cap'=>float,'spent'=>float,'exceeded'=>bool]. cap 0 = unlimited. */
    function aiCapInfo(): array
    {
        $cap = (float)getSetting('ai_monthly_cost_cap', '0');
        $spent = aiMonthSpend();
        return ['cap' => $cap, 'spent' => $spent, 'exceeded' => ($cap > 0 && $spent >= $cap)];
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
        $s = aiSettings();

        if (!$s['enabled'] || $s['model'] === '' || $s['api_key'] === '') {
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => 'AI is not configured.'];
        }

        $cap = aiCapInfo();
        if ($cap['exceeded']) {
            aiLogUsage($feature, $s['provider'], $s['model'], 0, 0, 0, 'blocked', 'monthly cost cap reached');
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => 'Monthly AI cost cap reached. Ask an admin to raise it in AI Settings.'];
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
            aiLogUsage($feature, $s['provider'], $s['model'], 0, 0, 0, 'error', $e->getMessage());
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => 'AI request failed.'];
        }

        if (!$res['ok']) {
            aiLogUsage($feature, $s['provider'], $s['model'], 0, 0, 0, 'error', $res['error'] ?? 'unknown');
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => $res['error'] ?? 'AI request failed.'];
        }

        $pt = (int)($res['usage']['prompt'] ?? 0);
        $ct = (int)($res['usage']['completion'] ?? 0);
        $cost = aiEstimateCost($s['model'], $pt, $ct);
        aiLogUsage($feature, $s['provider'], $s['model'], $pt, $ct, $cost, 'ok');

        return ['ok' => true, 'text' => $res['text'], 'usage' => ['prompt' => $pt, 'completion' => $ct, 'cost' => $cost], 'error' => null];
    }
}

// ── HTTP helper ──────────────────────────────────────────────────────────────
if (!function_exists('_aiHttp')) {
    function _aiHttp(string $url, array $headers, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'error' => 'Network error: ' . $err];
        $json = json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
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
            ['Content-Type: application/json', 'Authorization: Bearer ' . $s['api_key']], $body);
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
        $body = ['contents' => $contents, 'generationConfig' => ['temperature' => $temp, 'maxOutputTokens' => $max]];
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
