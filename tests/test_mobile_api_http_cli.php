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
// Generic master-data CRUD: create (minimal + full + idempotent) → get → list → update → delete.
function crudSection(string $ent, string $idKey, string $nameKey, array $full, array $upd): void {
    global $RUN;
    $base = "api/mobile/$ent";
    $made = [];

    [$c, $j] = api('POST', "$base/create.php", [$nameKey => "ZZ API TEST $ent min $RUN"]);
    ok($c === 200 && !empty($j[$idKey]), "$ent/create: minimal payload → 200", [$c, $j]);
    $id = (int)($j[$idKey] ?? 0); if ($id) $made[] = $id;

    [$c, $j] = api('POST', "$base/create.php", [$nameKey => "ZZ API TEST $ent form $RUN"], false);
    ok($c === 200 && !empty($j[$idKey]), "$ent/create: form-encoded → 200", [$c, $j]);
    if (!empty($j[$idKey])) $made[] = (int)$j[$idKey];

    [$c, $j] = api('POST', "$base/create.php", [$nameKey => "ZZ API TEST $ent full $RUN"] + $full);
    ok($c === 200 && !empty($j[$idKey]), "$ent/create: full payload → 200", [$c, $j]);
    $idFull = (int)($j[$idKey] ?? 0); if ($idFull) $made[] = $idFull;

    [$c, $j] = api('POST', "$base/create.php", []);
    ok($c === 422, "$ent/create: missing name → 422", [$c, $j]);

    $u = uuid4();
    [, $j1] = api('POST', "$base/create.php", [$nameKey => "ZZ API TEST $ent idem $RUN", 'client_uuid' => $u]);
    [, $j2] = api('POST', "$base/create.php", [$nameKey => "ZZ API TEST $ent idem $RUN", 'client_uuid' => $u]);
    ok(!empty($j1[$idKey]) && ($j1[$idKey] ?? 0) === ($j2[$idKey] ?? -1), "$ent/create: client_uuid replay is idempotent", [$j1, $j2]);
    if (!empty($j1[$idKey])) $made[] = (int)$j1[$idKey];

    if ($idFull) {
        [$c, $g] = api('GET', "$base/get.php", ['id' => $idFull]);
        ok($c === 200 && !empty($g['data']), "$ent/get: 200 with data", [$c, $g]);
        foreach ($full as $k => $v) {
            if (!array_key_exists($k, $g['data'] ?? [])) { ok(false, "$ent/get: field '$k' returned", 'missing'); continue; }
            ok((string)$g['data'][$k] == (string)$v || (is_numeric($v) && (float)$g['data'][$k] == (float)$v), "$ent/get: '$k' stored", [$v, $g['data'][$k]]);
        }
        $active = ($g['data']['status'] ?? null) === 'active';
        ok($active, "$ent/get: status = active", $g['data']['status'] ?? null);
    }
    if ($id) {
        [$c, $g] = api('GET', "$base/get.php", ['id' => $id]);
        ok($c === 200 && ($g['data']['status'] ?? null) === 'active', "$ent/get: minimal record status = active (not NULL ghost)", [$c, $g['data']['status'] ?? $g]);
    }

    [$c, $l] = api('GET', "$base/list.php", ['search' => "ZZ API TEST $ent", 'limit' => 50]);
    ok($c === 200 && count($l['data'] ?? []) >= count(array_unique($made)), "$ent/list: search finds all created", [$c, $l['total'] ?? $l]);
    [$c, $l] = api('GET', "$base/list.php", ['limit' => 1, 'offset' => 1]);
    ok($c === 200 && isset($l['total']), "$ent/list: limit/offset → 200", [$c]);

    if ($idFull) {
        [$c, $j] = api('POST', "$base/update.php", [$idKey => $idFull, $nameKey => "ZZ API TEST $ent full $RUN upd"] + $upd);
        ok($c === 200 && !empty($j['success']), "$ent/update: 200", [$c, $j]);
        [, $g] = api('GET', "$base/get.php", ['id' => $idFull]);
        foreach ($upd as $k => $v) ok((string)($g['data'][$k] ?? '') == (string)$v || (is_numeric($v) && (float)($g['data'][$k] ?? -1) == (float)$v), "$ent/update: '$k' persisted", [$v, $g['data'][$k] ?? null]);
        [$c, $j] = api('POST', "$base/update.php", [$idKey => 999999999, $nameKey => 'x']);
        ok($c === 404, "$ent/update: unknown id → 404", [$c, $j]);
    }

    foreach (array_unique($made) as $mid) {
        [$c, $j] = api('POST', "$base/delete.php", [$idKey => $mid]);
        ok($c === 200 && !empty($j['success']), "$ent/delete: #$mid → 200", [$c, $j]);
        [$c] = api('GET', "$base/get.php", ['id' => $mid]);
        ok($c === 404, "$ent/get after delete: #$mid → 404", $c);
    }
}

