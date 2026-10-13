<?php
/**
 * Mobile products — opening stock / stock edit for MANY shops in one request.
 *   php tests/test_mobile_product_per_shop_stock_cli.php
 *
 * api/mobile/products/create.php accepts `initial_stocks` and
 * api/mobile/products/update.php accepts `stocks`, each a list of
 * {warehouse_id, quantity}. The older single warehouse_id + initial_stock /
 * current_stock fields must keep working for app builds already installed.
 * Staff may only touch shops in their own scope.
 *
 * Runs the real endpoints in a child PHP process with a real session, against
 * the local tenant DB; every product it creates is removed afterwards.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

/** Run a mobile endpoint as a given user; returns [httpCode, json]. */
function callMobile(string $root, string $endpoint, array $body, int $userId, bool $isAdmin): array {
    $tmp = tempnam(sys_get_temp_dir(), 'mobps_');
    $isAdminLit = $isAdmin ? 'true' : 'false';
    file_put_contents($tmp, "<?php
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        \$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer cli';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $userId; \$_SESSION['role_id'] = " . ($isAdmin ? 1 : 99) . "; \$_SESSION['is_admin'] = $isAdminLit;
        \$_SESSION['permissions']['products'] = ['view' => true, 'create' => true, 'edit' => true, 'delete' => false];
        \$_POST = " . var_export($body, true) . ";
        register_shutdown_function(function () { \$o = ob_get_clean(); \$p = strpos((string)\$o, '{');
            echo (http_response_code() ?: 200) . '|' . (\$p === false ? \$o : substr(\$o, \$p)); });
        ob_start();
        include '$root/api/mobile/products/$endpoint';
    ");
    $out = trim((string)shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1'));
    @unlink($tmp);
    [$code, $json] = array_pad(explode('|', $out, 2), 2, '');
    return [(int)$code, json_decode($json, true) ?: ['raw' => $out]];
}

function stockIn(int $pid, int $wid): float {
    global $pdo;
    $s = $pdo->prepare("SELECT COALESCE(stock_quantity,0) FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
    $s->execute([$pid, $wid]);
    return (float)$s->fetchColumn();
}

$created = [];
function cleanupProducts(array $ids): void {
    global $pdo;
    foreach ($ids as $pid) {
        $mv = $pdo->prepare("SELECT movement_id FROM stock_movements WHERE product_id = ?");
        $mv->execute([$pid]);
        foreach ($mv->fetchAll(PDO::FETCH_COLUMN) as $mid) {
            $je = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type = 'stock_adjustment' AND entity_id = ?");
            $je->execute([(int)$mid]);
            foreach ($je->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = ?")->execute([$eid]);
                $pdo->prepare("DELETE FROM journal_entries WHERE entry_id = ?")->execute([$eid]);
            }
        }
        foreach (['product_batches', 'stock_movements', 'product_stocks', 'products'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE product_id = ?")->execute([$pid]);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint clean + wiring');
foreach (['api/mobile/products/_fields.php', 'api/mobile/products/create.php', 'api/mobile/products/update.php'] as $f) {
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lint clean") : fail("$f lint failed");
}
$createSrc = file_get_contents("$root/api/mobile/products/create.php");
$updateSrc = file_get_contents("$root/api/mobile/products/update.php");
str_contains($createSrc, "mobileShopQuantities(\$body, 'initial_stocks')") ? pass('create reads initial_stocks') : fail('create does not read initial_stocks');
str_contains($updateSrc, "mobileShopQuantities(\$body, 'stocks')") ? pass('update reads stocks') : fail('update does not read stocks');

$adminId = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 AND is_active = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$staffId = (int)$pdo->query("SELECT user_id FROM users WHERE is_admin = 0 AND is_active = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$shops   = array_map('intval', $pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN));

if (!$adminId || !$staffId || count($shops) < 3) {
    pass('fixtures missing (need an admin, a staff user and 3 active shops) — runtime sections skipped (n/a)');
    exit;
}
[$s1, $s2, $s3] = $shops;
$tag = time() . rand(100, 999);
$base = ['cost_price' => '100', 'selling_price' => '150', 'unit' => 'pcs'];

try {
    // ─────────────────────────────────────────────────────────────────────
    section('2. Admin — create with opening stock in several shops');
    [$code, $r] = callMobile($root, 'create.php', $base + [
        'product_name' => "Mobile Multi $tag",
        'initial_stocks' => [['warehouse_id' => $s1, 'quantity' => 12], ['warehouse_id' => $s2, 'quantity' => 7], ['warehouse_id' => $s3, 'quantity' => 0]],
    ], $adminId, true);
    $pid = (int)($r['product_id'] ?? 0);
    if ($pid) $created[] = $pid;
    ($code === 200 && !empty($r['success']) && $pid) ? pass('created via initial_stocks') : fail("create failed ($code): " . json_encode($r));
    if ($pid) {
        (stockIn($pid, $s1) == 12 && stockIn($pid, $s2) == 7) ? pass('stock: 12 in shop 1, 7 in shop 2') : fail('per-shop stock wrong');
        (stockIn($pid, $s3) == 0) ? pass('a shop given 0 gets no stock') : fail('zero-quantity shop got stock');
        $b = $pdo->prepare("SELECT COUNT(*) FROM product_batches WHERE product_id = ?");
        $b->execute([$pid]);
        ((int)$b->fetchColumn() === 2) ? pass('one batch per stocked shop (2)') : fail('batch count wrong');
        ((float)($r['current_stock'] ?? -1) === 19.0 && count($r['opening_stock_by_shop'] ?? []) === 2)
            ? pass('response: current_stock = 19 total, opening_stock_by_shop lists 2 shops')
            : fail('response totals wrong: ' . json_encode($r));
    }

    [$code, $r] = callMobile($root, 'create.php', $base + [
        'product_name' => "Mobile JsonStr $tag",
        'initial_stocks' => json_encode([$s1 => 4, $s2 => 6]),
    ], $adminId, true);
    $pidJson = (int)($r['product_id'] ?? 0);
    if ($pidJson) $created[] = $pidJson;
    ($pidJson && stockIn($pidJson, $s1) == 4 && stockIn($pidJson, $s2) == 6)
        ? pass('initial_stocks also accepted as a JSON string map (multipart with an image)')
        : fail('JSON-string initial_stocks failed: ' . json_encode($r));

    // ─────────────────────────────────────────────────────────────────────
    section('3. Old app builds — single shop fields still work');
    [$code, $r] = callMobile($root, 'create.php', $base + [
        'product_name' => "Mobile Legacy $tag", 'warehouse_id' => $s1, 'initial_stock' => 5,
    ], $adminId, true);
    $pidLegacy = (int)($r['product_id'] ?? 0);
    if ($pidLegacy) $created[] = $pidLegacy;
    ($pidLegacy && stockIn($pidLegacy, $s1) == 5) ? pass('warehouse_id + initial_stock still creates 5 in that shop') : fail('legacy create broken: ' . json_encode($r));

    if ($pidLegacy) {
        [$code, $r] = callMobile($root, 'update.php', ['product_id' => $pidLegacy, 'warehouse_id' => $s1, 'current_stock' => 9], $adminId, true);
        (!empty($r['success']) && stockIn($pidLegacy, $s1) == 9 && ($r['stock_adjustment']['after'] ?? null) == 9)
            ? pass('warehouse_id + current_stock still adjusts that shop (5 → 9), stock_adjustment returned')
            : fail('legacy update broken: ' . json_encode($r));
    }

    // ─────────────────────────────────────────────────────────────────────
    section('4. Admin — update stock in several shops at once');
    if ($pid) {
        [$code, $r] = callMobile($root, 'update.php', ['product_id' => $pid, 'stocks' => [
            ['warehouse_id' => $s1, 'quantity' => 10], ['warehouse_id' => $s2, 'quantity' => 7], ['warehouse_id' => $s3, 'quantity' => 3],
        ]], $adminId, true);
        (!empty($r['success'])) ? pass('update with stocks succeeded') : fail("update failed ($code): " . json_encode($r));
        (stockIn($pid, $s1) == 10 && stockIn($pid, $s2) == 7 && stockIn($pid, $s3) == 3)
            ? pass('stock now 10 / 7 / 3 across the three shops') : fail('per-shop stock after update wrong');
        (count($r['stock_adjustments'] ?? []) === 2)
            ? pass('stock_adjustments lists only the 2 shops that changed (unchanged shop skipped)')
            : fail('stock_adjustments wrong: ' . json_encode($r['stock_adjustments'] ?? null));
        $p = $pdo->prepare("SELECT current_stock FROM products WHERE product_id = ?");
        $p->execute([$pid]);
        ((float)$p->fetchColumn() === 20.0) ? pass('products.current_stock re-summed to 20') : fail('product total not re-summed');
    }

    // ─────────────────────────────────────────────────────────────────────
    section('5. Validation');
    [$code, $r] = callMobile($root, 'create.php', $base + ['product_name' => "Mobile Bad $tag", 'initial_stocks' => [['warehouse_id' => $s1, 'quantity' => -2]]], $adminId, true);
    ($code === 422) ? pass('negative quantity → 422') : fail("negative quantity not rejected ($code)");
    [$code, $r] = callMobile($root, 'create.php', $base + ['product_name' => "Mobile Dup $tag", 'initial_stocks' => [['warehouse_id' => $s1, 'quantity' => 1], ['warehouse_id' => $s1, 'quantity' => 2]]], $adminId, true);
    ($code === 422) ? pass('same shop listed twice → 422') : fail("duplicate shop not rejected ($code)");
    [$code, $r] = callMobile($root, 'create.php', $base + ['product_name' => "Mobile NoWh $tag", 'initial_stocks' => [['quantity' => 1]]], $adminId, true);
    ($code === 422) ? pass('entry without warehouse_id → 422') : fail("missing warehouse_id not rejected ($code)");
    $left = $pdo->prepare("SELECT COUNT(*) FROM products WHERE product_name IN (?, ?, ?)");
    $left->execute(["Mobile Bad $tag", "Mobile Dup $tag", "Mobile NoWh $tag"]);
    ((int)$left->fetchColumn() === 0) ? pass('rejected requests left no product behind') : fail('a rejected request created a product');

    // ─────────────────────────────────────────────────────────────────────
    section('6. Staff — only their assigned shops');
    $prev = $pdo->prepare("SELECT resource_id, granted_by FROM user_scope_overrides WHERE user_id = ? AND resource_type = 'warehouse'");
    $prev->execute([$staffId]);
    $prevGrants = $prev->fetchAll(PDO::FETCH_ASSOC);
    $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ? AND resource_type = 'warehouse'")->execute([$staffId]);
    $ins = $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by) VALUES (?, 'warehouse', ?, ?)");
    $ins->execute([$staffId, $s1, $adminId]);
    $ins->execute([$staffId, $s2, $adminId]);

    try {
        [$code, $r] = callMobile($root, 'create.php', $base + ['product_name' => "Mobile StaffDenied $tag",
            'initial_stocks' => [['warehouse_id' => $s1, 'quantity' => 2], ['warehouse_id' => $s3, 'quantity' => 2]]], $staffId, false);
        ($code === 403) ? pass('CREATE: staff refused (403) when ANY listed shop is outside their scope') : fail("staff not refused ($code): " . json_encode($r));
        $chk = $pdo->prepare("SELECT COUNT(*) FROM products WHERE product_name = ?");
        $chk->execute(["Mobile StaffDenied $tag"]);
        ((int)$chk->fetchColumn() === 0) ? pass('CREATE: nothing saved for the refused request') : fail('refused staff create left a product');

        [$code, $r] = callMobile($root, 'create.php', $base + ['product_name' => "Mobile StaffOk $tag",
            'initial_stocks' => [['warehouse_id' => $s1, 'quantity' => 3], ['warehouse_id' => $s2, 'quantity' => 4]]], $staffId, false);
        $pidStaff = (int)($r['product_id'] ?? 0);
        if ($pidStaff) $created[] = $pidStaff;
        ($pidStaff && stockIn($pidStaff, $s1) == 3 && stockIn($pidStaff, $s2) == 4)
            ? pass('CREATE: staff CAN stock both of their own shops (3 + 4)')
            : fail("staff own-shop create failed ($code): " . json_encode($r));

        if ($pidStaff) {
            [$code, $r] = callMobile($root, 'update.php', ['product_id' => $pidStaff, 'stocks' => [['warehouse_id' => $s3, 'quantity' => 5]]], $staffId, false);
            ($code === 403 && stockIn($pidStaff, $s3) == 0) ? pass('UPDATE: staff refused (403) for an unassigned shop, no stock written') : fail("staff update not refused ($code)");
            [$code, $r] = callMobile($root, 'update.php', ['product_id' => $pidStaff, 'stocks' => [['warehouse_id' => $s1, 'quantity' => 8], ['warehouse_id' => $s2, 'quantity' => 1]]], $staffId, false);
            (!empty($r['success']) && stockIn($pidStaff, $s1) == 8 && stockIn($pidStaff, $s2) == 1)
                ? pass('UPDATE: staff CAN adjust both own shops (3→8, 4→1)')
                : fail("staff own-shop update failed ($code): " . json_encode($r));
        }
    } finally {
        $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ? AND resource_type = 'warehouse'")->execute([$staffId]);
        foreach ($prevGrants as $g) $ins->execute([$staffId, $g['resource_id'], $g['granted_by']]);
        pass('staff user\'s original shop grants restored');
    }
} finally {
    cleanupProducts($created);
    $in = implode(',', array_map('intval', $created ?: [0]));
    ((int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_id IN ($in)")->fetchColumn() === 0)
        ? pass('all test products cleaned up') : fail('test products left behind');
}
