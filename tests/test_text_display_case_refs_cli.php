<?php
/**
 * Text Display Case — "References" gap-fill — CLI regression suite
 *   php tests/test_text_display_case_refs_cli.php
 *
 * Phase 1 wired caseFormat()/caseFormatJs() into the 11 master-data modules'
 * OWN list/view pages. This suite covers every OTHER place those same
 * entity names are referenced/displayed — POS screen, POS dashboard,
 * Z-report, Expenses, other reports, and shared Select2/AJAX endpoints —
 * built up batch by batch as each area is fixed.
 *
 * Same zero-fixture pattern as Phase 1's suite: temporarily rename one real
 * existing record to a distinctive string, hit the real endpoint/page under
 * 'upper' mode, assert the transform happened, restore the original value.
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

function _tdr_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'tdr_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}

// Calls a real api/*.php script in a fresh subprocess (admin session, GET
// params set), captures its JSON/HTML output.
function _tdr_call(string $root, int $uid, string $script, array $get = []): string {
    $getCode = '';
    foreach ($get as $k => $v) { $getCode .= "\$_GET[" . var_export($k, true) . "] = " . var_export($v, true) . ";\n        "; }
    return _tdr_run_php("
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
        $getCode
        ob_start();
        include '$root/$script';
        echo ob_get_clean();
    ");
}

function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 100) . "`"); }

function _tdr_set_mode(string $root, string $mode): void {
    _tdr_run_php("require '$root/roots.php'; save_setting('text_display_case', " . var_export($mode, true) . "); echo 'SET';");
}

// Full round trip: set 'upper' mode, rename one real record, call the
// endpoint, assert UPPERCASED text appears, restore original name + mode.
function _tdr_check(
    string $root, int $uid, PDO $pdo,
    string $table, string $idCol, int $id, string $nameCol,
    string $script, array $get, string $label
): void {
    $orig = $pdo->prepare("SELECT $nameCol FROM $table WHERE $idCol = ?");
    $orig->execute([$id]);
    $originalName = $orig->fetchColumn();
    if ($originalName === false) { fail("$label: fixture row $idCol=$id not found — skipped"); return; }

    $testLower = 'zz refs test ' . $idCol . $id;
    $testUpper = strtoupper($testLower);

    _tdr_run_php("
        require '$root/roots.php';
        save_setting('text_display_case', 'upper');
        \$pdo->prepare('UPDATE $table SET $nameCol = ? WHERE $idCol = ?')->execute([" . var_export($testLower, true) . ", $id]);
        echo 'SET';
    ");

    try {
        $out = _tdr_call($root, $uid, $script, $get);
        (strpos($out, $testUpper) !== false)
            ? pass("$label: UPPERCASED name found in output ('$testUpper')")
            : fail("$label: UPPERCASED name NOT found in output");
    } finally {
        _tdr_run_php("
            require '$root/roots.php';
            save_setting('text_display_case', 'as_typed');
            \$pdo->prepare('UPDATE $table SET $nameCol = ? WHERE $idCol = ?')->execute([" . var_export($originalName, true) . ", $id]);
            echo 'RESTORED';
        ");
    }
}

$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();

// ─────────────────────────────────────────────────────────────────────────
section('Batch A — shared Select2/AJAX search endpoints');

$touchedA = [
    'api/account/search_customers.php', 'api/account/search_suppliers.php',
    'api/pos/search_customers.php', 'api/pos/search_products_for_restock.php',
    'api/search_products.php', 'api/purchase/search_debit_suppliers.php',
    'api/sales/search_credit_customers.php',
];
foreach ($touchedA as $f) {
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg("$root/$f") . " 2>&1", $out, $rc);
    $rc === 0 ? pass("lint: $f") : fail("php -l failed: $f — " . implode(' ', $out));
}

if ($uid) {
    $cid = (int)$pdo->query("SELECT customer_id FROM customers WHERE status != 'deleted' LIMIT 1")->fetchColumn();
    if ($cid) _tdr_check($root, $uid, $pdo, 'customers', 'customer_id', $cid, 'customer_name',
        'api/account/search_customers.php', ['q' => 'zz refs test'], 'search_customers (account/reports)');

    $sid = (int)$pdo->query("SELECT s.supplier_id FROM suppliers s JOIN purchase_orders po ON po.supplier_id = s.supplier_id WHERE s.status = 'active' LIMIT 1")->fetchColumn();
    if ($sid) _tdr_check($root, $uid, $pdo, 'suppliers', 'supplier_id', $sid, 'supplier_name',
        'api/account/search_suppliers.php', ['q' => 'zz refs test'], 'search_suppliers (account/reports)');

    $pcid = (int)$pdo->query("SELECT customer_id FROM customers WHERE status = 'active' LIMIT 1")->fetchColumn();
    if ($pcid) _tdr_check($root, $uid, $pdo, 'customers', 'customer_id', $pcid, 'customer_name',
        'api/pos/search_customers.php', ['q' => 'zz refs test'], 'pos/search_customers');

    $prid = (int)$pdo->query("SELECT product_id FROM products WHERE status != 'deleted' AND is_service != 1 LIMIT 1")->fetchColumn();
    if ($prid) _tdr_check($root, $uid, $pdo, 'products', 'product_id', $prid, 'product_name',
        'api/pos/search_products_for_restock.php', ['q' => 'zz refs test'], 'pos/search_products_for_restock');

    $sp = (int)$pdo->query("SELECT product_id FROM products WHERE status = 'active' LIMIT 1")->fetchColumn();
    if ($sp) _tdr_check($root, $uid, $pdo, 'products', 'product_id', $sp, 'product_name',
        'api/search_products.php', ['q' => 'zz refs test'], 'search_products (text label only)');
} else {
    pass('no admin user fixture available — Batch A live checks skipped (n/a)');
}

// ─────────────────────────────────────────────────────────────────────────
section('Batch B — POS screen (grid, cart, credit aging, customer display, receipt)');

$touchedB = [
    'app/bms/pos/pos_scripts_new.php', 'app/bms/pos/pos_modals_new.php',
    'assets/js/pos-credit-aging.js', 'app/bms/pos/customer_display.php',
    'app/bms/pos/price_groups.php', 'api/pos/print_receipt.php',
];
foreach ($touchedB as $f) {
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg("$root/$f") . " 2>&1", $out, $rc);
    $rc === 0 ? pass("lint: $f") : fail("php -l failed: $f — " . implode(' ', $out));
}

// JS-render source checks — proves the exact render path was switched from
// the plain escape-only helper to the case-aware one, for every spot that
// can't be exercised as a subprocess (client-side template literals).
has(src($root, 'app/bms/pos/pos_scripts_new.php'), 'alt="${caseFormatJs(product.product_name)}"', 'POS grid tile: image alt uses caseFormatJs()');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), 'title="${caseFormatJs(product.product_name)}">${caseFormatJs(product.product_name)}', 'POS grid tile: name/title uses caseFormatJs()');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), '<h6>${caseFormatJs(currentProduct.product_name)}</h6>', 'POS quick-view: product name uses caseFormatJs()');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), '<strong class="small">${caseFormatJs(item.product_name)}</strong>', 'POS cart line: product name uses caseFormatJs()');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), "replace('%s', applyCaseModeJs(item.product_name))", 'POS price-override-below-min warning uses applyCaseModeJs()');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), '${applyCaseModeJs(item.product_name)} x${item.quantity}', 'POS WhatsApp receipt text uses applyCaseModeJs()');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), 'setCustomerSelection(existing.customer_id, applyCaseModeJs(existing.customer_name))', 'POS held-table-order resume uses applyCaseModeJs()');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), 'caseFormatJs(sale.customer_name) : PT.walkIn', 'POS held-sales list: customer name uses caseFormatJs()');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), 'setCustomerSelection(sale.customer_id, applyCaseModeJs(sale.customer_name))', 'POS held-sale load: customer name uses applyCaseModeJs()');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), '<span>${caseFormatJs(item.product_name)}</span>', 'POS discount picker: product name uses caseFormatJs()');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), "replace('%s', caseFormatJs(item.product_name)).replace('%s', newPrice", 'POS discount min-price error uses caseFormatJs()');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), "PT.resultingPriceNegative.replace('%s', caseFormatJs(item.product_name))", 'POS negative-price error uses caseFormatJs()');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), "'<span><strong>' + caseFormatJs(product.product_name) + '</strong><br>' +", 'POS barcode-scan toast uses caseFormatJs()');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), 'product_name:      product.product_name,', 'POS scanAddToCart: raw product_name preserved as the cart-line snapshot (never case-transformed at storage time)');
has(src($root, 'app/bms/pos/pos_scripts_new.php'), 'product_name: currentProduct.product_name,', 'POS addToCart: raw product_name preserved as the cart-line snapshot (never case-transformed at storage time)');

has(src($root, 'app/bms/pos/pos_modals_new.php'), 'caseFormat($_sup[\'supplier_name\'])', 'POS restock-modal supplier dropdown uses caseFormat()');

has(src($root, 'assets/js/pos-credit-aging.js'), "window.caseFormatJs(d) : '-'", 'Credit-aging table column: customer name uses caseFormatJs()');
has(src($root, 'assets/js/pos-credit-aging.js'), 'window.caseFormatJs(row.customer_name) : \'-\'', 'Credit-aging mobile card: customer name uses caseFormatJs()');
has(src($root, 'assets/js/pos-credit-aging.js'), 'window.applyCaseModeJs(row.customer_name) : \'-\'', 'Credit-aging repay modal: customer name uses applyCaseModeJs()');

has(src($root, 'app/bms/pos/customer_display.php'), '${caseFormatJs(item.product_name)}', 'Customer-facing 2nd-screen display: product name uses caseFormatJs()');
has(src($root, 'app/bms/pos/price_groups.php'), '${caseFormatJs(p.product_name)}', 'Price-group product grid: product name uses caseFormatJs()');

// Live end-to-end — print_receipt.php (the actual physical/PDF customer receipt)
if ($uid) {
    $fixture = $pdo->query("SELECT s.sale_id, s.customer_id, i.product_id
                               FROM pos_sales s JOIN pos_sale_items i ON i.sale_id = s.sale_id
                              WHERE s.customer_id IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$fixture) {
        $fixture = $pdo->query("SELECT s.sale_id, s.customer_id, i.product_id
                                   FROM pos_sales s JOIN pos_sale_items i ON i.sale_id = s.sale_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
    if ($fixture) {
        $saleId = (int)$fixture['sale_id'];
        $prodId = (int)$fixture['product_id'];
        $custId = $fixture['customer_id'] !== null ? (int)$fixture['customer_id'] : null;

        $origProduct = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ?");
        $origProduct->execute([$prodId]);
        $origProductName = $origProduct->fetchColumn();

        $origCustomerName = null;
        if ($custId) {
            $origCustomer = $pdo->prepare("SELECT customer_name FROM customers WHERE customer_id = ?");
            $origCustomer->execute([$custId]);
            $origCustomerName = $origCustomer->fetchColumn();
        }

        $testLower = 'zz refs receipt test';
        $testUpper = strtoupper($testLower);

        _tdr_run_php("
            require '$root/roots.php';
            save_setting('text_display_case', 'upper');
            \$pdo->prepare('UPDATE products SET product_name = ? WHERE product_id = ?')->execute([" . var_export($testLower, true) . ", $prodId]);
            " . ($custId ? "\$pdo->prepare('UPDATE customers SET customer_name = ? WHERE customer_id = ?')->execute([" . var_export($testLower . ' cust', true) . ", $custId]);" : "") . "
            echo 'SET';
        ");

        try {
            $html = _tdr_call($root, $uid, 'api/pos/print_receipt.php', ['id' => $saleId]);
            (strpos($html, $testUpper) !== false)
                ? pass("print_receipt.php: UPPERCASED product name found in the printed receipt")
                : fail("print_receipt.php: UPPERCASED product name NOT found in the printed receipt");
            if ($custId) {
                (strpos($html, strtoupper($testLower . ' cust')) !== false)
                    ? pass("print_receipt.php: UPPERCASED customer name found in the printed receipt")
                    : fail("print_receipt.php: UPPERCASED customer name NOT found in the printed receipt");
            }
        } finally {
            _tdr_run_php("
                require '$root/roots.php';
                save_setting('text_display_case', 'as_typed');
                \$pdo->prepare('UPDATE products SET product_name = ? WHERE product_id = ?')->execute([" . var_export($origProductName, true) . ", $prodId]);
                " . ($custId ? "\$pdo->prepare('UPDATE customers SET customer_name = ? WHERE customer_id = ?')->execute([" . var_export($origCustomerName, true) . ", $custId]);" : "") . "
                echo 'RESTORED';
            ");
        }
    } else {
        pass('no pos_sales fixture with items available — print_receipt.php live check skipped (n/a)');
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('Batch C — POS dashboard');

$rc = 0; $out = [];
exec("php -l " . escapeshellarg("$root/app/bms/pos/pos_dashboard.php") . " 2>&1", $out, $rc);
$rc === 0 ? pass('lint: app/bms/pos/pos_dashboard.php') : fail('php -l failed: app/bms/pos/pos_dashboard.php — ' . implode(' ', $out));

$dashSrc = src($root, 'app/bms/pos/pos_dashboard.php');
has($dashSrc, "{ data: 'name', render: d => caseFormatJs(d) }", 'Dashboard low-stock table: product name uses caseFormatJs()');
has($dashSrc, "{ data: 'party', render: d => caseFormatJs(d) }", 'Dashboard recent-sales table: party uses caseFormatJs()');
has($dashSrc, "{ data: 'party',          render: d => caseFormatJs(d) }", 'Dashboard sales-history table: party uses caseFormatJs()');
has($dashSrc, '<td>${caseFormatJs(p.name)}</td>', 'Dashboard Top Products tile uses caseFormatJs()');
has($dashSrc, '<td>${caseFormatJs(c.name)}</td>', 'Dashboard Top Cashiers tile uses caseFormatJs()');
has($dashSrc, "\$('#rcv_customer').text(applyCaseModeJs(row.party));", 'Dashboard receive-payment modal uses applyCaseModeJs()');
has($dashSrc, "\$('#ret_customer').text(applyCaseModeJs(res.sale.customer_name));", 'Dashboard return modal customer uses applyCaseModeJs()');
has($dashSrc, '<td>${caseFormatJs(l.product_name)}</td>', 'Dashboard return modal line items use caseFormatJs()');
has($dashSrc, '<small class="text-muted">${caseFormatJs(row.party)}', 'Dashboard mobile card: party uses caseFormatJs()');

_tdr_set_mode($root, 'as_typed');

// ─────────────────────────────────────────────────────────────────────────
section('Batch D — Z-report / shift history');

foreach (['app/bms/pos/zreport.php', 'app/bms/pos/shift_history.php'] as $f) {
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg("$root/$f") . " 2>&1", $out, $rc);
    $rc === 0 ? pass("lint: $f") : fail("php -l failed: $f — " . implode(' ', $out));
}
has(src($root, 'app/bms/pos/shift_history.php'), 'caseFormatJs(r.register) : \'—\'', 'Shift history mobile card: register uses caseFormatJs()');

if ($uid) {
    $shift = $pdo->query("SELECT sh.shift_id, sh.register_id, sh.user_id
                            FROM cash_register_shifts sh
                            JOIN users u ON u.user_id = sh.user_id
                            JOIN pos_registers r ON r.register_id = sh.register_id
                           LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($shift) {
        $shiftId = (int)$shift['shift_id'];
        $regId   = (int)$shift['register_id'];
        $cashierUid = (int)$shift['user_id'];

        $origReg = $pdo->prepare("SELECT register_name FROM pos_registers WHERE register_id = ?");
        $origReg->execute([$regId]); $origRegName = $origReg->fetchColumn();

        $origUser = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
        $origUser->execute([$cashierUid]); $origUsername = $origUser->fetchColumn();

        $testLower = 'zz refs zreport test';
        $testUpper = strtoupper($testLower);

        _tdr_run_php("
            require '$root/roots.php';
            save_setting('text_display_case', 'upper');
            \$pdo->prepare('UPDATE pos_registers SET register_name = ? WHERE register_id = ?')->execute([" . var_export($testLower, true) . ", $regId]);
            \$pdo->prepare('UPDATE users SET username = ? WHERE user_id = ?')->execute([" . var_export($testLower . ' cashier', true) . ", $cashierUid]);
            echo 'SET';
        ");

        try {
            $html = _tdr_call($root, $uid, 'app/bms/pos/zreport.php', ['shift_id' => $shiftId]);
            (strpos($html, $testUpper) !== false)
                ? pass("zreport.php: UPPERCASED register name found")
                : fail("zreport.php: UPPERCASED register name NOT found");
            (strpos($html, strtoupper($testLower . ' cashier')) !== false)
                ? pass("zreport.php: UPPERCASED cashier name found")
                : fail("zreport.php: UPPERCASED cashier name NOT found");

            $listHtml = _tdr_call($root, $uid, 'app/bms/pos/shift_history.php');
            (strpos($listHtml, $testUpper) !== false)
                ? pass("shift_history.php: UPPERCASED register name found in list")
                : fail("shift_history.php: UPPERCASED register name NOT found in list");
        } finally {
            _tdr_run_php("
                require '$root/roots.php';
                save_setting('text_display_case', 'as_typed');
                \$pdo->prepare('UPDATE pos_registers SET register_name = ? WHERE register_id = ?')->execute([" . var_export($origRegName, true) . ", $regId]);
                \$pdo->prepare('UPDATE users SET username = ? WHERE user_id = ?')->execute([" . var_export($origUsername, true) . ", $cashierUid]);
                echo 'RESTORED';
            ");
        }
    } else {
        pass('no cash_register_shifts fixture available — Batch D live checks skipped (n/a)');
    }
}

_tdr_set_mode($root, 'as_typed');

// ─────────────────────────────────────────────────────────────────────────
section('Batch E — Expenses');

$rc = 0; $out = [];
exec("php -l " . escapeshellarg("$root/app/constant/accounts/expenses.php") . " 2>&1", $out, $rc);
$rc === 0 ? pass('lint: app/constant/accounts/expenses.php') : fail('php -l failed: app/constant/accounts/expenses.php — ' . implode(' ', $out));
$rcNode = 0; $outNode = [];
exec('node --check ' . escapeshellarg("$root/assets/js/tables/bms-expenses-table.js") . ' 2>&1', $outNode, $rcNode);
$rcNode === 0 ? pass('node --check: assets/js/tables/bms-expenses-table.js') : fail('node --check failed: assets/js/tables/bms-expenses-table.js — ' . implode(' ', $outNode));

has(src($root, 'app/constant/accounts/expenses.php'), "'name' => applyCaseMode(\$s['supplier_name'])], \$suppliers))", 'Expenses Paid-To supplier picker uses applyCaseMode()');
has(src($root, 'app/constant/accounts/expenses.php'), "'name' => applyCaseMode(trim(\$e['first_name']", 'Expenses Paid-To staff picker uses applyCaseMode()');
has(src($root, 'app/constant/accounts/expenses.php'), 'caseFormat($warehouse[\'warehouse_name\'])', 'Expenses Simple-POS shop dropdown uses caseFormat()');

$expTableSrc = src($root, 'assets/js/tables/bms-expenses-table.js');
has($expTableSrc, 'window.caseFormatJs ? window.caseFormatJs(raw) : esc(raw)', 'Expenses table Paid-To column uses caseFormatJs()');
has($expTableSrc, 'window.caseFormatJs(d.paid_to_name) : esc(d.paid_to_name)', 'Expenses mobile card Paid-To uses caseFormatJs()');
has($expTableSrc, 'const paidTo = window.applyCaseModeJs ? window.applyCaseModeJs(paidToRaw) : paidToRaw;', 'Payment voucher Paid-To uses applyCaseModeJs()');

// Live check: get_expenses.php (the shared API source) intentionally left
// returning the RAW name — the case transform happens at the JS render
// layer (bms-expenses-table.js), not the API — since paid_to_name is
// resolved fresh via JOIN, never a stored snapshot, either point would be
// architecturally safe, but keeping the transform at render time matches
// every other AJAX/DataTables source in this codebase (Phase 1 precedent).
if ($uid) {
    $supFixture = $pdo->query("SELECT e.expense_id, e.paid_to_id
                                  FROM expenses e
                                 WHERE e.paid_to_type = 'supplier' AND e.paid_to_id IS NOT NULL
                                 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($supFixture) {
        $supId = (int)$supFixture['paid_to_id'];
        $orig = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ?");
        $orig->execute([$supId]);
        $origName = $orig->fetchColumn();

        $testLower = 'zz refs expense test';
        _tdr_run_php("
            require '$root/roots.php';
            save_setting('text_display_case', 'upper');
            \$pdo->prepare('UPDATE suppliers SET supplier_name = ? WHERE supplier_id = ?')->execute([" . var_export($testLower, true) . ", $supId]);
            echo 'SET';
        ");
        try {
            $json = _tdr_call($root, $uid, 'api/account/get_expenses.php', ['draw' => 1, 'start' => 0, 'length' => 100]);
            (strpos($json, $testLower) !== false)
                ? pass("get_expenses.php: API returns the RAW (untransformed) supplier name — transform happens client-side")
                : fail("get_expenses.php: expected the raw lowercase supplier name in the API response — got something else");
        } finally {
            _tdr_run_php("
                require '$root/roots.php';
                save_setting('text_display_case', 'as_typed');
                \$pdo->prepare('UPDATE suppliers SET supplier_name = ? WHERE supplier_id = ?')->execute([" . var_export($origName, true) . ", $supId]);
                echo 'RESTORED';
            ");
        }
    } else {
        pass('no expense fixture paid to a supplier — get_expenses.php live check skipped (n/a)');
    }
}

_tdr_set_mode($root, 'as_typed');
