<?php
/**
 * Staff half of tests/test_restaurant_online_menu_cli.php (included by it — uses its
 * fixture, http(), order() and ok()). Real HTTP as a logged-in admin.
 */
$S = ['X-Bms-Test-Staff: 1'];
$sget = fn(string $path) => http('GET', $path, null, $S);
$spost = fn(string $path, array $f, bool $csrf = true) => http('POST', $path, http_build_query($csrf ? $f + ['_csrf' => 'testcsrf'] : $f), $S, 'application/x-www-form-urlencoded');
$j = fn(array $r) => json_decode($r[2], true) ?: ['raw' => substr($r[2], 0, 300)];

echo "\n── 7. Staff: share settings and links ──\n";
[$c, , $b] = http('GET', "/api/restaurant/online_menu.php?warehouse_id=$wid");
ok($c === 401, 'settings API without login → 401');
$r = $j($sget("/api/restaurant/online_menu.php?warehouse_id=$wid"));
ok(!empty($r['success']) && $r['data']['enabled'] && str_ends_with((string)$r['data']['link'], "/m/$shopTok"), 'settings API returns the menu link');
ok(count($r['data']['tables']) === 2 && str_ends_with((string)$r['data']['tables'][0]['link'], "/m/$t5Tok"), 'one link per table');
$t7 = (int)$pdo->query("SELECT MAX(table_id) FROM restaurant_tables")->fetchColumn();
$pdo->prepare("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status) VALUES (?, (SELECT floor_id FROM restaurant_floors WHERE warehouse_id = ? LIMIT 1), '7', 2, 'available')")->execute([$wid, $wid]);
$r = $j($sget("/api/restaurant/online_menu.php?warehouse_id=$wid"));
ok(count($r['data']['tables']) === 3 && !empty($r['data']['tables'][2]['link']), 'a table added later gets its QR link automatically');
[$c, ] = $spost('/api/restaurant/online_menu.php', ['action' => 'save', 'warehouse_id' => $wid, 'enabled' => 1, 'order_table' => 1, 'order_pickup' => 0], false);
ok($c === 419, 'saving without the CSRF token is refused');
$r = $j($spost('/api/restaurant/online_menu.php', ['action' => 'save', 'warehouse_id' => $wid, 'enabled' => 1, 'order_table' => 1, 'order_pickup' => 0]));
$wrow = $pdo->query("SELECT online_order_pickup, online_order_table FROM warehouses WHERE warehouse_id = $wid")->fetch(PDO::FETCH_ASSOC);
ok(!empty($r['success']) && (int)$wrow['online_order_pickup'] === 0 && (int)$wrow['online_order_table'] === 1, 'switches saved (pickup off, table on)');
$spost('/api/restaurant/online_menu.php', ['action' => 'save', 'warehouse_id' => $wid, 'enabled' => 1, 'order_table' => 1, 'order_pickup' => 1]);
$r = $j($spost('/api/restaurant/online_menu.php', ['action' => 'save', 'warehouse_id' => 999999999, 'enabled' => 1]));
ok(empty($r['success']), 'a shop id that is not a restaurant / not in scope is refused');

$r = $j($spost('/api/restaurant/online_menu.php', ['action' => 'rotate', 'warehouse_id' => $wid]));
$newShop = substr((string)($r['data']['link'] ?? ''), -32);
$newT5   = substr((string)($r['data']['tables'][0]['link'] ?? ''), -32);
ok(!empty($r['success']) && roTokenLooksValid($newShop) && $newShop !== $shopTok && $newT5 !== $t5Tok, 'Make new links → new shop and table links');
[$c1, ] = http('GET', "/m/$t5Tok"); [$c2, ] = http('GET', "/m/$shopTok"); [$c3, ] = http('GET', "/m/$newT5");
ok($c1 === 404 && $c2 === 404 && $c3 === 200, 'old links and printed QR codes stop working; the new ones work');
$shopTok = $newShop; $t5Tok = $newT5;
$t6Tok = substr((string)$r['data']['tables'][1]['link'], -32);

