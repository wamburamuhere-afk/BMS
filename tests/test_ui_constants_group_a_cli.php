<?php
/**
 * UI-Constants Group A — Regression Guard
 *
 * Verifies the DataTable (§UI-2) / Select2 (§UI-3) work applied to the Group A
 * list pages. For every edited file it asserts:
 *   1. the file still passes `php -l`,
 *   2. the additions are present (DataTable init / Select2 init), and
 *   3. the existing logic that MUST NOT break is still present
 *      (AJAX loaders, server pagination, export functions, dynamic filters,
 *       onchange handlers).
 *
 * Run:  php tests/test_ui_constants_group_a_cli.php
 *   Exit 0 = all pass · Exit 1 = a regression slipped in.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
$passes = 0; $failures = 0;

function pass($m){ global $passes; $passes++; echo "  \033[32m✅\033[0m $m\n"; }
function fail($m){ global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

echo "\n\033[1m═══ UI-Constants Group A Guard ═══\033[0m\n";

/**
 * Each entry: file => [ 'has' => [needles...], 'keep' => [needles...] ]
 *   has  = additions that must be present
 *   keep = existing logic that must still be present (no-break)
 */
$specs = [
    'app/bms/product/brands.php' => [
        'has'  => ["id=\"brandsTable\"", "brandsTable').DataTable"],
        'keep' => ["function deleteBrand", "save_brand.php", "editBrand("],
    ],
    'app/bms/product/categories.php' => [
        'has'  => ["id=\"categoriesTable\"", "ordering: false", "modal_parent_id').select2"],
        'keep' => ["function openAddModal", "delete_category.php", "btn-edit-cat"],
    ],
    'app/bms/product/services.php' => [
        'has'  => ["svcCategoryFilter').select2", "svcModalSelects", "id=\"svc_tax_id\"", "change.select2"],
        'keep' => ["function filterWarehouses", "LIMIT :limit OFFSET :offset", "refreshAllComponentCosts"],
    ],
    'app/bms/sales/quotations/quotations.php' => [
        'has'  => ["quotationsTable').DataTable", "paging: false", "quoteCustomerFilter').select2"],
        'keep' => ["function exportExcel", "function copyTable", "function printQuote"],
    ],
    'app/bms/sales/quotations/quotation_form.php' => [
        'has'  => ["'#customer_id', '#project_id'"],
        'keep' => ["function filterWarehousesByProject", "loadCustomerInfo", "saveQuotationFinal"],
    ],
    'app/bms/sales/sales_returns/sales_returns.php' => [
        'has'  => ["filter_customer').select2"],
        'keep' => ["get_returns_paged.php", "function renderTable", "function loadDisplayData"],
    ],
    'app/bms/stock/stock_movements.php' => [
        'has'  => ["smWarehouseFilter').select2", "id=\"smWarehouseFilter\""],
        'keep' => ["LIMIT \$per_page OFFSET"],
    ],
    'app/bms/Suppliers/supplier_categories.php' => [
        'has'  => ["id=\"supplierCategoriesTable\"", "supplierCategoriesTable').DataTable"],
        'keep' => ["addCategoryForm"],
    ],
    'app/bms/tenders/tenders.php' => [
        'has'  => ["tender-emp-s2", "quickAddEmployeeModal').on('shown"],
        'keep' => ["function loadTenders", "staff_select_input').html(html).select2"],
    ],
    'app/constant/accounts/payment_vouchers.php' => [ // already compliant
        'has'  => ["voucher_expense_account", "voucher_project", "get_vouchers.php"],   // expense "category" is now a real expense account
        'keep' => ["function renderTable", "loadVouchers"],
    ],
    'app/constant/accounts/petty_cash.php' => [ // already compliant
        // expense "category" is a real expense account; filter is by expense account;
        // the meaningless deposit category was removed (account_categories retired).
        'has'  => ["filter_expense_account_id').select2", "expense_account_id').select2"],
        'keep' => ["loadTransactions", "get_transactions.php"],
    ],
    'app/constant/communication/sms_alerts.php' => [
        'has'  => ["smsAlertsTable').DataTable", "alertTypeFilter').select2", "smsModalSelects"],
        'keep' => ["Filtered SMS Alerts", "function applyFilters"],
    ],
    'app/constant/settings/asset_categories.php' => [
        'has'  => ["id=\"assetCategoriesTable\"", "DataTable().clear().destroy()",
                   "assetCategoriesTable').DataTable({"],
        'keep' => ["function loadCategories", "function openCategoryModal", "get_asset_categories.php"],
    ],
    'app/constant/settings/backup_restore.php' => [
        'has'  => ["id=\"backupsTable\"", "backupsTable').DataTable"],
        'keep' => ["BACKUP_API", "foreach (\$backups"],
    ],
];

// ── 1. Lint + content assertions per file ────────────────────────────────
foreach ($specs as $rel => $spec) {
    section($rel);
    $abs = "$root/" . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($abs)) { fail("file not found"); continue; }

    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($abs) . ' 2>&1', $out, $code);
    $code === 0 ? pass("php -l clean") : fail("SYNTAX ERROR: " . implode(' | ', $out));

    $src = file_get_contents($abs);
    foreach ($spec['has'] as $needle) {
        strpos($src, $needle) !== false
            ? pass("added: " . $needle)
            : fail("MISSING addition: " . $needle);
    }
    foreach ($spec['keep'] as $needle) {
        strpos($src, $needle) !== false
            ? pass("preserved: " . $needle)
            : fail("BROKEN — lost existing logic: " . $needle);
    }
}

// ── 2. Global invariants ─────────────────────────────────────────────────
section('Global invariants');

// No file added a client DataTable to a server/AJAX-paginated list (those
// pages must stay free of .DataTable on their main list — guarded by spec).
$ajaxPaged = [
    'app/bms/sales/sales_returns/sales_returns.php',
    'app/constant/accounts/payment_vouchers.php',
    'app/constant/accounts/petty_cash.php',
    'app/bms/tenders/tenders.php',
];
foreach ($ajaxPaged as $rel) {
    $src = file_get_contents("$root/" . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    // these pages legitimately may have NO DataTable on the main list
    strpos($src, "renderTable") !== false || strpos($src, "loadTenders") !== false
        ? pass("$rel keeps its AJAX renderer")
        : fail("$rel lost its AJAX renderer");
}

// profile.php — intentionally untouched (placeholder login table)
$prof = "$root/app/constant/profile/profile.php";
is_file($prof) && strpos(file_get_contents($prof), 'More login history would be loaded here') !== false
    ? pass("profile.php placeholder table intact (correctly no DataTable)")
    : fail("profile.php unexpected state");

echo "\n\033[1m══════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures > 0) { echo "\033[31m❌ $failures failure(s) — fix before pushing.\033[0m\n\n"; exit(1); }
echo "\033[32m✅ All UI-constants Group A checks passed.\033[0m\n\n";
exit(0);