if (section('customers')) {
    crudSection('customers', 'customer_id', 'customer_name',
        ['phone' => '0711000001', 'email' => 'zzc@example.com', 'address' => 'Plot 9', 'city' => 'Mwanza', 'customer_type' => 'business', 'credit_limit' => 50000, 'notes' => 'zz'],
        ['phone' => '0711000002', 'city' => 'Tanga', 'credit_limit' => 75000]);
}
if (section('suppliers')) {
    crudSection('suppliers', 'supplier_id', 'supplier_name',
        ['phone' => '0722000001', 'email' => 'zzs@example.com', 'address' => 'Plot 7', 'contact_person' => 'ZZ Person'],
        ['phone' => '0722000002', 'contact_person' => 'ZZ Person 2']);
}
if (section('products')) {
    crudSection('products', 'product_id', 'product_name',
        ['selling_price' => 1500, 'cost_price' => 1000, 'reorder_level' => 3, 'barcode' => "ZZ$RUN", 'unit' => 'pcs'],
        ['selling_price' => 1800, 'reorder_level' => 5]);
    // Service variant.
    [$c, $j] = api('POST', 'api/mobile/products/create.php', ['product_name' => "ZZ API TEST service $RUN", 'is_service' => 1, 'selling_price' => 2000]);
    ok($c === 200 && !empty($j['product_id']), 'products/create: service → 200', [$c, $j]);
    if (!empty($j['product_id'])) {
        [$c, $j] = api('POST', 'api/mobile/products/delete.php', ['product_id' => (int)$j['product_id']]);
        ok($c === 200, 'products/delete: service → 200', [$c, $j]);
    }
}

// =========================================================================
// Held sales: needs an active shift for the token's user. Opens one only when
// BMS_API_ALLOW_SHIFT=1 (and closes it again), so live runs never create shifts by default.
if (section('held')) {
    $openedShift = false;
    $prod = api('GET', 'api/pos/simple_products.php')[1]['data'][0] ?? null;
    $items = [['product_id' => (int)($prod['product_id'] ?? 0), 'quantity' => 1, 'price' => (float)($prod['selling_price'] ?? 100)]];
    $holdBody = ['items' => $items, 'subtotal' => $items[0]['price'], 'tax' => 0, 'reference' => "ZZ-HOLD-$RUN"];

    [$c, $j] = api('POST', 'api/pos/hold_sale.php', $holdBody);
    if (empty($j['success']) && stripos($j['message'] ?? '', 'shift') !== false && getenv('BMS_API_ALLOW_SHIFT') === '1') {
        $reg = api('GET', 'api/pos/get_registers.php')[1]['data'][0]['register_id'] ?? 0;
        [$c, $o] = api('POST', 'api/pos/open_shift.php', ['register_id' => $reg, 'opening_cash' => 0]);
        ok(!empty($o['success']), 'open_shift (JSON body) → success', [$c, $o]);
        $openedShift = !empty($o['success']);
        [$c, $j] = api('POST', 'api/pos/hold_sale.php', $holdBody);
    }
    if (empty($j['success']) && stripos($j['message'] ?? '', 'shift') !== false) {
        echo "  SKIP  held: no active shift for this user (set BMS_API_ALLOW_SHIFT=1 to open one)\n";
    } else {
        ok(!empty($j['success']) && !empty($j['hold_id']), 'hold_sale (JSON) → success with hold_id', [$c, $j]);
        $hid = (int)($j['hold_id'] ?? 0);
        $held = api('GET', 'api/pos/get_held_sales.php')[1]['data'] ?? [];
        $ids  = array_map(fn($h) => (int)($h['hold_id'] ?? 0), $held);
        ok(in_array($hid, $ids, true), 'hold_sale: returned hold_id exists in get_held_sales (not an activity_log id)', [$hid, $ids]);

        [$c, $j2] = api('POST', 'api/pos/hold_sale.php', ['items' => json_encode($items), 'subtotal' => $items[0]['price'], 'reference' => "ZZ-HOLD2-$RUN"], false);
        ok(!empty($j2['success']) && !empty($j2['hold_id']), 'hold_sale (form-encoded) → success', [$c, $j2]);

        [$c, $d] = api('POST', 'api/pos/delete_held_sale.php', ['hold_id' => $hid]);
        ok(!empty($d['success']), 'delete_held_sale (JSON) with returned hold_id → success', [$c, $d]);
        if (!empty($j2['hold_id'])) {
            [$c, $d] = api('POST', 'api/pos/delete_held_sale.php', ['hold_id' => (int)$j2['hold_id']], false);
            ok(!empty($d['success']), 'delete_held_sale (form-encoded) → success', [$c, $d]);
        }
    }
    if ($openedShift) {
        [$c, $x] = api('POST', 'api/pos/close_shift.php', ['ending_cash' => 0, 'notes' => 'ZZ API TEST']);
        ok(!empty($x['success']), 'close_shift (JSON body) → success', [$c, $x]);
    }
}

