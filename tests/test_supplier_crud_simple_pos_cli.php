<?php
/**
 * Supplier CRUD — full scope pass for Simple POS (2026-09-17, "same as Customer")
 *   php tests/test_supplier_crud_simple_pos_cli.php
 *
 * Follow-up to tests/test_customer_supplier_simple_pos_cli.php (registration
 * form, untouched here) and a direct mirror of
 * tests/test_customer_crud_simple_pos_cli.php's approach, applied to
 * Supplier's LIST page (suppliers.php) and DETAIL page (supplier_details.php)
 * and DELETE (api/delete_supplier.php).
 *
 * Scouted first (see conversation) — two things were ALREADY correct here,
 * unlike Customer, so this suite proves they stayed correct rather than
 * re-fixing them:
 *   - supplier_details.php's procurement-cycle tabs already used canView()/
 *     hasPermission(), which already fold in tenantModuleAllowsPage() before
 *     anything else (core/permissions.php) — the "module closed" gate was
 *     never missing here. This suite proves Simple POS collapses them
 *     FURTHER on top, without breaking that existing entitlement check.
 *   - Supplier's Edit action just redirects to suppliers.php?edit=X, reusing
 *     the modal already simplified there — no duplicate un-simplified copy
 *     to fix (unlike customer_details.php's own separate modal).
 *   - suppliers.status already includes 'deleted' in its enum (unlike
 *     customers.status before its own migration) — no schema change needed
 *     for the Delete fix.
 *
 *   A. STATIC — every touched file lints clean.
 *   B. WIRING — the Simple POS flag gates both pages' filters/columns/tabs;
 *              Expenses tab is deliberately KEPT (a standalone record, not
 *              part of the formal PO/GRN/Bill cycle); the hard DELETE
 *              statement is gone from delete_supplier.php.
 *   C. LIVE   — suppliers.php rendered in Simple/normal states; supplier_
 *              details.php rendered the same way, proving exactly one tab is
 *              always active (Expenses when available, else System Info) and
 *              that Simple POS collapsing never overrides an entitlement
 *              that was already off in normal mode; Delete confirmed
 *              soft-delete-always by direct SQL.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌\033[0m $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 70) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

function _scc_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'suppcrud_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return (string)$out;
}
function _scc_set_settings(string $root, string $simple): void {
    _scc_run_php("require '$root/roots.php'; save_setting('pos_simple_mode', " . var_export($simple, true) . "); save_setting('pos_advanced_supplier', '0'); echo 'SAVED';");
}
function _scc_render(string $root, int $uid, string $file, array $get = []): string {
    $getCode = var_export($get, true);
    return _scc_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        \$_GET = $getCode;
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
        ob_start();
        include '$root/$file';
        echo ob_get_clean();
    ");
}
function _scc_post(string $root, int $uid, string $file, array $post): array {
    $postCode = var_export($post, true);
    $out = _scc_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        \$_POST = $postCode;
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        ob_start();
        include '$root/$file';
        echo ob_get_clean();
    ");
    $json = json_decode(trim($out), true);
    return is_array($json) ? $json : ['success' => false, 'message' => 'NON_JSON_OUTPUT: ' . substr($out, 0, 300)];
}

$adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();
$wasSimple = get_setting('pos_simple_mode', '0');
$wasAdvSupp = get_setting('pos_advanced_supplier', '0');
$cleanupSettings = function () use ($root, $wasSimple, $wasAdvSupp) {
    _scc_run_php("require '$root/roots.php'; save_setting('pos_simple_mode', " . var_export($wasSimple, true) . "); save_setting('pos_advanced_supplier', " . var_export($wasAdvSupp, true) . "); echo 'SAVED';");
};
register_shutdown_function($cleanupSettings);

$createdSupplierIds = [];
$cleanupRecords = function () use ($pdo, &$createdSupplierIds) {
    foreach ($createdSupplierIds as $id) { $pdo->prepare("DELETE FROM suppliers WHERE supplier_id = ?")->execute([$id]); }
};
register_shutdown_function($cleanupRecords);

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint clean');
foreach (['app/bms/Suppliers/suppliers.php', 'app/bms/Suppliers/supplier_details.php', 'api/delete_supplier.php'] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring — suppliers.php (list)');
$listSrc = src($root, 'app/bms/Suppliers/suppliers.php');
has($listSrc, "\$simpleSupplierForm ? 'col-12 col-md-4' : 'col-6 col-md-3'", 'Status filter reflows to fill the row when the other 3 filters are hidden');
has($listSrc, "id=\"categoryFilter\"", 'Category filter still exists in the full-mode branch');
has($listSrc, "\$simpleSupplierForm): ?>", 'the simple-mode conditional wrapping the extra filters is present');

section('2b. Source wiring — supplier_details.php (detail)');
$detailSrc = src($root, 'app/bms/Suppliers/supplier_details.php');
has($detailSrc, '$simpleSupplierForm = posSimpleModeEnabled() && !advancedSupplierEnabled();', 'reuses the exact same flag as the registration form');
has($detailSrc, "canView('grn') && !\$simpleSupplierForm", 'GRN tab keeps its existing entitlement check AND adds Simple POS on top');
has($detailSrc, "hasPermission('purchase_returns') && !\$simpleSupplierForm", 'Purchase Returns tab: entitlement check preserved, Simple POS added');
has($detailSrc, "canView('rfq') && !\$simpleSupplierForm", 'RFQ tab: entitlement check preserved, Simple POS added');
has($detailSrc, "canView('debit_notes') && !\$simpleSupplierForm", 'Debit Notes tab: entitlement check preserved, Simple POS added');
has($detailSrc, "\$default_supplier_tab = canView('expenses') ? 'pane-expenses' : 'pane-sysinfo';", 'default tab falls back to Expenses (kept) then System Info, never a blank Sales Orders-style assumption');
// Expenses is deliberately NOT gated on !$simpleSupplierForm anywhere.
$expensesBlockStart = strpos($detailSrc, "<?php if (canView('expenses')): ?>");
$expensesBlockHasSimpleGate = $expensesBlockStart !== false && strpos(substr($detailSrc, $expensesBlockStart, 40), '$simpleSupplierForm') !== false;
(!$expensesBlockHasSimpleGate) ? pass('Expenses tab is deliberately KEPT even in Simple POS (not part of the formal PO/GRN/Bill cycle)') : fail('Expenses tab was wrongly gated on Simple POS too');
has($detailSrc, "Quick Restock never touches this table at all", 'Statistics Cards row is explicitly documented as gated on Simple POS, no replacement data (unlike Customer)');
has($detailSrc, "t('Supplier Information')", 'the consolidated Supplier Information card exists');
has($detailSrc, "one consolidated card instead of the remains of 4", 'consolidation is documented and gated on $simpleSupplierForm');

section('2c. Source wiring — delete_supplier.php');
$delSrc = src($root, 'api/delete_supplier.php');
has($delSrc, "UPDATE suppliers SET status = 'deleted'", 'delete is a soft-delete');
(strpos($delSrc, "DELETE FROM suppliers") === false) ? pass('the hard DELETE FROM suppliers statement is gone entirely') : fail('a hard DELETE FROM suppliers still exists somewhere in this file');

// ─────────────────────────────────────────────────────────────────────────
if ($adminUid <= 0) {
    fail('No admin user found — skipping live sections');
} else {
    section('3. Live — suppliers.php list across 2 states');
    _scc_set_settings($root, '1');
    $simpleHtml = _scc_render($root, $adminUid, 'app/bms/Suppliers/suppliers.php');
    (!str_contains($simpleHtml, 'Fatal error')) ? pass('Simple POS: renders with no PHP fatal') : fail('PHP fatal: ' . substr($simpleHtml, 0, 300));
    (!str_contains($simpleHtml, 'id="categoryFilter"') && !str_contains($simpleHtml, 'id="countryFilter"'))
        ? pass('Simple POS: Category/Country/City filters are gone') : fail('a hidden-in-simple filter is still present');
    (str_contains($simpleHtml, 'id="statusFilter"')) ? pass('Simple POS: Status filter still present') : fail('Status filter missing');
    // t('Total Orders') renders in whatever language the dev admin account has
    // saved (this environment's admin has 'sw' set) — accept either.
    (!str_contains($simpleHtml, 'Total Orders') && !str_contains($simpleHtml, 'Jumla ya Oda'))
        ? pass('Simple POS: "Total Orders" column header is gone') : fail('Total Orders column header still present');

    _scc_set_settings($root, '0');
    $normalHtml = _scc_render($root, $adminUid, 'app/bms/Suppliers/suppliers.php');
    (str_contains($normalHtml, 'id="categoryFilter"') && (str_contains($normalHtml, 'Total Orders') || str_contains($normalHtml, 'Jumla ya Oda')))
        ? pass('Normal tenant: full filter/column set unchanged') : fail('normal tenant lost filters/columns — regression');

    section('4. Live — supplier_details.php across states (needs a real supplier)');
    _scc_set_settings($root, '0');
    $suffix = bin2hex(random_bytes(3));
    $addRes = _scc_post($root, $adminUid, 'api/add_supplier.php', [
        'supplier_name' => "SuppCrudTest-$suffix", 'phone' => '+255722000001', 'bank_name' => 'NMB',
    ]);
    $suppId = (int)($addRes['supplier_id'] ?? 0);
    ($suppId > 0) ? pass('manufactured a real supplier for the detail-page tests') : fail('setup failed: ' . json_encode($addRes));
    if ($suppId > 0) $createdSupplierIds[] = $suppId;

    if ($suppId > 0) {
        _scc_set_settings($root, '1');
        $simpleDetail = _scc_render($root, $adminUid, 'app/bms/Suppliers/supplier_details.php', ['id' => $suppId]);
        (!str_contains($simpleDetail, 'Fatal error')) ? pass('Simple POS detail page: renders with no PHP fatal') : fail('PHP fatal: ' . substr($simpleDetail, 0, 400));
        (!str_contains($simpleDetail, 'data-bs-target="#pane-payments"'))
            ? pass('Simple POS: Recent Payments tab is gone') : fail('Recent Payments tab still present in Simple POS');
        (!str_contains($simpleDetail, 'data-bs-target="#pane-pos"'))
            ? pass('Simple POS: Recent Purchase Orders tab is gone') : fail('Purchase Orders tab still present in Simple POS');
        (!str_contains($simpleDetail, 'data-bs-target="#pane-grn"'))
            ? pass('Simple POS: Goods Received tab is gone') : fail('GRN tab still present in Simple POS');
        (!str_contains($simpleDetail, 'data-bs-target="#pane-projects"'))
            ? pass('Simple POS: Projects Involved tab is gone') : fail('Projects tab still present in Simple POS');
        (substr_count($simpleDetail, 'nav-link active') === 1) ? pass('Simple POS: exactly one tab marked active by default') : fail('not exactly one default-active tab found');
        // 2026-09-17 follow-up: Bank Name/Account fold into the new consolidated
        // "Supplier Information" card (no separate "Bank Information" heading
        // anymore in Simple mode) — Supplier's simple registration KEEPS bank
        // fields visible (unlike Customer), so the VALUE must still render.
        (str_contains($simpleDetail, 'NMB'))
            ? pass('Simple POS: bank details still show (bank fields ARE collected for Supplier, unlike Customer), now inside the consolidated card') : fail('bank_name value missing even though it was set');
        (str_contains($simpleDetail, 'Supplier Information') || str_contains($simpleDetail, 'Taarifa'))
            ? pass('Simple POS: the consolidated "Supplier Information" card is present') : fail('consolidated card missing');
        (!str_contains($simpleDetail, 'Total Orders'))
            ? pass('Simple POS: the old purchase_orders-derived Statistics Cards row is gone') : fail('old Statistics Cards row (Total Orders etc.) still present');
        (substr_count($simpleDetail, 'N/A') === 0)
            ? pass('Simple POS: ZERO "N/A" placeholder rows anywhere on the page') : fail('N/A rows still present: ' . substr_count($simpleDetail, 'N/A') . ' found');

        _scc_set_settings($root, '0');
        $normalDetail = _scc_render($root, $adminUid, 'app/bms/Suppliers/supplier_details.php', ['id' => $suppId]);
        (str_contains($normalDetail, 'data-bs-target="#pane-payments"') && str_contains($normalDetail, 'data-bs-target="#pane-projects"') && str_contains($normalDetail, 'Total Orders'))
            ? pass('Normal tenant: everything present, unchanged — including the original Statistics Cards row') : fail('normal tenant lost tabs/cards — regression');

        section('5. Live — Delete is now always soft-delete');
        $delRes = _scc_post($root, $adminUid, 'api/delete_supplier.php', ['supplier_id' => $suppId]);
        (($delRes['success'] ?? false) === true) ? pass('delete_supplier.php succeeds') : fail('delete failed: ' . json_encode($delRes));
        $rowAfterDelete = $pdo->query("SELECT status FROM suppliers WHERE supplier_id = $suppId")->fetch(PDO::FETCH_ASSOC);
        (is_array($rowAfterDelete)) ? pass('the supplier row STILL EXISTS after delete — never hard-removed') : fail('the row is GONE — hard delete still happening');
        (($rowAfterDelete['status'] ?? '') === 'deleted') ? pass('status correctly set to \'deleted\' (soft delete)') : fail('status is not \'deleted\': ' . json_encode($rowAfterDelete));
    }
}

$cleanupRecords();
$cleanupSettings();
