<?php
/**
 * tests/test_mobile_api_http_cli.php
 * Real HTTP smoke test for the Flutter mobile API (api/mobile/** + api/pos/**).
 * Calls each endpoint over HTTP with a Bearer token and asserts status code,
 * response shape, and stored values. Test records are named "ZZ API TEST ..."
 * and removed through the API's own delete endpoints.
 *
 * Env:
 *   BMS_API_BASE   default http://localhost/bms
 *   BMS_API_TOKEN  a mobile token, OR
 *   BMS_API_USER + BMS_API_PASS  to log in via api/mobile/login.php
 *   BMS_API_ONLY   optional comma list of sections to run (e.g. "warehouses")
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$BASE  = rtrim(getenv('BMS_API_BASE') ?: 'http://localhost/bms', '/');
$TOKEN = getenv('BMS_API_TOKEN') ?: '';
$ONLY  = array_filter(array_map('trim', explode(',', getenv('BMS_API_ONLY') ?: '')));

$pass = 0; $fail = 0; $failures = [];
function ok(bool $cond, string $label, $detail = null): void {
    global $pass, $fail, $failures;
    if ($cond) { echo "  PASS  $label\n"; $pass++; return; }
    $msg = $label . ($detail !== null ? '  →  ' . (is_string($detail) ? $detail : json_encode($detail)) : '');
    echo "  FAIL  $msg\n"; $fail++; $failures[] = $msg;
}
function section(string $name): bool {
    global $ONLY;
    if ($ONLY && !in_array($name, $ONLY, true)) return false;
    echo "\n=== $name ===\n";
    return true;
}

/** @return array{0:int,1:?array,2:string} [http_code, decoded_json, raw_body] */
function api(string $method, string $path, array $data = [], bool $json = true, bool $auth = true): array {
    global $BASE, $TOKEN;
    $url = $BASE . '/' . ltrim($path, '/');
    $ch  = curl_init();
    $headers = ['Accept: application/json'];
    if ($auth && $TOKEN !== '') $headers[] = 'Authorization: Bearer ' . $TOKEN;
    if ($method === 'GET') {
        if ($data) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($data);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($json) { $headers[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); }
        else       { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true), $raw];
}
function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

echo "Base: $BASE\n";
if ($TOKEN === '') {
    [$c, $j] = api('POST', 'api/mobile/login.php', [
        'username' => getenv('BMS_API_USER') ?: '', 'password' => getenv('BMS_API_PASS') ?: '',
        'device_name' => 'api-http-test',
    ], false, false);
    $TOKEN = (string)($j['token'] ?? '');
    if ($TOKEN === '') { echo "Login failed (HTTP $c). Set BMS_API_TOKEN or BMS_API_USER/BMS_API_PASS.\n"; exit(1); }
}
$RUN = substr(bin2hex(random_bytes(3)), 0, 6);

