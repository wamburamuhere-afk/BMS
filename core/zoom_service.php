<?php
/**
 * core/zoom_service.php
 * ----------------------
 * Zoom Server-to-Server OAuth service layer (plan: zoom.md, Phase 2).
 *
 * One company, one Zoom account — Server-to-Server OAuth is Zoom's own
 * recommended mechanism for this case (no per-user Zoom login, no marketplace
 * Authorization Code flow). Config lives in system_settings (Phase 1); the
 * Client Secret is decrypted only here, never returned to the client.
 *
 * Design rules (mirrors core/ai_service.php):
 *   - NEVER throws to the caller. Every public function returns
 *     ['success'=>bool,'message'=>string,'data'=>mixed|null].
 *   - The access token is cached in system_settings (survives across
 *     requests) and auto-refreshes ~60s before expiry.
 *   - A Zoom API failure is data, not an uncaught exception — callers
 *     (api/manage_meeting.php) decide how to degrade.
 *
 * Public API:
 *   zoomConfigured(): bool
 *   zoomGetAccessToken(): array               ['success','message','data'=>['access_token'=>string]]
 *   zoomCreateMeeting(array $data): array      ['success','message','data'=>['zoom_meeting_id','join_url','start_url','password']]
 *   zoomUpdateMeeting(string $zoomId, array $data): array
 *   zoomDeleteMeeting(string $zoomId): array
 *
 * Test seam: if $GLOBALS['ZOOM_HTTP_MOCK'] is set to a callable, _zoomHttp()
 * calls it instead of curl. Used only by tests/test_zoom_service_cli.php so
 * Phase 2 can be verified without a live Zoom account.
 */

require_once __DIR__ . '/crypto.php';

if (!function_exists('zoomSettings')) {
    /** Decoded Zoom config (secret decrypted). */
    function zoomSettings(): array
    {
        $enc = getSetting('zoom_client_secret_enc', '');
        return [
            'enabled'       => getSetting('zoom_enabled', '0') === '1',
            'account_id'    => trim(getSetting('zoom_account_id', '')),
            'client_id'     => trim(getSetting('zoom_client_id', '')),
            'client_secret' => $enc !== '' ? (decryptSecret($enc) ?? '') : '',
        ];
    }
}

if (!function_exists('zoomConfigured')) {
    /** True when Zoom is enabled AND has all three S2S OAuth credentials. */
    function zoomConfigured(): bool
    {
        $s = zoomSettings();
        return $s['enabled'] && $s['account_id'] !== '' && $s['client_id'] !== '' && $s['client_secret'] !== '';
    }
}

if (!function_exists('zoomGetAccessToken')) {
    /**
     * Return a valid Bearer token, fetching + caching a new one if the cached
     * one is missing/expiring within 60s. Cache lives in system_settings so it
     * survives across requests (tokens last ~1hr).
     */
    function zoomGetAccessToken(): array
    {
        if (!zoomConfigured()) {
            return ['success' => false, 'message' => 'Zoom is not configured. Set it up in Zoom Integration settings first.', 'data' => null];
        }

        $expiresAt = (int)getSetting('zoom_token_expires_at', '0');
        $cachedEnc = getSetting('zoom_access_token_enc', '');
        if ($expiresAt > time() + 60 && $cachedEnc !== '') {
            $cached = decryptSecret($cachedEnc);
            if ($cached !== null && $cached !== '') {
                return ['success' => true, 'message' => 'OK (cached)', 'data' => ['access_token' => $cached]];
            }
        }

        $s = zoomSettings();
        $auth = base64_encode($s['client_id'] . ':' . $s['client_secret']);
        $url  = 'https://zoom.us/oauth/token?grant_type=account_credentials&account_id=' . rawurlencode($s['account_id']);
        $r = _zoomHttp('POST', $url, ['Authorization: Basic ' . $auth]);

        if (!$r['ok']) {
            return ['success' => false, 'message' => $r['error'] ?: 'Could not obtain a Zoom access token.', 'data' => null];
        }

        $token = (string)($r['json']['access_token'] ?? '');
        $ttl   = (int)($r['json']['expires_in'] ?? 3600);
        if ($token === '') {
            return ['success' => false, 'message' => 'Zoom did not return an access token.', 'data' => null];
        }

        _zoomCacheToken($token, time() + $ttl);
        return ['success' => true, 'message' => 'OK', 'data' => ['access_token' => $token]];
    }
}

if (!function_exists('_zoomCacheToken')) {
    function _zoomCacheToken(string $token, int $expiresAt): void
    {
        global $pdo;
        try {
            $up = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, updated_at)
                VALUES (:k, :v, 'zoom', '0', NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");
            $up->execute([':k' => 'zoom_access_token_enc', ':v' => encryptSecret($token)]);
            $up->execute([':k' => 'zoom_token_expires_at', ':v' => (string)$expiresAt]);
        } catch (Throwable $e) { /* cache is best-effort; a fetch failure just means we re-auth next call */ }
    }
}

if (!function_exists('_zoomBuildMeetingBody')) {
    /** Map our meeting fields to Zoom's Create/Update Meeting request body. */
    function _zoomBuildMeetingBody(array $data): array
    {
        $body = [
            'topic'      => (string)($data['topic'] ?? 'Meeting'),
            'type'       => 2, // scheduled meeting
            'timezone'   => (string)($data['timezone'] ?? 'Africa/Dar_es_Salaam'),
            'settings'   => [
                'host_video'        => !empty($data['host_video']),
                'participant_video' => !empty($data['participant_video']),
                'waiting_room'      => !empty($data['waiting_room']),
                'auto_recording'    => !empty($data['auto_recording']) ? 'cloud' : 'none',
            ],
        ];
        if (!empty($data['start_time'])) $body['start_time'] = (string)$data['start_time']; // ISO 8601 UTC
        if (!empty($data['duration']))   $body['duration']   = (int)$data['duration'];
        if (!empty($data['password']))   $body['password']   = (string)$data['password'];
        if (!empty($data['agenda']))     $body['agenda']     = substr((string)$data['agenda'], 0, 2000);
        return $body;
    }
}

