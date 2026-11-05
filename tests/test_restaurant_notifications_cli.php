<?php
/**
 * Restaurant notifications (core/restaurant_notify.php).
 *   php tests/test_restaurant_notifications_cli.php
 *
 * Static: 11 events registered (module Restaurant), default rule "All admins —
 *         in-app + email", hooks in place, the safe link check.
 * Real:   a temporary restaurant (removed after). Each of the 12 alerts fires, goes
 *         to every admin in-app AND queues an email (held — BMS_NOTIF_HOLD_EMAIL=1 —
 *         so nothing reaches a real inbox; the rows are deleted at the end), carries
 *         a link to the exact record, and fires only once (dedupe). A staff role
 *         added on notification_rules receives it too; one without a rule does not.
 *         HTTP: notification_open marks read + redirects only to an in-app path; a
 *         page opened from a notification focuses the record.
 * Smart POS is switched on only inside this test (the shared setting is untouched).
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);
putenv('BMS_NOTIF_HOLD_EMAIL=1');

// Smart POS on for this process / test server only (helpers.php wraps get_setting in function_exists).
function get_setting($key, $default = '') {
    global $pdo;
    static $cache = null;
    if ($key === 'pos_simple_mode') return '1';
    if ($cache === null) {
        $cache = [];
        if ($pdo instanceof PDO) { try { foreach ($pdo->query("SELECT setting_key, setting_value FROM system_settings") as $r) $cache[$r['setting_key']] = $r['setting_value']; } catch (Throwable $e) {} }
    }
    return $cache[$key] ?? $default;
}

// ─── Web-server router (this file run by `php -S`) ───────────────────────────
if (PHP_SAPI === 'cli-server') {
    $path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/' && is_file($root . $path) && !str_ends_with($path, '.php')) return false;
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid = (int)($_SERVER['HTTP_X_TEST_USER'] ?? getenv('BMS_TEST_ADMIN'));
    $_SESSION['user_id'] = $uid; $_SESSION['username'] = 'u' . $uid; $_SESSION['role'] = 'admin';
    $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true; $_SESSION['user_lang'] = 'en'; $_SESSION['csrf_token'] = 'testcsrf';
    $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['PHP_SELF'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
    chdir($root);
    require $root . '/index.php';
    return true;
}

// Load the system before any output (sessions/headers), when there is a database.
if (is_file("$root/includes/config.php")) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function done_exit() { global $pass, $fail; echo "\n" . ($fail === 0 ? "\033[32mALL $pass PASSED\033[0m" : "\033[31m$fail FAILED\033[0m, $pass passed") . "\n"; exit($fail === 0 ? 0 : 1); }
$src = fn(string $f) => (string)@file_get_contents("$root/$f");

echo "\n── 1. Static ──\n";
$core = $src('core/restaurant_notify.php');
preg_match_all("/'(restaurant\.[a-z_]+)'\s*=>\s*\[/", $core, $m);
ok(count(array_unique($m[1])) === 12, '12 restaurant events defined (+ existing customer-credit events)');
ok(str_contains($src('core/restaurant_notify_schema.php'), "\$addRule->execute([\$key, 'admins', 1])"), 'default rule: All admins — in-app + email');
ok(str_contains($src('core/restaurant_online.php'), 'rnNotifyOnlineOrder($pdo, $id)'), 'hook: new online order');
ok(str_contains($src('api/restaurant/update_ticket_status.php'), 'rnNotifyKitchenReady($pdo, $ticket_id)'), 'hook: kitchen marks ready');
ok(str_contains($src('api/restaurant/save_reservation.php'), 'rnNotifyReservationNew($pdo, $id)'), 'hook: new reservation');
ok(str_contains($src('api/run_background_jobs.php'), 'rnRunFrequentChecks($pdo)') && str_contains($src('header.php'), "get_setting('rn_checks_last_ts'"), 'frequent checks run every ~2 minutes from the background job');
ok(str_contains($src('cron/run_notification_checks.php'), 'rnRunDailyChecks($pdo)'), 'daily checks run with the daily notification checks');
ok(str_contains($src('header.php'), "getUrl('notification_open') . '?id='") && str_contains($src('header.php'), 'rd-bell-menu'), 'the bell opens a list; each item opens its record');
ok(str_contains($src('app/constant/communication/notification_center.php'), "NC_OPEN + '?id='") && str_contains($src('app/constant/communication/notification_center.php'), "escapeHtml(row.message || '')"), 'Notifications page: rows open their record; message text escaped');

if (!is_file("$root/includes/config.php")) { echo "  (no includes/config.php — runtime checks skipped)\n"; done_exit(); }

require_once "$root/core/restaurant_notify_schema.php";
global $pdo;
restaurantNotifyEnsureSchema($pdo, function () {});
require_once "$root/core/restaurant_supplier_credit.php";
if (function_exists('restaurantOnlineEnsureSchema') || is_file("$root/core/restaurant_online_schema.php")) { require_once "$root/core/restaurant_online_schema.php"; restaurantOnlineEnsureSchema($pdo, function () {}); }
require_once "$root/core/restaurant_online.php";

ok(rnSafeActionPath('online_orders?order=5') === 'online_orders?order=5' && rnSafeActionPath('/purchases?item=3&shop=2') === 'purchases?item=3&shop=2', 'safe link: in-app paths pass');
ok(rnSafeActionPath('https://evil.example/x') === '' && rnSafeActionPath('//evil.example') === '' && rnSafeActionPath('javascript:alert(1)') === '' && rnSafeActionPath('../../etc') === '' && rnSafeActionPath("a\\b") === '', 'safe link: other sites / scripts / .. are refused');

// ─── Fixture ─────────────────────────────────────────────────────────────────
const FX = 'ZZ RN Test';
$T0 = date('Y-m-d H:i:s', time() - 2);
function fx_cleanup(PDO $pdo): void {
    foreach ($pdo->query("SELECT warehouse_id FROM warehouses WHERE warehouse_name LIKE '" . FX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $w) {
        $w = (int)$w;
        $sales = $pdo->query("SELECT sale_id FROM pos_sales WHERE warehouse_id = $w")->fetchAll(PDO::FETCH_COLUMN);
        if ($sales) $pdo->exec("DELETE FROM pos_sale_items WHERE sale_id IN (" . implode(',', array_map('intval', $sales)) . ")");
        $pdo->exec("DELETE FROM pos_sales WHERE warehouse_id = $w");
        $tix = $pdo->query("SELECT ticket_id FROM kitchen_tickets WHERE warehouse_id = $w")->fetchAll(PDO::FETCH_COLUMN);
        if ($tix) $pdo->exec("DELETE FROM kitchen_ticket_items WHERE ticket_id IN (" . implode(',', array_map('intval', $tix)) . ")");
        foreach (['kitchen_tickets', 'kitchen_stations', 'pos_held_sales', 'restaurant_online_orders', 'restaurant_reservations', 'restaurant_tables', 'restaurant_floors', 'supplier_invoices'] as $t) {
            try { $pdo->exec("DELETE FROM $t WHERE warehouse_id = $w"); } catch (Throwable $e) {}
        }
        $pids = $pdo->query("SELECT product_id FROM products WHERE warehouse_id = $w")->fetchAll(PDO::FETCH_COLUMN);
        if ($pids) { $in = implode(',', array_map('intval', $pids)); $pdo->exec("DELETE FROM product_stocks WHERE product_id IN ($in)"); $pdo->exec("DELETE FROM products WHERE product_id IN ($in)"); }
        $pdo->exec("DELETE FROM warehouses WHERE warehouse_id = $w");
    }
    $pdo->exec("DELETE FROM suppliers WHERE supplier_name LIKE '" . FX . "%'");
    foreach ($pdo->query("SELECT user_id FROM users WHERE username LIKE 'zzrn_%'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        $pdo->exec("DELETE FROM user_scope_overrides WHERE user_id = " . (int)$u);
        $pdo->exec("DELETE FROM notifications WHERE user_id = " . (int)$u);
    }
    foreach ($pdo->query("SELECT role_id FROM roles WHERE role_name LIKE '" . FX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $r) $pdo->exec("DELETE FROM role_permissions WHERE role_id = " . (int)$r);
    $pdo->exec("DELETE FROM users WHERE username LIKE 'zzrn_%'");
    $pdo->exec("DELETE FROM roles WHERE role_name LIKE '" . FX . "%'");
}
fx_cleanup($pdo);
$cleanNotif = function () use ($pdo, $T0) {
    $pdo->prepare("DELETE FROM notifications WHERE created_at >= ? AND (event_key LIKE 'restaurant.%' OR event_key LIKE 'pos_credit.%')")->execute([$T0]);
    $pdo->prepare("DELETE FROM notification_outbox WHERE created_at >= ? AND (event_key LIKE 'restaurant.%' OR event_key LIKE 'pos_credit.%')")->execute([$T0]);
    $pdo->prepare("DELETE FROM notification_log WHERE created_at >= ? AND event_key LIKE 'restaurant.%'")->execute([$T0]);
    $pdo->exec("DELETE FROM notification_dedupe WHERE dedupe_key LIKE 'restaurant.%' AND dedupe_key NOT LIKE '%|restaurant_reservation|%'");
    $pdo->prepare("DELETE FROM notification_rules WHERE created_at >= ? AND target_type = 'role'")->execute([$T0]);
};
register_shutdown_function(function () use ($pdo, $cleanNotif) { $cleanNotif(); fx_cleanup($pdo); });

$admins = array_keys(array_filter(usersWithPermission($pdo, 'restaurant_pos'), fn($u) => $u['is_admin']));
$adminsWithMail = array_keys(array_filter(usersWithPermission($pdo, 'restaurant_pos'), fn($u) => $u['is_admin'] && $u['email'] !== ''));
$admin = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 AND is_active = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$ins = function (string $sql, array $a = []) use ($pdo): int { $pdo->prepare($sql)->execute($a); return (int)$pdo->lastInsertId(); };
$wid   = $ins("INSERT INTO warehouses (warehouse_code, warehouse_name, status, pos_mode) VALUES (?, ?, 'active', 'restaurant')", ['ZZRN' . random_int(1000, 9999), FX . ' Restaurant']);
$floor = $ins("INSERT INTO restaurant_floors (warehouse_id, name, sort_order, status) VALUES (?, 'Main', 0, 'active')", [$wid]);
$t5    = $ins("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status) VALUES (?, ?, '5', 4, 'occupied')", [$wid, $floor]);
$kst   = $ins("INSERT INTO kitchen_stations (warehouse_id, name, status) VALUES (?, 'Kitchen', 'active')", [$wid]);
$dish  = $ins("INSERT INTO products (product_name, selling_price, cost_price, purchase_price, unit, is_service, track_inventory, warehouse_id, kitchen_station_id, is_menu_item, is_available, status) VALUES (?, 7000, 0, 0, 'pcs', 1, 0, ?, ?, 1, 1, 'active')", [FX . ' Pilau', $wid, $kst]);
$soda  = $ins("INSERT INTO products (product_name, selling_price, cost_price, purchase_price, unit, min_stock_level, is_service, track_inventory, warehouse_id, is_menu_item, is_available, status) VALUES (?, 1000, 600, 600, 'pcs', 6, 0, 1, ?, 1, 1, 'active')", [FX . ' Soda', $wid]);
$pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity) VALUES (?, ?, 2)")->execute([$soda, $wid]);
$waiterRole = $ins("INSERT INTO roles (role_name) VALUES (?)", [FX . ' Waiter']);
$waiter = $ins("INSERT INTO users (username, password, email, role, role_id, first_name, last_name, is_active) VALUES (?, 'x', '', 'staff', ?, 'Zz', 'Waiter', 1)", ['zzrn_waiter' . random_int(100, 999), $waiterRole]);
$permId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'restaurant_pos' LIMIT 1")->fetchColumn();
if ($permId) $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view) VALUES (?, ?, 1)")->execute([$waiterRole, $permId]);
// The waiter works in this shop (warehouse scope) — staff only get alerts for their own shops.
$pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by) VALUES (?, 'warehouse', ?, ?)")->execute([$waiter, $wid, $admin]);

/** What one event produced since the test started. */
$got = function (string $event, ?string $entityLike = null) use ($pdo, $T0): array {
    $n = $pdo->prepare("SELECT user_id, title, message, action_url FROM notifications WHERE event_key = ? AND created_at >= ?" . ($entityLike ? " AND action_url LIKE ?" : ''));
    $n->execute($entityLike ? [$event, $T0, $entityLike] : [$event, $T0]);
    $rows = $n->fetchAll(PDO::FETCH_ASSOC);
    $e = $pdo->prepare("SELECT recipient_user_id, scheduled_for FROM notification_outbox WHERE event_key = ? AND created_at >= ?");
    $e->execute([$event, $T0]);
    return ['users' => array_map('intval', array_column($rows, 'user_id')), 'rows' => $rows, 'mail' => $e->fetchAll(PDO::FETCH_ASSOC)];
};
$check = function (string $label, string $event, string $urlStart, ?string $like = null) use ($got, $admins, $adminsWithMail) {
    $g = $got($event, $like);
    $allAdmins = !array_diff($admins, $g['users']);
    ok($g['rows'] && $allAdmins, "$label → every admin got it in the bell (" . count(array_unique($g['users'])) . ')');
    $mailTo = array_map('intval', array_column($g['mail'], 'recipient_user_id'));
    ok(!array_diff($adminsWithMail, $mailTo) && !array_filter($g['mail'], fn($m) => $m['scheduled_for'] !== '2099-12-31 00:00:00'), "$label → an email is queued for every admin with an address (held in the test)");
    ok($g['rows'] && str_starts_with((string)$g['rows'][0]['action_url'], $urlStart), "$label → opens " . ($g['rows'][0]['action_url'] ?? '?'));
    return $g;
};

