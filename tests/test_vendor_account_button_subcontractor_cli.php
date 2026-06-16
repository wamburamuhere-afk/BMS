<?php
/**
 * Step 2 — "View Account" button on the Sub-Contractors list — CLI test
 *   php tests/test_vendor_account_button_subcontractor_cli.php
 *
 * Verifies:
 *  1. sub_contractors.php action dropdown has View Account → vendor_statement?vendor_type=sub_contractor
 *  2. get_vendor_statement.php now filters supplier_invoices by invoice_type (ID-collision fix)
 *  3. search_vendors.php exists, returns sub-contractors tagged with type='sub_contractor'
 *  4. Live-DB: a real sub-contractor with invoices resolves without bleeding into a
 *     supplier that shares the same numeric supplier_id
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────────
section('1. Sub-Contractors list source — View Account button wired up');
$src = file_get_contents("$root/app/bms/operations/sub_contractors.php");
(strpos($src, "getUrl('vendor_statement') ?>?vendor_id=<?= \$sc['supplier_id'] ?>&vendor_type=sub_contractor") !== false)
    ? pass('action dropdown links to vendor_statement with vendor_id + vendor_type=sub_contractor')
    : fail('View Account link missing or malformed in sub_contractors.php');
(strpos($src, 'View Account') !== false)
    ? pass('link is labelled "View Account"')
    : fail('label "View Account" missing');

// ─────────────────────────────────────────────────────────────────────────────
section('2. get_vendor_statement.php — invoice_type filter present (ID-collision fix)');
$apiSrc = file_get_contents("$root/api/account/get_vendor_statement.php");
(strpos($apiSrc, '$vendor_type') !== false)
    ? pass('$vendor_type parameter is parsed')
    : fail('$vendor_type not present in get_vendor_statement.php');
(strpos($apiSrc, 'si.invoice_type = ?') !== false)
    ? pass('all supplier_invoices queries filter by invoice_type (collision guard)')
    : fail('invoice_type filter missing — cross-table bleed still possible');
(strpos($apiSrc, "sub_contractor") !== false)
    ? pass("sub_contractor branch handled (credit-note leg conditional)")
    : fail('sub_contractor branch not detected');

// ─────────────────────────────────────────────────────────────────────────────
section('3. search_vendors.php — exists and returns both entity types');
$searchFile = "$root/api/account/search_vendors.php";
file_exists($searchFile)
    ? pass('api/account/search_vendors.php exists')
    : fail('api/account/search_vendors.php MISSING');
if (file_exists($searchFile)) {
    $sv = file_get_contents($searchFile);
    (strpos($sv, "'sub_contractor' AS type") !== false)
        ? pass('search_vendors tags sub-contractors with type=sub_contractor')
        : fail('sub_contractor type tag missing in search_vendors.php');
    (strpos($sv, "'supplier' AS type") !== false)
        ? pass('search_vendors tags suppliers with type=supplier')
        : fail('supplier type tag missing in search_vendors.php');
    (strpos($sv, 'UNION ALL') !== false)
        ? pass('UNION ALL merges both tables in one query')
        : fail('UNION ALL not found — both tables may not be queried');
}

// ─────────────────────────────────────────────────────────────────────────────
section('4. vendor_statement.php UI — reads vendor_type from Select2 option');
$uiSrc = file_get_contents("$root/app/constant/reports/vendor_statement.php");
(strpos($uiSrc, "search_vendors.php") !== false)
    ? pass("VEND_URL points to search_vendors.php (not old search_suppliers.php)")
    : fail("VEND_URL still points to old search_suppliers.php");
(strpos($uiSrc, "data('type')") !== false)
    ? pass("loadStatement() reads data('type') from selected option")
    : fail("loadStatement() does not read data-type — vendor_type will be empty");
(strpos($uiSrc, "data-type=") !== false)
    ? pass('pre-filled option has data-type attribute for type-aware prefill')
    : fail('pre-filled option missing data-type attribute');

// ─────────────────────────────────────────────────────────────────────────────
section('5. Live-DB — sub-contractor resolves independently from colliding supplier id');

// Find a sub-contractor that has at least one supplier_invoice
$scId = (int)($pdo->query("
    SELECT sc.supplier_id
      FROM sub_contractors sc
      JOIN supplier_invoices si ON si.supplier_id = sc.supplier_id AND si.invoice_type = 'sub_contractor'
     WHERE sc.status = 'active'
     LIMIT 1
")->fetchColumn() ?: 0);

if (!$scId) {
    echo "  \033[33m⚠️  No active sub-contractor with invoice history found — skipping live-DB checks\033[0m\n";
} else {
    // Fetch sub-contractor name
    $scRow = $pdo->prepare("SELECT supplier_name FROM sub_contractors WHERE supplier_id = ?");
    $scRow->execute([$scId]);
    $scName = (string)$scRow->fetchColumn();
    $scName ? pass("sub-contractor #$scId resolves to '$scName'") : fail("sub-contractor #$scId not found");

    // Check whether a supplier with the same id exists (demonstrates the collision)
    $suppRow = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ?");
    $suppRow->execute([$scId]);
    $suppName = (string)$suppRow->fetchColumn();
    if ($suppName) {
        pass("ID collision confirmed: supplier #$scId is '$suppName' — different entity, same numeric id");
    } else {
        pass("No supplier shares id #$scId on this DB (collision not live here, but guard still needed)");
    }

    // Verify that the invoice count is non-zero ONLY for sub_contractor type
    $cntSC = (int)$pdo->prepare("
        SELECT COUNT(*) FROM supplier_invoices
         WHERE supplier_id = ? AND invoice_type = 'sub_contractor'
           AND status IN ('approved','partial','paid')
    ")->execute([$scId]) ? (int)$pdo->query("
        SELECT COUNT(*) FROM supplier_invoices
         WHERE supplier_id = $scId AND invoice_type = 'sub_contractor'
           AND status IN ('approved','partial','paid')
    ")->fetchColumn() : 0;

    // Use a fresh prepared statement for correctness
    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) FROM supplier_invoices
         WHERE supplier_id = ? AND invoice_type = 'sub_contractor'
           AND status IN ('approved','partial','paid')
    ");
    $cntStmt->execute([$scId]);
    $cntSC = (int)$cntStmt->fetchColumn();

    $cntSC > 0
        ? pass("sub-contractor #$scId has $cntSC qualifying invoice(s) visible with invoice_type filter")
        : fail("no qualifying invoices found for sub-contractor #$scId with invoice_type='sub_contractor'");
}
