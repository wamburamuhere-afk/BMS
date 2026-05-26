<?php
/**
 * BMS — Module Scope Enforcement Regression Guard
 *
 * Verifies that every module list page and detail page applies the correct
 * project-scope pattern so non-admin users see only records from their
 * assigned projects (or global records with NULL project_id).
 *
 * Rules enforced:
 *   LIST PAGES   — scopeFilterSqlNullable('project') OR custom inline scope block
 *   DETAIL PAGES — assertScopeForRecordHtml OR custom multi-project gate
 *   DROPDOWNS    — project <select> query guarded by isAdmin() or scope check
 *   ORDER        — permission check must appear before scope filter in file
 *
 * Run:
 *   php tests/test_scope_enforcement_cli.php
 *
 * Exit 0 = all checks pass.
 * Exit 1 = at least one check failed — block the PR.
 */

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

function src(string $rel): string {
    global $root;
    return @file_get_contents("$root/$rel") ?: '';
}

function hasAny(string $content, array $patterns): bool {
    foreach ($patterns as $p) {
        if (strpos($content, $p) !== false) return true;
    }
    return false;
}

// Patterns that indicate an admin bypass / scope guard on a project query
// Covers: isAdmin(), !empty($_SESSION['scope']['is_admin']), scope']['is_admin']
$ADMIN_GUARD = [
    'isAdmin()',
    "scope']['is_admin']",   // substring of $_SESSION['scope']['is_admin']
    "['is_admin']",
];

// Patterns that indicate a permission check exists
$PERM_CHECK = [
    'autoEnforcePermission',
    'requireViewPermission',
    'canView(',
    'canCreate(',
    '!$can_view',
];

echo "\n\033[1m═══ BMS Module Scope Enforcement Guard ═══\033[0m\n";

// ── 1. LIST PAGES — scope filter on main query ────────────────────────────
head('List pages — scope filter on main query');

$list_checks = [
    [
        'app/bms/customer/customers.php',
        'Customers list',
        ["scopeFilterSqlNullable('project'"],
    ],
    [
        'app/bms/Suppliers/suppliers.php',
        'Suppliers list — custom multi-project block',
        ['supplier_scope_sql'],
    ],
    [
        'app/bms/operations/sub_contractors.php',
        'Sub-contractors list — custom multi-project block',
        ['sc_scope_sql'],
    ],
    [
        'app/bms/product/products.php',
        'Products list — project scope in $conditions',
        ['project_id IS NULL OR p.project_id IN'],
    ],
    [
        'app/bms/product/services.php',
        'Services list — project scope in $conditions',
        ['project_id IS NULL OR p.project_id IN'],
    ],
    [
        'app/bms/pos/employees.php',
        'Employees list',
        ["scopeFilterSqlNullable('project'"],
    ],
    [
        'app/bms/stock/warehouses.php',
        'Warehouses list',
        ["scopeFilterSqlNullable('project'"],
    ],
    [
        'app/constant/accounts/budget.php',
        'Budget list — all queries scoped',
        ['b_scope_where_sql', 'b_scope_on_sql'],
    ],
    [
        'api/account/get_expenses.php',
        'Expenses API — all queries scoped (nullable)',
        ["scopeFilterSqlNullable('project'"],
    ],
];

foreach ($list_checks as [$file, $desc, $patterns]) {
    $content = src($file);
    if (!$content) {
        bad("FILE MISSING: $file");
    } elseif (hasAny($content, $patterns)) {
        ok($desc);
    } else {
        bad("NO SCOPE FILTER — $desc ($file)");
    }
}

// ── 2. DETAIL / EDIT PAGES — record-level scope gate ─────────────────────
head('Detail / edit pages — record-level scope gate');