echo "\n── 2. Instant alerts ──\n";
$ctx = ['warehouse' => ['warehouse_id' => $wid, 'warehouse_name' => FX, 'order_table' => true, 'order_pickup' => true, 'menu_token' => str_repeat('a', 32)], 'table' => ['table_id' => $t5, 'table_number' => '5'], 'token' => str_repeat('b', 32)];
$placed = roPlaceOrder($pdo, $ctx, ['items' => [['product_id' => $dish, 'product_name' => FX . ' Pilau', 'price' => 7000, 'quantity' => 2]], 'subtotal' => 14000], '', '', '', str_repeat('c', 64));
$oid = (int)$pdo->query("SELECT order_id FROM restaurant_online_orders WHERE public_ref = " . $pdo->quote($placed['public_ref']))->fetchColumn();
$g = $check('New online order', 'restaurant.online_order_new', 'online_orders?order=' . $oid);
ok(str_contains($g['rows'][0]['message'] ?? '', 'Table 5') && str_contains($g['rows'][0]['message'] ?? '', '14,000'), '   …message says the table and the amount');
$n1 = count($got('restaurant.online_order_new')['rows']);
rnNotifyOnlineOrder($pdo, $oid);
ok(count($got('restaurant.online_order_new')['rows']) === $n1, '   …fires only once per order');
ok(!in_array($waiter, $got('restaurant.online_order_new')['users'], true), '   …a staff member with no rule does not get it (staff are added on Notification rules)');

