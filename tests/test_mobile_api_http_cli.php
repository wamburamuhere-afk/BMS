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
function api(string $method, string $path, array $data = [], bool|string $json = true, bool $auth = true): array {
    global $BASE, $TOKEN;
    $url = $BASE . '/' . ltrim($path, '/');
    $ch  = curl_init();
    $headers = ['Accept: application/json'];
    if ($auth && $TOKEN !== '') $headers[] = 'Authorization: Bearer ' . $TOKEN;
    if ($method === 'GET') {
        if ($data) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($data);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($json === 'multipart') { curl_setopt($ch, CURLOPT_POSTFIELDS, $data); }
        elseif ($json) { $headers[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); }

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
// Sales lifecycle — posts real (small) sales; only run against a demo/test tenant.
// Requires BMS_API_ALLOW_SHIFT=1 when the user has no open shift.
if (section('sales')) {
    $stockOf = fn(int $pid) => (float)(api('GET', 'api/mobile/products/get.php', ['id' => $pid])[1]['data']['current_stock'] ?? -1);
    $wh = (int)(api('GET', 'api/mobile/warehouses/list.php', ['status' => 'active', 'limit' => 1])[1]['data'][0]['warehouse_id'] ?? 0);
    [$c, $p] = api('POST', 'api/mobile/products/create.php', ['product_name' => "ZZ API TEST sale item $RUN", 'selling_price' => 1000, 'cost_price' => 0, 'initial_stock' => 5, 'warehouse_id' => $wh]);
    $pid = (int)($p['product_id'] ?? 0);
    ok($pid > 0 && $stockOf($pid) == 5.0, 'sales: test product with 5 in stock', [$c, $p]);

    // Shift.
    $openedShift = false;
    [$c, $o] = api('POST', 'api/pos/open_shift.php', ['register_id' => (int)(api('GET', 'api/pos/get_registers.php')[1]['data'][0]['register_id'] ?? 0), 'opening_cash' => 0]);
    if (!empty($o['success'])) { $openedShift = true; ok(true, 'open_shift → success'); }
    else echo "  INFO  open_shift: " . ($o['message'] ?? $c) . " (using existing shift)\n";
    $shiftId = (int)($o['shift_id'] ?? 0);

    [$c, $r] = api('POST', 'api/pos/generate_receipt_number.php', []);
    ok($c === 200 && !empty($r['receipt_number'] ?? $r['data']['receipt_number'] ?? null), 'generate_receipt_number → receipt', [$c, $r]);

    $sale = fn(array $extra = []) => api('POST', 'api/pos/process_sale.php', $extra + [
        'warehouse_id' => $wh, 'payment_method' => 'cash', 'amount_tendered' => 1000, 'subtotal' => 1000, 'total' => 1000,
        'items' => [['product_id' => $pid, 'quantity' => 1, 'price' => 1000]],
    ]);

    $u = uuid4();
    [$c, $s1] = $sale(['client_uuid' => $u]);
    ok($c === 200 && !empty($s1['sale_id']) && ($s1['payment_status'] ?? '') === 'paid', 'process_sale: cash sale → 200 paid', [$c, $s1]);
    [$c, $s1b] = $sale(['client_uuid' => $u]);
    ok($c === 200 && ($s1b['sale_id'] ?? 0) == ($s1['sale_id'] ?? -1), 'process_sale: client_uuid replay returns same sale', [$c, $s1b]);
    ok($stockOf($pid) == 4.0, 'process_sale: stock 5 → 4 (replay did not deduct twice)', $stockOf($pid));

    [$c, $s2] = $sale(['items' => [['product_id' => $pid, 'quantity' => 1, 'unit_price' => 1000]]]);
    ok($c === 200 && !empty($s2['sale_id']), 'process_sale: unit_price alias accepted', [$c, $s2]);

    [$c, $x] = $sale(['items' => [['product_id' => $pid, 'quantity' => 999, 'price' => 1000]], 'amount_tendered' => 999000, 'total' => 999000, 'subtotal' => 999000]);
    ok($c === 409, 'process_sale: insufficient stock → 409', [$c, $x]);
    [$c, $x] = $sale(['items' => []]);
    ok($c === 422, 'process_sale: empty cart → 422', [$c, $x]);
    [$c, $x] = $sale(['amount_tendered' => 10, 'amount_paid' => 10]);
    ok($c === 422, 'process_sale: cash underpayment → 422', [$c, $x]);
    [$c, $x] = $sale(['payment_method' => 'credit']);
    ok($c === 422, 'process_sale: credit sale without customer → 422', [$c, $x]);

    // History + items.
    $rcpt = (string)($s1['receipt_number'] ?? '');
    [$c, $h] = api('GET', 'api/pos/get_sales.php', ['date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d'), 'search' => $rcpt]);
    ok($c === 200 && count($h['data'] ?? []) === 1 && ($h['data'][0]['receipt_number'] ?? '') === $rcpt, 'get_sales: date_from/date_to + search finds the sale', [$c, count($h['data'] ?? [])]);
    [$c, $it] = api('GET', 'api/pos/get_sale_items.php', ['sale_id' => (int)($s2['sale_id'] ?? 0)]);
    $lines = $it['lines'] ?? [];
    ok($c === 200 && count($lines) === 1, 'get_sale_items → 1 line', [$c, $it]);

    // Return the second sale's line.
    $sid = (int)($lines[0]['sale_item_id'] ?? 0);
    [$c, $rt] = api('POST', 'api/pos/create_return.php', ['original_sale_id' => (int)($s2['sale_id'] ?? 0), 'reason' => 'ZZ API TEST', 'refund_method' => 'cash', 'items' => [['sale_item_id' => $sid, 'return_qty' => 1]], 'client_uuid' => $ru = uuid4()]);
    ok($c === 200 && !empty($rt['success']), 'create_return (JSON body, items array) → success', [$c, $rt]);
    [$c, $rt2] = api('POST', 'api/pos/create_return.php', ['original_sale_id' => (int)($s2['sale_id'] ?? 0), 'reason' => 'ZZ API TEST', 'refund_method' => 'cash', 'items' => json_encode([['sale_item_id' => $sid, 'return_qty' => 1]]), 'client_uuid' => $ru], false);
    ok($c === 200 && !empty($rt2['idempotent']), 'create_return: client_uuid replay (form) is idempotent', [$c, $rt2]);
    ok($stockOf($pid) == 4.0, 'create_return: stock back to 4', $stockOf($pid));

    // Void the first sale.
    [$c, $v] = api('POST', 'api/pos/void_sale.php', ['sale_id' => (int)($s1['sale_id'] ?? 0), 'reason' => 'ZZ API TEST void']);
    ok($c === 200 && !empty($v['success']), 'void_sale (JSON body) → success', [$c, $v]);
    ok($stockOf($pid) == 5.0, 'void_sale: stock back to 5', $stockOf($pid));
    [$c, $v] = api('POST', 'api/pos/void_sale.php', ['sale_id' => (int)($s1['sale_id'] ?? 0), 'reason' => 'again']);
    ok(empty($v['success']), 'void_sale: voiding twice is rejected', [$c, $v]);

    // Credit sale → due date → receive payment.
    [$c, $cu] = api('POST', 'api/mobile/customers/create.php', ['customer_name' => "ZZ API TEST credit $RUN", 'credit_limit' => 1000000]);
    $cid = (int)($cu['customer_id'] ?? 0);
    [$c, $cs] = $sale(['payment_method' => 'credit', 'customer_id' => $cid, 'amount_tendered' => 0, 'amount_paid' => 0]);
    ok($c === 200 && (float)($cs['balance_due'] ?? 0) == 1000.0, 'process_sale: credit sale → balance_due 1000', [$c, $cs]);
    $csid = (int)($cs['sale_id'] ?? 0);
    [$c, $d] = api('GET', 'api/pos/get_credit_sale_detail.php', ['sale_id' => $csid]);
    ok($c === 200 && !empty($d['success']), 'get_credit_sale_detail → 200', [$c, $d]);
    [$c, $d] = api('POST', 'api/pos/update_credit_due_date.php', ['sale_id' => $csid, 'due_date' => date('Y-m-d', strtotime('+7 days'))]);
    ok($c === 200 && !empty($d['success']), 'update_credit_due_date (JSON body) → success', [$c, $d]);
    $pu = uuid4();
    [$c, $rp] = api('POST', 'api/pos/receive_payment.php', ['sale_id' => $csid, 'amount' => 400, 'payment_method' => 'cash', 'client_uuid' => $pu]);
    ok($c === 200 && !empty($rp['success']), 'receive_payment 400 (JSON body) → success', [$c, $rp]);
    [$c, $rp2] = api('POST', 'api/pos/receive_payment.php', ['sale_id' => $csid, 'amount' => 400, 'payment_method' => 'cash', 'client_uuid' => $pu], false);
    ok(!empty($rp2['idempotent']), 'receive_payment: client_uuid replay is idempotent', [$c, $rp2]);
    [$c, $rp3] = api('POST', 'api/pos/receive_payment.php', ['sale_id' => $csid, 'amount' => 600, 'payment_method' => 'cash']);
    ok($c === 200 && !empty($rp3['success']), 'receive_payment: remaining 600 → success', [$c, $rp3]);
    [$c, $rp4] = api('POST', 'api/pos/receive_payment.php', ['sale_id' => $csid, 'amount' => 1, 'payment_method' => 'cash']);
    ok(empty($rp4['success']), 'receive_payment: over-payment rejected', [$c, $rp4]);
    $aging = api('GET', 'api/pos/get_credit_aging.php')[1]['data'] ?? [];
    ok(!in_array($csid, array_map(fn($r) => (int)($r['sale_id'] ?? 0), $aging), true), 'get_credit_aging: fully paid sale no longer listed');

    // Credit limit: the sale being made must not be counted twice; over-limit → 409 + override.
    [$c, $lc] = api('POST', 'api/mobile/customers/create.php', ['customer_name' => "ZZ API TEST limit $RUN", 'credit_limit' => 1500]);
    $lcid = (int)($lc['customer_id'] ?? 0);
    [$c, $l1] = $sale(['payment_method' => 'credit', 'customer_id' => $lcid, 'amount_tendered' => 0, 'amount_paid' => 0]);
    ok($c === 200 && !empty($l1['sale_id']), 'credit limit 1500: first 1000 credit sale allowed (no double count)', [$c, $l1]);
    [$c, $l2] = $sale(['payment_method' => 'credit', 'customer_id' => $lcid, 'amount_tendered' => 0, 'amount_paid' => 0]);
    ok($c === 409 && ($l2['error_code'] ?? '') === 'credit_limit_exceeded' && array_key_exists('can_override', $l2), 'credit limit: second 1000 → 409 credit_limit_exceeded', [$c, $l2]);
    if (!empty($l2['can_override'])) {
        [$c, $l3] = $sale(['payment_method' => 'credit', 'customer_id' => $lcid, 'amount_tendered' => 0, 'amount_paid' => 0, 'override_credit_limit' => 1]);
        ok($c === 200 && !empty($l3['sale_id']), 'credit limit: manager override → 200', [$c, $l3]);
        if (!empty($l3['sale_id'])) api('POST', 'api/pos/void_sale.php', ['sale_id' => (int)$l3['sale_id'], 'reason' => 'ZZ API TEST']);
    }
    if (!empty($l1['sale_id'])) api('POST', 'api/pos/void_sale.php', ['sale_id' => (int)$l1['sale_id'], 'reason' => 'ZZ API TEST']);
    if ($lcid) api('POST', 'api/mobile/customers/update.php', ['customer_id' => $lcid, 'customer_name' => "ZZ API TEST limit $RUN", 'status' => 'inactive']);

    // Cash drawer.
    $du = uuid4();
    [$c, $cd] = api('POST', 'api/pos/quick_cash_drawer.php', ['type' => 'cash_in', 'amount' => 100, 'reason' => 'ZZ API TEST', 'client_uuid' => $du]);
    ok($c === 200 && !empty($cd['success']), 'quick_cash_drawer cash_in (JSON body) → success', [$c, $cd]);
    [$c, $cd2] = api('POST', 'api/pos/quick_cash_drawer.php', ['type' => 'cash_in', 'amount' => 100, 'reason' => 'ZZ API TEST', 'client_uuid' => $du], false);
    ok(!empty($cd2['idempotent']), 'quick_cash_drawer: replay is idempotent', [$c, $cd2]);
    [$c, $cd3] = api('POST', 'api/pos/quick_cash_drawer.php', ['type' => 'cash_out', 'amount' => 100, 'reason' => 'ZZ API TEST']);
    ok($c === 200 && !empty($cd3['success']), 'quick_cash_drawer cash_out → success', [$c, $cd3]);

    [$c, $ee] = api('POST', 'api/pos/email_receipt.php', ['sale_id' => (int)($s2['sale_id'] ?? 0), 'email' => 'not-an-email']);
    ok(empty($ee['success']), 'email_receipt: invalid email rejected', [$c, $ee]);

    if ($openedShift) {
        [$c, $z] = api('GET', 'api/pos/get_z_report.php', ['shift_id' => $shiftId]);
        ok($c === 200 && !empty($z['success']), 'get_z_report → 200', [$c, $z]);
        [$c, $x] = api('POST', 'api/pos/close_shift.php', ['ending_cash' => 1600, 'notes' => 'ZZ API TEST']);
        ok($c === 200 && !empty($x['success']), 'close_shift → success', [$c, $x]);
    }

    // Records with sales cannot be deleted — mark them inactive instead.
    [$c, $d] = api('POST', 'api/mobile/products/delete.php', ['product_id' => $pid]);
    ok($c === 409, 'products/delete: product with sales → 409', [$c, $d]);
    api('POST', 'api/mobile/products/update.php', ['product_id' => $pid, 'product_name' => "ZZ API TEST sale item $RUN", 'status' => 'inactive']);
    if ($cid) api('POST', 'api/mobile/customers/update.php', ['customer_id' => $cid, 'customer_name' => "ZZ API TEST credit $RUN", 'status' => 'inactive']);
}

// =========================================================================
if (section('expenses')) {
    $u = uuid4();
    $body = ['description' => "ZZ API TEST expense $RUN", 'amount' => 100, 'expense_date' => date('Y-m-d'), 'client_uuid' => $u];
    [$c, $e] = api('POST', 'api/mobile/expenses/create.php', $body);
    ok($c === 200 && !empty($e['expense_id']), 'expenses/create → 200', [$c, $e]);
    [$c, $e2] = api('POST', 'api/mobile/expenses/create.php', $body, false);
    ok(($e2['expense_id'] ?? 0) == ($e['expense_id'] ?? -1) && !empty($e2['idempotent']), 'expenses/create: replay idempotent', [$c, $e2]);
    $eid = (int)($e['expense_id'] ?? 0);
    [$c, $g] = api('GET', 'api/mobile/expenses/get.php', ['id' => $eid]);
    ok($c === 200 && (float)($g['data']['amount'] ?? 0) == 100.0, 'expenses/get → amount 100', [$c, $g['data'] ?? $g]);
    [$c, $l] = api('GET', 'api/mobile/expenses/list.php', ['search' => "ZZ API TEST expense $RUN"]);
    ok($c === 200 && count($l['data'] ?? []) >= 1, 'expenses/list: search finds it', [$c, $l['total'] ?? $l]);
    foreach ([[], ['amount' => 'x'], ['amount' => -5], ['expense_date' => '01/10/2026']] as $bad) {
        [$c, $x] = api('POST', 'api/mobile/expenses/create.php', array_merge(['description' => 'ZZ bad', 'amount' => 1, 'expense_date' => date('Y-m-d')], $bad ?: ['description' => '']));
        ok($c === 422, 'expenses/create: invalid input → 422 ' . json_encode($bad ?: ['description' => '']), [$c, $x]);
    }
    [$c, $up] = api('POST', 'api/mobile/expenses/update.php', ['expense_id' => $eid, 'description' => "ZZ API TEST expense $RUN upd"]);
    echo "  INFO  expenses/update on a paid expense → HTTP $c " . json_encode($up) . "\n";
    [$c, $dl] = api('POST', 'api/mobile/expenses/delete.php', ['expense_id' => $eid]);
    echo "  INFO  expenses/delete on a paid expense → HTTP $c " . json_encode($dl) . "\n";
}

// =========================================================================
// Web-parity features (v24): expense void/delete, stock adjust, catalog, product fields, registers, receipt.
if (section('parity')) {
    $wh = (int)(api('GET', 'api/mobile/warehouses/list.php', ['status' => 'active', 'limit' => 1])[1]['data'][0]['warehouse_id'] ?? 0);
    $stockOf = fn(int $pid) => (float)(api('GET', 'api/mobile/products/get.php', ['id' => $pid])[1]['data']['current_stock'] ?? -1);

    // Expenses: create (paid) → void → delete.
    [$c, $e] = api('POST', 'api/mobile/expenses/create.php', ['description' => "ZZ API TEST void $RUN", 'amount' => 50, 'expense_date' => date('Y-m-d')]);
    $eid = (int)($e['expense_id'] ?? 0);
    ok($eid > 0, 'expenses/create for void test', [$c, $e]);
    [$c, $d] = api('POST', 'api/mobile/expenses/delete.php', ['expense_id' => $eid]);
    ok($c === 409, 'expenses/delete: paid expense → 409 (void first)', [$c, $d]);
    [$c, $v] = api('POST', 'api/mobile/expenses/void.php', ['expense_id' => $eid]);
    ok($c === 200 && !empty($v['success']), 'expenses/void → 200', [$c, $v]);
    [$c, $g] = api('GET', 'api/mobile/expenses/get.php', ['id' => $eid]);
    ok(($g['data']['status'] ?? '') === 'rejected', 'expenses/void: status = rejected', $g['data']['status'] ?? $g);
    [$c, $v2] = api('POST', 'api/mobile/expenses/void.php', ['expense_id' => $eid], false);
    ok($c === 200 && !empty($v2['idempotent']), 'expenses/void: second void is idempotent', [$c, $v2]);
    [$c, $d] = api('POST', 'api/mobile/expenses/delete.php', ['expense_id' => $eid]);
    ok($c === 200 && !empty($d['success']), 'expenses/delete after void → 200', [$c, $d]);
    [$c] = api('GET', 'api/mobile/expenses/get.php', ['id' => $eid]);
    ok($c === 404, 'expenses/get after delete → 404', $c);
    [$c, $d] = api('POST', 'api/mobile/expenses/delete.php', ['expense_id' => 999999999]);
    ok($c === 404, 'expenses/delete unknown → 404', [$c, $d]);

    // Catalog: category, brand, unit, tax rates.
    [$c, $cat] = api('POST', 'api/mobile/categories/create.php', ['category_name' => "ZZ API TEST cat $RUN"]);
    ok($c === 200 && !empty($cat['category_id']), 'categories/create → 200', [$c, $cat]);
    [$c, $cat2] = api('POST', 'api/mobile/categories/create.php', ['category_name' => "ZZ API TEST cat $RUN"]);
    ok($c === 409, 'categories/create duplicate → 409', [$c, $cat2]);
    [$c, $br] = api('POST', 'api/mobile/brands/save.php', ['brand_name' => "ZZ API TEST brand $RUN"]);
    ok($c === 200 && !empty($br['success']), 'brands/save → 200', [$c, $br]);
    $brands = api('GET', 'api/mobile/brands/list.php')[1]['data'] ?? [];
    $brandId = 0; foreach ($brands as $b) if ($b['brand_name'] === "ZZ API TEST brand $RUN") $brandId = (int)$b['brand_id'];
    ok($brandId > 0, 'brands/list includes new brand', count($brands));
    $ucode = 'Z' . strtoupper(substr($RUN, 0, 5));
    [$c, $un] = api('POST', 'api/mobile/units/create.php', ['unit_name' => "ZZ unit $RUN", 'unit_code' => $ucode]);
    ok($c === 200 && !empty($un['success']), 'units/create → 200', [$c, $un]);
    [$c, $un2] = api('POST', 'api/mobile/units/create.php', ['unit_name' => "ZZ unit $RUN", 'unit_code' => $ucode]);
    ok($c === 409, 'units/create duplicate code → 409', [$c, $un2]);
    [$c, $ul] = api('GET', 'api/mobile/units/list.php');
    ok($c === 200 && in_array($ucode, array_column($ul['data'] ?? [], 'unit_code'), true), 'units/list includes new unit', [$c]);
    [$c, $tx] = api('GET', 'api/mobile/tax_rates/list.php');
    ok($c === 200 && isset($tx['data']), 'tax_rates/list → 200', [$c, $tx]);
    $taxId = (int)($tx['data'][0]['rate_id'] ?? 0);

    // Product with web-parity fields.
    $body = ['product_name' => "ZZ API TEST parity $RUN", 'selling_price' => 1000, 'cost_price' => 0, 'discount_rate' => 10,
             'wholesale_price' => 850, 'brand_id' => $brandId, 'category_id' => (int)($cat['category_id'] ?? 0),
             'min_stock_level' => 2, 'max_stock_level' => 50, 'is_taxable' => 1, 'manufacturer' => 'ZZ Mfg', 'model' => 'M1',
             'expiry_days' => 30, 'initial_stock' => 5, 'warehouse_id' => $wh, 'expiry_date' => date('Y-m-d', strtotime('+30 days'))];
    if ($taxId) $body['tax_id'] = $taxId;
    [$c, $p] = api('POST', 'api/mobile/products/create.php', $body);
    $pid = (int)($p['product_id'] ?? 0);
    ok($c === 200 && $pid > 0, 'products/create with parity fields → 200', [$c, $p]);
    [$c, $g] = api('GET', 'api/mobile/products/get.php', ['id' => $pid]);
    $d = $g['data'] ?? [];
    ok((float)($d['min_selling_price'] ?? 0) == 900.0, 'products/get: min_selling_price derived from discount_rate (900)', $d['min_selling_price'] ?? null);
    ok((float)($d['wholesale_price'] ?? 0) == 850.0 && (int)($d['brand_id'] ?? 0) === $brandId && ($d['brand_name'] ?? '') === "ZZ API TEST brand $RUN",
       'products/get: wholesale_price + brand stored and joined', [$d['wholesale_price'] ?? null, $d['brand_id'] ?? null, $d['brand_name'] ?? null]);
    ok(($d['manufacturer'] ?? '') === 'ZZ Mfg' && (int)($d['expiry_days'] ?? 0) === 30 && (float)($d['max_stock_level'] ?? 0) == 50.0, 'products/get: manufacturer/expiry_days/max_stock_level stored', $d);
    if ($taxId) ok((int)($d['tax_id'] ?? 0) === $taxId && ($d['tax_name'] ?? '') !== '', 'products/get: tax_id + tax_name', [$d['tax_id'] ?? null, $d['tax_name'] ?? null]);
    ok(count($d['stock_by_shop'] ?? []) === 1 && (float)($d['stock_by_shop'][0]['stock_quantity'] ?? 0) == 5.0, 'products/get: stock_by_shop shows 5', $d['stock_by_shop'] ?? null);
    [$c, $x] = api('POST', 'api/mobile/products/create.php', ['product_name' => "ZZ API TEST bad $RUN", 'selling_price' => 100, 'discount_rate' => 150]);
    ok($c === 422, 'products/create: discount_rate > 100 → 422', [$c, $x]);
    [$c, $x] = api('POST', 'api/mobile/products/create.php', ['product_name' => "ZZ API TEST bad2 $RUN", 'selling_price' => 100, 'brand_id' => 999999999]);
    ok($c === 422, 'products/create: unknown brand_id → 422', [$c, $x]);

    // Update: price change recomputes the floor; stock edit goes through an adjustment.
    [$c, $u] = api('POST', 'api/mobile/products/update.php', ['product_id' => $pid, 'selling_price' => 2000]);
    ok($c === 200, 'products/update: partial update without product_name → 200', [$c, $u]);
    $d = api('GET', 'api/mobile/products/get.php', ['id' => $pid])[1]['data'] ?? [];
    ok((float)($d['min_selling_price'] ?? 0) == 1800.0, 'products/update: min_selling_price follows new price (1800)', $d['min_selling_price'] ?? null);
    [$c, $u] = api('POST', 'api/mobile/products/update.php', ['product_id' => $pid, 'current_stock' => 7, 'warehouse_id' => $wh]);
    ok($c === 200 && (float)($u['stock_adjustment']['after'] ?? -1) == 7.0 && !empty($u['stock_adjustment']['reference_number']),
       'products/update: current_stock 5 → 7 recorded as an adjustment', [$c, $u]);
    ok($stockOf($pid) == 7.0, 'products/update: current_stock now 7', $stockOf($pid));

    // Image upload (multipart).
    $png = sys_get_temp_dir() . "/zz_$RUN.png";
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    [$c, $u] = api('POST', 'api/mobile/products/update.php', ['product_id' => $pid, 'product_image' => new CURLFile($png, 'image/png', 'p.png')], 'multipart');
    ok($c === 200 && str_starts_with((string)($u['image_url'] ?? ''), 'uploads/products/'), 'products/update: multipart image upload stored', [$c, $u]);
    $fake = sys_get_temp_dir() . "/zz_$RUN.png.php";
    file_put_contents($fake, '<?php echo 1;');
    [$c, $u] = api('POST', 'api/mobile/products/update.php', ['product_id' => $pid, 'product_image' => new CURLFile($fake, 'image/png', 'x.png')], 'multipart');
    ok($c === 422, 'products/update: non-image content rejected → 422', [$c, $u]);
    @unlink($png); @unlink($fake);

    // Per-product selling unit.
    [$c, $pu] = api('POST', 'api/mobile/products/unit_save.php', ['product_id' => $pid, 'unit_label' => 'Box', 'base_unit_multiplier' => 6]);
    ok($c === 200 && !empty($pu['id']), 'products/unit_save → 200', [$c, $pu]);
    [$c, $pl] = api('GET', 'api/pos/get_product_units.php', ['product_id' => $pid]);
    ok($c === 200 && str_contains(json_encode($pl), 'Box'), 'get_product_units lists Box', [$c]);
    if (!empty($pu['id'])) {
        [$c, $pd] = api('POST', 'api/mobile/products/unit_delete.php', ['id' => (int)$pu['id']]);
        ok($c === 200 && !empty($pd['success']), 'products/unit_delete → 200', [$c, $pd]);
    }

    // Stock adjustment.
    $u = uuid4();
    $adj = ['product_id' => $pid, 'warehouse_id' => $wh, 'quantity' => 2, 'movement_type' => 'damaged', 'reason' => 'ZZ API TEST', 'client_uuid' => $u];
    [$c, $a] = api('POST', 'api/mobile/stock/adjust.php', $adj);
    ok($c === 200 && !empty($a['reference_number']), 'stock/adjust damaged 2 → 200', [$c, $a]);
    [$c, $a2] = api('POST', 'api/mobile/stock/adjust.php', $adj, false);
    ok($c === 200 && !empty($a2['idempotent']) && ($a2['reference_number'] ?? '') === ($a['reference_number'] ?? '-'), 'stock/adjust: replay is idempotent', [$c, $a2]);
    ok($stockOf($pid) == 5.0, 'stock/adjust: stock 7 → 5 (once)', $stockOf($pid));
    [$c, $x] = api('POST', 'api/mobile/stock/adjust.php', ['product_id' => $pid, 'warehouse_id' => $wh, 'quantity' => 99, 'movement_type' => 'adjustment_out', 'reason' => 'ZZ']);
    ok($c === 409, 'stock/adjust: removing more than available → 409', [$c, $x]);
    [$c, $x] = api('POST', 'api/mobile/stock/adjust.php', ['product_id' => $pid, 'warehouse_id' => $wh, 'quantity' => 1, 'movement_type' => 'set', 'reason' => 'ZZ']);
    ok($c === 422, 'stock/adjust: invalid movement_type → 422', [$c, $x]);
    [$c, $a] = api('POST', 'api/mobile/stock/adjust.php', ['product_id' => $pid, 'warehouse_id' => $wh, 'quantity' => 3, 'movement_type' => 'found', 'reason' => 'ZZ API TEST']);
    ok($c === 200 && $stockOf($pid) == 8.0, 'stock/adjust found 3 → stock 8', [$c, $a, $stockOf($pid)]);

    // Receipt HTML for a real sale.
    $anySale = api('GET', 'api/pos/get_sales.php', ['limit' => 1])[1]['data'][0] ?? null;
    if ($anySale) {
        global $BASE, $TOKEN;
        $ch = curl_init("$BASE/api/pos/print_receipt.php?id=" . (int)$anySale['sale_id']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $TOKEN"], CURLOPT_SSL_VERIFYPEER => false]);
        $html = (string)curl_exec($ch); $hc = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        ok($hc === 200 && str_contains($html, (string)$anySale['receipt_number']), 'print_receipt (Bearer) → HTML with receipt number', [$hc, substr(strip_tags($html), 0, 120)]);
    }

    // Registers / targets / printer: plan-gated (pos_advanced) → 200 or 403 with a plan message, never 401.
    [$c, $r] = api('POST', 'api/pos/save_register.php', ['register_name' => "ZZ API TEST reg $RUN", 'register_code' => "ZZ$RUN", 'warehouse_id' => $wh]);
    ok(in_array($c, [200, 403], true) && $c !== 401, "save_register (Bearer) → $c", [$c, $r]);
    if ($c === 200 && !empty($r['register_id'])) {
        [$c, $t] = api('POST', 'api/pos/toggle_register_status.php', ['register_id' => (int)$r['register_id'], 'status' => 'inactive']);
        ok($c === 200 && !empty($t['success']), 'toggle_register_status → 200', [$c, $t]);
    }
    [$c, $r] = api('POST', 'api/pos/save_sales_target.php', ['warehouse_id' => $wh, 'period_month' => date('Y-m'), 'target_amount' => 1000]);
    ok(in_array($c, [200, 403], true), "save_sales_target (Bearer) → $c", [$c, $r]);

    // Product has stock movements but no sales → deletable.
    [$c, $d] = api('POST', 'api/mobile/products/delete.php', ['product_id' => $pid]);
    ok($c === 200, 'products/delete parity product → 200', [$c, $d]);
}

// =========================================================================
if (section('misc')) {
    [$c, $j] = api('POST', 'api/mobile/login.php', ['username' => 'nobody-' . $RUN, 'password' => 'wrong'], false, false);
    ok(empty($j['success']), 'login: wrong credentials rejected', [$c, $j]);
    if (getenv('BMS_API_USER')) {
        [$c, $j] = api('POST', 'api/mobile/login.php', ['username' => getenv('BMS_API_USER'), 'password' => getenv('BMS_API_PASS'), 'device_name' => 'api-http-test-json'], true, false);
        ok(!empty($j['token']), 'login: JSON body → token', [$c, $j['message'] ?? null]);
        if (!empty($j['token'])) {
            global $TOKEN; $keep = $TOKEN; $TOKEN = $j['token'];
            [$c, $o] = api('POST', 'api/mobile/logout.php', []);
            ok($c === 200 && !empty($o['success']), 'logout → 200', [$c, $o]);
            [$c] = api('GET', 'api/mobile/me.php');
            ok($c === 401, 'me after logout → 401', $c);
            $TOKEN = $keep;
        }
    }
    [$c] = api('GET', 'api/mobile/me.php', [], true, false);
    ok($c === 401, 'me without token → 401', $c);
    [$c, $j] = api('GET', 'api/mobile/me.php');
    ok($c === 200 && !empty($j['user']['id']), 'me → user', [$c]);
    [$c, $j] = api('GET', 'api/mobile/platform_info.php', [], true, false);
    ok($c === 200 && !empty($j['platform_name']), 'platform_info (no auth) → 200', [$c]);
    $sub = explode('.', parse_url($GLOBALS['BASE'], PHP_URL_HOST))[0];
    [$c, $j] = api('GET', 'api/mobile/tenant_info.php', ['subdomain' => $sub], true, false);
    echo "  INFO  tenant_info?subdomain=$sub → HTTP $c " . substr(json_encode($j), 0, 160) . "\n";
    [$c, $j] = api('GET', 'api/mobile/tenant_info.php', ['subdomain' => 'no-such-tenant-' . $RUN], true, false);
    ok(in_array($c, [404, 422], true), 'tenant_info: unknown subdomain → 404/422', [$c, $j]);

    // Notifications.
    [$c, $n] = api('GET', 'api/mobile/notifications/list.php');
    ok($c === 200 && isset($n['badge_count']), 'notifications/list → badge_count', [$c]);
    [$c, $j] = api('POST', 'api/mobile/notifications/mark_read.php', ['notification_id' => 0]);
    ok(empty($j['success']), 'notifications/mark_read: id 0 rejected', [$c, $j]);
    [$c, $j] = api('POST', 'api/mobile/notifications/mark_all_read.php', ['action' => 'mark_all_read']);
    ok($c === 200 && !empty($j['success']), 'notifications/mark_all_read (JSON) → success', [$c, $j]);

    // Quick customer + search.
    [$c, $q] = api('POST', 'api/pos/save_customer_quick.php', ['customer_name' => "ZZ API TEST quick $RUN", 'phone' => '0799' . substr($RUN, 0, 6)]);
    ok($c === 200 && !empty($q['success']), 'save_customer_quick (JSON) → success', [$c, $q]);
    [$c, $s] = api('GET', 'api/pos/search_customers.php', ['q' => "ZZ API TEST quick $RUN"]);
    $found = $s['results'] ?? $s['data'] ?? [];
    ok($c === 200 && count($found) >= 1, 'search_customers finds quick customer', [$c, $s]);
    $qid = (int)($q['customer_id'] ?? $q['id'] ?? $q['data']['customer_id'] ?? ($found[0]['id'] ?? 0));
    if ($qid) api('POST', 'api/mobile/customers/delete.php', ['customer_id' => $qid]);

    // Product helpers + settings.
    $any = api('GET', 'api/pos/simple_products.php')[1]['data'][0] ?? [];
    $wh  = (int)(api('GET', 'api/mobile/warehouses/list.php', ['status' => 'active', 'limit' => 1])[1]['data'][0]['warehouse_id'] ?? 0);
    [$c, $j] = api('GET', 'api/pos/get_product_units.php', ['product_id' => (int)($any['product_id'] ?? 0)]);
    ok($c === 200 && !empty($j['success']), 'get_product_units → 200', [$c, $j]);
    [$c, $j] = api('GET', 'api/pos/get_available_serials.php', ['product_id' => (int)($any['product_id'] ?? 0), 'warehouse_id' => $wh]);
    echo "  INFO  get_available_serials → HTTP $c " . substr(json_encode($j), 0, 160) . "\n";
    [$c, $j] = api('GET', 'api/pos/get_products.php', ['type' => 'products', 'limit' => 2]);
    ok($c === 200 && !empty($j['success']), 'get_products?type=products → 200', [$c, substr(json_encode($j), 0, 200)]);
    $hist = api('GET', 'api/pos/get_shift_history.php')[1]['data'][0]['shift_id'] ?? 0;
    if ($hist) {
        [$c, $z] = api('GET', 'api/pos/get_z_report.php', ['shift_id' => (int)$hist]);
        ok($c === 200 && !empty($z['success']), "get_z_report shift #$hist → 200", [$c, substr(json_encode($z), 0, 200)]);
    }
    [$c, $j] = api('POST', 'api/pos/save_pos_setting.php', ['key' => 'pos_receipt_width', 'value' => 'bogus']);
    ok($c === 422, 'save_pos_setting: invalid value → 422', [$c, $j]);
    [$c, $j] = api('POST', 'api/pos/save_pos_setting.php', ['key' => 'not_allowed', 'value' => '1']);
    ok($c === 400, 'save_pos_setting: unknown key → 400', [$c, $j]);
    foreach (['api/pos/get_simple_dashboard_chart.php', 'api/pos/get_dashboard.php'] as $ep) {
        [$c] = api('GET', $ep);
        ok($c === 200, "$ep → 200", $c);
    }
}

// =========================================================================
echo "\n" . str_repeat('-', 60) . "\n";
echo "Result: $pass/" . ($pass + $fail) . " passed" . ($fail ? "  *** $fail FAILED ***" : '') . "\n";
if ($failures) { echo "\nFailures:\n - " . implode("\n - ", $failures) . "\n"; exit(1); }