[$c, , $b] = $sget("/menu_qr?warehouse_id=$wid");
ok($c === 200 && substr_count($b, 'data-link="') === 4 && str_contains($b, "/m/$t5Tok"), 'print page has a QR card per table + the shop card');
[$c, , $b] = $sget('/menu');
ok($c === 200 && str_contains($b, 'openShareMenu()') && str_contains($b, 'id="shareMenuModal"'), 'Menu page shows the Share menu button and panel');

echo "\n── 8. Staff: accept into a table that already has an order ──\n";
$pdo->exec("UPDATE restaurant_online_orders SET status = 'completed' WHERE warehouse_id = $wid");
// The waiter already took an order for table 5.
$waiterItems = [['product_id' => $pSoda, 'product_name' => FX . ' Soda', 'price' => 1000, 'quantity' => 2, 'tax_rate' => 0, 'discounted_price' => 1000]];
$pdo->prepare("INSERT INTO pos_held_sales (user_id, shift_id, warehouse_id, table_id, hold_reference, items_data, subtotal, tax_amount, total_amount, held_at, status)
               VALUES (?, NULL, ?, ?, 'Table 5', ?, 2000, 0, 2000, NOW() - INTERVAL 5 MINUTE, 'held')")->execute([$admin, $wid, $t5, json_encode($waiterItems)]);
$waiterHold = (int)$pdo->lastInsertId();
[$c, $r] = order($t5Tok, [['id' => $pChips, 'qty' => 1, 'options' => [$oRice]]]);
ok($c === 200, 'customer at table 5 orders Chips Kuku');
$oid = (int)$pdo->query("SELECT MAX(order_id) FROM restaurant_online_orders WHERE warehouse_id = $wid")->fetchColumn();
$cnt = $j($sget('/api/restaurant/online_orders.php?action=count'));
ok(($cnt['pending'] ?? 0) >= 1 && (int)($cnt['latest']['order_id'] ?? 0) === $oid, 'the alert count sees the new order');
$lst = $j($sget("/api/restaurant/online_orders.php?action=list&warehouse_id=$wid"));
$mine = array_values(array_filter($lst['data'] ?? [], fn($o) => $o['order_id'] === $oid))[0] ?? null;
ok($mine && $mine['status'] === 'pending' && $mine['table'] === '5' && $mine['lines'][0]['options'] === ['Rice'], 'Online orders list shows it as New, table 5, with its choice');
[$c, ] = $spost('/api/restaurant/online_orders.php', ['action' => 'accept', 'order_id' => $oid], false);
ok($c === 419, 'accept without CSRF is refused');
[$c, ] = http('POST', '/api/restaurant/online_orders.php', http_build_query(['action' => 'accept', 'order_id' => $oid]), [], 'application/x-www-form-urlencoded');
ok($c === 401, 'accept without login is refused');
$r = $j($spost('/api/restaurant/online_orders.php', ['action' => 'accept', 'order_id' => $oid]));
ok(!empty($r['success']) && (int)$r['hold_id'] > $waiterHold, 'accepted → saved as a new version of the table order' . (empty($r['success']) ? ' — ' . json_encode($r) : ''));
$newHold = (int)($r['hold_id'] ?? 0);
$h = $pdo->query("SELECT * FROM pos_held_sales WHERE hold_id = $newHold")->fetch(PDO::FETCH_ASSOC);
$hi = json_decode((string)($h['items_data'] ?? '[]'), true);
ok($h && $h['status'] === 'held' && (int)$h['table_id'] === $t5 && count($hi) === 2 && abs((float)$h['subtotal'] - 9000) < 0.01, "table 5's order = waiter's 2 sodas + the customer's dish (9000)");
ok((int)($hi[1]['online_order_id'] ?? 0) === $oid && ($hi[1]['modifiers'][0]['option_name'] ?? '') === 'Rice', 'the online line is marked with its order and keeps the choice');
ok($pdo->query("SELECT status FROM pos_held_sales WHERE hold_id = $waiterHold")->fetchColumn() === 'cancelled', 'the old version is closed (one open order per table)');
ok($pdo->query("SELECT status FROM restaurant_tables WHERE table_id = $t5")->fetchColumn() === 'occupied', 'table 5 is occupied');
$r2 = $j($spost('/api/restaurant/online_orders.php', ['action' => 'accept', 'order_id' => $oid]));
ok(empty($r2['success']) && !empty($r2['refresh']), 'accepting twice is refused (already handled)');
[, , $sb] = http('GET', "/m/$t5Tok/o/" . $pdo->query("SELECT public_ref FROM restaurant_online_orders WHERE order_id = $oid")->fetchColumn() . '/status');
ok((json_decode($sb, true)['status'] ?? '') === 'accepted', "the customer's status page now says accepted");

echo "\n── 9. The till cannot drop an accepted online order ──\n";
// A waiter whose till still shows the OLD version saves it (2 sodas → 3 sodas).
$stale = [['product_id' => $pSoda, 'product_name' => FX . ' Soda', 'price' => 1000, 'quantity' => 3, 'tax_rate' => 0, 'discounted_price' => 1000]];
$hr = $j(http('POST', '/api/pos/hold_sale.php', json_encode(['reference' => 'Table 5', 'items' => $stale, 'subtotal' => 3000, 'tax' => 0, 'warehouse_id' => $wid, 'table_id' => $t5, 'loaded_hold_id' => $waiterHold]), $S));
$cur = $pdo->query("SELECT * FROM pos_held_sales WHERE table_id = $t5 AND status = 'held' ORDER BY hold_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$ci = json_decode((string)$cur['items_data'], true);
ok(!empty($hr['success']) && count($ci) === 2 && (int)$ci[0]['quantity'] === 3 && (int)($ci[1]['online_order_id'] ?? 0) === $oid && abs((float)$cur['subtotal'] - 10000) < 0.01,
   "saving an out-of-date copy keeps the waiter's change AND puts the customer's dish back (10000)" . (empty($hr['success']) ? ' — ' . json_encode($hr) : ''));
// An app that does not send loaded_hold_id at all is treated the same way (safety first).
$hr = $j(http('POST', '/api/pos/hold_sale.php', json_encode(['reference' => 'Table 5', 'items' => $stale, 'subtotal' => 3000, 'tax' => 0, 'warehouse_id' => $wid, 'table_id' => $t5]), $S));
$ci = json_decode((string)$pdo->query("SELECT items_data FROM pos_held_sales WHERE table_id = $t5 AND status = 'held' ORDER BY hold_id DESC LIMIT 1")->fetchColumn(), true);
ok(count($ci) === 2, 'a save that does not say which version it loaded also keeps the online line');
// Kitchen gets the whole table order.
$curHold = (int)$pdo->query("SELECT hold_id FROM pos_held_sales WHERE table_id = $t5 AND status = 'held' ORDER BY hold_id DESC LIMIT 1")->fetchColumn();
$k = $j($spost('/api/restaurant/send_to_kitchen.php', ['hold_id' => $curHold, 'warehouse_id' => $wid]));
$kt = $pdo->query("SELECT kti.quantity FROM kitchen_tickets kt JOIN kitchen_ticket_items kti ON kti.ticket_id = kt.ticket_id WHERE kt.warehouse_id = $wid AND kti.product_id = $pChips")->fetchColumn();
ok(!empty($k['success']) && (float)$kt === 1.0, 'send to kitchen: the customer\'s dish reaches the kitchen ticket' . (empty($k['success']) ? ' — ' . json_encode($k) : ''));
// The waiter deliberately removes it from the CURRENT version → respected.
$hr = $j(http('POST', '/api/pos/hold_sale.php', json_encode(['reference' => 'Table 5', 'items' => $stale, 'subtotal' => 3000, 'tax' => 0, 'warehouse_id' => $wid, 'table_id' => $t5, 'loaded_hold_id' => $curHold]), $S));
$ci = json_decode((string)$pdo->query("SELECT items_data FROM pos_held_sales WHERE table_id = $t5 AND status = 'held' ORDER BY hold_id DESC LIMIT 1")->fetchColumn(), true);
ok(!empty($hr['success']) && count($ci) === 1, 'a waiter editing the current version can remove it on purpose');
$lst = $j($sget("/api/restaurant/online_orders.php?action=list&warehouse_id=$wid"));
ok($pdo->query("SELECT status FROM restaurant_online_orders WHERE order_id = $oid")->fetchColumn() === 'completed', '…and that online order then counts as done (no longer on the table)');

echo "\n── 10. Staff: pickup, reject, ready, done ──\n";
[$c, $r] = order($shopTok, [['id' => $pPilau, 'qty' => 2]], ['name' => 'Juma', 'phone' => '0712 000 111']);
$pick = (int)$pdo->query("SELECT MAX(order_id) FROM restaurant_online_orders WHERE warehouse_id = $wid")->fetchColumn();
$pickRef = (string)$pdo->query("SELECT public_ref FROM restaurant_online_orders WHERE order_id = $pick")->fetchColumn();
$r = $j($spost('/api/restaurant/online_orders.php', ['action' => 'accept', 'order_id' => $pick]));
$ph = $pdo->query("SELECT * FROM pos_held_sales WHERE hold_id = " . (int)($r['hold_id'] ?? 0))->fetch(PDO::FETCH_ASSOC);
ok(!empty($r['success']) && $ph && $ph['table_id'] === null && (int)$ph['user_id'] === $admin && str_contains((string)$ph['hold_reference'], 'Juma') && abs((float)$ph['subtotal'] - 12000) < 0.01,
   'pickup accepted → a held order on the till for this staff member ("ONLINE W-… · Juma", 12000)' . (empty($r['success']) ? ' — ' . json_encode($r) : ''));
$r = $j($spost('/api/restaurant/online_orders.php', ['action' => 'complete', 'order_id' => $pick]));
ok(!empty($r['success']), 'accepted → done');
$r = $j($spost('/api/restaurant/online_orders.php', ['action' => 'ready', 'order_id' => $pick]));
ok(empty($r['success']), 'done → ready is refused (status only moves forward)');

[$c, $r] = order($t6Tok, [['id' => $pSoda, 'qty' => 1]]);
$rej = (int)$pdo->query("SELECT MAX(order_id) FROM restaurant_online_orders WHERE warehouse_id = $wid")->fetchColumn();
$rejRef = (string)$pdo->query("SELECT public_ref FROM restaurant_online_orders WHERE order_id = $rej")->fetchColumn();
$r = $j($spost('/api/restaurant/online_orders.php', ['action' => 'reject', 'order_id' => $rej, 'reason' => '<b>Sold out</b> today']));
ok(!empty($r['success']) && $pdo->query("SELECT status FROM restaurant_online_orders WHERE order_id = $rej")->fetchColumn() === 'rejected', 'reject with a reason');
[$c, , $b] = http('GET', "/m/$t6Tok/o/$rejRef");
ok($c === 200 && str_contains($b, '&lt;b&gt;Sold out&lt;/b&gt; today') && !str_contains($b, '<b>Sold out</b>'), 'the customer sees the reason, escaped');
$r = $j($spost('/api/restaurant/online_orders.php', ['action' => 'accept', 'order_id' => $rej]));
ok(empty($r['success']), 'a rejected order cannot be accepted afterwards');
ok((int)$pdo->query("SELECT COUNT(*) FROM pos_held_sales WHERE table_id = $t6 AND status = 'held'")->fetchColumn() === 0, 'a rejected order never touched the table');

[$c, , $b] = $sget('/online_orders');
ok($c === 200 && str_contains($b, 'id="ooList"'), 'Online orders page opens for staff');
[$c, , $b] = $sget('/dashboard');
ok($c === 200 && str_contains($b, "getUrl('online_orders')") === false && str_contains($b, '/online_orders'), 'the staff menu links to Online orders once a shop is online');