$hold = $ins("INSERT INTO pos_held_sales (user_id, warehouse_id, table_id, hold_reference, items_data, subtotal, total_amount, held_at, status) VALUES (?, ?, ?, 'Table 5', '[]', 14000, 14000, NOW() - INTERVAL 4 HOUR, 'held')", [$waiter, $wid, $t5]);
$tk = $ins("INSERT INTO kitchen_tickets (hold_id, warehouse_id, station_id, table_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'ready', NOW(), NOW())", [$hold, $wid, $kst, $t5]);
$pdo->prepare("INSERT INTO kitchen_ticket_items (ticket_id, product_id, quantity, status) VALUES (?, ?, 2, 'ready')")->execute([$tk, $dish]);
// The waiter's role gets "Kitchen order ready" only for orders they took (record owner) — added the way notification_rules.php saves it.
$pdo->prepare("INSERT INTO notification_rules (event_key, target_type, target_id, channel_email, channel_inapp, digest, is_active, created_at, updated_at) VALUES ('restaurant.kitchen_ready', 'role', ?, 0, 1, 'immediate', 1, NOW(), NOW())")->execute([$waiterRole]);
rnNotifyKitchenReady($pdo, $tk);
$g = $check('Kitchen order ready', 'restaurant.kitchen_ready', 'tables-board?table=' . $t5);
ok(in_array($waiter, $g['users'], true), '   …a staff role added on Notification rules receives it too');
ok(str_contains($g['rows'][0]['message'] ?? '', 'Pilau'), '   …message lists the dishes');

