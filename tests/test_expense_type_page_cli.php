<?php
/**
 * Expense Types & Categories — dedicated page + payroll/invoice dropdown fix — CLI test
 *   php tests/test_expense_type_page_cli.php
 *
 * Verifies:
 *  - The new dedicated page + the reused schema APIs exist and lint clean.
 *  - PART 1: both payee cascades on the expense form have a .fail() guard, and the
 *    two lookup APIs are hardened to emit JSON only (so the box never hangs on "Loading…").
 *  - PART 2: the inline quick-manage modal/JS is gone from expenses.php and replaced
 *    by a link to expense_types; the route + menu link exist; no DB change.
 *  - Runtime: a type → category → sub-category round-trip through the EXISTING
 *    manage schema (rolled back), proving the hierarchy the page renders is supported.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }
function hasnt(string $hay, string $needle, string $label): void { strpos($hay, $needle) === false ? pass($label) : fail("$label — found `" . substr($needle, 0, 50) . "`"); }
function countOf(string $hay, string $needle): int { return substr_count($hay, $needle); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
$files = [
    'app/constant/accounts/expense_types.php',
    'app/constant/accounts/expenses.php',
    'api/finance/get_expense_schema.php',
    'api/finance/manage_expense_schema.php',
    'api/account/get_employee_payrolls.php',
    'api/account/get_payee_invoices.php',
];
foreach ($files as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. PART 1 — dropdown never hangs on "Loading…"');
$exp = src($root, 'app/constant/accounts/expenses.php');
countOf($exp, '.fail(function()') >= 2
    ? pass('both payee cascades have a .fail() guard (' . countOf($exp, '.fail(function()') . ' found)')
    : fail('expected >=2 .fail() guards on the cascades, got ' . countOf($exp, '.fail(function()'));
has($exp, 'Not available', 'cascade .fail() resolves the box to "Not available"');

$prl = src($root, 'api/account/get_employee_payrolls.php');
has($prl, "error_reporting(0)", 'payroll API suppresses stray notices');
has($prl, "ini_set('display_errors', '0')", 'payroll API disables display_errors');
has($prl, "global \$pdo;", 'payroll API pulls in global $pdo');

$inv = src($root, 'api/account/get_payee_invoices.php');
has($inv, "error_reporting(0)", 'invoice API suppresses stray notices');
has($inv, "ini_set('display_errors', '0')", 'invoice API disables display_errors');

// ─────────────────────────────────────────────────────────────────────────
section('3. PART 2 — inline manage modal replaced by a dedicated page');
hasnt($exp, 'openQuickManageModal', 'expenses.php no longer opens the inline manage modal');
hasnt($exp, 'quickManageTypeModal', 'expenses.php no longer contains the inline manage modal markup');
hasnt($exp, 'function renderManageTypes', 'inline manage JS removed (renderManageTypes)');
hasnt($exp, 'function quickSaveCategory', 'inline manage JS removed (quickSaveCategory)');
has($exp, "getUrl('expense_types')", 'expenses.php links to the dedicated expense_types page');
// the create-form cascade must remain intact
has($exp, 'function findCatInTree', 'create-form cascade helper findCatInTree kept');
has($exp, 'function renderCascadeDropdown', 'create-form cascade renderer kept');
has($exp, 'function loadExpenseSchema', 'create form still loads the schema');

$page = src($root, 'app/constant/accounts/expense_types.php');
has($page, "buildUrl('api/finance/get_expense_schema.php')", 'page reuses get_expense_schema (no new read endpoint)');
has($page, "buildUrl('api/finance/manage_expense_schema.php')", 'page reuses manage_expense_schema (no new write endpoint)');
has($page, "autoEnforcePermission('expenses')", 'page is permission-gated');
has($page, "action:'add_type'", 'page can add a type');
has($page, "action:'add_category'", 'page can add a category / sub-category');
has($page, "action:'edit_category'", 'page can edit (rename) a category');
has($page, "action:'delete_category'", 'page can delete a category');
has($page, "action:'delete_type'", 'page can delete a type');
has($page, 'parent_id:parentId', 'sub-categories are linked by parent_id');

// ─────────────────────────────────────────────────────────────────────────
section('4. Route + menu wired');
$routes = src($root, 'roots.php');
has($routes, "'expense_types' => ACCOUNTS_DIR . '/expense_types.php'", 'expense_types route registered');
$header = src($root, 'header.php');
has($header, "getUrl('expense_types')", 'Finance menu links to Expense Types & Categories');

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime — type → category → sub-category round-trip (rolled back)');
try {
    $hasParent = $pdo->query("SHOW COLUMNS FROM expense_categories LIKE 'parent_id'")->rowCount() > 0;
    $pdo->beginTransaction();

    // add_type (mirrors manage_expense_schema add_type)
    $pdo->prepare("INSERT INTO expense_types (name, show_project) VALUES (?, ?)")
        ->execute(['__TEST_TYPE__', 1]);
    $typeId = (int)$pdo->lastInsertId();
    $typeId > 0 ? pass("type created (id $typeId)") : fail('type not created');

    // add_category at root
    if ($hasParent) {
        $pdo->prepare("INSERT INTO expense_categories (type_id, parent_id, name) VALUES (?, NULL, ?)")
            ->execute([$typeId, '__TEST_CAT__']);
    } else {
        $pdo->prepare("INSERT INTO expense_categories (type_id, name) VALUES (?, ?)")
            ->execute([$typeId, '__TEST_CAT__']);
    }
    $catId = (int)$pdo->lastInsertId();
    $catId > 0 ? pass("root category created (id $catId)") : fail('category not created');

    if ($hasParent) {
        // add sub-category under the category
        $pdo->prepare("INSERT INTO expense_categories (type_id, parent_id, name) VALUES (?, ?, ?)")
            ->execute([$typeId, $catId, '__TEST_SUB__']);
        $subId = (int)$pdo->lastInsertId();
        $linkedParent = (int)$pdo->query("SELECT parent_id FROM expense_categories WHERE id=$subId")->fetchColumn();
        $linkedParent === $catId ? pass("sub-category linked to its parent (parent_id=$catId)") : fail("sub-category parent wrong ($linkedParent)");

        // edit_category (rename) the sub
        $pdo->prepare("UPDATE expense_categories SET name=? WHERE id=?")->execute(['__TEST_SUB_RENAMED__', $subId]);
        $pdo->query("SELECT name FROM expense_categories WHERE id=$subId")->fetchColumn() === '__TEST_SUB_RENAMED__'
            ? pass('sub-category renamed') : fail('rename failed');

        // delete_category the sub
        $pdo->prepare("DELETE FROM expense_categories WHERE id=?")->execute([$subId]);
        (int)$pdo->query("SELECT COUNT(*) FROM expense_categories WHERE id=$subId")->fetchColumn() === 0
            ? pass('sub-category deleted') : fail('delete failed');
    } else {
        pass('parent_id column absent — sub-category test skipped (flat categories)');
    }

    // delete_type cascade guard: type must NOT be linked to any expense (it is brand new)
    $linked = (int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE type_id=$typeId")->fetchColumn();
    $linked === 0 ? pass('new type has no linked expenses (safe to delete)') : fail('unexpected expense link');

    $pdo->rollBack();
    pass('round-trip rolled back (no persistence)');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('runtime error: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Inline "Other (add new…)" on the expense form');
$exp = src($root, 'app/constant/accounts/expenses.php');
has($exp, "const OTHER_VALUE = '__other__'", 'expenses.php defines the Other sentinel');
has($exp, "EXPENSE_CAN_MANAGE_SCHEMA", 'Other is gated by manage-schema permission');
has($exp, 'Other (add new', 'the "Other (add new…)" option label is present');
// injected into all three selectors
(substr_count($exp, 'opts += otherOption()') + substr_count($exp, 'options += otherOption()')) >= 3
    ? pass('Other option injected into type + category + cascade builders (>=3)')
    : fail('expected Other in >=3 builders, got ' . (substr_count($exp, 'otherOption()')));
has($exp, "if (typeId === OTHER_VALUE)", 'type change handler intercepts Other');
has($exp, "if (catId === OTHER_VALUE)", 'cascade change handler intercepts Other');
has($exp, "action: 'add_type'", 'defineNewType calls add_type (existing API, no DB change)');
has($exp, "action: 'add_category'", 'defineNewCategory calls add_category');
has($exp, "populateCascadeForCategory(parseInt(res.id))", 'new category is re-selected after reload (data preserved)');
has($exp, "buildUrl('api/finance/manage_expense_schema.php')", 'reuses the existing manage_expense_schema endpoint');