// =========================================================================
// quick_restock replay: buying_price 0 → no ledger posting; stock only on a ZZ test product.
if (section('restock')) {
    [$c, $p] = api('POST', 'api/mobile/products/create.php', ['product_name' => "ZZ API TEST restock $RUN", 'selling_price' => 1000]);
    $pid = (int)($p['product_id'] ?? 0);
    ok($pid > 0, 'restock: test product created', [$c, $p]);
    if ($pid) {
        $before = (float)(api('GET', 'api/mobile/products/get.php', ['id' => $pid])[1]['data']['current_stock'] ?? 0);
        $u = uuid4();
        $body = ['product_id' => $pid, 'quantity' => 1, 'buying_price' => 0, 'selling_price' => 1000, 'client_uuid' => $u];
        if (getenv('BMS_API_PAID_FROM')) $body['paid_from_account_id'] = (int)getenv('BMS_API_PAID_FROM');
        $wh = api('GET', 'api/mobile/warehouses/list.php', ['status' => 'active', 'limit' => 1])[1]['data'][0]['warehouse_id'] ?? 0;
        if ($wh) $body['warehouse_id'] = (int)$wh;
        [$c1, $r1] = api('POST', 'api/pos/quick_restock.php', $body);
        ok(!empty($r1['success']), 'quick_restock (JSON body) → success', [$c1, $r1]);
        [$c2, $r2] = api('POST', 'api/pos/quick_restock.php', $body, false);
        ok(!empty($r2['success']) && !empty($r2['idempotent']) && ($r2['batch_id'] ?? 0) == ($r1['batch_id'] ?? -1),
           'quick_restock: replay with same client_uuid is idempotent', [$c2, $r2]);
        ok(($r2['reference_number'] ?? '') !== '' && ($r2['reference_number'] ?? '') === ($r1['reference_number'] ?? null),
           'quick_restock: replay returns original reference_number', [$r1['reference_number'] ?? null, $r2['reference_number'] ?? null]);
        $after = (float)(api('GET', 'api/mobile/products/get.php', ['id' => $pid])[1]['data']['current_stock'] ?? 0);
        ok(abs(($after - $before) - 1) < 0.0001, 'quick_restock: stock increased by exactly 1 after replay', [$before, $after]);
        [$c, $d] = api('POST', 'api/mobile/products/delete.php', ['product_id' => $pid]);
        ok($c === 200, 'restock: test product deleted', [$c, $d]);
    }

    // Opening stock at create goes through the stock ledger (product_stocks + movement), not a bare column write.
    $wh = api('GET', 'api/mobile/warehouses/list.php', ['status' => 'active', 'limit' => 1])[1]['data'][0]['warehouse_id'] ?? 0;
    [$c, $p] = api('POST', 'api/mobile/products/create.php', ['product_name' => "ZZ API TEST opening $RUN", 'selling_price' => 500, 'cost_price' => 0, 'initial_stock' => 2, 'warehouse_id' => (int)$wh]);
    ok($c === 200 && ($p['track_inventory'] ?? null) === true, 'products/create: track_inventory defaults on', [$c, $p]);
    $pid = (int)($p['product_id'] ?? 0);
    if ($pid) {
        $g = api('GET', 'api/mobile/products/get.php', ['id' => $pid])[1]['data'] ?? [];
        ok((float)($g['current_stock'] ?? -1) == 2.0, 'products/create: opening stock = 2', $g['current_stock'] ?? null);
        [$c, $d] = api('POST', 'api/mobile/products/delete.php', ['product_id' => $pid]);
        ok($c === 200, 'products/delete: product with opening stock → 200', [$c, $d]);
    }
}

// =========================================================================
echo "\n" . str_repeat('-', 60) . "\n";
echo "Result: $pass/" . ($pass + $fail) . " passed" . ($fail ? "  *** $fail FAILED ***" : '') . "\n";
if ($failures) { echo "\nFailures:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
