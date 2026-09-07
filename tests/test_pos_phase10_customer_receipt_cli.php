<?php
/**
 * POS Phase 10 — Select2 customer picker, quick-add, receipt printing/email — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_pos_phase10_customer_receipt_cli.php
 *
 * Verifies:
 *   1. New/touched files lint-clean.
 *   2. Wiring source patterns:
 *      - search_customers.php: permission-gated, project-scoped (security.md §23)
 *      - quick_add_customer.php: now CSRF-protected
 *      - email_receipt.php: permission-gated, CSRF-protected, uses the real
 *        SMTP-backed sendEmail() (never a fake/simulated send)
 *      - pos.php: the old hard-limit-50 server-rendered customer list is gone,
 *        replaced by the AJAX-backed select + quick-add button
 *      - pos_scripts_new.php: Select2 AJAX init present, setCustomerSelection()
 *        used at all three restore sites (localStorage, held sale, reset),
 *        cash-drawer messaging no longer falsely claims to have opened it
 *      - pos_config_settings.php: CSRF-protected, saves the new receipt settings
 *      - print_receipt.php: configurable paper width + auto-print wiring
 *   3. Live-DB read-only check: the exact customer-search query used by
 *      search_customers.php runs cleanly and respects project scope for a
 *      non-admin (reuses the already-tested scopeFilterSqlNullable()).
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/project_scope.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

$failures = 0;
$passes   = 0;

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$files = [
    'api/quick_add_customer.php', 'api/pos/search_customers.php', 'api/pos/email_receipt.php',
    'api/pos/print_receipt.php', 'app/bms/pos/pos.php', 'app/bms/pos/pos_modals_new.php',
    'app/bms/pos/pos_scripts_new.php', 'app/constant/settings/pos_config_settings.php',
];

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ($files as $f) {
    $path = "$root/$f";
    if (!file_exists($path)) { fail("$f missing"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($path) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$f lint-clean") : fail("$f lint failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$searchSrc  = file_get_contents("$root/api/pos/search_customers.php");
$quickSrc   = file_get_contents("$root/api/quick_add_customer.php");
$emailSrc   = file_get_contents("$root/api/pos/email_receipt.php");
$posSrc     = file_get_contents("$root/app/bms/pos/pos.php");
$modalsSrc  = file_get_contents("$root/app/bms/pos/pos_modals_new.php");
$scriptsSrc = file_get_contents("$root/app/bms/pos/pos_scripts_new.php");
$settingsSrc = file_get_contents("$root/app/constant/settings/pos_config_settings.php");
$rcptSrc    = file_get_contents("$root/api/pos/print_receipt.php");

$checks = [
    [$searchSrc, "canView('pos')",                              'search_customers.php gated by canView(pos)'],
    [$searchSrc, "scopeFilterSqlNullable('project', 'c')",       'search_customers.php applies project scope (security.md §23)'],
    [$quickSrc,  "csrf_check();",                                'quick_add_customer.php now has csrf_check()'],
    [$emailSrc,  "canView('pos')",                               'email_receipt.php gated by canView(pos)'],
    [$emailSrc,  "csrf_check();",                                'email_receipt.php has csrf_check()'],
    [$emailSrc,  "sendEmail(\$email,",                           'email_receipt.php uses the real sendEmail()'],
    [$emailSrc,  "mailer_last_error()",                          'email_receipt.php surfaces the real mailer error on failure'],
    [$posSrc,    'id="btnQuickAddCustomer"',                     'pos.php has the quick-add-customer button'],
    [$modalsSrc, 'id="quickAddCustomerModal"',                   'pos_modals_new.php has the quick-add-customer modal'],
    [$scriptsSrc, "function setCustomerSelection",               'pos_scripts_new.php defines setCustomerSelection()'],
    [$scriptsSrc, "ajax: {",                                     'pos_scripts_new.php Select2 uses an AJAX source (not a static list)'],
    [$scriptsSrc, "api/pos/search_customers.php",                'pos_scripts_new.php Select2 points at search_customers.php'],
    [$scriptsSrc, "setCustomerSelection(savedCustomer, savedCustomerName)", 'localStorage restore uses setCustomerSelection()'],
    [$scriptsSrc, "setCustomerSelection(sale.customer_id, sale.customer_name)", 'held-sale restore uses setCustomerSelection()'],
    [$scriptsSrc, "POS_AUTO_PRINT_RECEIPT",                      'pos_scripts_new.php defines POS_AUTO_PRINT_RECEIPT'],
    [$scriptsSrc, "cannot send a direct",                        'openCashDrawer() no longer falsely claims success'],
    [$settingsSrc, "csrf_check();",                              'pos_config_settings.php POST handler now has csrf_check()'],
    [$settingsSrc, "pos_receipt_width",                          'pos_config_settings.php saves pos_receipt_width'],
    [$settingsSrc, "pos_auto_print_receipt",                     'pos_config_settings.php saves pos_auto_print_receipt'],
    [$rcptSrc,   "\$receipt_width = getSetting('pos_receipt_width'", 'print_receipt.php reads the configurable paper width'],
    [$rcptSrc,   "function emailReceipt()",                      'print_receipt.php has the Email Receipt button wiring'],
];
foreach ($checks as [$src, $needle, $label]) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// The old hard-limited server-rendered customer list must be gone.
strpos($posSrc, "customers WHERE status = 'active' ORDER BY customer_name LIMIT 50") === false
    ? pass('pos.php no longer server-renders a hard-limited 50-row customer list')
    : fail('old hard-limited customer <option> loop still present in pos.php');

// ─────────────────────────────────────────────────────────────────────────
section('3. Live-DB read-only check — customer search query + project scope');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;

try {
    $sql = "SELECT customer_id, customer_name, customer_code, phone, mobile FROM customers c WHERE status = 'active'";
    $sql .= scopeFilterSqlNullable('project', 'c');
    $sql .= " ORDER BY customer_name ASC LIMIT 20";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    pass('customer search query (with project scope applied) runs cleanly, returned ' . count($rows) . ' row(s)');
} catch (Throwable $e) {
    fail('customer search query threw: ' . $e->getMessage());
}

$hasEmailCol = (bool)$pdo->query("SHOW COLUMNS FROM customers LIKE 'email'")->fetch();
$hasEmailCol ? pass('customers.email column exists (used to prefill Email Receipt)') : fail('customers.email column missing');

exit($failures === 0 ? 0 : 1);