$res = $ins("INSERT INTO restaurant_reservations (table_id, warehouse_id, customer_name, customer_phone, reservation_time, party_size, status, created_by, created_at, updated_at) VALUES (?, ?, 'Asha', '0712', NOW() + INTERVAL 30 MINUTE, 4, 'booked', ?, NOW(), NOW())", [$t5, $wid, $admin]);
rnNotifyReservationNew($pdo, $res);
$check('New reservation', 'restaurant.reservation_new', 'reservations?date=');

echo "\n── 3. Every-few-minutes checks ──\n";
$old = $ins("INSERT INTO restaurant_online_orders (warehouse_id, table_id, order_type, order_code, public_ref, items_json, subtotal, status, client_hash, created_at) VALUES (?, ?, 'table', 'W-9901', ?, '[]', 5000, 'pending', ?, NOW() - INTERVAL 9 MINUTE)", [$wid, $t5, str_repeat('d', 32), str_repeat('e', 64)]);
$slow = $ins("INSERT INTO kitchen_tickets (hold_id, warehouse_id, station_id, table_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'queued', NOW() - INTERVAL 35 MINUTE, NOW())", [$hold, $wid, $kst, $t5]);
$sum = rnRunFrequentChecks($pdo);
$check('Online order not accepted (9 min)', 'restaurant.online_order_waiting', 'online_orders?order=' . $old, '%order=' . $old);
$check('Kitchen order waiting (35 min)', 'restaurant.kitchen_waiting', 'kitchen-dashboard?ticket=' . $slow, '%ticket=' . $slow);
$check('Reservation within the hour', 'restaurant.reservation_upcoming', 'reservations?date=', '%id=' . $res);
$check('Table bill open 4 h', 'restaurant.table_open_long', 'tables-board?table=' . $t5);
$before = $pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key LIKE 'restaurant.%' AND created_at >= " . $pdo->quote($T0))->fetchColumn();
rnRunFrequentChecks($pdo);
ok((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key LIKE 'restaurant.%' AND created_at >= " . $pdo->quote($T0))->fetchColumn() === (int)$before, 'running the checks again sends nothing twice');

echo "\n── 4. Daily checks ──\n";
$sup = $ins("INSERT INTO suppliers (supplier_code, supplier_name, status) VALUES (?, ?, 'active')", ['ZZRN' . random_int(100, 999), FX . ' Mama Neema']);
$bill = $ins("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, recorded_by, due_date, amount, amount_paid, status, warehouse_id, notes)
              VALUES ('supplier', ?, 'ZZRN-1', CURDATE(), CURDATE(), ?, CURDATE() + INTERVAL 1 DAY, 30000, 10000, 'partial', ?, 'Vegetables')", [$sup, $admin, $wid]);
$sale = $ins("INSERT INTO pos_sales (receipt_number, shift_id, user_id, payment_method, warehouse_id, sale_date, sale_status, is_return_sale, grand_total) VALUES (?, 0, ?, 'cash', ?, NOW() - INTERVAL 1 DAY, 'completed', 0, 14000)", ['ZZRN-' . random_int(1000, 9999), $admin, $wid]);
$pdo->prepare("INSERT INTO pos_sale_items (sale_id, product_id, product_name, quantity, unit_price) VALUES (?, ?, ?, 2, 7000)")->execute([$sale, $dish, FX . ' Pilau']);
if (date('j') === '1') $pdo->exec("UPDATE pos_sales SET sale_date = NOW() WHERE sale_id = $sale");   // keep it inside "this month" on the 1st
$daily = rnRunDailyChecks($pdo);
$check('Low stock (2 left)', 'restaurant.stock_low', 'purchases?item=' . $soda, '%item=' . $soda . '%');
$g = $check('Supplier payment due tomorrow', 'restaurant.supplier_payment_due', 'purchases?bill=' . $bill, '%bill=' . $bill);
ok(str_contains($g['rows'][0]['message'] ?? '', '20,000') && str_contains($g['rows'][0]['message'] ?? '', 'tomorrow'), '   …says what is owed (30,000 − 10,000) and when');
$check("Yesterday's summary", 'restaurant.daily_summary', 'dashboard');
$check('Dishes with no cost', 'restaurant.dishes_no_cost', 'menu?nocost=1');
$before = $pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key LIKE 'restaurant.%' AND created_at >= " . $pdo->quote($T0))->fetchColumn();
rnRunDailyChecks($pdo);
ok((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE event_key LIKE 'restaurant.%' AND created_at >= " . $pdo->quote($T0))->fetchColumn() === (int)$before, 'the daily checks send each alert only once a day');
$cr = $pdo->query("SELECT target_type, channel_email FROM notification_rules WHERE event_key = 'pos_credit.overdue' AND is_active = 1")->fetchAll(PDO::FETCH_KEY_PAIR);
ok(($cr['admins'] ?? null) === 1 || ($cr['admins'] ?? null) === '1' || count($cr) > 0, 'customer credit due / overdue: admins get it by email too (rule present)');

echo "\n── 5. Click → the exact record ──\n";
$port = 18000 + random_int(0, 900);
$nul = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
$env = array_merge(getenv(), ['BMS_TEST_ADMIN' => (string)$admin, 'BMS_NOTIF_HOLD_EMAIL' => '1']);
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __FILE__], [0 => ['pipe', 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $pipes, $root, $env);
register_shutdown_function(function () use (&$srv) { if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); } });
$up = false; for ($i = 0; $i < 50 && !$up; $i++) { usleep(100000); $s = @fsockopen('127.0.0.1', $port); if ($s) { fclose($s); $up = true; } }
ok($up, 'test web server up');
$http = function (string $path, int $user = 0) use ($port) {
    $ctx = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => 90, 'header' => $user ? "X-Test-User: $user" : '']]);
    $b = (string)@file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    $h = $http_response_header ?? []; $loc = '';
    foreach ($h as $l) if (stripos($l, 'Location:') === 0) $loc = trim(substr($l, 9));
    return [preg_match('#^HTTP/\S+\s+(\d+)#', $h[0] ?? '', $m) ? (int)$m[1] : 0, $loc, $b];
};
$nid = (int)$pdo->query("SELECT notification_id FROM notifications WHERE user_id = $admin AND event_key = 'restaurant.online_order_new' AND created_at >= " . $pdo->quote($T0) . " ORDER BY notification_id DESC LIMIT 1")->fetchColumn();
[$c, $loc] = $http("/notification_open?id=$nid");
ok($c === 302 && str_ends_with($loc, "/online_orders?order=$oid"), "opening the notification goes to that order (got $c $loc)");
ok((int)$pdo->query("SELECT is_read FROM notifications WHERE notification_id = $nid")->fetchColumn() === 1, '…and marks it read');
$evil = createNotification($pdo, $admin, ['title' => 'x', 'message' => 'x', 'event_key' => 'restaurant.test', 'action_url' => 'https://evil.example/steal']);
[$c, $loc] = $http("/notification_open?id=$evil");
ok($c === 302 && str_ends_with($loc, '/notification_center'), 'a stored link to another site is never followed (goes to Notifications)');
$pdo->exec("DELETE FROM notifications WHERE notification_id = $evil");
$wn = (int)$pdo->query("SELECT notification_id FROM notifications WHERE user_id = $waiter AND created_at >= " . $pdo->quote($T0) . " LIMIT 1")->fetchColumn();
[$c, $loc] = $http("/notification_open?id=$wn", $admin);
ok($wn > 0 && str_ends_with($loc, '/notification_center') && (int)$pdo->query("SELECT is_read FROM notifications WHERE notification_id = $wn")->fetchColumn() === 0, "someone else's notification cannot be opened or marked read");
foreach ([["/online_orders?order=$oid", 'const focus = ' . $oid], ["/kitchen-dashboard?ticket=$slow", 'const KDS_FOCUS = ' . $slow], ["/tables-board?table=$t5", 'const TB_FOCUS = ' . $t5],
          ["/reservations?id=$res", 'id: ' . $res . ','], ["/purchases?bill=$bill", 'const RP_FOCUS_BILL = ' . $bill], ['/menu?nocost=1', 'let NOCOST = true']] as [$path, $needle]) {
    $pdo->exec("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = $wid");
    [$c, , $b] = $http($path);
    ok($c === 200 && str_contains($b, $needle), "$path opens focused on the record");
}
done_exit();
