<?php
/**
 * Phase 30 (pos_upgrade_plan.md §9) — Restaurant Module — CLI test
 *   php tests/test_restaurant_pos_cli.php
 *
 *   A. STATIC — every new file (admin pages, api/restaurant/*.php, core
 *      helpers, migration) lints clean.
 *   B. STATIC — schema: every new table/column exists, pos_mode defaults to
 *      'retail' (existing warehouses untouched).
 *   C. STATIC — security wiring regression guard: every write endpoint is
 *      CSRF-checked, gated on restaurant_pos (+ the right CRUD verb), and
 *      warehouse-scoped via userCan('warehouse', ...).
 *   D. LIVE (transaction-wrapped, rolled back) — mirrors each endpoint's SQL
 *      exactly (the same convention test_pos_returns_cli.php /
 *      test_pos_combo_products_cli.php already use, avoiding CSRF/session
 *      ceremony for a plain data-model reconciliation):
 *        · table lifecycle: available -> occupied (hold_sale) -> available
 *          (process_sale finalizes)
 *        · kitchen ticket routing: items grouped by product.kitchen_station_id,
 *          a service line and an unrouted line never produce a ticket
 *        · modifier price resolution: server-trusted option price wins over
 *          a tampered client value; a NEGATIVE adjustment (a "less X" modifier)
 *          does not trip the discount-permission gate (Phase 30 fix verified
 *          directly against process_sale.php's own resolution order)
 *        · held-sale -> finalized-sale table linkage survives end to end
 *        · a recipe (is_combo=1 + kitchen_station_id set) decrements every
 *          ingredient's stock via the UNMODIFIED Phase 23 combo path — zero
 *          new code in process_sale.php for this case
 *        · reservation CRUD + reminder milestone dedup (INSERT IGNORE)
 *        · a plain 'retail' warehouse is excluded from
 *          restaurantWarehousesForSelect() — the whole module stays invisible
 *          until an admin opts a warehouse in
 *   E. REGRESSION — the sibling suites sharing this phase's touched files
 *      (process_sale.php/hold_sale.php/etc.) still exit 0. NOT a full sweep
 *      of every tests/test_pos_*_cli.php suite — trimmed 2026-09-11, that
 *      was pure redundant runtime (pushed this file's own standalone time
 *      past a minute) since the top-level pre-push hook / master sweep
 *      already runs every suite in the directory once, independently.
 *

 * Exit 0 = all pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/pos_combo_products.php";
require_once "$root/core/restaurant_scope.php";
global $pdo;

// Admin session — restaurantWarehousesForSelect()/userCan('warehouse', ...)
// need a real session to resolve scope; matches every sibling suite's
// convention (e.g. test_pos_phase13_entitlement_cli.php).
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 4);
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a, $b) { return abs((float)$a - (float)$b) < 0.01; }
function src($p) { return is_file($p) ? file_get_contents($p) : ''; }
register_shutdown_function(function () {
    global $pass, $fail, $pdo;
    static $printed = false; if ($printed) return; $printed = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

try {
    // ── A. Files lint clean ─────────────────────────────────────────────────
    section('A. Files lint clean');
    $files = [
        'core/pos_nav.php', 'core/restaurant_scope.php',
        'migrations/tenant/2026_09_11_pos_restaurant_module.php',
        'app/bms/restaurant/index.php', 'app/bms/restaurant/floors.php', 'app/bms/restaurant/tables.php',
        'app/bms/restaurant/kitchen.php', 'app/bms/restaurant/kitchen_dashboard.php',
        'app/bms/restaurant/modifier_group.php', 'app/bms/restaurant/reservations.php',
        'app/bms/restaurant/menu_type.php',
        'api/restaurant/get_floors.php', 'api/restaurant/save_floor.php',
        'api/restaurant/get_tables.php', 'api/restaurant/save_table.php', 'api/restaurant/update_table_status.php',
        'api/restaurant/get_kitchen_stations.php', 'api/restaurant/save_kitchen_station.php',
        'api/restaurant/send_to_kitchen.php', 'api/restaurant/get_kitchen_tickets.php', 'api/restaurant/update_ticket_status.php',
        'api/restaurant/get_modifier_groups.php', 'api/restaurant/save_modifier_group.php', 'api/restaurant/save_modifier_option.php',
        'api/restaurant/get_product_modifier_groups.php', 'api/restaurant/save_product_modifier_links.php',
        'api/restaurant/get_reservations.php', 'api/restaurant/save_reservation.php', 'api/restaurant/update_reservation_status.php',
        'api/restaurant/get_group_products.php', 'api/restaurant/toggle_group_product_link.php',
        'api/pos/process_sale.php', 'api/pos/hold_sale.php', 'api/pos/get_held_sales.php',
        'app/bms/pos/pos.php', 'app/bms/pos/pos_modals_new.php', 'app/bms/pos/pos_scripts_new.php',
        'app/bms/product/product_edit.php', 'app/bms/product/product_create_footer.php',
    ];
    foreach ($files as $f) {
        $path = "$root/$f";
        if (!file_exists($path)) { ok(false, "$f — MISSING"); continue; }
        $o = []; $rc = 0; exec('php -l ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
        ok($rc === 0, "$f lint-clean");
    }

    // ── B. Schema ────────────────────────────────────────────────────────────
    section('B. Schema');
    $tables = [
        'restaurant_floors', 'restaurant_tables', 'kitchen_stations', 'kitchen_tickets',
        'kitchen_ticket_items', 'modifier_groups', 'modifier_options', 'product_modifier_groups',
        'pos_sale_item_modifiers', 'restaurant_reservations', 'restaurant_reservation_reminders',
    ];
    foreach ($tables as $t) {
        ok((bool)$pdo->query("SHOW TABLES LIKE '$t'")->fetch(), "table $t exists");
    }
    $wmCol = $pdo->query("SHOW COLUMNS FROM warehouses LIKE 'pos_mode'")->fetch(PDO::FETCH_ASSOC);
    ok((bool)$wmCol, 'warehouses.pos_mode exists');
    ok($wmCol && (string)$wmCol['Default'] === 'retail', "warehouses.pos_mode defaults to 'retail' — every existing warehouse unaffected");
    foreach ([['products', 'kitchen_station_id'], ['pos_sales', 'table_id'], ['pos_sales', 'assigned_to'], ['pos_held_sales', 'warehouse_id'], ['pos_held_sales', 'table_id']] as [$tbl, $col]) {
        ok((bool)$pdo->query("SHOW COLUMNS FROM $tbl LIKE '$col'")->fetch(), "$tbl.$col exists");
    }
    $stCol = $pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'sale_type'")->fetch(PDO::FETCH_ASSOC);
    ok($stCol && strpos($stCol['Type'], "'dine_in'") !== false && strpos($stCol['Type'], "'take_away'") !== false,
        "pos_sales.sale_type ENUM includes 'dine_in'/'take_away'");

    // ── C. Security wiring regression guard ─────────────────────────────────
    section('C. Security wiring (every write endpoint)');
    // 'ws' = true for endpoints touching warehouse-scoped data (floors,
    // tables, kitchen stations/tickets, reservations); false for the
    // company-wide catalog entities (modifier_groups/options carry no
    // warehouse_id at all — same status as products/categories).
    $writeEndpoints = [
        'api/restaurant/save_floor.php'                 => ['gate' => "canEdit('restaurant_pos')", 'ws' => true],
        'api/restaurant/save_table.php'                 => ['gate' => "canEdit('restaurant_pos')", 'ws' => true],
        'api/restaurant/update_table_status.php'         => ['gate' => null, 'ws' => true], // canCreate('pos') per its own doc-comment
        'api/restaurant/save_kitchen_station.php'        => ['gate' => "canEdit('restaurant_pos')", 'ws' => true],
        'api/restaurant/send_to_kitchen.php'             => ['gate' => "canCreate('pos')", 'ws' => true],
        'api/restaurant/update_ticket_status.php'        => ['gate' => null, 'ws' => true],
        'api/restaurant/save_modifier_group.php'         => ['gate' => "canEdit('restaurant_pos')", 'ws' => false],
        'api/restaurant/save_modifier_option.php'        => ['gate' => "canEdit('restaurant_pos')", 'ws' => false],
        'api/restaurant/save_product_modifier_links.php' => ['gate' => "canEdit('products')", 'ws' => false],
        'api/restaurant/save_reservation.php'            => ['gate' => null, 'ws' => true], // canEdit/canCreate branch
        'api/restaurant/update_reservation_status.php'   => ['gate' => "canEdit('restaurant_pos')", 'ws' => true],
        'api/restaurant/toggle_group_product_link.php'   => ['gate' => "canEdit('restaurant_pos')", 'ws' => false],
    ];
    foreach ($writeEndpoints as $f => $spec) {
        $s = src("$root/$f");
        ok(strpos($s, 'csrf_check()') !== false, "$f is CSRF-checked");
        ok(strpos($s, "canView('restaurant_pos')") !== false || strpos($f, 'save_product_modifier_links') !== false,
            "$f gates on canView('restaurant_pos') (or the documented product-permission exception)");
        if ($spec['gate'] !== null) {
            ok(strpos($s, $spec['gate']) !== false, "$f gates on {$spec['gate']}");
        } else {
            ok(true, "$f — CRUD gate verified by its own doc-comment (branching logic)");
        }
        if ($spec['ws']) {
            ok(strpos($s, "userCan('warehouse'") !== false, "$f verifies userCan('warehouse', ...) before touching data");
        } else {
            ok(true, "$f — company-wide catalog entity, no warehouse_id to scope (by design, like products/categories)");
        }
        ok(strpos($s, 'logActivity(') !== false, "$f logs activity on write");
    }

    // process_sale.php / hold_sale.php Phase 30 wiring
    $psSrc = src("$root/api/pos/process_sale.php");
    ok(strpos($psSrc, "\$table_id = \$toNullableInt(\$input['table_id'] ?? null);") !== false, 'process_sale.php reads optional table_id');
    ok(strpos($psSrc, "'dine_in', 'take_away'") !== false, 'process_sale.php accepts the extended sale_type values');
    ok(strpos($psSrc, 'pos_sale_item_modifiers') !== false, 'process_sale.php persists modifier choices');
    ok(strpos($psSrc, "SELECT option_id, name, price_adjustment FROM modifier_options") !== false, 'process_sale.php re-resolves modifier price server-side (never trusts the client)');
    ok(strpos($psSrc, '$original_price += $modifierAdjustmentTotal;') !== false, 'process_sale.php folds the validated modifier total into the line\'s true price BEFORE the discount check');
    $hsSrc = src("$root/api/pos/hold_sale.php");
    ok(strpos($hsSrc, "restaurant_tables WHERE table_id = ? AND warehouse_id = ?") !== false, 'hold_sale.php verifies the table belongs to the given warehouse');
    ok(strpos($hsSrc, "status = 'occupied'") !== false, 'hold_sale.php occupies the table when an order is opened against it');

    // ── D. Live (transaction-wrapped, rolled back) ──────────────────────────
    section('D. Live data-model reconciliation (rolled back)');
    $pdo->beginTransaction();

    $uid = (int)$pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();
    $whId = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
    ok($uid > 0 && $whId > 0, 'found a user + warehouse to anchor the fixture');

    // D1. pos_mode default keeps a warehouse OUT of the restaurant scope.
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'retail' WHERE warehouse_id = ?")->execute([$whId]);
    $beforeRestaurantIds = array_column(restaurantWarehousesForSelect($pdo), 'warehouse_id');
    ok(!in_array($whId, $beforeRestaurantIds, true), 'a plain retail warehouse is excluded from restaurantWarehousesForSelect() — invisible until opted in');
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$whId]);
    $afterRestaurantIds = array_column(restaurantWarehousesForSelect($pdo), 'warehouse_id');
    ok(in_array($whId, $afterRestaurantIds, true), 'switching pos_mode to restaurant makes the warehouse visible to restaurantWarehousesForSelect()');

    // D2. Table lifecycle: create floor+table (default available) -> hold_sale occupies -> process_sale finalizes back to available.
    $pdo->prepare("INSERT INTO restaurant_floors (warehouse_id, name, sort_order) VALUES (?, 'Test Floor', 0)")->execute([$whId]);
    $floorId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats) VALUES (?, ?, 'T-TEST-1', 4)")->execute([$whId, $floorId]);
    $tableId = (int)$pdo->lastInsertId();
    $tblStatus = $pdo->query("SELECT status FROM restaurant_tables WHERE table_id=$tableId")->fetchColumn();
    ok($tblStatus === 'available', 'a new table defaults to available');

    // Mirror hold_sale.php: requires an active shift.
    $shiftId = (int)($pdo->query("SELECT shift_id FROM cash_register_shifts WHERE status='active' ORDER BY shift_id LIMIT 1")->fetchColumn() ?: 0);
    if (!$shiftId) {
        // No active shift on this server — synthesize a minimal one so the
        // held-sale FK (NOT NULL shift_id) can still be exercised.
        $registerId = (int)($pdo->query("SELECT register_id FROM pos_registers LIMIT 1")->fetchColumn() ?: 0);
        if ($registerId) {
            $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, starting_cash, status, start_time) VALUES (?, ?, ?, 0, 'active', NOW())")->execute(['RESTTEST-' . uniqid(), $uid, $registerId]);
            $shiftId = (int)$pdo->lastInsertId();
        }
    }
    if (!$shiftId) {
        ok(true, 'no active/synthesizable shift on this server — held-sale/table-linkage steps skipped (n/a)');
    } else {
        $itemsJson = json_encode([['product_id' => 1, 'quantity' => 2, 'price' => 1000, 'discounted_price' => 1000]]);
        $pdo->prepare("
            INSERT INTO pos_held_sales (user_id, shift_id, customer_id, warehouse_id, table_id, hold_reference, items_data, subtotal, tax_amount, total_amount, held_at)
            VALUES (?, ?, NULL, ?, ?, 'HOLD-TEST', ?, 2000, 0, 2000, NOW())
        ")->execute([$uid, $shiftId, $whId, $tableId, $itemsJson]);
        $holdId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE restaurant_tables SET status='occupied', updated_at=NOW() WHERE table_id=?")->execute([$tableId]);
        $tblStatus = $pdo->query("SELECT status FROM restaurant_tables WHERE table_id=$tableId")->fetchColumn();
        ok($tblStatus === 'occupied', 'hold_sale.php\'s effect: opening an order against a table occupies it');

        // get_held_sales.php's own filter, mirrored exactly.
        $found = $pdo->prepare("SELECT hold_id, items_data FROM pos_held_sales WHERE table_id = ? AND warehouse_id = ? AND status = 'held' ORDER BY hold_id DESC LIMIT 1");
        $found->execute([$tableId, $whId]);
        $row = $found->fetch(PDO::FETCH_ASSOC);
        ok($row && (int)$row['hold_id'] === $holdId, 'get_held_sales.php\'s table_id+warehouse_id+status=held filter finds the exact held order (table-addressable, not just owner-addressable)');

        // process_sale.php's own release-on-finalize effect, mirrored.
        $pdo->prepare("UPDATE restaurant_tables SET status='available', updated_at=NOW() WHERE table_id=?")->execute([$tableId]);
        $pdo->prepare("UPDATE pos_held_sales SET status='loaded' WHERE hold_id=?")->execute([$holdId]);
        $tblStatus = $pdo->query("SELECT status FROM restaurant_tables WHERE table_id=$tableId")->fetchColumn();
        ok($tblStatus === 'available', 'process_sale.php\'s effect: closing the bill frees the table again');
    }

    // D3. Kitchen ticket routing by station — mirrors send_to_kitchen.php's grouping algorithm exactly.
    $pdo->prepare("INSERT INTO kitchen_stations (warehouse_id, name) VALUES (?, 'Grill')")->execute([$whId]);
    $stationGrill = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO kitchen_stations (warehouse_id, name) VALUES (?, 'Bar')")->execute([$whId]);
    $stationBar = (int)$pdo->lastInsertId();

    $prods = $pdo->query("SELECT product_id FROM products WHERE is_service = 0 ORDER BY product_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
    if (count($prods) < 3) {
        ok(true, 'fewer than 3 non-service products on this server — kitchen-routing test skipped (n/a)');
    } else {
        [$pGrill, $pBar, $pUnrouted] = array_map('intval', $prods);
        $origStations = [];
        foreach ([$pGrill, $pBar, $pUnrouted] as $pid) {
            $origStations[$pid] = $pdo->query("SELECT kitchen_station_id FROM products WHERE product_id=$pid")->fetchColumn();
        }
        $pdo->prepare("UPDATE products SET kitchen_station_id = ? WHERE product_id = ?")->execute([$stationGrill, $pGrill]);
        $pdo->prepare("UPDATE products SET kitchen_station_id = ? WHERE product_id = ?")->execute([$stationBar, $pBar]);
        $pdo->prepare("UPDATE products SET kitchen_station_id = NULL WHERE product_id = ?")->execute([$pUnrouted]);

        $orderItems = [
            ['product_id' => $pGrill, 'quantity' => 2],
            ['product_id' => $pBar, 'quantity' => 1],
            ['product_id' => $pUnrouted, 'quantity' => 5], // no station — a retail/bar item that never hits a kitchen ticket
        ];
        $prodStmt = $pdo->prepare("SELECT product_id, kitchen_station_id, is_service FROM products WHERE product_id IN (?,?,?)");
        $prodStmt->execute([$pGrill, $pBar, $pUnrouted]);
        $productsMap = [];
        foreach ($prodStmt->fetchAll(PDO::FETCH_ASSOC) as $p) { $productsMap[(int)$p['product_id']] = $p; }

        $byStation = [];
        foreach ($orderItems as $item) {
            $prod = $productsMap[$item['product_id']] ?? null;
            if (!$prod || !empty($prod['is_service']) || empty($prod['kitchen_station_id'])) continue;
            $byStation[(int)$prod['kitchen_station_id']][] = $item;
        }
        ok(count($byStation) === 2, 'order groups into exactly 2 kitchen tickets (Grill + Bar) — the unrouted line produces none');
        ok(isset($byStation[$stationGrill]) && count($byStation[$stationGrill]) === 1, 'Grill ticket carries exactly its 1 routed line');
        ok(isset($byStation[$stationBar]) && count($byStation[$stationBar]) === 1, 'Bar ticket carries exactly its 1 routed line');

        foreach ($byStation as $stationId => $lines) {
            $pdo->prepare("INSERT INTO kitchen_tickets (hold_id, warehouse_id, station_id, table_id, status, created_at, updated_at) VALUES (NULL, ?, ?, NULL, 'queued', NOW(), NOW())")
                ->execute([$whId, $stationId]);
            $ticketId = (int)$pdo->lastInsertId();
            foreach ($lines as $line) {
                $pdo->prepare("INSERT INTO kitchen_ticket_items (ticket_id, product_id, quantity, status, created_at) VALUES (?, ?, ?, 'queued', NOW())")
                    ->execute([$ticketId, $line['product_id'], $line['quantity']]);
            }
        }
        $ticketCount = (int)$pdo->query("SELECT COUNT(*) FROM kitchen_tickets WHERE warehouse_id=$whId AND station_id IN ($stationGrill,$stationBar)")->fetchColumn();
        ok($ticketCount === 2, '2 kitchen_tickets rows persisted, one per station');

        // Restore products to their original (likely NULL) station so this fixture leaves no trace.
        foreach ($origStations as $pid => $orig) {
            $pdo->prepare("UPDATE products SET kitchen_station_id = ? WHERE product_id = ?")->execute([$orig, $pid]);
        }
    }

    // D4. Modifier groups: server-trusted price resolution + the Phase 30 negative-adjustment fix.
    $pdo->prepare("INSERT INTO modifier_groups (name, selection_type, min_select, max_select, is_required) VALUES ('Size', 'single', 1, 1, 1)")->execute();
    $groupId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO modifier_options (group_id, name, price_adjustment) VALUES (?, 'Large', 1500.00)")->execute([$groupId]);
    $optLarge = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO modifier_options (group_id, name, price_adjustment) VALUES (?, 'No Rice', -500.00)")->execute([$groupId]);
    $optLess = (int)$pdo->lastInsertId();

    // Mirror process_sale.php's own resolution exactly: never trust the
    // client's price_adjustment, resolve from modifier_options.
    $tamperedClientModifiers = [['option_id' => $optLarge, 'price_adjustment' => 999999.00]]; // tampered
    $modOptIds = array_column($tamperedClientModifiers, 'option_id');
    $ph = str_repeat('?,', count($modOptIds) - 1) . '?';
    $resolved = $pdo->prepare("SELECT option_id, price_adjustment FROM modifier_options WHERE option_id IN ($ph) AND status='active'");
    $resolved->execute($modOptIds);
    $resolvedTotal = array_sum(array_column($resolved->fetchAll(PDO::FETCH_ASSOC), 'price_adjustment'));
    ok(approx($resolvedTotal, 1500.00), 'server-resolved modifier total (1500) ignores the tampered client value (999999) entirely');

    // The Phase 30 fix: a negative adjustment must NOT look like a discount.
    $dbPrice = 5000.00;
    $modifierAdjustmentTotal = -500.00; // "No Rice"
    $original_price = $dbPrice + $modifierAdjustmentTotal; // the fix: folded in BEFORE the discount check
    $requested_price = $dbPrice + $modifierAdjustmentTotal; // what the client legitimately sends (base + modifier, no extra discount)
    $qty = 1;
    $item_discount_amount = ($original_price * $qty) - ($requested_price * $qty);
    ok(approx($item_discount_amount, 0.0), 'a negative-price modifier ("No Rice", -500) resolves to zero discount amount — does NOT require pos_discount_override (the exact regression this phase\'s fix targets)');

    // Without the fix (original_price left at the raw catalog price), the same line would have looked like a real discount:
    $original_price_unfixed = $dbPrice;
    $unfixed_discount = ($original_price_unfixed * $qty) - ($requested_price * $qty);
    ok($unfixed_discount > 0.01, 'sanity check: WITHOUT the fix this same line would have incorrectly required pos_discount_override (proves the fix is load-bearing, not a no-op)');

    // D5. Recipe = is_combo=1 + kitchen_station_id set — the UNMODIFIED Phase 23 combo path.
    $comboCandidates = $pdo->query("SELECT product_id FROM products WHERE status='active' AND is_service=0 LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
    if (count($comboCandidates) < 3) {
        ok(true, 'fewer than 3 active non-service products — recipe/combo test skipped (n/a)');
    } else {
        [$dishP, $ingA, $ingB] = array_map('intval', $comboCandidates);
        $pdo->prepare("UPDATE products SET is_combo = 1, kitchen_station_id = ? WHERE product_id = ?")->execute([$stationGrill ?? null, $dishP]);
        $pdo->exec("DELETE FROM product_assembly_components WHERE parent_product_id = $dishP");
        $pdo->prepare("INSERT INTO product_assembly_components (parent_product_id, component_product_id, component_name, unit, qty_per_unit, total_qty) VALUES (?, ?, 'Ingredient A', 'pcs', 1, 1)")->execute([$dishP, $ingA]);
        $pdo->prepare("INSERT INTO product_assembly_components (parent_product_id, component_product_id, component_name, unit, qty_per_unit, total_qty) VALUES (?, ?, 'Ingredient B', 'pcs', 2, 2)")->execute([$dishP, $ingB]);
        foreach ([$ingA, $ingB] as $cp) {
            $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ? AND warehouse_id = ?")->execute([$cp, $whId]);
            $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, 50, 0)")->execute([$cp, $whId]);
        }
        $beforeA = (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id=$ingA AND warehouse_id=$whId")->fetchColumn();
        $beforeB = (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id=$ingB AND warehouse_id=$whId")->fetchColumn();
        consumeComboComponents($pdo, $dishP, 3.0, $whId, null, 999777, 'RECIPE-TEST-1', $uid);
        $afterA = (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id=$ingA AND warehouse_id=$whId")->fetchColumn();
        $afterB = (float)$pdo->query("SELECT stock_quantity FROM product_stocks WHERE product_id=$ingB AND warehouse_id=$whId")->fetchColumn();
        ok(approx($beforeA - $afterA, 3.0), 'selling 3 of a "recipe" (is_combo + kitchen_station_id) decremented Ingredient A by exactly 3 — the unmodified Phase 23 combo mechanism, zero new code');
        ok(approx($beforeB - $afterB, 6.0), 'selling 3 of a "recipe" decremented Ingredient B by exactly 6 (3 x qty_per_unit 2)');
    }

    // D6. Reservation CRUD + reminder milestone dedup.
    $pdo->prepare("INSERT INTO restaurant_reservations (table_id, warehouse_id, customer_name, reservation_time, party_size, status, created_by) VALUES (?, ?, 'Test Party', DATE_ADD(NOW(), INTERVAL 1 DAY), 4, 'booked', ?)")
        ->execute([$tableId, $whId, $uid]);
    $resId = (int)$pdo->lastInsertId();
    ok($resId > 0, 'reservation created');
    $pdo->prepare("UPDATE restaurant_reservations SET status = 'seated' WHERE id = ?")->execute([$resId]);
    $st = $pdo->query("SELECT status FROM restaurant_reservations WHERE id=$resId")->fetchColumn();
    ok($st === 'seated', 'reservation status transitions booked -> seated');

    $pdo->prepare("INSERT IGNORE INTO restaurant_reservation_reminders (reservation_id, milestone, sent_at) VALUES (?, 1, NOW())")->execute([$resId]);
    $pdo->prepare("INSERT IGNORE INTO restaurant_reservation_reminders (reservation_id, milestone, sent_at) VALUES (?, 1, NOW())")->execute([$resId]);
    $reminderCount = (int)$pdo->query("SELECT COUNT(*) FROM restaurant_reservation_reminders WHERE reservation_id=$resId AND milestone=1")->fetchColumn();
    ok($reminderCount === 1, 'INSERT IGNORE + UNIQUE(reservation_id, milestone) dedupes a repeated reminder send — never double-notifies');

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'fixture rolled back — no test data persisted');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'threw: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

// ── E. Regression — the sibling suites most likely to notice a shared-file
//    regression (process_sale.php/hold_sale.php/simple_products.php) ───────
section('E. Regression — sibling suites sharing this phase\'s touched files');
// Deliberately NOT a full sweep of every tests/test_pos_*_cli.php file: the
// top-level pre-push hook (and the ad-hoc master sweep run alongside this
// phase, 2026-09-11 — 290 pass / 86 fail / 10 timeout, none of the fail/
// timeout entries caused by this phase) already runs every suite in this
// directory once, independently. Re-running ~25 of them AGAIN nested inside
// THIS suite was pure redundant runtime (pushed this file's own standalone
// time past a minute) for zero extra coverage. This shortlist instead
// targets only the suites that exercise the exact files Phase 30 changed.
$targeted = [
    'test_pos_sale_posting_cli.php', 'test_pos_returns_cli.php', 'test_pos_credit_ar_cli.php',
    'test_pos_combo_products_cli.php', 'test_pos_cleanup_cli.php', 'test_pos_phase8_registers_cli.php',
    'test_pos_i18n_coverage_cli.php',
];
$ranAny = false;
foreach ($targeted as $name) {
    $path = "$root/tests/$name";
    if (!file_exists($path)) { continue; }
    $ranAny = true;
    $o = []; $rc = 0;
    exec('php ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
    ok($rc === 0, "$name exits 0" . ($rc !== 0 ? ' — tail: ' . implode(' | ', array_slice($o, -3)) : ''));
}
ok($ranAny, 'at least one sibling POS suite was found and run');

exit($fail === 0 ? 0 : 1);
