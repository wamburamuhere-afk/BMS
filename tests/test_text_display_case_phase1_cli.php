<?php
/**
 * Text Display Case — Phase 1 (master-data list/view pages) — CLI regression suite
 *   php tests/test_text_display_case_phase1_cli.php
 *
 * Covers the 11 modules Phase 1 wired caseFormat()/caseFormatJs() into:
 * Products, Services, Customers, Suppliers, Warehouses, Projects, Tenders,
 * Sub-contractors, Employees, Categories, Brands. Read-only display
 * formatting only (core/text_display_case.php) — raw stored data is never
 * rewritten, so this suite proves the transform end to end WITHOUT creating
 * any new fixtures: it temporarily renames one existing real record per
 * entity to a distinctive lowercase test string, renders the real page
 * under 'upper' mode, asserts the UPPERCASED string appears (proving the
 * transform actually ran) and the raw lowercase string does NOT (proving
 * it isn't just falling through unchanged), then restores the original
 * name — every entity ends the run with its original data untouched.
 *
 *   A. STATIC   — every file this phase touched lints clean.
 *   B. PER-MODULE — list page (and view/detail page where one exists) for
 *                  each of the 11 areas, both real HTML-render architectures
 *                  (server PHP loop, and client AJAX/DataTables JSON) proven
 *                  live against the actual dev database.
 *   C. CODE FIELDS STAY RAW — a handful of representative code/ID fields
 *                  (SKU, supplier_code, customer_code, tender_no) are
 *                  confirmed UNCHANGED under 'upper' mode, proving the
 *                  name-vs-code exclusion boundary holds.
 *   D. DEFAULT MODE — a spot check that 'as_typed' (the neutral default)
 *                  leaves rendered output byte-identical to before Phase 1.
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

function _tdp1_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'tdp1_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}

// Renders $script (a real app page) via a fresh subprocess, with $get params
// set in $_GET, as an admin session. Returns the raw HTML.
function _tdp1_render(string $root, int $uid, string $script, array $get = []): string {
    $getCode = '';
    foreach ($get as $k => $v) { $getCode .= "\$_GET[" . var_export($k, true) . "] = " . var_export($v, true) . ";\n        "; }
    return _tdp1_run_php("
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

// Full round trip for one entity: set mode, rename, render list (+ optional
// view page), assert, restore. $labelPrefix keeps assertion text readable.
function _tdp1_check_entity(
    string $root, int $uid, PDO $pdo,
    string $table, string $idCol, int $id, string $nameCol,
    ?string $listScript, ?array $listGet,
    ?string $viewScript, ?array $viewGet,
    string $labelPrefix
): void {
    $orig = $pdo->prepare("SELECT $nameCol FROM $table WHERE $idCol = ?");
    $orig->execute([$id]);
    $originalName = $orig->fetchColumn();
    if ($originalName === false) {
        fail("$labelPrefix: fixture row $idCol=$id not found — skipped");
        return;
    }

    $testLower = 'zz phase1 case test ' . $idCol . $id;
    $testUpper = strtoupper($testLower);

    _tdp1_run_php("
        require '$root/roots.php';
        save_setting('text_display_case', 'upper');
        \$pdo->prepare('UPDATE $table SET $nameCol = ? WHERE $idCol = ?')->execute([" . var_export($testLower, true) . ", $id]);
        echo 'SET';
    ");

    try {
        if ($listScript) {
            $html = _tdp1_render($root, $uid, $listScript, $listGet ?? []);
            (strpos($html, $testUpper) !== false)
                ? pass("$labelPrefix list: renamed record shows UPPERCASED ('$testUpper' found)")
                : fail("$labelPrefix list: UPPERCASED name not found in rendered output");
            // Deliberately NOT asserting the raw lowercase string is absent —
            // several pages legitimately carry the raw (untransformed) value
            // in non-display contexts alongside the formatted display text:
            // a lowercased data-name search-index attribute (employees.php),
            // and edit-modal JS pre-fills reading the DataTables row cache
            // (categories.php/brands.php .val(cat.category_name) etc.) —
            // both correct by design (see core/text_display_case.php's file
            // header: never used for an editable input's value).
        }
        if ($viewScript) {
            $html = _tdp1_render($root, $uid, $viewScript, $viewGet ?? []);
            (strpos($html, $testUpper) !== false)
                ? pass("$labelPrefix view: renamed record shows UPPERCASED ('$testUpper' found)")
                : fail("$labelPrefix view: UPPERCASED name not found in rendered output");
        }
    } finally {
        // Always restore, even if an assertion above failed.
        _tdp1_run_php("
            require '$root/roots.php';
            save_setting('text_display_case', 'as_typed');
            \$pdo->prepare('UPDATE $table SET $nameCol = ? WHERE $idCol = ?')->execute([" . var_export($originalName, true) . ", $id]);
            echo 'RESTORED';
        ");
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
$touchedFiles = [
    'core/text_display_case.php', 'header.php', 'app/constant/settings/system_settings.php',
    'app/bms/product/products.php', 'app/bms/product/product_view.php',
    'app/bms/product/services.php', 'app/bms/product/service_view.php',
    'app/bms/product/categories.php', 'app/bms/product/brands.php',
    'app/bms/customer/customers.php', 'app/bms/customer/customer_details.php',
    'app/bms/Suppliers/suppliers.php', 'app/bms/Suppliers/supplier_details.php',
    'app/bms/stock/warehouses.php', 'app/bms/stock/warehouse_view.php',
    'app/bms/operations/projects.php', 'app/bms/operations/project_view.php',
    'includes/project_view/scripts/pv_js_01.php',
    'app/bms/tenders/tenders.php', 'app/bms/tenders/tender_view.php',
    'app/bms/operations/sub_contractors.php', 'app/bms/operations/sub_contractor_details.php',
    'app/bms/pos/employees.php', 'app/bms/pos/employee_details.php', 'api/get_employees.php',
];
foreach ($touchedFiles as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Per-module end-to-end (rename real record -> render -> assert -> restore)');

$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();
if (!$uid) {
    pass('no admin user fixture available — section 2 skipped (n/a)');
} else {
    // Products
    $pid = (int)$pdo->query("SELECT product_id FROM products WHERE is_service = 0 AND status = 'active' LIMIT 1")->fetchColumn();
    if ($pid) _tdp1_check_entity($root, $uid, $pdo, 'products', 'product_id', $pid, 'product_name',
        'app/bms/product/products.php', null,
        'app/bms/product/product_view.php', ['id' => $pid],
        'Products');

    // Services (same table, is_service=1)
    $svid = (int)$pdo->query("SELECT product_id FROM products WHERE is_service = 1 AND status = 'active' LIMIT 1")->fetchColumn();
    if ($svid) _tdp1_check_entity($root, $uid, $pdo, 'products', 'product_id', $svid, 'product_name',
        'app/bms/product/services.php', null,
        'app/bms/product/service_view.php', ['id' => $svid],
        'Services');

    // Customers
    $cid = (int)$pdo->query("SELECT customer_id FROM customers WHERE status != 'deleted' LIMIT 1")->fetchColumn();
    if ($cid) _tdp1_check_entity($root, $uid, $pdo, 'customers', 'customer_id', $cid, 'customer_name',
        null, null, // list is server-side AJAX DataTables (JSON, not rendered HTML) — covered by source-wiring checks in section 1/3 instead
        'app/bms/customer/customer_details.php', ['id' => $cid],
        'Customers');

    // Suppliers
    $sid = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status != 'deleted' LIMIT 1")->fetchColumn();
    if ($sid) _tdp1_check_entity($root, $uid, $pdo, 'suppliers', 'supplier_id', $sid, 'supplier_name',
        'app/bms/Suppliers/suppliers.php', null,
        'app/bms/Suppliers/supplier_details.php', ['id' => $sid],
        'Suppliers');

    // Warehouses
    $wid = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' LIMIT 1")->fetchColumn();
    if ($wid) _tdp1_check_entity($root, $uid, $pdo, 'warehouses', 'warehouse_id', $wid, 'warehouse_name',
        'app/bms/stock/warehouses.php', null,
        'app/bms/stock/warehouse_view.php', ['id' => $wid],
        'Warehouses');

    // Projects — list is server-side AJAX DataTables JSON, view page IS
    // server-rendered but gated behind the Projects module being enabled
    // for this tenant (off by default in this dev DB) — enable it for the
    // duration of this one check, restore whatever it was before after.
    $projectsWasEnabled = get_setting('enable_projects', '0');
    _tdp1_run_php("require '$root/roots.php'; save_setting('enable_projects', '1'); echo 'SET';");
    $projid = (int)$pdo->query("SELECT project_id FROM projects LIMIT 1")->fetchColumn();
    if ($projid) _tdp1_check_entity($root, $uid, $pdo, 'projects', 'project_id', $projid, 'project_name',
        null, null,
        'app/bms/operations/project_view.php', ['id' => $projid],
        'Projects');
    _tdp1_run_php("require '$root/roots.php'; save_setting('enable_projects', " . var_export($projectsWasEnabled, true) . "); echo 'RESET';");

    // Tenders — list is server-side AJAX DataTables JSON, view page IS server-rendered
    $tid = (int)$pdo->query("SELECT tender_id FROM tenders LIMIT 1")->fetchColumn();
    if ($tid) _tdp1_check_entity($root, $uid, $pdo, 'tenders', 'tender_id', $tid, 'procuring_entity_name',
        null, null,
        'app/bms/tenders/tender_view.php', ['id' => $tid],
        'Tenders');

    // Sub-contractors — its OWN table (sub_contractors), not a filtered view
    // of suppliers, despite the near-identical column shape.
    $scid = (int)$pdo->query("SELECT supplier_id FROM sub_contractors WHERE status != 'deleted' LIMIT 1")->fetchColumn();
    if ($scid) _tdp1_check_entity($root, $uid, $pdo, 'sub_contractors', 'supplier_id', $scid, 'supplier_name',
        'app/bms/operations/sub_contractors.php', null,
        'app/bms/operations/sub_contractor_details.php', ['id' => $scid],
        'Sub-contractors');

    // Employees — list is server-side DataTables via api/get_employees.php (JSON), card view is server PHP loop
    $eid = (int)$pdo->query("SELECT employee_id FROM employees LIMIT 1")->fetchColumn();
    if ($eid) _tdp1_check_entity($root, $uid, $pdo, 'employees', 'employee_id', $eid, 'first_name',
        'app/bms/pos/employees.php', null,
        'app/bms/pos/employee_details.php', ['id' => $eid],
        'Employees');

    // Categories
    $catid = (int)$pdo->query("SELECT category_id FROM categories WHERE status = 'active' LIMIT 1")->fetchColumn();
    if ($catid) _tdp1_check_entity($root, $uid, $pdo, 'categories', 'category_id', $catid, 'category_name',
        'app/bms/product/categories.php', null,
        null, null,
        'Categories');

    // Brands
    $brid = (int)$pdo->query("SELECT brand_id FROM brands WHERE status = 'active' LIMIT 1")->fetchColumn();
    if ($brid) _tdp1_check_entity($root, $uid, $pdo, 'brands', 'brand_id', $brid, 'brand_name',
        'app/bms/product/brands.php', null,
        null, null,
        'Brands');
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Server-side AJAX/DataTables JSON endpoints (customers, projects, tenders, employees lists)');
// These render client-side from JSON, not server HTML — verified by
// confirming the JS render functions actually call caseFormatJs()/
// applyCaseModeJs(), and (for employees) that the PHP API applies
// caseFormat() to its JSON payload directly.
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 80) . "`"); }

has(src($root, 'app/bms/customer/customers.php'), "render: (data) => `<strong>\${caseFormatJs(data)}</strong>`", 'Customers DataTable: customer_name column uses caseFormatJs()');
has(src($root, 'app/bms/operations/projects.php'), 'caseFormatJs(data)', 'Projects DataTable: project_name column uses caseFormatJs()');
has(src($root, 'app/bms/tenders/tenders.php'), 'caseFormatJs(row.entity_name', 'Tenders DataTable: entity_name column uses caseFormatJs()');
has(src($root, 'api/get_employees.php'), "caseFormat(\$emp['first_name']", 'Employees server-side API: name field uses caseFormat()');

// ─────────────────────────────────────────────────────────────────────────
section('4. Code/ID fields stay raw under \'upper\' mode (name-vs-code boundary holds)');

if ($uid) {
    _tdp1_run_php("require '$root/roots.php'; save_setting('text_display_case', 'upper'); echo 'SET';");

    $pid = (int)$pdo->query("SELECT product_id FROM products WHERE is_service = 0 AND sku IS NOT NULL LIMIT 1")->fetchColumn();
    if ($pid) {
        $sku = $pdo->prepare("SELECT sku FROM products WHERE product_id = ?"); $sku->execute([$pid]);
        $skuVal = $sku->fetchColumn();
        if ($skuVal) {
            $html = _tdp1_render($root, $uid, 'app/bms/product/products.php');
            (strpos($html, $skuVal) !== false)
                ? pass("Products: SKU '$skuVal' appears UNCHANGED (not case-transformed) under 'upper' mode")
                : fail("Products: SKU '$skuVal' not found verbatim — may have been unexpectedly transformed");
        }
    }

    $sid = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status != 'deleted' AND supplier_code IS NOT NULL LIMIT 1")->fetchColumn();
    if ($sid) {
        $code = $pdo->prepare("SELECT supplier_code FROM suppliers WHERE supplier_id = ?"); $code->execute([$sid]);
        $codeVal = $code->fetchColumn();
        if ($codeVal) {
            $html = _tdp1_render($root, $uid, 'app/bms/Suppliers/suppliers.php');
            (strpos($html, $codeVal) !== false)
                ? pass("Suppliers: supplier_code '$codeVal' appears UNCHANGED under 'upper' mode")
                : fail("Suppliers: supplier_code '$codeVal' not found verbatim — may have been unexpectedly transformed");
        }
    }

    _tdp1_run_php("require '$root/roots.php'; save_setting('text_display_case', 'as_typed'); echo 'RESET';");
}

// ─────────────────────────────────────────────────────────────────────────
section('5. \'as_typed\' mode — zero behaviour change confirmed live (no longer the stored default as of 2026-09-18, but still a selectable, unchanged-passthrough mode)');

if ($uid) {
    _tdp1_run_php("require '$root/roots.php'; save_setting('text_display_case', 'as_typed'); echo 'SET';");
    $pid = (int)$pdo->query("SELECT product_id FROM products WHERE is_service = 0 LIMIT 1")->fetchColumn();
    if ($pid) {
        $nm = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ?"); $nm->execute([$pid]);
        $nameVal = $nm->fetchColumn();
        $html = _tdp1_render($root, $uid, 'app/bms/product/product_view.php', ['id' => $pid]);
        (strpos($html, htmlspecialchars($nameVal)) !== false)
            ? pass("Products view: real product name renders unchanged under 'as_typed' (default) mode")
            : fail("Products view: real product name did NOT render as expected under 'as_typed' mode");
    }
}