if (!function_exists('zoomCreateMeeting')) {
    /**
     * $data: topic, agenda, start_time (ISO8601 UTC), duration (minutes),
     * timezone, password, host_video, participant_video, waiting_room,
     * auto_recording, host_email (the Zoom user who owns the meeting).
     */
    function zoomCreateMeeting(array $data): array
    {
        $hostEmail = trim((string)($data['host_email'] ?? ''));
        if ($hostEmail === '') return ['success' => false, 'message' => 'A Zoom host email is required.', 'data' => null];

        $tok = zoomGetAccessToken();
        if (!$tok['success']) return ['success' => false, 'message' => $tok['message'], 'data' => null];

        $body = _zoomBuildMeetingBody($data);
        $r = _zoomHttp('POST', 'https://api.zoom.us/v2/users/' . rawurlencode($hostEmail) . '/meetings',
            ['Authorization: Bearer ' . $tok['data']['access_token'], 'Content-Type: application/json'], $body);

        if (!$r['ok']) return ['success' => false, 'message' => $r['error'] ?: 'Could not create the Zoom meeting.', 'data' => null];

        $j = $r['json'];
        return ['success' => true, 'message' => 'Zoom meeting created.', 'data' => [
            'zoom_meeting_id' => (string)($j['id'] ?? ''),
            'join_url'        => (string)($j['join_url'] ?? ''),
            'start_url'       => (string)($j['start_url'] ?? ''),
            'password'        => (string)($j['password'] ?? ''),
        ]];
    }
}

if (!function_exists('zoomUpdateMeeting')) {
    function zoomUpdateMeeting(string $zoomId, array $data): array
    {
        if ($zoomId === '') return ['success' => false, 'message' => 'Missing Zoom meeting id.', 'data' => null];
        $tok = zoomGetAccessToken();
        if (!$tok['success']) return ['success' => false, 'message' => $tok['message'], 'data' => null];

        $body = _zoomBuildMeetingBody($data);
        $r = _zoomHttp('PATCH', 'https://api.zoom.us/v2/meetings/' . rawurlencode($zoomId),
            ['Authorization: Bearer ' . $tok['data']['access_token'], 'Content-Type: application/json'], $body);

        if (!$r['ok']) return ['success' => false, 'message' => $r['error'] ?: 'Could not update the Zoom meeting.', 'data' => null];
        return ['success' => true, 'message' => 'Zoom meeting updated.', 'data' => null];
    }
}

if (!function_exists('zoomDeleteMeeting')) {
    function zoomDeleteMeeting(string $zoomId): array
    {
        if ($zoomId === '') return ['success' => false, 'message' => 'Missing Zoom meeting id.', 'data' => null];
        $tok = zoomGetAccessToken();
        if (!$tok['success']) return ['success' => false, 'message' => $tok['message'], 'data' => null];

        $r = _zoomHttp('DELETE', 'https://api.zoom.us/v2/meetings/' . rawurlencode($zoomId),
            ['Authorization: Bearer ' . $tok['data']['access_token']]);

        // Zoom returns 404 if the meeting was already deleted/ended on their side —
        // treat that as success so a local cancel never gets stuck retrying forever.
        if (!$r['ok'] && ($r['http_code'] ?? 0) !== 404) {
            return ['success' => false, 'message' => $r['error'] ?: 'Could not delete the Zoom meeting.', 'data' => null];
        }
        return ['success' => true, 'message' => 'Zoom meeting deleted.', 'data' => null];
    }
}

// ── HTTP helper ──────────────────────────────────────────────────────────────
if (!function_exists('_zoomHttp')) {
    /**
     * $method: GET|POST|PATCH|DELETE. $body: array (JSON-encoded) or null.
     * Returns ['ok'=>bool,'json'=>array|null,'http_code'=>int,'error'=>?string].
     */
    function _zoomHttp(string $method, string $url, array $headers, ?array $body = null): array
    {
        if (isset($GLOBALS['ZOOM_HTTP_MOCK']) && is_callable($GLOBALS['ZOOM_HTTP_MOCK'])) {
            return ($GLOBALS['ZOOM_HTTP_MOCK'])($method, $url, $headers, $body);
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        // Ensure TLS verification has a CA bundle even when php.ini's curl.cainfo
        // is unset/wrong (common on Windows/WAMP). We ship one in includes/.
        $caBundle = __DIR__ . '/../includes/cacert.pem';
        if (is_file($caBundle)) {
            $opts[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) return ['ok' => false, 'json' => null, 'http_code' => 0, 'error' => 'Network error: ' . $err];
        $json = $raw !== '' ? json_decode($raw, true) : [];
        if ($code < 200 || $code >= 300) {
            $msg = is_array($json) ? ($json['message'] ?? ('HTTP ' . $code)) : ('HTTP ' . $code);
            return ['ok' => false, 'json' => $json, 'http_code' => $code, 'error' => is_string($msg) ? $msg : ('HTTP ' . $code)];
        }
        return ['ok' => true, 'json' => $json, 'http_code' => $code, 'error' => null];
    }
}
