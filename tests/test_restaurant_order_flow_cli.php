<?php
/**
 * tests/test_restaurant_order_flow_cli.php — restaurant_order_flow_plan.md
 * Waiter → Cashier → Kitchen → Waiter → Bill, both modes, every role, waste + ledger, retail unchanged.
 *
 *   php tests/test_restaurant_order_flow_cli.php
 *
 * Parent: commits the few settings the child needs (get_setting() caches per process), runs the
 * in-process scenarios in a child (`run`) inside ONE rolled-back transaction, runs endpoint gates in
 * subprocess workers (`worker`), then restores every setting. Exit 0 = all pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
$mode = $argv[1] ?? '';

// ─── worker: render one endpoint/page with a given session (committed DB) ────
if ($mode === 'worker') {
    chdir($root);
    require_once "$root/roots.php";
    require_once "$root/core/feature_registry.php";
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[2]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) { $_SESSION[$k] = $v; }
    $GLOBALS['__bms_features'] = $cfg['features'] ?? [];
    foreach (($cfg['post'] ?? []) as $k => $v) { $_POST[$k] = $v; }
    foreach (($cfg['get'] ?? []) as $k => $v) { $_GET[$k] = $v; }
    $_SERVER['REQUEST_METHOD'] = !empty($cfg['post']) ? 'POST' : 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $captured = '';
    register_shutdown_function(function () use (&$captured) {
        $buf = ob_get_contents();
        if ($buf !== false) { $captured .= $buf; @ob_end_clean(); }
        echo "\n___OUT___\n" . $captured;
    });
    ob_start();
    try { require "$root/" . $cfg['page']; } catch (Throwable $e) { $captured .= "\n___EXC___" . $e->getMessage(); }
    exit;
}

// ─── run: the in-process scenarios (child) ───────────────────────────────────
if ($mode === 'run') {
    require __DIR__ . '/helpers/restaurant_inproc.php';
    require_once "$root/core/restaurant_order_flow.php";
    require_once "$root/core/financial_reports.php";
    global $pdo, $admin, $W, $DRINK, $DPRICE;

    $P = function (array $map): array {
        $out = [];
        foreach ($map as $key => $verbs) {
            $out[$key] = ['view' => false, 'create' => false, 'edit' => false, 'delete' => false, 'review' => false, 'approve' => false];
            foreach ($verbs as $v) $out[$key][$v] = true;
        }
        return $out;
    };
    $WAITER  = $P(['pos' => ['view'], 'restaurant_pos' => ['view'], 'restaurant_orders' => ['view', 'create', 'edit']]);
    $CASHIER = $P(['pos' => ['view', 'create'], 'restaurant_pos' => ['view'], 'restaurant_orders' => ['view', 'create', 'edit', 'delete', 'approve'], 'kitchen_display' => ['view']]);
    $COOK    = $P(['restaurant_pos' => ['view'], 'kitchen_display' => ['view', 'edit']]);
    $as = function (?array $perms, int $uid) {
        $_SESSION['user_id'] = $uid;
        $_SESSION['is_admin'] = $perms === null;
        $_SESSION['role_id'] = $perms === null ? 1 : 999;
        $_SESSION['permissions'] = $perms ?? [];
        $_SESSION['scope'] = ['is_admin' => true];   // shop scope is not what these tests are about
    };
    $UW = 11; $UC = 12; $UK = 9;   // waiter / cashier / cook user ids (session only)
    $setConfirm = function (bool $on) use ($pdo) {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('restaurant_confirm_orders', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$on ? '1' : '0']);
    };
    $line = fn(int $pid, float $q, float $price, string $note = '') => ['product_id' => $pid, 'product_name' => 'x', 'quantity' => $q, 'price' => $price, 'discounted_price' => $price, 'tax_rate' => 0, 'note' => $note];
    $hold = function (int $table, array $items) use ($W) {
        $sub = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $items));
        return call('api/pos/hold_sale.php', 'POST', ['reference' => 'T', 'items' => $items, 'subtotal' => $sub, 'tax' => 0, 'warehouse_id' => $W, 'table_id' => $table], true);
    };
    $pay = function (array $items, array $extra = []) use ($W) {
        $tot = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $items));
        return call('api/pos/process_sale.php', 'POST', ['customer_id' => null, 'warehouse_id' => $W, 'project_id' => null, 'items' => $items,
            'subtotal' => $tot, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => $tot,
            'payment_method' => 'cash', 'amount_tendered' => $tot, 'change_given' => 0, 'amount_paid' => $tot] + $extra, true);
    };
    $tickets = fn(int $table) => rofCurrentTickets($pdo, $W, $table);
    $sum = function (array $tks, ?string $status = null, ?int $pid = null): float {
        $s = 0.0;
        foreach ($tks as $t) { if ($t['status'] === 'cancelled' || ($status && $t['status'] !== $status)) continue;
            foreach ($t['items'] as $i) if (!$pid || (int)$i['product_id'] === $pid) $s += (float)$i['quantity']; }
        return $s;
    };
    $try = function (callable $f) { try { $f(); return null; } catch (RofException $e) { return $e; } };

    $pdo->startOuter();
    try {
        $as(null, $admin);
        $setConfirm(false);
        $pdo->prepare("UPDATE products SET kitchen_station_id = NULL WHERE product_id = ?")->execute([$DRINK]);
        $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-ROF', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
        $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-ROF2', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$UC, $W]);
        $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
        $CHIPS = restaurantCreateMenuItem($pdo, $W, ['type' => 'dish', 'name' => "ROF Chips $sfx", 'price' => 3000, 'prep_area' => 'kitchen', 'plate_cost' => 900], $admin);
        $STEW  = restaurantCreateMenuItem($pdo, $W, ['type' => 'dish', 'name' => "ROF Stew $sfx", 'price' => 8000, 'prep_area' => 'kitchen'], $admin);
        // Stew has a recipe: 2 × the stocked drink fixture per plate (a stand-in ingredient with stock and cost).
        $pdo->prepare("UPDATE products SET is_combo = 1 WHERE product_id = ?")->execute([$STEW]);
        $pdo->prepare("INSERT INTO product_assembly_components (parent_product_id, component_product_id, component_name, unit, qty_per_unit) VALUES (?, ?, 'ing', 'pcs', 2)")->execute([$STEW, $DRINK]);
        $pdo->prepare("UPDATE products SET cost_price = 500 WHERE product_id = ?")->execute([$DRINK]);
        $floor = restaurantDefaultFloorId($pdo, $W);
        $mk = function (string $n) use ($pdo, $W, $floor) {
            $pdo->prepare("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status) VALUES (?, ?, ?, 4, 'available')")->execute([$W, $floor, $n]);
            return (int)$pdo->lastInsertId();
        };
        $T1 = $mk("RA$sfx"); $T2 = $mk("RB$sfx"); $T3 = $mk("RC$sfx"); $T4 = $mk("RD$sfx"); $T5 = $mk("RE$sfx");

        // ── A. Team flow OFF (today's behaviour) ─────────────────────────────
        section('A. Team flow OFF — sending only what is new');
        ok(!rofConfirmRequired($pdo), 'setting off → no cashier step');
        $h = $hold($T1, [$line($CHIPS, 2, 3000, 'no salt'), $line($DRINK, 1, $DPRICE)]);
        ok(!empty($h['success']), 'admin saves table order' . (!empty($h['success']) ? '' : ' — ' . ($h['message'] ?? '')));
        $r = rofSendHold($pdo, (int)$h['hold_id'], $W, $admin);
        $tk = $tickets($T1);
        ok($r['status'] === 'queued' && count($tk) === 1 && $sum($tk) == 2.0, 'first send: one queued ticket, 2 chips, the drink stays out');
        ok(($tk[0]['items'][0]['modifiers_summary'] ?? '') === 'no salt', 'the kitchen note travels');
        rofAdvance($pdo, (int)$tk[0]['ticket_id'], 'ready', $admin);
        $h = $hold($T1, [$line($CHIPS, 3, 3000, 'no salt'), $line($DRINK, 1, $DPRICE)]);
        $r = rofSendHold($pdo, (int)$h['hold_id'], $W, $admin);
        $tk = $tickets($T1);
        ok(count($r['ticket_ids']) === 1 && $sum($tk, 'queued') == 1.0 && $sum($tk) == 3.0, 'second round sends ONLY the 1 new chips (bug fixed: the ready 2 are not re-sent)');
        $r = rofSendHold($pdo, (int)$h['hold_id'], $W, $admin);
        ok($r['nothing_new'] === true, 'sending again with no change → nothing new');
        $kv = call('api/restaurant/get_kitchen_tickets.php', 'GET', ['warehouse_id' => $W, 'status' => 'queued,preparing,ready']);
        ok(!empty($kv['success']) && count(array_filter($kv['data'], fn($t) => (int)$t['table_id'] === $T1)) === 2, 'kitchen screen lists both tickets');

        section('A2. OFF — removing a cooked dish needs no new step, but is recorded');
        $h = $hold($T1, [$line($CHIPS, 2, 3000, 'no salt'), $line($DRINK, 1, $DPRICE)]);
        ok(!empty($h['success']), 'saving with one chips fewer is allowed (no new block when the setting is off)' . (!empty($h['success']) ? '' : ' — ' . ($h['message'] ?? '')));
        $tk = $tickets($T1);
        ok($sum($tk) == 2.0 && $sum($tk, 'queued') == 0.0, 'the NOT-started chips was taken off first (queued line gone)');
        ok((int)val("SELECT COUNT(*) FROM restaurant_order_cancellations WHERE table_id = ? AND stage = 'before_kitchen'", [$T1]) === 1, 'cancellation recorded (before the kitchen started, no cost)');

        section('A3. OFF — paying closes the kitchen tickets');
        $s = $pay([$line($CHIPS, 2, 3000, 'no salt'), $line($DRINK, 1, $DPRICE)], ['table_id' => $T1, 'sale_type' => 'dine_in']);
        ok(!empty($s['success']), 'bill paid' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? '')));
        $sid = (int)($s['sale_id'] ?? 0);
        ok((int)val("SELECT COUNT(*) FROM kitchen_tickets WHERE sale_id = ? AND closed_at IS NOT NULL", [$sid]) >= 1 && !$tickets($T1), 'tickets now belong to the sale and left the table');
        ok(val("SELECT COUNT(*) FROM kitchen_tickets WHERE sale_id = ? AND status = 'ready'", [$sid]) == 0, 'ready food became served on payment');

        // ── B. Team flow ON ──────────────────────────────────────────────────
        $setConfirm(true);
        section('B. Team flow ON — waiter → cashier → kitchen → waiter');
        ok(rofConfirmRequired($pdo), 'setting on + kitchen screen → cashier step on');
        $as($WAITER, $UW);
        ok(rofCanTake($pdo) && !rofCanConfirm($pdo) && !rofCanPay() && !rofCanKitchen($pdo), 'waiter: takes orders; cannot confirm, pay or cook');
        $h = $hold($T2, [$line($CHIPS, 2, 3000), $line($STEW, 1, 8000)]);
        ok(!empty($h['success']), 'waiter saves the table order without a cash shift' . (!empty($h['success']) ? '' : ' — ' . ($h['message'] ?? '')));
        $r = rofSendHold($pdo, (int)$h['hold_id'], $W, $UW);
        $tk = $tickets($T2);
        ok($r['status'] === 'pending' && $sum($tk, 'pending') == 3.0, 'waiter sends → waiting for the cashier');
        $as(null, $admin);
        $kv = call('api/restaurant/get_kitchen_tickets.php', 'GET', ['warehouse_id' => $W, 'status' => 'queued,preparing,ready']);
        ok(!array_filter($kv['data'] ?? [], fn($t) => (int)$t['table_id'] === $T2), 'the kitchen screen does NOT show it yet');
        $as($WAITER, $UW);
        $e = $try(fn() => rofConfirm($pdo, $W, $T2, null, $UW));
        ok($e && $e->errorCode === 'not_allowed', 'waiter cannot confirm');
        $as($CASHIER, $UC);
        $po = rofPendingOrders($pdo, $W);
        $mine = array_values(array_filter($po, fn($o) => $o['table_id'] === $T2));
        ok(count($mine) === 1 && count($mine[0]['items']) === 2 && abs($mine[0]['value'] - 14000) < 0.01, 'cashier sees the order waiting (2 lines, value 14,000)');
        ok(rofPendingCount($pdo, [$W]) >= 1, 'waiting count for the badge');
        $ids = rofConfirm($pdo, $W, $T2, null, $UC);
        $tk = $tickets($T2);
        ok($ids && $sum($tk, 'queued') == 3.0 && (int)$tk[0]['confirmed_by'] === $UC, 'cashier confirms → queued, confirmed_by recorded');
        $e = $try(fn() => rofConfirm($pdo, $W, $T2, null, $UC));
        ok($e && $e->errorCode === 'nothing_pending', 'confirming twice is refused');

        $as($WAITER, $UW);
        $e = $try(fn() => rofAdvance($pdo, (int)$tk[0]['ticket_id'], 'preparing', $UW));
        ok($e && $e->errorCode === 'not_allowed', 'waiter cannot cook');
        $as($COOK, $UK);
        foreach ($tk as $t) { rofAdvance($pdo, (int)$t['ticket_id'], 'preparing', $UK); rofAdvance($pdo, (int)$t['ticket_id'], 'ready', $UK); }
        $tk = $tickets($T2);
        ok($sum($tk, 'ready') == 3.0, 'cook: preparing → ready');
        $e = $try(fn() => rofAdvance($pdo, (int)$tk[0]['ticket_id'], 'served', $UK));
        ok($e && $e->errorCode === 'not_allowed', 'cook cannot mark served — the waiter does');
        $e = $try(fn() => rofAdvance($pdo, (int)$tk[0]['ticket_id'], 'preparing', $UK));
        ok($e && $e->errorCode === 'backward', 'tickets never move backward');
        $as($WAITER, $UW);
        foreach ($tk as $t) rofAdvance($pdo, (int)$t['ticket_id'], 'served', $UW);
        $tk = $tickets($T2);
        ok($sum($tk, 'served') == 3.0 && (int)$tk[0]['served_by'] === $UW, 'waiter marks received → served, served_by recorded');

        section('B2. ON — cooked food leaves only with the cashier + a reason (waste posted)');
        $stockBefore = (float)val("SELECT stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?", [$DRINK, $W]);
        $h = $hold($T2, [$line($CHIPS, 2, 3000)]);   // waiter drops the stew
        ok(empty($h['success']) && ($h['error_code'] ?? '') === 'cancel_needs_approval', 'waiter removing a served stew → refused (needs the cashier)');
        $as($CASHIER, $UC);
        $h = $hold($T2, [$line($CHIPS, 2, 3000)]);
        ok(empty($h['success']) && ($h['error_code'] ?? '') === 'cancel_needs_reason', 'cashier without a reason → asked for one');
        $jeBefore = (int)val("SELECT COUNT(*) FROM journal_entries WHERE entity_type = 'kitchen_waste'");
        $h = call('api/pos/hold_sale.php', 'POST', ['reference' => 'T', 'items' => [$line($CHIPS, 2, 3000)], 'subtotal' => 6000, 'tax' => 0,
                  'warehouse_id' => $W, 'table_id' => $T2, 'cancel_reason' => 'Customer sent it back'], true);
        ok(!empty($h['success']), 'cashier with a reason → saved' . (!empty($h['success']) ? '' : ' — ' . ($h['message'] ?? '')));
        $c = $pdo->query("SELECT * FROM restaurant_order_cancellations WHERE table_id = $T2 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        ok($c && $c['stage'] === 'after_kitchen' && (int)$c['product_id'] === $STEW && $c['reason'] === 'Customer sent it back' && (int)$c['approved_by'] === $UC, 'waste row: stew, after kitchen, reason, approved by the cashier');
        ok(abs((float)$c['posted_cost'] - 1000) < 0.01 && (int)$c['journal_entry_id'] > 0, 'recipe cost posted: 2 × 500 = 1,000');
        $stockAfter = (float)val("SELECT stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?", [$DRINK, $W]);
        ok(abs(($stockBefore - $stockAfter) - 2) < 0.001, 'the ingredients left stock (2)');
        ok((int)val("SELECT COUNT(*) FROM journal_entries WHERE entity_type = 'kitchen_waste'") === $jeBefore + 1, 'one waste ledger entry');
        $jl = $pdo->query("SELECT jei.type, jei.amount, a.account_id FROM journal_entry_items jei JOIN accounts a ON a.account_id = jei.account_id WHERE jei.entry_id = " . (int)$c['journal_entry_id'])->fetchAll(PDO::FETCH_ASSOC);
        $dr = array_sum(array_map(fn($l) => $l['type'] === 'debit' ? (float)$l['amount'] : 0, $jl));
        $cr = array_sum(array_map(fn($l) => $l['type'] === 'credit' ? (float)$l['amount'] : 0, $jl));
        ok(count($jl) === 2 && abs($dr - $cr) < 0.001 && in_array((int)cogsAccountId($pdo), array_map(fn($l) => (int)$l['account_id'], $jl), true)
           && in_array((int)inventoryAccountId($pdo), array_map(fn($l) => (int)$l['account_id'], $jl), true), 'waste entry: Dr Cost of Sales / Cr Inventory, balanced (no account chosen)');
        ok($sum($tickets($T2), null, $STEW) == 0.0, 'stew gone from the kitchen order');

        section('B3. ON — returned by the cashier');
        $as($WAITER, $UW);
        $h = $hold($T2, [$line($CHIPS, 3, 3000)]);
        $r = rofSendHold($pdo, (int)$h['hold_id'], $W, $UW);
        ok($r['status'] === 'pending' && $sum($tickets($T2), 'pending') == 1.0, 'one more chips → waiting for the cashier (only the new one)');
        $as($CASHIER, $UC);
        $e = $try(fn() => rofReturn($pdo, $W, $T2, null, $UC, ''));
        ok($e && $e->errorCode === 'reason_required', 'returning needs a reason for the waiter');
        rofReturn($pdo, $W, $T2, null, $UC, 'Out of chips');
        ok($sum($tickets($T2), 'pending') == 0.0 && (int)val("SELECT COUNT(*) FROM restaurant_order_cancellations WHERE table_id = ? AND source = 'returned'", [$T2]) === 1, 'returned: cancelled + recorded (no cost)');

        section('B4. ON — the bill waits for the food');
        $h = $hold($T2, [$line($CHIPS, 3, 3000)]);
        $as($WAITER, $UW);
        $r = rofSendHold($pdo, (int)$h['hold_id'], $W, $UW);
        $as($CASHIER, $UC);
        rofConfirm($pdo, $W, $T2, null, $UC);
        $s = $pay([$line($CHIPS, 3, 3000)], ['table_id' => $T2, 'sale_type' => 'dine_in']);
        ok(empty($s['success']) && ($s['error_code'] ?? '') === 'kitchen_not_served' && ($s['can_mark_served'] ?? null) === false, 'cashier cannot close a bill with food still cooking');
        $s = $pay([$line($CHIPS, 3, 3000)], ['table_id' => $T2, 'sale_type' => 'dine_in', 'kitchen_served_ok' => 1]);
        ok(empty($s['success']) && ($s['error_code'] ?? '') === 'kitchen_not_served', '…and cannot force it (no kitchen right)');
        $as(null, $admin);
        $s = $pay([$line($CHIPS, 3, 3000)], ['table_id' => $T2, 'sale_type' => 'dine_in', 'kitchen_served_ok' => 1]);
        ok(!empty($s['success']), 'owner (also the kitchen) may confirm "mark all served and pay"' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? '')));
        $sid2 = (int)($s['sale_id'] ?? 0);
        ok((int)val("SELECT COUNT(*) FROM kitchen_tickets WHERE sale_id = ? AND status <> 'served' AND status <> 'cancelled'", [$sid2]) === 0, 'all its food is served and linked to the sale');
        ok((float)val("SELECT grand_total FROM pos_sales WHERE sale_id = ?", [$sid2]) == 9000.0, 'the sale is the 3 chips served (the returned/cancelled ones were never sold)');

        section('B5. ON — split bill keeps the rest on the table');
        $as($WAITER, $UW);
        $h = $hold($T3, [$line($CHIPS, 2, 3000)]);
        rofSendHold($pdo, (int)$h['hold_id'], $W, $UW);
        $as(null, $admin);
        rofConfirm($pdo, $W, $T3, null, $admin);
        foreach ($tickets($T3) as $t) { rofAdvance($pdo, (int)$t['ticket_id'], 'ready', $admin); rofAdvance($pdo, (int)$t['ticket_id'], 'served', $admin); }
        $as($CASHIER, $UC);
        $s = $pay([$line($CHIPS, 1, 3000)], ['table_id' => $T3, 'sale_type' => 'dine_in', 'keep_table' => 1]);
        ok(!empty($s['success']), 'pay 1 of 2 chips (served)' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? '')));
        $h = $hold($T3, [$line($CHIPS, 1, 3000)]);
        ok(!empty($h['success']) && $sum($tickets($T3)) == 2.0, 'saving the remaining 1 cancels nothing (1 paid + 1 open = 2 sent)');
        $s = $pay([$line($CHIPS, 1, 3000)], ['table_id' => $T3, 'sale_type' => 'dine_in']);
        ok(!empty($s['success']) && !$tickets($T3), 'second part closes the order' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? '')));

        section('B6. ON — customer leaves: cancel the whole order');
        $as($WAITER, $UW);
        $h = $hold($T4, [$line($CHIPS, 1, 3000), $line($DRINK, 1, $DPRICE)]);
        rofSendHold($pdo, (int)$h['hold_id'], $W, $UW);
        $e = $try(fn() => rofCancelOrder($pdo, $W, $T4, $UW, 'left'));
        ok($e && $e->errorCode === 'cancel_not_allowed', 'waiter cannot cancel a whole order');
        $as($CASHIER, $UC);
        rofCancelOrder($pdo, $W, $T4, $UC, 'Customer left');
        ok(!$tickets($T4) && (int)val("SELECT COUNT(*) FROM pos_held_sales WHERE table_id = ? AND status = 'held'", [$T4]) === 0
           && val("SELECT status FROM restaurant_tables WHERE table_id = ?", [$T4]) === 'available', 'order cancelled, held order closed, table free');

        section('B7. ON — cancelling one line from the kitchen list');
        $as($WAITER, $UW);
        $h = $hold($T5, [$line($CHIPS, 2, 3000)]);
        rofSendHold($pdo, (int)$h['hold_id'], $W, $UW);
        $item = (int)$tickets($T5)[0]['items'][0]['id'];
        rofCancelLine($pdo, $item, 1, $UW, '');
        $hd = rofHoldOrder($pdo, $T5);
        $hi = json_decode($hd['items_data'], true);
        ok($sum($tickets($T5)) == 1.0 && (float)$hi[0]['quantity'] == 1.0 && (float)$hd['total_amount'] == 3000.0, 'waiter cancels 1 of their own waiting chips → kitchen 1, bill 1, total 3,000');
        $as($CASHIER, $UC);
        rofConfirm($pdo, $W, $T5, null, $UC);
        $as($WAITER, $UW);
        $e = $try(fn() => rofCancelLine($pdo, $item, 1, $UW, ''));
        ok($e && $e->errorCode === 'cancel_not_allowed', 'after the cashier confirmed, the waiter cannot remove it');

        section('B8. ON — move keeps every line, served ones too');
        $as(null, $admin);
        foreach ($tickets($T5) as $t) { rofAdvance($pdo, (int)$t['ticket_id'], 'ready', $admin); rofAdvance($pdo, (int)$t['ticket_id'], 'served', $admin); }
        rofMoveOrder($pdo, $T5, $T4);
        $pdo->prepare("UPDATE pos_held_sales SET table_id = ? WHERE table_id = ? AND status = 'held'")->execute([$T4, $T5]);
        ok(!$tickets($T5) && $sum($tickets($T4), 'served') == 1.0, 'served chips moved with the order');
        $r = rofSendHold($pdo, (int)rofHoldOrder($pdo, $T4)['hold_id'], $W, $admin);
        ok($r['nothing_new'], 'after the move nothing is re-sent');

        section('C. Take-away + void');
        $s = $pay([$line($CHIPS, 1, 3000)], ['sale_type' => 'take_away']);
        $tsid = (int)($s['sale_id'] ?? 0);
        $ids = rofSendSale($pdo, $tsid, $W, $admin);
        ok(count($ids) === 1 && val("SELECT status FROM kitchen_tickets WHERE ticket_id = ?", [$ids[0]]) === 'queued', 'paid take-away goes straight to the kitchen (no cashier step — the cashier took the money)');
        rofAdvance($pdo, $ids[0], 'preparing', $admin);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'test-csrf';
        $v = call('api/pos/void_sale.php', 'POST', ['sale_id' => $tsid, 'reason' => 'test'], true);
        ok(!empty($v['success']), 'take-away voided' . (!empty($v['success']) ? '' : ' — ' . ($v['message'] ?? '')));
        ok(val("SELECT status FROM kitchen_tickets WHERE ticket_id = ?", [$ids[0]]) === 'cancelled'
           && (int)val("SELECT COUNT(*) FROM restaurant_order_cancellations WHERE sale_id = ? AND stage = 'after_kitchen' AND source = 'void'", [$tsid]) === 1, 'its cooking food stopped and was recorded as waste');

        section('D. Switching the team flow off releases waiting orders');
        $as($WAITER, $UW);
        $h = $hold($T3, [$line($CHIPS, 1, 3000)]);
        rofSendHold($pdo, (int)$h['hold_id'], $W, $UW);
        $as(null, $admin);
        ok(rofPromotePending($pdo, $admin) >= 1 && $sum($tickets($T3), 'queued') == 1.0, 'pending → queued');

        section('E. Retail is unchanged');
        $rh = call('api/pos/hold_sale.php', 'POST', ['reference' => 'R', 'items' => [$line($DRINK, 1, $DPRICE)], 'subtotal' => $DPRICE, 'tax' => 0, 'warehouse_id' => $W], true);
        ok(!empty($rh['success']), 'a plain hold (no table) works as before');
        $rs = $pay([$line($DRINK, 1, $DPRICE)]);
        ok(!empty($rs['success']), 'a plain sale (no table) works as before');

        section('F. Ledger');
        $chk = assertLedgerBalanced($pdo, date('Y-m-d'));
        ok(!empty($chk['ledger_balanced']), 'Σ Dr = Σ Cr after everything');
    } catch (Throwable $e) {
        ok(false, 'unexpected: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        $pdo->endOuter();
    }
    exit;
}

// ─── parent ──────────────────────────────────────────────────────────────────
require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
if (!function_exists('ok')) {   // conditional: the child (`run`) defines its own via the shared helper
    function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
    function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
}
$val = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); };
$setSetting = function (string $k, $v) use ($pdo) {
    if ($v === null || $v === false) { $pdo->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]); return; }
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
};
$worker = function (array $cfg) use ($root) {
    $f = tempnam(sys_get_temp_dir(), 'rof'); file_put_contents($f, json_encode($cfg));
    $out = (string)shell_exec('php ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    $p = explode('___OUT___', $out, 2);
    return ['err' => $p[0] ?? '', 'out' => trim($p[1] ?? ''), 'json' => json_decode(trim($p[1] ?? ''), true)];
};

$drink = $pdo->query("SELECT ps.warehouse_id FROM product_stocks ps JOIN products p ON p.product_id = ps.product_id
               WHERE ps.stock_quantity >= 10 AND p.is_service = 0 AND COALESCE(p.is_combo,0) = 0 AND p.selling_price > 0 AND p.status = 'active'
                 AND p.selling_price >= p.min_selling_price
                 AND NOT EXISTS (SELECT 1 FROM product_serials sn WHERE sn.product_id = p.product_id)
            ORDER BY ps.stock_quantity DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$drink) { ok(false, 'fixture: a stocked product'); exit(1); }
$W = (int)$drink['warehouse_id'];

$keys = ['pos_simple_mode', 'restaurant_kitchen_mode', 'restaurant_confirm_orders'];
$snap = [];
foreach ($keys as $k) $snap[$k] = $val("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$k]);
$origMode = $val("SELECT pos_mode FROM warehouses WHERE warehouse_id = ?", [$W]);

try {
    $setSetting('pos_simple_mode', '1');
    $setSetting('restaurant_kitchen_mode', 'screen');
    $setSetting('restaurant_confirm_orders', '0');
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W]);

    section('Schema + rights (migration ran)');
    ok((bool)$val("SELECT permission_id FROM permissions WHERE page_key = 'restaurant_orders'") && (bool)$val("SELECT permission_id FROM permissions WHERE page_key = 'kitchen_display'"), 'permission rows present');
    $type = (string)($pdo->query("SHOW COLUMNS FROM kitchen_tickets LIKE 'status'")->fetch(PDO::FETCH_ASSOC)['Type'] ?? '');
    ok(strpos($type, "'pending'") !== false && strpos($type, "'cancelled'") !== false, 'ticket statuses include pending + cancelled');
    require_once "$root/core/feature_registry.php";
    ok(in_array('restaurant_pos', featureForPageKey('restaurant_orders'), true) && in_array('restaurant_pos', featureForPageKey('kitchen_display'), true), 'both rights belong to the Restaurant POS module');
    require_once "$root/core/role_permission_ui.php";
    ok(rolePermissionHasWorkflow('restaurant_orders'), 'Restaurant Orders shows the Approve box on Roles & Permissions');
    $evt = $pdo->query("SELECT page_key, required_verb FROM notification_events WHERE event_key = 'restaurant.order_to_confirm'")->fetch(PDO::FETCH_ASSOC);
    ok($evt && $evt['page_key'] === 'restaurant_orders' && $evt['required_verb'] === 'approve', 'notification "order waiting for the cashier" goes to confirmers');

    // Ready-made roles: create in a transaction, check, roll back.
    require_once "$root/core/restaurant_order_flow_schema.php";
    $pdo->beginTransaction();
    try {
        foreach (array_keys(rofsReadyRoles()) as $n) $pdo->prepare("UPDATE roles SET role_name = CONCAT(role_name, '__x') WHERE role_name = ?")->execute([$n]);
        $made = rofsEnsureReadyRoles($pdo, function () {});
        ok($made === ['Waiter', 'Cashier', 'Cook', 'Restaurant Manager'], 'four ready-made roles created when missing');
        $wr = (int)$val("SELECT role_id FROM roles WHERE role_name = 'Waiter'");
        $g = $pdo->prepare("SELECT p.page_key, rp.can_create, rp.can_approve FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id WHERE rp.role_id = ?");
        $g->execute([$wr]);
        $wg = []; foreach ($g->fetchAll(PDO::FETCH_ASSOC) as $r) $wg[$r['page_key']] = $r;
        ok(!empty($wg['restaurant_orders']['can_create']) && empty($wg['restaurant_orders']['can_approve']) && empty($wg['pos']['can_create']), 'Waiter: takes orders, cannot confirm, cannot sell');
        ok(rofsEnsureReadyRoles($pdo, function () {}) === [], 'running again creates nothing (idempotent)');
    } finally {
        $pdo->rollBack();
    }

    section('Endpoint gates (subprocess, real session)');
    $base = ['user_id' => 11, 'is_admin' => false, 'role_id' => 999, 'user_lang' => 'en', 'csrf_token' => 'test-csrf', 'scope' => ['is_admin' => true]];
    $perm = fn(array $map) => array_map(fn($verbs) => array_fill_keys($verbs, true) + array_fill_keys(['view', 'create', 'edit', 'delete', 'review', 'approve'], false), $map);
    $F = ['pos' => true, 'restaurant_pos' => true];
    $waiter = $base + ['permissions' => $perm(['pos' => ['view'], 'restaurant_pos' => ['view'], 'restaurant_orders' => ['view', 'create', 'edit']])];
    $cook   = ['user_id' => 9] + $base + ['permissions' => $perm(['restaurant_pos' => ['view'], 'kitchen_display' => ['view', 'edit']])];
    $r = $worker(['page' => 'api/pos/process_sale.php', 'session' => $waiter, 'features' => $F, 'post' => ['x' => 1]]);
    ok(isset($r['json']['success']) && $r['json']['success'] === false && stripos($r['json']['message'] ?? '', 'permission') !== false, 'waiter cannot take payment (process_sale refuses)');
    $r = $worker(['page' => 'api/restaurant/get_kitchen_tickets.php', 'session' => $waiter, 'features' => $F, 'get' => ['warehouse_id' => $W]]);
    ok(($r['json']['success'] ?? null) === false, 'waiter does not get the kitchen screen');
    $r = $worker(['page' => 'api/restaurant/get_kitchen_tickets.php', 'session' => $cook, 'features' => $F, 'get' => ['warehouse_id' => $W]]);
    ok(($r['json']['success'] ?? null) === true, 'cook gets the kitchen screen' . (($r['json']['success'] ?? null) === true ? '' : ' — ' . substr($r['out'] . $r['err'], 0, 300)));
    $r = $worker(['page' => 'api/restaurant/send_to_kitchen.php', 'session' => $cook, 'features' => $F, 'post' => ['hold_id' => 1, 'warehouse_id' => $W, '_csrf' => 'test-csrf']]);
    ok(($r['json']['success'] ?? null) === false && ($r['json']['message'] ?? '') === 'Permission denied', 'cook cannot send orders' . (($r['json']['message'] ?? '') === 'Permission denied' ? '' : ' — ' . substr($r['out'] . $r['err'], 0, 300)));
    $r = $worker(['page' => 'api/restaurant/order_flow.php', 'session' => $waiter, 'features' => $F, 'get' => ['action' => 'pending', 'warehouse_id' => $W]]);
    ok(($r['json']['success'] ?? null) === false, 'waiter cannot list orders to confirm');
    $r = $worker(['page' => 'api/restaurant/order_flow.php', 'session' => $waiter, 'features' => $F, 'get' => ['action' => 'count']]);
    ok(($r['json']['success'] ?? null) === true && ($r['json']['count'] ?? -1) === 0, 'waiting-orders badge: 0 for a waiter' . (($r['json']['success'] ?? null) === true ? '' : ' — ' . substr($r['out'] . $r['err'], 0, 300)));
    $r = $worker(['page' => 'api/restaurant/order_flow.php', 'session' => $waiter, 'features' => ['pos' => true, 'restaurant_pos' => false], 'get' => ['action' => 'count']]);
    ok(($r['json']['success'] ?? null) === false, 'Restaurant POS off → refused');
    foreach (['api/restaurant/order_flow.php', 'api/restaurant/send_to_kitchen.php', 'api/restaurant/get_kitchen_tickets.php'] as $pg) {
        ok(!preg_match('/PHP (Warning|Notice|Fatal)|___EXC___/', $worker(['page' => $pg, 'session' => $cook, 'features' => $F, 'get' => ['warehouse_id' => $W, 'action' => 'count']])['err']), "$pg: no PHP warnings");
    }

    section('Pages (subprocess renders, admin)');
    $adminId = (int)$val("SELECT user_id FROM users WHERE is_active = 1 AND role_id = 1 ORDER BY user_id LIMIT 1");
    $sess = ['user_id' => $adminId, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en', 'csrf_token' => 'test-csrf'];
    $clean = fn($r) => !preg_match('/PHP (Warning|Notice|Fatal|Parse error)|___EXC___/', $r['err'] . $r['out']);
    $setSetting('restaurant_confirm_orders', '1');
    $r = $worker(['page' => 'app/bms/pos/pos.php', 'session' => $sess, 'features' => $F]);
    ok(strpos($r['out'], 'const POS_FLOW = {"on":true') !== false && strpos($r['out'], 'id="rofConfirmModal"') !== false && strpos($r['out'], 'id="rofStrip"') !== false, 'till: flow rights, confirm window and kitchen list present');
    ok(strpos($r['out'], 'id="processPaymentBtn"') !== false && strpos($r['out'], 'id="rofNoPayNote"') === false, 'admin keeps Pay (no waiter note)');
    ok($clean($r), 'till renders with no PHP warnings');
    $r = $worker(['page' => 'app/bms/pos/pos.php', 'session' => $sess, 'features' => ['pos' => true, 'restaurant_pos' => false]]);
    ok(strpos($r['out'], 'const POS_FLOW = {"on":false}') !== false && strpos($r['out'], 'id="rofStrip"') === false && strpos($r['out'], 'id="processPaymentBtn"') !== false, 'Restaurant POS off: till exactly as before (no flow UI, Pay present)');
    $r = $worker(['page' => 'app/bms/restaurant/kitchen_dashboard.php', 'session' => $sess, 'features' => $F]);
    ok(strpos($r['out'], 'const KDS_TEAM = true;') !== false && $clean($r), 'kitchen screen: team flow known (stops at Ready), no warnings');
    $r = $worker(['page' => 'app/bms/restaurant/tables_board.php', 'session' => $sess, 'features' => $F]);
    ok(strpos($r['out'], 'function tbKitchen') !== false && $clean($r), 'tables board: food-state badges, no warnings');
    $r = $worker(['page' => 'app/bms/restaurant/waste_report.php', 'session' => $sess, 'features' => $F]);
    ok(strpos($r['out'], 'id="wasteTable"') !== false && $clean($r), 'waste report renders, no warnings');
    $r = $worker(['page' => 'api/restaurant/get_tables_board.php', 'session' => $sess, 'features' => $F, 'get' => ['warehouse_id' => $W]]);
    ok(($r['json']['success'] ?? null) === true && (!($r['json']['data'] ?? []) || array_key_exists('kitchen', $r['json']['data'][0])), 'tables board API carries each table\'s kitchen state');

    section('Selling Settings — the switch');
    $r = $worker(['page' => 'app/constant/settings/pos_config_settings.php', 'session' => $sess, 'features' => $F]);
    ok(strpos($r['out'], 'name="restaurant_confirm_orders"') !== false && $clean($r), 'switch shown in the Restaurant section');
    $rolesBefore = $pdo->query("SELECT role_name FROM roles")->fetchAll(PDO::FETCH_COLUMN);
    $baseSet = ['_csrf' => 'test-csrf', 'pos_discount_type' => get_setting('pos_discount_type', 'percentage'), 'pos_products_display_limit' => '20',
                'pos_products_display_limit_mobile' => '10', 'pos_receipt_width' => get_setting('pos_receipt_width', '80'), 'pos_old_sale_max_days' => get_setting('pos_old_sale_max_days', '30'),
                'restaurant_order_take_away' => '1', 'restaurant_order_delivery' => '1'];
    if (get_setting('pos_auto_print_receipt', '0') === '1') $baseSet['pos_auto_print_receipt'] = '1';
    $made = [];
    try {
        $setSetting('restaurant_confirm_orders', '0');
        $r = $worker(['page' => 'app/constant/settings/pos_config_settings.php', 'session' => $sess, 'features' => $F, 'post' => $baseSet + ['restaurant_kitchen_mode' => 'screen', 'restaurant_confirm_orders' => '1']]);
        ok($val("SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_confirm_orders'") === '1', 'switch on → saved');
        $made = array_values(array_diff($pdo->query("SELECT role_name FROM roles")->fetchAll(PDO::FETCH_COLUMN), $rolesBefore));
        $missing = array_diff(['Waiter', 'Cashier', 'Cook', 'Restaurant Manager'], $rolesBefore);
        ok(!array_diff($missing, $made), 'turning it on adds the missing ready-made roles (' . ($made ? implode(', ', $made) : 'all existed') . ')');
        $r = $worker(['page' => 'app/constant/settings/pos_config_settings.php', 'session' => $sess, 'features' => $F, 'post' => $baseSet + ['restaurant_kitchen_mode' => 'none', 'restaurant_confirm_orders' => '1']]);
        ok($val("SELECT setting_value FROM system_settings WHERE setting_key = 'restaurant_confirm_orders'") === '0', '"By mouth" → the switch cannot stay on (no kitchen step)');
    } finally {
        foreach ($made as $n) {
            $rid = (int)$val("SELECT role_id FROM roles WHERE role_name = ? ORDER BY role_id DESC LIMIT 1", [$n]);
            if ($rid) { $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$rid]); $pdo->prepare("DELETE FROM roles WHERE role_id = ?")->execute([$rid]); }
        }
        $setSetting('restaurant_kitchen_mode', 'screen');
        $setSetting('restaurant_confirm_orders', '0');
    }

    section('In-process scenarios (one rolled-back transaction)');
    $child = (string)shell_exec('php ' . escapeshellarg(__FILE__) . ' run 2>&1');
    echo $child;
    preg_match('/Passes:\s+(?:\e\[\d+m)?(\d+)/', $child, $pm); preg_match('/Failures:\s+(?:\e\[\d+m)?(\d+)/', $child, $fm);
    $cp = (int)($pm[1] ?? 0); $cf = isset($fm[1]) ? (int)$fm[1] : 1;
    ok($cp > 40 && $cf === 0, "child scenarios: $cp passed, $cf failed");
} finally {
    foreach ($snap as $k => $v) $setSetting($k, $v === false ? null : $v);
    $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$origMode, $W]);
    $after = [];
    foreach ($keys as $k) $after[$k] = $val("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$k]);
    ok($after == $snap, 'settings restored exactly');
}
echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail > 0 ? 1 : 0);
