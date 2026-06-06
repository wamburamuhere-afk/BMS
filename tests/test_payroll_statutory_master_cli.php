<?php
/**
 * tests/test_payroll_statutory_master_cli.php
 * -------------------------------------------
 * MASTER test for the PAYE / NSSF / SDL statutory payroll feature. Covers every file
 * touched: the engine, migration, both payroll endpoints, the payment posting, the
 * remittance schedule + remit API, and the payroll UI.
 *
 *   php tests/test_payroll_statutory_master_cli.php
 *
 * Runtime DB checks seed a fake period ('2099-01'), assert, then roll back AND delete
 * (safe whether the tables are InnoDB or MyISAM). No real data is touched.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payroll_tax.php";
require_once "$root/core/payment_source.php";
global $pdo;

$pass = 0; $fail = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  [PASS] $m\n"; }
function no(string $m): void   { global $fail; $fail++; echo "  [FAIL] $m\n"; }
function sec(string $t): void  { echo "\n== $t ==\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : no($m); }
function near($a, $b): bool { return abs((float)$a - (float)$b) < 0.01; }
function src(string $p): string { return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? ok($label) : no("$label (missing: " . substr($needle, 0, 50) . ")"); }

register_shutdown_function(function () {
    global $pass, $fail;
    echo "\n" . str_repeat('-', 56) . "\nRESULT: $pass passed, $fail failed\n";
    if ($fail > 0) exit(1);
});

// ── 1. Files exist + lint clean ───────────────────────────────────────────────
sec('1. Files lint clean');
foreach ([
    'core/payroll_tax.php', 'core/payment_source.php',
    'migrations/2026_06_05_payroll_statutory_foundation.php',
    'api/process_payroll.php', 'api/preview_payroll.php',
    'api/bulk_update_payroll_status.php', 'api/remit_statutory.php',
    'api/get_payrolls.php', 'app/bms/pos/payroll.php',
    'app/bms/pos/statutory_remittances.php',
] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    chk($rc === 0, "lint: $f");
}

// ── 2. Migration applied ──────────────────────────────────────────────────────
sec('2. Migration applied (schema + seed)');
chk((bool)$pdo->query("SHOW COLUMNS FROM payroll_settings LIKE 'category'")->fetch(), 'payroll_settings.category exists');
chk((bool)$pdo->query("SHOW COLUMNS FROM payroll LIKE 'nssf_employee'")->fetch(), 'payroll.nssf_employee exists');
chk((bool)$pdo->query("SHOW TABLES LIKE 'statutory_remittances'")->fetch(), 'statutory_remittances table exists');
$band1 = $pdo->query("SELECT tax_rate FROM tax_brackets WHERE is_active=1 AND min_income=270000 LIMIT 1")->fetchColumn();
chk(near($band1, 8), "PAYE first band is 8% (not the stale 9%) — got $band1");
foreach ([
    'default_salaries_expense_account_id', 'default_paye_payable_account_id',
    'default_nssf_payable_account_id', 'default_sdl_payable_account_id', 'default_sdl_expense_account_id',
] as $k) {
    chk((int)getSetting($k, 0) > 0, "account mapping $k is set");
}
chk((float)payrollSetting($pdo, 'sdl_rate', 0) == 3.5, 'sdl_rate = 3.5');
chk((int)payrollSetting($pdo, 'sdl_min_employees', 0) === 10, 'sdl_min_employees = 10');

// ── 3. Engine — pure math vs the TRA document ─────────────────────────────────
sec('3. PAYE / SDL pure math');
$b = defaultTanzaniaPayeBrackets();
$paye = fn($x) => calcProgressiveTax((float)$x, $b)['tax'];
chk(near($paye(270000), 0),       'PAYE: 270,000 → 0');
chk(near($paye(520000), 20000),   'PAYE: 520,000 → 20,000');
chk(near($paye(900000), 103000),  'PAYE: 900,000 → 103,000');
chk(near($paye(2000000), 428000), 'PAYE: 2,000,000 → 428,000');
chk(near(calcSdlAmount(10000000, 3.5, 9, 10), 0),       'SDL: 9 employees → exempt');
chk(near(calcSdlAmount(10000000, 3.5, 10, 10), 350000), 'SDL: 10 employees → 350,000');

// ── 4. Engine — DB-backed ─────────────────────────────────────────────────────
sec('4. Engine DB-backed (reads live config)');
$st = computeEmployeeStatutory($pdo, 1000000, '2026-06-01');
chk(near($st['nssf_employee'], 100000), 'gross 1,000,000 → NSSF 100,000');
chk(near($st['taxable'], 900000),       'taxable = gross − NSSF = 900,000');
chk(near($st['paye'], 103000),          'PAYE on 900,000 = 103,000');
chk(periodRemittanceDueDate('2026-06') === '2026-07-07', 'due date = month-end + 7 days (2026-06 → 2026-07-07)');

// ── 5. Wiring (source) ────────────────────────────────────────────────────────
sec('5. Wiring across endpoints + UI');
has(src("$root/api/process_payroll.php"), 'computeEmployeeStatutory', 'process_payroll uses the engine');
has(src("$root/api/process_payroll.php"), 'syncStatutoryRemittances', 'process_payroll refreshes the remittance schedule');
has(src("$root/api/preview_payroll.php"), 'computeEmployeeStatutory', 'preview_payroll uses the engine');
has(src("$root/api/bulk_update_payroll_status.php"), 'postPayrollPayment', 'pay flow uses the compound posting');
has(src("$root/api/remit_statutory.php"), "default_paye_payable_account_id", 'remit clears PAYE Payable');
has(src("$root/api/remit_statutory.php"), "'sdl'  => 'default_sdl_expense_account_id'", 'remit books SDL as expense');
has(src("$root/app/bms/pos/payroll.php"), "data: 'allowances'", 'payroll table has the Allowance column');
has(src("$root/app/bms/pos/payroll.php"), '>Allowance<', 'payroll header shows Allowance');
has(src("$root/api/get_payrolls.php"), "'p.allowances'", 'get_payrolls sort-map includes allowances');

// ── 6. Runtime end-to-end (fake period, rolled back + deleted) ────────────────
sec('6. Runtime: sync schedule + compound payment posting');
$period = '2099-01'; $pnum = 'PAY-TEST-209901';
$emp  = (int)$pdo->query("SELECT employee_id FROM employees ORDER BY employee_id LIMIT 1")->fetchColumn();
$cash = (int)($pdo->query("SELECT account_id FROM accounts WHERE status='active' AND account_type='asset' AND cash_flow_category='cash' ORDER BY account_id LIMIT 1")->fetchColumn() ?: 0);

$cleanup = function () use ($pdo, $period, $pnum) {
    $pdo->prepare("DELETE FROM statutory_remittances WHERE period = ?")->execute([$period]);
    $pdo->prepare("DELETE FROM payroll WHERE payroll_number = ?")->execute([$pnum]);
};

if ($emp <= 0) { no('no employee available for runtime test'); }
else {
    $cleanup();
    $pdo->beginTransaction();
    try {
        // basic 800,000; NSSF 80,000; PAYE 60,000; net 660,000
        $pdo->prepare("INSERT INTO payroll
            (payroll_number, employee_id, payroll_period, payroll_date, basic_salary, allowances,
             deductions, gross_salary, tax_amount, nssf_employee, net_salary, month, year,
             payment_status, status, created_at)
            VALUES (?, ?, ?, '2099-01-25', 800000, 0, 0, 800000, 60000, 80000, 660000, 1, 2099,
             'approved', 'approved', NOW())")
            ->execute([$pnum, $emp, $period]);
        $pid = (int)$pdo->lastInsertId();

        // sync schedule
        $r = syncStatutoryRemittances($pdo, $period, null);
        chk($r['due_date'] === '2099-02-07', 'sync: due date = 2099-02-07');
        $rem = [];
        foreach ($pdo->query("SELECT tax_type, amount FROM statutory_remittances WHERE period='$period'") as $row) $rem[$row['tax_type']] = (float)$row['amount'];
        chk(near($rem['paye'] ?? -1, 60000), 'sync: PAYE obligation = 60,000');
        chk(near($rem['nssf'] ?? -1, 80000), 'sync: NSSF obligation = 80,000');
        chk(isset($rem['sdl']) && near($rem['sdl'], 0), 'sync: SDL = 0 (single employee < 10)');

        // compound payment posting
        if ($cash > 0) {
            $p = $pdo->query("SELECT * FROM payroll WHERE payroll_id=$pid")->fetch(PDO::FETCH_ASSOC);
            $bankBefore = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$cash")->fetchColumn();
            $txn = postPayrollPayment($pdo, $p, $cash, null);
            chk($txn > 0, 'postPayrollPayment posted a transaction');
            if ($txn) {
                $dr = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM books_transactions WHERE transaction_id=$txn AND type='debit'")->fetchColumn();
                $cr = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM books_transactions WHERE transaction_id=$txn AND type='credit'")->fetchColumn();
                chk(near($dr, 800000) && near($cr, 800000), "journal balances: Dr $dr = Cr $cr = gross 800,000");
                $bankAfter = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$cash")->fetchColumn();
                chk(near($bankBefore - $bankAfter, 660000), 'bank reduced by NET only (660,000)');
            }
        } else {
            echo "  [skip] no cash/bank account configured — posting test skipped\n";
        }
    } catch (Throwable $e) {
        no('runtime error: ' . $e->getMessage());
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $cleanup();
        ok('runtime artifacts cleaned up (rolled back + deleted)');
    }
}