$detail_checks = [
    [
        'app/bms/customer/customer_details.php',
        'Customer detail',
        ["assertScopeForRecordHtml('customers'"],
    ],
    [
        'app/bms/customer/edit_customer.php',
        'Customer edit',
        ["assertScopeForRecordHtml('customers'"],
    ],
    [
        'app/bms/customer/customer_documents.php',
        'Customer documents',
        ["assertScopeForRecordHtml('customers'"],
    ],
    [
        'app/bms/Suppliers/supplier_details.php',
        'Supplier detail — custom multi-project gate',
        ['supplier_projects', 'SELECT 1 FROM suppliers'],
    ],
    [
        'app/bms/operations/sub_contractor_details.php',
        'Sub-contractor detail — custom multi-project gate',
        ['sub_contractor_projects', 'SELECT 1 FROM sub_contractors'],
    ],
    [
        'app/bms/stock/warehouse_view.php',
        'Warehouse view',
        ["assertScopeForRecordHtml('warehouses'"],
    ],
];

foreach ($detail_checks as [$file, $desc, $patterns]) {
    $content = src($file);
    if (!$content) {
        bad("FILE MISSING: $file");
    } elseif (hasAny($content, $patterns)) {
        ok($desc);
    } else {
        bad("NO SCOPE GATE — $desc ($file)");
    }
}

// ── 3. PROJECT DROPDOWNS — admin guard on project <select> query ──────────
head('Project dropdowns — admin guard on project query');

$dropdown_checks = [
    ['app/bms/customer/customers.php',              'Customers'],
    ['app/bms/Suppliers/suppliers.php',             'Suppliers'],
    ['app/bms/Suppliers/supplier_details.php',      'Supplier detail assign-project modal'],
    ['app/bms/operations/sub_contractors.php',      'Sub-contractors'],
    ['app/bms/operations/sub_contractor_details.php','Sub-contractor detail assign-project modal'],
    ['app/bms/product/services.php',                'Services'],
    ['app/bms/pos/employees.php',                   'Employees'],
    ['app/bms/stock/warehouses.php',                'Warehouses'],
    ['app/constant/accounts/budget.php',           'Budget'],
    ['app/constant/accounts/expenses.php',         'Expenses'],
];

foreach ($dropdown_checks as [$file, $desc]) {
    $content = src($file);
    if (!$content) {
        bad("FILE MISSING: $file");
    } elseif (hasAny($content, $ADMIN_GUARD)) {
        ok("$desc — project dropdown guarded");
    } else {
        bad("NO ADMIN GUARD on project dropdown — $desc ($file)");
    }
}

// ── 4. PERMISSION FIRST — permission check before scope filter ────────────
head('Permission check appears before scope filter in file');

$order_checks = [
    ['app/bms/customer/customers.php',         "canView('customers')",  'scopeFilterSqlNullable'],
    ['app/bms/Suppliers/suppliers.php',         'autoEnforcePermission', 'supplier_scope_sql'],
    ['app/bms/operations/sub_contractors.php',  'canView(',              'sc_scope_sql'],
    ['app/bms/product/products.php',            'requireViewPermission', 'project_id IS NULL'],
    ['app/bms/product/services.php',            'requireViewPermission', 'project_id IS NULL'],
    ['app/bms/pos/employees.php',               'autoEnforcePermission', 'scopeFilterSqlNullable'],
    ['app/bms/stock/warehouses.php',            'autoEnforcePermission', 'scopeFilterSqlNullable'],
    ['app/constant/accounts/budget.php',        'autoEnforcePermission', 'project_id IS NULL'],
    ['app/constant/accounts/expenses.php',      'autoEnforcePermission', 'isAdmin()'],
];

foreach ($order_checks as [$file, $perm_marker, $scope_marker]) {
    $content = src($file);
    if (!$content) { bad("FILE MISSING: $file"); continue; }

    $perm_pos  = strpos($content, $perm_marker);
    $scope_pos = strpos($content, $scope_marker);

    if ($perm_pos === false) {
        bad("NO PERMISSION CHECK ($perm_marker) in $file");
    } elseif ($scope_pos === false) {
        bad("NO SCOPE FILTER ($scope_marker) in $file");
    } elseif ($perm_pos < $scope_pos) {
        ok(basename($file) . " — permission before scope");
    } else {
        bad(basename($file) . " — scope appears BEFORE permission — wrong order");
    }
}

// ── Result ────────────────────────────────────────────────────────────────
echo "\n\033[1m═══ Result ═══\033[0m\n";
if ($failures === 0) {
    echo "\033[32m✅ All $passes checks passed.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m❌ $failures check(s) failed, $passes passed.\033[0m\n\n";
    exit(1);
}
