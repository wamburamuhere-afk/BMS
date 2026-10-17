<?php
/**
 * core/platform_link.php — a company's own system talking to BJP's install
 * (the platform root) about its subscription. customer_subscription_plan.md Phase 5.
 *
 * A tenant never opens BJP's database. Its server sends a signed request to
 * api/platform/tenant_gateway.php on the platform root; the root checks the
 * signature, looks the tenant up in the control DB and answers ONLY about the
 * customer linked to that tenant. The tenant id comes from the tenant's own
 * server (bmsCurrentTenantId()), never from a browser.
 *
 * Signing key: derived from the platform's TENANT_CRED_KEY (server-only, the same
 * on the root and every tenant of one install), so no extra secret is needed and
 * the raw key is never used directly.
 */
require_once __DIR__ . '/tenant_crypto.php';
require_once __DIR__ . '/tenant_resolver.php';

if (!function_exists('plKey')) {
    function plKey(): ?string
    {
        try {
            if (function_exists('tenantCredKeyAvailable') && !tenantCredKeyAvailable()) return null;
            return hash_hmac('sha256', 'bms-platform-link-v1', tenantCredKeyRaw(), true);
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('plSignature')) {
    function plSignature(int $tenantId, int $ts, string $body): ?string
    {
        $k = plKey();
        return $k === null ? null : hash_hmac('sha256', $tenantId . '|' . $ts . '|' . hash('sha256', $body), $k);
    }
}

if (!function_exists('plVerify')) {
    /** A request is genuine and fresh (±120 s). */
    function plVerify(int $tenantId, int $ts, string $body, string $sig): bool
    {
        if ($tenantId <= 0 || abs(time() - $ts) > 120 || $sig === '') return false;
        $expect = plSignature($tenantId, $ts, $body);
        return $expect !== null && hash_equals($expect, $sig);
    }
}

if (!function_exists('plRootUrl')) {
    /** The platform root, e.g. https://bms.bjptechnologies.co.tz (PLATFORM_ROOT_URL overrides, for odd setups). */
    function plRootUrl(): ?string
    {
        $o = trim((string)getenv('PLATFORM_ROOT_URL'));
        if ($o !== '') return rtrim($o, '/');
        $base = tenantBaseDomain();
        return $base ? 'https://' . $base : null;
    }
}

if (!function_exists('plCall')) {
    /**
     * Ask the platform root. Returns the decoded JSON, or ['success'=>false,'message'=>…].
     * $transport lets tests hand the request to the gateway in-process.
     */
    function plCall(int $tenantId, string $action, array $params = [], ?callable $transport = null): array
    {
        $fail = fn(string $m) => ['success' => false, 'message' => $m];
        $body = json_encode(['action' => $action] + $params);
        $ts = time();
        $sig = plSignature($tenantId, $ts, $body);
        if ($sig === null) return $fail('Subscription services are not available on this server yet.');
        $headers = ['X-Platform-Tenant' => (string)$tenantId, 'X-Platform-Ts' => (string)$ts, 'X-Platform-Sig' => $sig];

        if ($transport !== null) {
            $j = $transport($body, $headers);
            return is_array($j) ? $j : $fail('The subscription service did not answer properly.');
        }

        $root = plRootUrl();
        if ($root === null || !function_exists('curl_init')) return $fail('Subscription services are not available on this server yet.');
        $ch = curl_init($root . '/api/platform/tenant_gateway.php');
        $h = ['Content-Type: application/json'];
        foreach ($headers as $k => $v) $h[] = "$k: $v";
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            error_log("plCall($action): $err");
            return $fail('Could not reach BJP right now. Please try again in a moment.');
        }
        $j = json_decode((string)$raw, true);
        return is_array($j) ? $j : $fail('The subscription service did not answer properly.');
    }
}

/* ── Short-lived links to a customer copy of a document ─────────────────────── */

if (!function_exists('plDocToken')) {
    function plDocToken(string $type, int $id, int $customerId, int $ttl = 1800): ?string
    {
        $k = plKey();
        if ($k === null) return null;
        $payload = rtrim(strtr(base64_encode($type . '|' . $id . '|' . $customerId . '|' . (time() + $ttl)), '+/', '-_'), '=');
        return $payload . '.' . hash_hmac('sha256', 'doc|' . $payload, $k);
    }
}

if (!function_exists('plDocTokenRead')) {
    /** @return array{type:string, id:int, customer_id:int}|null */
    function plDocTokenRead(string $token): ?array
    {
        $k = plKey();
        if ($k === null || substr_count($token, '.') !== 1) return null;
        [$payload, $sig] = explode('.', $token);
        if (!hash_equals(hash_hmac('sha256', 'doc|' . $payload, $k), $sig)) return null;
        $parts = explode('|', (string)base64_decode(strtr($payload, '-_', '+/')));
        if (count($parts) !== 4 || (int)$parts[3] < time() || !in_array($parts[0], ['quotation', 'invoice', 'contract'], true)) return null;
        return ['type' => $parts[0], 'id' => (int)$parts[1], 'customer_id' => (int)$parts[2]];
    }
}
