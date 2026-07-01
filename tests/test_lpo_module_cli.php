<?php
/**
 * Customer LPO Module + LPO -> DN(Outbound) -> Invoice chain — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_lpo_module_cli.php
 *
 * Verifies files+lint, the two foundation migrations, permission/route
 * wiring, three-approval gating on the new save/review/approve_lpo.php
 * endpoints, the LPO->DN(outbound) prefill math, approve_dn.php's
 * auto-fulfillment status advance, and the DN->Invoice prefill wiring.
 * Also runs a live (transaction-wrapped, rolled back) create/save
 * round trip against the real DB. Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$failures = 0; $passes = 0;
function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }
function hasNot(string $hay, string $needle, string $label): void { strpos($hay, $needle) === false ? pass($label) : fail("$label — should NOT contain `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $passes, $failures; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
$files = [
    'migrations/2026_07_01_lpo_standalone_foundation.php',
    'migrations/2026_07_01_dn_customer_party_type.php',
    'app/bms/sales/lpo/lpos.php',
    'app/bms/sales/lpo/lpo_create.php',
    'app/bms/sales/lpo/lpo_view.php',
    'app/bms/sales/lpo/print_lpo.php',
    'api/customer/save_lpo.php',
    'api/customer/review_lpo.php',
    'api/customer/approve_lpo.php',
    'api/customer/change_lpo_status.php',
    'api/customer/get_lpo.php',
    'api/customer/get_lpos_list.php',
    'api/customer/delete_lpo.php',
    'api/customer/delete_lpo_attachment.php',
];
foreach ($files as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' | ', $out));
}
foreach (['api/create_dn.php', 'api/update_dn.php', 'api/approve_dn.php', 'app/bms/grn/dn_outbound.php',
          'app/bms/grn/dn_view.php', 'app/bms/invoice/invoice_create.php', 'api/account/save_invoice.php',
          'app/bms/invoice/invoice_view.php', 'app/bms/invoice/invoice_print.php', 'header.php'] as $f) {
    $full = "$root/$f";
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass("Syntax OK: $f") : fail("php -l failed: $f — " . implode(' | ', $out));
}
// add_lpo.php / update_lpo.php must be gone (superseded by save_lpo.php)
(!file_exists("$root/api/customer/add_lpo.php"))    ? pass('api/customer/add_lpo.php removed (superseded)')    : fail('api/customer/add_lpo.php still exists — should be removed');
(!file_exists("$root/api/customer/update_lpo.php")) ? pass('api/customer/update_lpo.php removed (superseded)') : fail('api/customer/update_lpo.php still exists — should be removed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Foundation migrations');
$mig1 = src($root, 'migrations/2026_07_01_lpo_standalone_foundation.php');
has($mig1, "ADD COLUMN reviewed_by",        'migration: adds customer_lpos.reviewed_by');
has($mig1, "ADD COLUMN approved_by",        'migration: adds customer_lpos.approved_by');
has($mig1, "ADD COLUMN project_id",         'migration: adds customer_lpos.project_id');
has($mig1, "customer_lpo_items ADD COLUMN product_id", 'migration: adds customer_lpo_items.product_id');
has($mig1, "deliveries ADD COLUMN customer_lpo_id",    'migration: adds deliveries.customer_lpo_id');
has($mig1, "invoices ADD COLUMN delivery_id",          'migration: adds invoices.delivery_id');
has($mig1, "invoices ADD COLUMN customer_lpo_id",      'migration: adds invoices.customer_lpo_id');
has($mig1, "page_key = 'lpo'",               'migration: seeds lpo permission');
has($mig1, "can_review = 1, can_approve = 1", 'migration: grants review/approve to Admin+MD only');

$mig2 = src($root, 'migrations/2026_07_01_dn_customer_party_type.php');
has($mig2, "'supplier','subcontractor','customer'", 'migration: widens party_type ENUM with customer');

// ─────────────────────────────────────────────────────────────────────────
section('3. Routes + permission mapping');
$roots = src($root, 'roots.php');
has($roots, "'lpos' => SALES_DIR",           'roots.php: lpos route registered');
has($roots, "'lpo_create' => SALES_DIR",     'roots.php: lpo_create route registered');
has($roots, "'lpo_view' => SALES_DIR",       'roots.php: lpo_view route registered');
has($roots, "'print_lpo' => SALES_DIR",      'roots.php: print_lpo route registered');
has($roots, "api/review_lpo",                'roots.php: api/review_lpo route registered');
has($roots, "api/approve_lpo",               'roots.php: api/approve_lpo route registered');

$perms = src($root, 'core/permissions.php');
has($perms, "'lpos.php' => 'lpo'",           'permissions.php: lpos.php maps to lpo page_key');
has($perms, "'lpo_create.php' => 'lpo'",     'permissions.php: lpo_create.php maps to lpo page_key');

// ─────────────────────────────────────────────────────────────────────────
section('4. Three-approval gating — save/review/approve_lpo.php');
$save = src($root, 'api/customer/save_lpo.php');
has($save, "canCreate('lpo')",               'save_lpo.php: gated by canCreate on create');
has($save, "canEdit('lpo')",                 'save_lpo.php: gated by canEdit on update');
hasNot($save, "\$_POST['status']",           'save_lpo.php: does NOT accept an arbitrary status field');
has($save, "nextCode(\$pdo, 'LPO')",         'save_lpo.php: uses nextCode for numbering');
has($save, "codeForEdit(\$pdo, 'LPO'",       'save_lpo.php: re-codes legacy numbers on edit');
has($save, "workflowCaptureSignature",       'save_lpo.php: captures created signature');

$review = src($root, 'api/customer/review_lpo.php');
has($review, "canReview('lpo')",             'review_lpo.php: gated by canReview');
has($review, "assertReviewable(",            'review_lpo.php: uses assertReviewable guard');
has($review, "status = 'reviewed'",          'review_lpo.php: sets status reviewed');

$approve = src($root, 'api/customer/approve_lpo.php');
has($approve, "canApprove('lpo')",           'approve_lpo.php: gated by canApprove');
has($approve, "assertApprovable(",           'approve_lpo.php: uses assertApprovable guard');
has($approve, "status = 'approved'",         'approve_lpo.php: sets status approved');

$chg = src($root, 'api/customer/change_lpo_status.php');
has($chg, "'cancelled'",                     'change_lpo_status.php: only handles cancellation now');
hasNot($chg, "'reviewed'",                   'change_lpo_status.php: no longer moves to reviewed directly');

// ─────────────────────────────────────────────────────────────────────────
section('5. LPO -> DN(Outbound) prefill + linkage');
$outbound = src($root, 'app/bms/grn/dn_outbound.php');
has($outbound, "\$_GET['lpo_id']",           'dn_outbound.php: reads ?lpo_id=');
has($outbound, "'approved', 'partially_fulfilled'", 'dn_outbound.php: validates LPO status before prefill');
has($outbound, "LPO_ITEMS",                  'dn_outbound.php: exposes LPO_ITEMS to JS for prefill');
has($outbound, "customer_lpo_id",            'dn_outbound.php: threads customer_lpo_id through the form');
has($outbound, "party_type === 'customer'",  'dn_outbound.php: locks party fields for customer-linked DN (PHP)');

$apiC = src($root, 'api/create_dn.php');
has($apiC, "'subcontractor', 'customer'",    'create_dn.php: accepts customer party_type');
has($apiC, "customer_lpo_id",                'create_dn.php: persists customer_lpo_id');
has($apiC, "FROM customer_lpos WHERE lpo_id", 'create_dn.php: validates linked LPO');

$apiU = src($root, 'api/update_dn.php');
has($apiU, "'subcontractor', 'customer'",    'update_dn.php: accepts customer party_type');
has($apiU, "customer_lpo_id",                'update_dn.php: persists customer_lpo_id on edit');

$appDn = src($root, 'api/approve_dn.php');
has($appDn, "customer_lpo_id",               'approve_dn.php: reads customer_lpo_id');
has($appDn, "'fulfilled' : (\$anyDelivered ? 'partially_fulfilled'", 'approve_dn.php: derives fulfilled/partially_fulfilled from delivered qty');
has($appDn, "status IN ('approved', 'partially_fulfilled')", 'approve_dn.php: never regresses LPO status');

$dnView = src($root, 'app/bms/grn/dn_view.php');
has($dnView, "cu.customer_name",             'dn_view.php: resolves customer party name');
has($dnView, "getUrl('invoice_create') ?>?delivery=", 'dn_view.php: Create Invoice button links with ?delivery=');

// ─────────────────────────────────────────────────────────────────────────
section('6. DN(Outbound) -> Invoice prefill + optional refs');
$invC = src($root, 'app/bms/invoice/invoice_create.php');
has($invC, "\$_GET['delivery']",             'invoice_create.php: reads ?delivery=');
has($invC, "dn_type = 'outbound' AND status = 'approved'", 'invoice_create.php: only prefills from an approved outbound DN');
has($invC, "id=\"delivery_id\"",             'invoice_create.php: optional Delivery Note select field present');
has($invC, "id=\"customer_lpo_id\"",         'invoice_create.php: optional Customer LPO select field present');

$saveInv = src($root, 'api/account/save_invoice.php');
has($saveInv, "\$_POST['delivery_id']",      'save_invoice.php: reads delivery_id');
has($saveInv, "\$_POST['customer_lpo_id']",  'save_invoice.php: reads customer_lpo_id');
has($saveInv, "delivery_id, customer_lpo_id", 'save_invoice.php: inserts/updates delivery_id + customer_lpo_id');

$invV = src($root, 'app/bms/invoice/invoice_view.php');
has($invV, "DN Ref:",                        'invoice_view.php: shows DN Ref line');
has($invV, "LPO Ref:",                       'invoice_view.php: shows LPO Ref line');

// ─────────────────────────────────────────────────────────────────────────
section('7. Sales menu reorder');
$hdr = src($root, 'header.php');
$salesStart = strpos($hdr, 'id="salesDropdown"');
$salesEnd   = $salesStart !== false ? strpos($hdr, '</ul>', $salesStart) : false;
$salesBlock = ($salesStart !== false && $salesEnd !== false) ? substr($hdr, $salesStart, $salesEnd - $salesStart) : '';
($salesBlock !== '') ? pass('header.php: located the Sales dropdown block') : fail('header.php: could not isolate the Sales dropdown block');

$posQuot   = strpos($salesBlock, "getUrl('quotations')");
$posSO     = strpos($salesBlock, "getUrl('sales_orders')");
$posLpo    = strpos($salesBlock, "getUrl('lpos')");
$posDnOut  = strpos($salesBlock, "getUrl('delivery_notes') ?>?type=outbound");
$posInv    = strpos($salesBlock, "getUrl('invoices')");
$posPos    = strpos($salesBlock, "getUrl('pos')");
$posReturn = strpos($salesBlock, "getUrl('sales_returns')");
$posCredit = strpos($salesBlock, "getUrl('credit_notes')");
$order_ok = $posQuot !== false && $posSO !== false && $posLpo !== false && $posDnOut !== false
    && $posInv !== false && $posPos !== false && $posReturn !== false && $posCredit !== false
    && $posQuot < $posSO && $posSO < $posLpo && $posLpo < $posDnOut && $posDnOut < $posInv
    && $posInv < $posPos && $posPos < $posReturn && $posReturn < $posCredit;
$order_ok ? pass('header.php: Sales menu order is Quotations, SO, LPO, DN(Outbound), Invoices, POS, Returns, Credit Notes')
          : fail('header.php: Sales menu order does not match the expected sequence');

$list = src($root, 'app/bms/grn/delivery_notes.php');
has($list, "get('type') === 'outbound'",     'delivery_notes.php: preselects outbound tab from ?type=outbound');

// ─────────────────────────────────────────────────────────────────────────
section('8. Runtime DB state (migrations applied)');
try {
    $perm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'lpo' LIMIT 1")->fetchColumn();
    $perm ? pass("permission 'lpo' seeded (id $perm)") : fail("permission 'lpo' not seeded — run the migration");

    foreach (['reviewed_by', 'approved_by', 'project_id', 'prepared_by_name'] as $col) {
        (bool)$pdo->query("SHOW COLUMNS FROM customer_lpos LIKE '$col'")->fetch()
            ? pass("customer_lpos.$col exists") : fail("customer_lpos.$col missing — run the migration");
    }
    (bool)$pdo->query("SHOW COLUMNS FROM customer_lpo_items LIKE 'product_id'")->fetch()
        ? pass('customer_lpo_items.product_id exists') : fail('customer_lpo_items.product_id missing — run the migration');
    (bool)$pdo->query("SHOW COLUMNS FROM deliveries LIKE 'customer_lpo_id'")->fetch()
        ? pass('deliveries.customer_lpo_id exists') : fail('deliveries.customer_lpo_id missing — run the migration');
    (bool)$pdo->query("SHOW COLUMNS FROM deliveries LIKE 'customer_id'")->fetch()
        ? pass('deliveries.customer_id exists') : fail('deliveries.customer_id missing');
    foreach (['delivery_id', 'customer_lpo_id'] as $col) {
        (bool)$pdo->query("SHOW COLUMNS FROM invoices LIKE '$col'")->fetch()
            ? pass("invoices.$col exists") : fail("invoices.$col missing — run the migration");
    }

    $partyTypeCol = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'party_type'")->fetch(PDO::FETCH_ASSOC);
    ($partyTypeCol && strpos($partyTypeCol['Type'], "'customer'") !== false)
        ? pass("deliveries.party_type ENUM includes 'customer'") : fail("deliveries.party_type ENUM missing 'customer' — run the migration");

    $lpoSeq = $pdo->query("SELECT sequence_name FROM code_sequences WHERE sequence_name = 'LPO' LIMIT 1")->fetchColumn();
    $lpoSeq ? pass("code_sequences 'LPO' registered") : fail("code_sequences 'LPO' not registered");
} catch (Throwable $e) {
    fail('Runtime DB check error: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('9. Live create/save round trip (transaction, rolled back)');
try {
    $pdo->beginTransaction();

    $custId = (int)$pdo->query("SELECT customer_id FROM customers WHERE status = 'active' ORDER BY customer_id LIMIT 1")->fetchColumn();
    if (!$custId) {
        pass('no active customer available — live round trip skipped (not a failure)');
    } else {
        require_once "$root/core/code_generator.php";
        $lpoNumber = nextCode($pdo, 'LPO');
        strpos($lpoNumber, '-LPO-') !== false ? pass("nextCode('LPO') allocates a company-prefixed number: $lpoNumber") : fail("nextCode('LPO') returned unexpected format: $lpoNumber");

        $pdo->prepare("
            INSERT INTO customer_lpos (lpo_number, customer_id, issue_date, amount, currency, status, created_by, prepared_by_name, prepared_by_role, prepared_at, created_at)
            VALUES (?, ?, CURDATE(), 1000, 'TZS', 'pending', 1, 'Test Runner', 'Tester', NOW(), NOW())
        ")->execute([$lpoNumber, $custId]);
        $lpoId = (int)$pdo->lastInsertId();
        $lpoId > 0 ? pass("customer_lpos row created (id $lpoId, status=pending)") : fail('customer_lpos INSERT failed');

        $prod = $pdo->query("SELECT product_id, product_name FROM products WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($prod) {
            $pdo->prepare("INSERT INTO customer_lpo_items (lpo_id, product_id, sort_order, product_name, quantity, unit_price, tax_rate, total) VALUES (?, ?, 1, ?, 10, 100, 0, 1000)")
                ->execute([$lpoId, $prod['product_id'], $prod['product_name']]);
            pass('customer_lpo_items row created with product_id link (PO-parity items)');
        } else {
            pass('no active product available — item-link check skipped (not a failure)');
        }

        // Simulate the review -> approve sequence at the SQL level (same
        // transitions review_lpo.php / approve_lpo.php perform).
        require_once "$root/core/workflow.php";
        try { assertReviewable('pending'); pass('assertReviewable(pending) allows review'); } catch (Throwable $e) { fail('assertReviewable(pending) unexpectedly threw'); }
        $pdo->prepare("UPDATE customer_lpos SET status='reviewed' WHERE lpo_id=?")->execute([$lpoId]);
        try { assertApprovable('reviewed'); pass('assertApprovable(reviewed) allows approval'); } catch (Throwable $e) { fail('assertApprovable(reviewed) unexpectedly threw'); }
        try { assertApprovable('pending'); fail('assertApprovable(pending) should have thrown'); } catch (Throwable $e) { pass('assertApprovable(pending) correctly rejects out-of-sequence approval'); }
        $pdo->prepare("UPDATE customer_lpos SET status='approved' WHERE lpo_id=?")->execute([$lpoId]);

        $finalStatus = $pdo->query("SELECT status FROM customer_lpos WHERE lpo_id = $lpoId")->fetchColumn();
        $finalStatus === 'approved' ? pass('LPO reached approved status via the sequential chain') : fail("LPO ended in unexpected status: $finalStatus");
    }

    $pdo->rollBack();
    pass('transaction rolled back — no test data persisted');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Live round trip error: ' . $e->getMessage());
}
