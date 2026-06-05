<?php
/**
 * AR/AP Aging + Customer/Vendor Statements — CLI test
 *   php tests/test_ar_ap_aging_cli.php
 *
 * Verifies: the 8 new files exist + lint; routes + menu wired; the APIs are
 * permission-gated + project-scoped with the correct aging buckets; and a real
 * runtime exercise of the AR and AP endpoints on seeded data (rolled back) lands
 * invoices/bills in the right bucket, and statements compute opening/running/closing.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/project_scope.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

/** Include an endpoint with a mocked admin GET request; return decoded JSON. */
function callEndpoint(string $root, string $rel, array $get) {
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    include "$root/$rel";
    $out = ob_get_clean();
    return json_decode($out, true);
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
$files = [
    'api/account/get_ar_aging.php', 'api/account/get_ap_aging.php',
    'api/account/get_customer_statement.php', 'api/account/get_vendor_statement.php',
    'app/constant/reports/ar_aging.php', 'app/constant/reports/ap_aging.php',
    'app/constant/reports/customer_statement.php', 'app/constant/reports/vendor_statement.php',
];
foreach ($files as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $o, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Routes + menu wired');
$routes = src($root, 'roots.php');
has($routes, "'ar_aging' => REPORTS_DIR . '/ar_aging.php'", 'ar_aging route registered');
has($routes, "'ap_aging' => REPORTS_DIR . '/ap_aging.php'", 'ap_aging route registered');
has($routes, "'customer_statement' => REPORTS_DIR . '/customer_statement.php'", 'customer_statement route registered');
has($routes, "'vendor_statement' => REPORTS_DIR . '/vendor_statement.php'", 'vendor_statement route registered');
has($routes, "'reports/delinquency_report' => REPORTS_DIR . '/ar_aging.php'", 'delinquency stub now points at AR aging');
$header = src($root, 'header.php');
has($header, "getUrl('ar_aging')", 'Receivables Aging menu link present');
has($header, "getUrl('ap_aging')", 'Payables Aging menu link present');
has($header, "getUrl('customer_statement')", 'Customer Statement menu link present');
has($header, "getUrl('vendor_statement')", 'Vendor Statement menu link present');

// ─────────────────────────────────────────────────────────────────────────
section('3. API contracts — permission, scope, buckets');
foreach (['api/account/get_ar_aging.php' => 'i', 'api/account/get_ap_aging.php' => 'si'] as $f => $alias) {
    $s = src($root, $f);
    has($s, "canView('financial_reports')", "$f gated by financial_reports");
    has($s, "scopeFilterSqlNullable('project', '$alias')", "$f applies project scope");
    has($s, "userCan('project'", "$f verifies a chosen project");
    has($s, "\$days <= 30", "$f buckets 1-30");
    has($s, "\$days <= 60", "$f buckets 31-60");
    has($s, "\$days <= 90", "$f buckets 61-90");
}
foreach (['api/account/get_customer_statement.php', 'api/account/get_vendor_statement.php'] as $f) {
    $s = src($root, $f);
    has($s, "canView('financial_reports')", "$f gated by financial_reports");
    has($s, "opening", "$f computes an opening balance");
    has($s, "scopeFilterSqlNullable", "$f applies project scope");
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — AR aging endpoint buckets a real overdue invoice');
$_SESSION = ['user_id' => 4, 'role_id' => 1, 'username' => 'cli-test'];
try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO customers (customer_name, status, created_by) VALUES ('__TEST_AR_CUST__','active',4)")->execute();
    $cid = (int)$pdo->lastInsertId();

    // One 45-days-overdue invoice (balance 500) → bucket 31-60.
    $due = date('Y-m-d', strtotime('-45 days'));
    $pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, due_date, grand_total, paid_amount, balance_due, status, created_by)
                   VALUES (?,?,?,?,?,?,?, 'approved', 4)")
        ->execute(['__TEST_INV__', $cid, date('Y-m-d', strtotime('-60 days')), $due, 800.00, 300.00, 500.00]);

    $res = callEndpoint($root, 'api/account/get_ar_aging.php', ['as_of_date' => date('Y-m-d'), 'customer_id' => $cid]);
    if (!is_array($res) || empty($res['success'])) {
        fail('AR endpoint did not return success JSON');
    } else {
        abs((float)$res['summary']['d31_60'] - 500.0) < 0.001 ? pass('AR: 45-day-overdue 500 landed in 31-60 bucket') : fail('AR bucket wrong: ' . json_encode($res['summary']));
        abs((float)$res['summary']['total'] - 500.0) < 0.001 ? pass('AR: total outstanding = 500') : fail('AR total wrong: ' . $res['summary']['total']);
        (count($res['customers']) === 1) ? pass('AR: one customer row returned') : fail('AR customer rows: ' . count($res['customers']));
    }
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('AR runtime error: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime — AP aging endpoint buckets a real old bill (net of WHT)');
try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO suppliers (supplier_name, status, created_by) VALUES ('__TEST_AP_VEND__','active',4)")->execute();
    $vid = (int)$pdo->lastInsertId();

    // 100-days-old approved bill: amount 1000, WHT 50 → payable 950, bucket 90+.
    $raised = date('Y-m-d', strtotime('-100 days'));
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, wht_amount, status, recorded_by, created_at, updated_at)
                   VALUES ('supplier', ?, '__TEST_BILL__', ?, ?, 1000.00, 50.00, 'approved', 4, NOW(), NOW())")
        ->execute([$vid, $raised, date('Y-m-d')]);

    $res = callEndpoint($root, 'api/account/get_ap_aging.php', ['as_of_date' => date('Y-m-d'), 'vendor_id' => $vid]);
    if (!is_array($res) || empty($res['success'])) {
        fail('AP endpoint did not return success JSON');
    } else {
        abs((float)$res['summary']['over_90'] - 950.0) < 0.001 ? pass('AP: 100-day bill (net WHT) = 950 in 90+ bucket') : fail('AP bucket wrong: ' . json_encode($res['summary']));
        abs((float)$res['summary']['total'] - 950.0) < 0.001 ? pass('AP: total payable = 950 (net of WHT)') : fail('AP total wrong: ' . $res['summary']['total']);
    }
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('AP runtime error: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Runtime — customer statement opening + running balance');
try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO customers (customer_name, status, created_by) VALUES ('__TEST_STMT_CUST__','active',4)")->execute();
    $cid = (int)$pdo->lastInsertId();

    // Prior-period invoice (opening), in-period invoice + payment.
    $pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, due_date, grand_total, paid_amount, balance_due, status, created_by)
                   VALUES ('__OPEN__', ?, '2026-01-10','2026-02-10', 1000,0,1000,'approved',4)")->execute([$cid]);
    $pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, due_date, grand_total, paid_amount, balance_due, status, created_by)
                   VALUES ('__INRANGE__', ?, '2026-03-05','2026-04-05', 600,0,600,'approved',4)")->execute([$cid]);
    $pdo->prepare("INSERT INTO payments (payment_number, invoice_id, customer_id, payment_date, amount, status, created_by)
                   VALUES ('__PAY__', 0, ?, '2026-03-20', 400, 'completed', 4)")->execute([$cid]);

    $res = callEndpoint($root, 'api/account/get_customer_statement.php',
        ['customer_id' => $cid, 'date_from' => '2026-03-01', 'date_to' => '2026-03-31']);
    if (!is_array($res) || empty($res['success'])) {
        fail('customer statement did not return success JSON');
    } else {
        abs((float)$res['opening_balance'] - 1000.0) < 0.001 ? pass('Statement: opening balance = 1000 (prior invoice)') : fail('opening wrong: ' . $res['opening_balance']);
        // 1000 opening + 600 charge - 400 payment = 1200 closing
        abs((float)$res['closing_balance'] - 1200.0) < 0.001 ? pass('Statement: closing balance = 1200 (running)') : fail('closing wrong: ' . $res['closing_balance']);
        (count($res['lines']) === 2) ? pass('Statement: 2 in-period lines (invoice + payment)') : fail('lines: ' . count($res['lines']));
    }
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('statement runtime error: ' . $e->getMessage());
}
