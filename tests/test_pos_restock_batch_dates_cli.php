<?php
/**
 * POS Restock modal — Manufacturing/Expiry Date fields — CLI test
 *   php tests/test_pos_restock_batch_dates_cli.php
 *
 * Covers products_simple_pos_plan.md §8: the Restock modal
 * (app/bms/pos/pos_modals_new.php) gains two OPTIONAL date fields —
 * Manufacturing Date and Expiry Date — present in every mode (forcing an
 * expiry date on every restock would break for non-perishable goods).
 * api/pos/quick_restock.php passes both through to the already-shared
 * receiveProductBatch() (same helper GRN approval and product creation use).
 * The already-built expiry-notification cron needs no changes — it just
 * starts seeing real dates in product_batches.
 *
 *   A. STATIC  — files lint clean; source wiring present.
 *   B. RENDERED — the modal renders both new fields, in both Simple and
 *                 normal POS mode.
 *   C. RUNTIME — the real api/pos/quick_restock.php endpoint, called twice
 *                (dates given / dates omitted), confirming the batch row
 *                gets exactly what was submitted either way.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 70) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

function _prd_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'restockdates_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function _prd_set_simple(string $root, string $simple): void {
    _prd_run_php("require '$root/roots.php'; save_setting('pos_simple_mode', " . var_export($simple, true) . "); echo 'SAVED';");
}
function _prd_render_modal(string $root, int $uid): string {
    return _prd_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
        \$can_restock_product = true;
        ob_start();
        include '$root/app/bms/pos/pos_modals_new.php';
        echo ob_get_clean();
    ");
}
function _prd_run_endpoint(string $root, int $uid, array $post): string {
    $postExport = var_export($post, true);
    return _prd_run_php("
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['csrf_token'] = 'test-token';
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        \$_POST = $postExport;
        \$_POST['_csrf'] = 'test-token';
        ob_start();
        include '$root/api/pos/quick_restock.php';
        echo ob_get_clean();
    ");
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
foreach (['app/bms/pos/pos_modals_new.php', 'api/pos/quick_restock.php'] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
has(src($root, 'app/bms/pos/pos_modals_new.php'), 'id="restock_manufacturing_date" name="manufacturing_date"', 'modal has Manufacturing Date field');
has(src($root, 'app/bms/pos/pos_modals_new.php'), 'id="restock_expiry_date" name="expiry_date"', 'modal has Expiry Date field');
$quickRestockSrc = src($root, 'api/pos/quick_restock.php');
has($quickRestockSrc, "\$manufacturing_date     = !empty(\$_POST['manufacturing_date'])", 'endpoint reads manufacturing_date from POST (optional)');
has($quickRestockSrc, "\$expiry_date            = !empty(\$_POST['expiry_date'])", 'endpoint reads expiry_date from POST (optional)');
has($quickRestockSrc, "'manufacturing_date' => \$manufacturing_date", 'endpoint passes manufacturing_date into receiveProductBatch()');
has($quickRestockSrc, "'expiry_date'      => \$expiry_date", 'endpoint passes expiry_date into receiveProductBatch()');

// ─────────────────────────────────────────────────────────────────────────
section('3. Rendered HTML — the modal, both modes');

$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();
$simpleModeBefore = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();

if (!$uid) {
    pass('no admin user fixture available — sections 3-4 skipped (n/a)');
} else {
    _prd_set_simple($root, '1');
    $simpleHtml = _prd_render_modal($root, $uid);
    has($simpleHtml, 'id="restock_manufacturing_date"', 'Simple mode render: Manufacturing Date present');
    has($simpleHtml, 'id="restock_expiry_date"', 'Simple mode render: Expiry Date present');

    _prd_set_simple($root, '0');
    $normalHtml = _prd_render_modal($root, $uid);
    has($normalHtml, 'id="restock_manufacturing_date"', 'Normal mode render: Manufacturing Date present');
    has($normalHtml, 'id="restock_expiry_date"', 'Normal mode render: Expiry Date present');
    has($normalHtml, 'id="restock_paid_from_account_id"', 'Normal mode render: Paid From still present (unrelated, unaffected)');

    _prd_set_simple($root, (string)($simpleModeBefore === false ? '0' : $simpleModeBefore));
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — the real endpoint, dates given and dates omitted');

$prodRow = $pdo->query("SELECT product_id FROM products WHERE status='active' AND is_service=0 AND track_inventory=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$whRow   = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$uid || !$prodRow || !$whRow) {
    pass('no admin user / product / warehouse fixture available — section 4 skipped (n/a)');
} else {
    $pid = (int)$prodRow['product_id'];
    $wid = (int)$whRow['warehouse_id'];

    function _prd_cleanup(PDO $pdo, int $productId, float $qty): void {
        $b = $pdo->prepare("SELECT batch_id FROM product_batches WHERE product_id = ? ORDER BY batch_id DESC LIMIT 1");
        $b->execute([$productId]);
        $batchId = $b->fetchColumn();
        if ($batchId) {
            $mv = $pdo->prepare("SELECT movement_id FROM stock_movements WHERE product_id = ? ORDER BY movement_id DESC LIMIT 1");
            $mv->execute([$productId]);
            $movementId = $mv->fetchColumn();
            if ($movementId) $pdo->prepare("DELETE FROM stock_movements WHERE movement_id = ?")->execute([$movementId]);
            $pdo->prepare("DELETE FROM product_batches WHERE batch_id = ?")->execute([$batchId]);
        }
        $pdo->prepare("UPDATE products SET current_stock = current_stock - ? WHERE product_id = ?")->execute([$qty, $productId]);
    }

    // 4a. Dates given.
    _prd_set_simple($root, '1');
    $withDatesOut = _prd_run_endpoint($root, $uid, [
        'product_id' => $pid, 'warehouse_id' => $wid, 'quantity' => 2,
        'date' => date('Y-m-d'), 'buying_price' => 500, 'wholesale_price' => '',
        'selling_price' => 800,
        'manufacturing_date' => '2026-04-01', 'expiry_date' => '2027-04-01',
    ]);
    $withDatesJson = json_decode($withDatesOut, true);
    if (empty($withDatesJson['success'])) {
        fail('endpoint call (dates given) failed: ' . ($withDatesJson['message'] ?? $withDatesOut));
    } else {
        pass('endpoint accepted the restock with Manufacturing/Expiry Date given');
        $b = $pdo->prepare("SELECT manufacturing_date, expiry_date FROM product_batches WHERE product_id = ? ORDER BY batch_id DESC LIMIT 1");
        $b->execute([$pid]);
        $row = $b->fetch(PDO::FETCH_ASSOC);
        ($row && $row['manufacturing_date'] === '2026-04-01') ? pass('manufacturing_date landed on the batch') : fail('manufacturing_date missing/wrong: ' . var_export($row['manufacturing_date'] ?? null, true));
        ($row && $row['expiry_date'] === '2027-04-01') ? pass('expiry_date landed on the batch') : fail('expiry_date missing/wrong: ' . var_export($row['expiry_date'] ?? null, true));
        _prd_cleanup($pdo, $pid, 2);
    }

    // 4b. Dates omitted — must still succeed, batch dates stay NULL (never forced).
    $noDatesOut = _prd_run_endpoint($root, $uid, [
        'product_id' => $pid, 'warehouse_id' => $wid, 'quantity' => 3,
        'date' => date('Y-m-d'), 'buying_price' => 600, 'wholesale_price' => '',
        'selling_price' => 900,
        // no manufacturing_date / expiry_date at all
    ]);
    $noDatesJson = json_decode($noDatesOut, true);
    if (empty($noDatesJson['success'])) {
        fail('endpoint call (dates omitted) failed: ' . ($noDatesJson['message'] ?? $noDatesOut));
    } else {
        pass('endpoint still succeeds with no dates submitted at all (non-perishable goods)');
        $b2 = $pdo->prepare("SELECT manufacturing_date, expiry_date FROM product_batches WHERE product_id = ? ORDER BY batch_id DESC LIMIT 1");
        $b2->execute([$pid]);
        $row2 = $b2->fetch(PDO::FETCH_ASSOC);
        ($row2 && $row2['manufacturing_date'] === null) ? pass('manufacturing_date correctly NULL when omitted') : fail('manufacturing_date unexpectedly set: ' . var_export($row2['manufacturing_date'] ?? null, true));
        ($row2 && $row2['expiry_date'] === null) ? pass('expiry_date correctly NULL when omitted') : fail('expiry_date unexpectedly set: ' . var_export($row2['expiry_date'] ?? null, true));
        _prd_cleanup($pdo, $pid, 3);
    }

    // Clean up the GL postings this test's two restocks created (same
    // matching pattern test_pos_quick_restock_cli.php's suite already uses:
    // by the product's name inside the posted description).
    $prodName = (string)$pdo->query("SELECT product_name FROM products WHERE product_id = $pid")->fetchColumn();
    if ($prodName !== '') {
        $j = $pdo->prepare("SELECT DISTINCT entry_id FROM journal_entry_items WHERE description LIKE ?");
        $j->execute(['%' . $prodName . '%']);
        foreach ($j->fetchAll(PDO::FETCH_COLUMN) as $entryId) {
            $je = $pdo->prepare("SELECT entry_date FROM journal_entries WHERE entry_id = ?");
            $je->execute([$entryId]);
            if ($je->fetchColumn() === date('Y-m-d')) {
                $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = ?")->execute([$entryId]);
                $pdo->prepare("DELETE FROM journal_entries WHERE entry_id = ?")->execute([$entryId]);
            }
        }
    }
}
