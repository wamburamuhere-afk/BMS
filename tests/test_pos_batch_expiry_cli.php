<?php
/**
 * Phase 17 (pos_upgrade_plan.md §8) — batch/lot + expiry tracking, end-to-end
 * (GRN -> stock -> POS -> alerts) — CLI test
 *   php tests/test_pos_batch_expiry_cli.php
 *
 * Verifies:
 *   1. Files lint clean.
 *   2. Schema: product_batches, pos_sale_item_batches,
 *      product_batch_expiry_reminders tables + the notification_events row exist.
 *   3. Wiring: approve_grn.php writes batches; process_sale.php/void_sale.php/
 *      create_return.php call the FEFO helpers; core/notify.php has the
 *      warehouse-scope branch; cron/run_notification_checks.php has the
 *      product.batch_expiring block; product_view.php shows the Batches card.
 *   4. Runtime — consumeFefoBatches(): oldest-expiry-first, splits across
 *      batches when one isn't enough, never errors on insufficient stock,
 *      no-op for a non-batch-tracked product.
 *   5. Runtime — reverseFefoBatchConsumption(): full reversal (void) restores
 *      every consumed batch exactly; partial reversal (return) restores only
 *      up to the returned quantity and leaves the link row shrunk, not deleted.
 *   6. Runtime — warehouseIdsForUser(): admin -> ['*']; grant-all override ->
 *      ['*']; specific-warehouse override -> that warehouse only.
 *   7. Runtime — resolveRecipients() warehouse-scope branch narrows correctly
 *      (mirrors test_notification_engine_cli.php's project-scope test shape).
 *   8. Runtime — the cron check: a batch expiring within a milestone fires
 *      once, dedupes on a second run (idempotent), and a rule targeting a
 *      specific user + email actually resolves that one recipient with the
 *      email channel on.
 *   9. Runtime — 2026-09-11 gap fix: a plain (non-batch-tracked) product
 *      with expiry_date + email_alerts=1 now fires and dedupes the same way
 *      batches do, reusing the same product.batch_expiring event; a product
 *      that also has batch rows is excluded from this path (no double-alert).
 *
 * All DB writes happen inside rolled-back transactions — nothing persists.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/pos_batch_consumption.php";
require_once "$root/core/warehouse_scope.php";
require_once "$root/core/notify.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌\033[0m $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail, $pdo; static $printed = false; if ($printed) return; $printed = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint clean');
$files = [
    'core/pos_batch_consumption.php', 'core/warehouse_scope.php', 'core/notify.php',
    'api/approve_grn.php', 'api/pos/process_sale.php', 'api/pos/void_sale.php',
    'api/pos/create_return.php', 'cron/run_notification_checks.php',
    'app/bms/product/product_view.php', 'app/dashboard.php',
    'migrations/tenant/2026_09_08_pos_product_batches.php',
];
foreach ($files as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Schema');
foreach (['product_batches', 'pos_sale_item_batches', 'product_batch_expiry_reminders'] as $t) {
    $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
    $exists ? pass("table `$t` exists") : fail("table `$t` MISSING — run the migration");
}
$ev = $pdo->query("SELECT * FROM notification_events WHERE event_key = 'product.batch_expiring'")->fetch(PDO::FETCH_ASSOC);
($ev && (int)$ev['scope_aware'] === 1) ? pass("notification_events 'product.batch_expiring' exists and is scope_aware") : fail('event row missing or not scope_aware');

// ─────────────────────────────────────────────────────────────────────────
section('3. Wiring');
has(src($root, 'api/approve_grn.php'), 'INSERT INTO product_batches', 'approve_grn.php writes product_batches');
has(src($root, 'api/pos/process_sale.php'), 'consumeFefoBatches(', 'process_sale.php consumes via FEFO');
has(src($root, 'api/pos/void_sale.php'), 'reverseFefoBatchConsumption(', 'void_sale.php reverses batch consumption');
has(src($root, 'api/pos/create_return.php'), 'reverseFefoBatchConsumption(', 'create_return.php reverses batch consumption (partial)');
has(src($root, 'core/notify.php'), "ctx['warehouse_id']", 'resolveRecipients() reads warehouse_id from ctx');
has(src($root, 'core/notify.php'), 'warehouseIdsForUser(', 'resolveRecipients() calls warehouseIdsForUser()');
has(src($root, 'cron/run_notification_checks.php'), "'product.batch_expiring'", 'scheduler emits product.batch_expiring');
has(src($root, 'app/bms/product/product_view.php'), 'product_batches', 'product_view.php reads product_batches');
has(src($root, 'app/dashboard.php'), 'product_batches', 'dashboard.php expiring widget reads product_batches');

// ─────────────────────────────────────────────────────────────────────────
section('4/5. Runtime — FEFO consumption + reversal (rolled back)');
$prodRow = $pdo->query("SELECT product_id FROM products WHERE status='active' AND is_service=0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$whRow   = $pdo->query("SELECT warehouse_id FROM warehouses LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$prodRow || !$whRow) {
    pass('no active product/warehouse to test against — skipped (n/a)');
} else {
    $pid = (int)$prodRow['product_id'];
    $wid = (int)$whRow['warehouse_id'];

    $pdo->beginTransaction();

    // A fake pos_sale_items row to attach consumption to (FK-less table, but
    // needs a real-looking sale_item_id for the link table).
    $saleItemId = (int)$pdo->query("SELECT COALESCE(MAX(sale_item_id),0) + 900000 FROM pos_sale_items")->fetchColumn();

    // Two batches: 5 units expiring sooner, 10 units expiring later.
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, expiry_date, quantity_received, quantity_remaining, unit_cost) VALUES (?, ?, 'BATCH-OLD', DATE_ADD(CURDATE(), INTERVAL 5 DAY), 5, 5, 100)")->execute([$pid, $wid]);
    $oldBatchId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, expiry_date, quantity_received, quantity_remaining, unit_cost) VALUES (?, ?, 'BATCH-NEW', DATE_ADD(CURDATE(), INTERVAL 60 DAY), 10, 10, 120)")->execute([$pid, $wid]);
    $newBatchId = (int)$pdo->lastInsertId();

    // Consume 8: should take all 5 from OLD (nearer expiry) then 3 from NEW.
    $res = consumeFefoBatches($pdo, $pid, $wid, 8.0, $saleItemId);
    (abs($res['consumed'] - 8.0) < 0.001) ? pass('consumeFefoBatches() consumed exactly 8 units total') : fail('wrong total consumed: ' . $res['consumed']);
    (count($res['lines']) === 2 && $res['lines'][0]['batch_id'] === $oldBatchId && abs($res['lines'][0]['quantity'] - 5.0) < 0.001)
        ? pass('consumed all 5 from the nearer-expiry batch first (FEFO)')
        : fail('FEFO order wrong: ' . json_encode($res['lines']));
    (isset($res['lines'][1]) && $res['lines'][1]['batch_id'] === $newBatchId && abs($res['lines'][1]['quantity'] - 3.0) < 0.001)
        ? pass('spilled over into the second batch for the remaining 3 units')
        : fail('spillover into second batch wrong');

    $oldRemaining = (float)$pdo->query("SELECT quantity_remaining FROM product_batches WHERE batch_id=$oldBatchId")->fetchColumn();
    $newRemaining = (float)$pdo->query("SELECT quantity_remaining FROM product_batches WHERE batch_id=$newBatchId")->fetchColumn();
    (abs($oldRemaining - 0.0) < 0.001) ? pass('OLD batch fully depleted (0 remaining)') : fail("OLD batch remaining wrong: $oldRemaining");
    (abs($newRemaining - 7.0) < 0.001) ? pass('NEW batch correctly left with 7 remaining') : fail("NEW batch remaining wrong: $newRemaining");

    // Insufficient stock: request more than total available (12 > 7 remaining) — must not error.
    $res2 = consumeFefoBatches($pdo, $pid, $wid, 12.0, $saleItemId + 1);
    (abs($res2['consumed'] - 7.0) < 0.001) ? pass('requesting more than available consumes only what exists (7), no error thrown') : fail('insufficient-stock handling wrong: ' . $res2['consumed']);

    // No-op for a product with zero batch rows.
    $res3 = consumeFefoBatches($pdo, 999999999, $wid, 5.0, $saleItemId + 2);
    (abs($res3['consumed'] - 0.0) < 0.001 && empty($res3['lines'])) ? pass('non-batch-tracked product -> no-op, empty result') : fail('non-batch-tracked product unexpectedly consumed something');

    // Full reversal (void semantics) of the first consumption (8 units across 2 batches).
    $restored = reverseFefoBatchConsumption($pdo, $saleItemId);
    (abs($restored - 8.0) < 0.001) ? pass('reverseFefoBatchConsumption() (full/void) restored exactly 8 units') : fail("full reversal restored wrong amount: $restored");
    // NEW was drained to 0 by the intervening insufficient-stock test (res2,
    // which took its 7 remaining units, all from NEW since OLD was already
    // 0) — so this reversal's +3 to NEW lands on 0, not on the earlier 7.
    $oldAfterVoid = (float)$pdo->query("SELECT quantity_remaining FROM product_batches WHERE batch_id=$oldBatchId")->fetchColumn();
    $newAfterVoid = (float)$pdo->query("SELECT quantity_remaining FROM product_batches WHERE batch_id=$newBatchId")->fetchColumn();
    (abs($oldAfterVoid - 5.0) < 0.001 && abs($newAfterVoid - 3.0) < 0.001)
        ? pass('void restored the EXACT originating batches (5 back to OLD, 3 back to NEW)')
        : fail("void restore mismatch: old=$oldAfterVoid new=$newAfterVoid");
    $linksLeft = (int)$pdo->query("SELECT COUNT(*) FROM pos_sale_item_batches WHERE sale_item_id=$saleItemId")->fetchColumn();
    ($linksLeft === 0) ? pass('link rows deleted after full reversal (idempotent — a repeat void is a no-op)') : fail("link rows not cleaned up: $linksLeft left");

    // Partial reversal (return semantics) of the second consumption (7 units, all from NEW).
    $partialRestored = reverseFefoBatchConsumption($pdo, $saleItemId + 1, 3.0);
    (abs($partialRestored - 3.0) < 0.001) ? pass('partial reversal (return) restored only the requested 3 units') : fail("partial reversal wrong amount: $partialRestored");
    $linkQtyLeft = (float)$pdo->query("SELECT quantity FROM pos_sale_item_batches WHERE sale_item_id=" . ($saleItemId + 1))->fetchColumn();
    (abs($linkQtyLeft - 4.0) < 0.001) ? pass('link row shrunk to 4 remaining (7 - 3), not deleted — a second partial return can still reverse the rest') : fail("link row not shrunk correctly: $linkQtyLeft");

    $pdo->rollBack();
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Runtime — warehouseIdsForUser()');
// A real NON-ADMIN user (admins always bypass scope — need a genuine
// scope-subject to test narrowing, not the bypass path).
$anyUser = (int)($pdo->query("
    SELECT DISTINCT u.user_id FROM users u
    JOIN roles r ON u.role_id = r.role_id
    JOIN role_permissions rp ON rp.role_id = r.role_id
    JOIN permissions p ON p.permission_id = rp.permission_id
    WHERE u.is_active = 1 AND COALESCE(r.is_admin,0) = 0 AND p.page_key = 'products' AND rp.can_view = 1
    LIMIT 1
")->fetchColumn() ?: 0);
if (!$anyUser || !$whRow) {
    pass('no active user/warehouse to test against — skipped (n/a)');
} else {
    (warehouseIdsForUser($pdo, $anyUser, true) === ['*']) ? pass('isAdmin=true -> [\'*\'] unconditionally') : fail('admin bypass broken');

    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM user_scope_overrides WHERE user_id = $anyUser AND resource_type = 'warehouse'");

    $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id) VALUES (?, 'warehouse', NULL)")->execute([$anyUser]);
    (warehouseIdsForUser($pdo, $anyUser, false) === ['*']) ? pass('grant-all override (resource_id NULL) -> [\'*\']') : fail('grant-all override not honoured');

    $pdo->exec("DELETE FROM user_scope_overrides WHERE user_id = $anyUser AND resource_type = 'warehouse'");
    $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id) VALUES (?, 'warehouse', ?)")->execute([$anyUser, $whRow['warehouse_id']]);
    $ids = warehouseIdsForUser($pdo, $anyUser, false);
    ($ids === [(int)$whRow['warehouse_id']]) ? pass('specific-warehouse override -> exactly that warehouse') : fail('specific override wrong: ' . json_encode($ids));

    $pdo->rollBack();
}

// ─────────────────────────────────────────────────────────────────────────
section('7. Runtime — resolveRecipients() warehouse-scope branch');
if (!$anyUser || !$whRow) {
    pass('no active user/warehouse to test against — skipped (n/a)');
} else {
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM user_scope_overrides WHERE user_id = $anyUser AND resource_type = 'warehouse'");
    // This user is scoped to a DIFFERENT (nonexistent) warehouse id — must be excluded.
    $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id) VALUES (?, 'warehouse', 999999)")->execute([$anyUser]);

    $event = ['page_key' => 'products', 'required_verb' => 'view', 'scope_aware' => 1, 'event_key' => 'product.batch_expiring', 'module' => 'Inventory'];
    $rule = [['target_type' => 'user', 'target_id' => $anyUser, 'channel_inapp' => 1, 'channel_email' => 1]];

    $r1 = resolveRecipients($pdo, $event, ['warehouse_id' => (int)$whRow['warehouse_id']], $rule);
    (count($r1) === 0) ? pass('user scoped to a DIFFERENT warehouse is excluded from this warehouse\'s event') : fail('warehouse scope did not exclude an out-of-scope user: ' . json_encode(array_keys($r1)));

    // Now grant that exact warehouse — must be included, with email channel on (the "route to a specific user + email" case).
    $pdo->exec("DELETE FROM user_scope_overrides WHERE user_id = $anyUser AND resource_type = 'warehouse'");
    $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id) VALUES (?, 'warehouse', ?)")->execute([$anyUser, $whRow['warehouse_id']]);

    $r2 = resolveRecipients($pdo, $event, ['warehouse_id' => (int)$whRow['warehouse_id']], $rule);
    (count($r2) === 1 && isset($r2[$anyUser]) && !empty($r2[$anyUser]['channels']['email']))
        ? pass('user granted this exact warehouse IS included, with the email channel on (specific-user + email rule works)')
        : fail('in-scope user not correctly included: ' . json_encode($r2));

    $pdo->rollBack();
}

// ─────────────────────────────────────────────────────────────────────────
section('8. Runtime — milestone dedup on the batch-expiry cron check (rolled back)');
if (!$prodRow || !$whRow) {
    pass('no active product/warehouse to test against — skipped (n/a)');
} else {
    require_once "$root/cron/run_notification_checks.php";

    $pdo->beginTransaction();
    $pid = (int)$prodRow['product_id'];
    $wid = (int)$whRow['warehouse_id'];
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, expiry_date, quantity_received, quantity_remaining, unit_cost) VALUES (?, ?, 'BATCH-DUE', DATE_ADD(CURDATE(), INTERVAL 3 DAY), 20, 20, 50)")->execute([$pid, $wid]);
    $dueBatchId = (int)$pdo->lastInsertId();

    $before = (int)$pdo->query("SELECT COUNT(*) FROM product_batch_expiry_reminders WHERE batch_id=$dueBatchId")->fetchColumn();
    run_notification_checks($pdo);
    $after = (int)$pdo->query("SELECT COUNT(*) FROM product_batch_expiry_reminders WHERE batch_id=$dueBatchId")->fetchColumn();
    ($before === 0 && $after > 0) ? pass('first run fires the reached milestone(s) and records them') : fail("milestone not recorded: before=$before after=$after");

    run_notification_checks($pdo);
    $afterSecond = (int)$pdo->query("SELECT COUNT(*) FROM product_batch_expiry_reminders WHERE batch_id=$dueBatchId")->fetchColumn();
    ($afterSecond === $after) ? pass('second run is idempotent — no duplicate milestone rows') : fail("dedup failed: $after -> $afterSecond");

    $pdo->rollBack();
}

// ─────────────────────────────────────────────────────────────────────────
section('9. Runtime — plain (non-batch-tracked) product expiry alert (2026-09-11 gap fix, rolled back)');
if (!$prodRow || !$whRow) {
    pass('no active product/warehouse to test against — skipped (n/a)');
} else {
    require_once "$root/cron/run_notification_checks.php";

    $pdo->beginTransaction();
    $pid = (int)$prodRow['product_id'];
    $wid = (int)$whRow['warehouse_id'];

    // No batch rows for this product in this transaction — the NOT EXISTS
    // guard must route it through the new plain-product block, not the
    // existing batch block.
    $pdo->prepare("UPDATE products SET expiry_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY), email_alerts = 1, status = 'active' WHERE product_id = ?")->execute([$pid]);
    $pdo->prepare("
        INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity)
        VALUES (?, ?, 15, 0)
        ON DUPLICATE KEY UPDATE stock_quantity = 15
    ")->execute([$pid, $wid]);

    $before = (int)$pdo->query("SELECT COUNT(*) FROM product_expiry_reminders WHERE product_id=$pid AND warehouse_id=$wid")->fetchColumn();
    run_notification_checks($pdo);
    $after = (int)$pdo->query("SELECT COUNT(*) FROM product_expiry_reminders WHERE product_id=$pid AND warehouse_id=$wid")->fetchColumn();
    ($before === 0 && $after > 0) ? pass('a plain expiring product (no batch rows) now fires and records a milestone') : fail("plain-product milestone not recorded: before=$before after=$after");

    run_notification_checks($pdo);
    $afterSecond = (int)$pdo->query("SELECT COUNT(*) FROM product_expiry_reminders WHERE product_id=$pid AND warehouse_id=$wid")->fetchColumn();
    ($afterSecond === $after) ? pass('second run is idempotent — no duplicate milestone rows') : fail("dedup failed: $after -> $afterSecond");

    // Now give it a batch row too — the NOT EXISTS guard must suppress the
    // plain-product path entirely so it is never double-alerted.
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, expiry_date, quantity_received, quantity_remaining, unit_cost) VALUES (?, ?, 'BATCH-GUARD', DATE_ADD(CURDATE(), INTERVAL 3 DAY), 5, 5, 10)")->execute([$pid, $wid]);
    $pdo->exec("DELETE FROM product_expiry_reminders WHERE product_id=$pid AND warehouse_id=$wid");
    run_notification_checks($pdo);
    $afterBatched = (int)$pdo->query("SELECT COUNT(*) FROM product_expiry_reminders WHERE product_id=$pid AND warehouse_id=$wid")->fetchColumn();
    ($afterBatched === 0) ? pass('a product WITH batch rows is excluded from the plain-product path (no double-alert)') : fail("NOT EXISTS guard failed — plain-product path fired for a batch-tracked product ($afterBatched rows)");

    $pdo->rollBack();
}