// =========================================================================
if (section('warehouses')) {
    $created = [];

    // Name only — the Flutter app's minimal payload (was HTTP 500: NULL pos_mode/status).
    [$c, $j] = api('POST', 'api/mobile/warehouses/create.php', ['warehouse_name' => "ZZ API TEST shop $RUN"]);
    ok($c === 200 && !empty($j['success']), 'create: name-only payload → 200', [$c, $j]);
    $idA = (int)($j['warehouse_id'] ?? 0);
    if ($idA) $created[] = $idA;
    ok(($j['warehouse_code'] ?? '') !== '', 'create: auto-generated warehouse_code returned', $j['warehouse_code'] ?? null);
    $codeA = (string)($j['warehouse_code'] ?? '');

    if ($idA) {
        [$c, $g] = api('GET', 'api/mobile/warehouses/get.php', ['id' => $idA]);
        ok($c === 200 && ($g['data']['pos_mode'] ?? null) === 'retail', 'get: default pos_mode = retail', $g['data']['pos_mode'] ?? $g);
        ok(($g['data']['status'] ?? null) === 'active', 'get: default status = active', $g['data']['status'] ?? null);
    }

    // Second auto-code must differ (sequential, no rand() collisions).
    [$c, $j] = api('POST', 'api/mobile/warehouses/create.php', ['warehouse_name' => "ZZ API TEST shop2 $RUN"], false);
    ok($c === 200 && !empty($j['success']), 'create: form-encoded name-only → 200', [$c, $j]);
    if (!empty($j['warehouse_id'])) $created[] = (int)$j['warehouse_id'];
    ok(($j['warehouse_code'] ?? '') !== '' && ($j['warehouse_code'] ?? '') !== $codeA, 'create: second auto code is distinct', [$codeA, $j['warehouse_code'] ?? null]);

    // Full payload.
    $explicit = "ZZT$RUN";
    [$c, $j] = api('POST', 'api/mobile/warehouses/create.php', [
        'warehouse_name' => "ZZ API TEST shop3 $RUN", 'warehouse_code' => $explicit, 'address' => 'Plot 1',
        'city' => 'Dodoma', 'phone' => '0700000000', 'email' => 'zz@example.com', 'contact_person' => 'ZZ',
        'notes' => 'test', 'pos_mode' => 'restaurant', 'status' => 'inactive',
    ]);
    ok($c === 200 && ($j['warehouse_code'] ?? '') === strtoupper($explicit), 'create: full payload keeps explicit code', [$c, $j]);
    $idC = (int)($j['warehouse_id'] ?? 0);
    if ($idC) {
        $created[] = $idC;
        [$c, $g] = api('GET', 'api/mobile/warehouses/get.php', ['id' => $idC]);
        ok(($g['data']['pos_mode'] ?? null) === 'restaurant' && ($g['data']['status'] ?? null) === 'inactive',
           'get: explicit pos_mode/status stored', [$g['data']['pos_mode'] ?? null, $g['data']['status'] ?? null]);
    }

    // Duplicate explicit code → 409.
    [$c, $j] = api('POST', 'api/mobile/warehouses/create.php', ['warehouse_name' => "ZZ API TEST dup $RUN", 'warehouse_code' => $explicit]);
    ok($c === 409, 'create: duplicate code → 409', [$c, $j]);

    // Invalid enum values fall back to defaults instead of erroring.
    [$c, $j] = api('POST', 'api/mobile/warehouses/create.php', ['warehouse_name' => "ZZ API TEST enum $RUN", 'pos_mode' => 'bogus', 'status' => 'bogus']);
    ok($c === 200, 'create: invalid pos_mode/status fall back to defaults', [$c, $j]);
    if (!empty($j['warehouse_id'])) $created[] = (int)$j['warehouse_id'];

    // Missing name → 422.
    [$c, $j] = api('POST', 'api/mobile/warehouses/create.php', []);
    ok($c === 422, 'create: missing name → 422', [$c, $j]);

    // Idempotent replay with client_uuid.
    $u = uuid4();
    [$c1, $j1] = api('POST', 'api/mobile/warehouses/create.php', ['warehouse_name' => "ZZ API TEST idem $RUN", 'client_uuid' => $u]);
    [$c2, $j2] = api('POST', 'api/mobile/warehouses/create.php', ['warehouse_name' => "ZZ API TEST idem $RUN", 'client_uuid' => $u]);
    ok($c1 === 200 && $c2 === 200 && ($j1['warehouse_id'] ?? 0) === ($j2['warehouse_id'] ?? -1) && !empty($j2['idempotent']),
       'create: client_uuid replay returns same shop (idempotent)', [$j1, $j2]);
    if (!empty($j1['warehouse_id'])) $created[] = (int)$j1['warehouse_id'];

    // Update + list.
    if ($idA) {
        [$c, $j] = api('POST', 'api/mobile/warehouses/update.php', ['warehouse_id' => $idA, 'warehouse_name' => "ZZ API TEST shop $RUN upd", 'city' => 'Arusha']);
        ok($c === 200 && !empty($j['success']), 'update: 200', [$c, $j]);
        [$c, $l] = api('GET', 'api/mobile/warehouses/list.php', ['search' => "ZZ API TEST shop $RUN upd"]);
        ok($c === 200 && count($l['data'] ?? []) >= 1, 'list: search finds updated shop', [$c, $l['total'] ?? null]);
    }

    // Cleanup.
    foreach (array_unique($created) as $id) {
        [$c, $j] = api('POST', 'api/mobile/warehouses/delete.php', ['warehouse_id' => $id]);
        ok($c === 200, "delete: shop #$id → 200", [$c, $j]);
    }
}

// =========================================================================
echo "\n" . str_repeat('-', 60) . "\n";
echo "Result: $pass/" . ($pass + $fail) . " passed" . ($fail ? "  *** $fail FAILED ***" : '') . "\n";
if ($failures) { echo "\nFailures:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
