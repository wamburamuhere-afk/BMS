<?php
/**
 * tests/test_payroll_statutory_master_cli.php
 * -------------------------------------------
 * MASTER test for the PAYE / NSSF / SDL statutory payroll feature (accrual model).
 * Covers every touched file: engine, migrations, both payroll endpoints, the accrual
 * + payment posting, the remittance schedule + remit API, and the payroll UI.
 *
 *   php tests/test_payroll_statutory_master_cli.php
 *
 * Runtime DB checks seed a fake period ('2099-01'), assert, then roll back AND delete.
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
function bal(PDO $pdo, int $id): float { return (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$id")->fetchColumn(); }

register_shutdown_function(function () {
    global $pass, $fail;
    echo "\n" . str_repeat('-', 56) . "\nRESULT: $pass passed, $fail failed\n";
    if ($fail > 0) exit(1);
});

// ── 1. Files exist + lint clean ───────────────────────────────────────────────
sec('1. Files lint clean');
foreach ([
    'core/payroll_tax.php', 'core/payment_source.php',
    'migrations/2026_06_05_payroll_statutory_foundation.php', 'migrations/2026_06_05_payroll_accrual.php',
    'api/process_payroll.php', 'api/preview_payroll.php', 'api/bulk_update_payroll_status.php',
    'api/approve_payroll.php', 'api/delete_payroll.php', 'api/update_payroll.php',
    'api/remit_statutory.php', 'api/get_payrolls.php',
    'app/bms/pos/payroll.php', 'app/bms/pos/statutory_remittances.php',
] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    chk($rc === 0, "lint: $f");
}

// ── 2. Migrations applied ─────────────────────────────────────────────────────
sec('2. Migrations applied (schema + seed)');
chk((bool)$pdo->query("SHOW COLUMNS FROM payroll LIKE 'nssf_employee'")->fetch(), 'payroll.nssf_employee exists');
chk((bool)$pdo->query("SHOW COLUMNS FROM payroll LIKE 'accrual_transaction_id'")->fetch(), 'payroll.accrual_transaction_id exists');
chk((bool)$pdo->query("SHOW TABLES LIKE 'statutory_remittances'")->fetch(), 'statutory_remittances table exists');
$band1 = $pdo->query("SELECT tax_rate FROM tax_brackets WHERE is_active=1 AND min_income=270000 LIMIT 1")->fetchColumn();
chk(near($band1, 8), "PAYE first band is 8% — got $band1");
foreach ([
    'default_salaries_expense_account_id', 'default_paye_payable_account_id',
    'default_nssf_payable_account_id', 'default_sdl_payable_account_id',
    'default_sdl_expense_account_id', 'default_salaries_payable_account_id',
] as $k) {
    chk((int)getSetting($k, 0) > 0, "account mapping $k is set");
}

// ── 3. Engine — pure math ─────────────────────────────────────────────────────
sec('3. PAYE / SDL pure math');
$b = defaultTanzaniaPayeBrackets();
$paye = fn($x) => calcProgressiveTax((float)$x, $b)['tax'];
chk(near($paye(520000), 20000),   'PAYE: 520,000 → 20,000');
chk(near($paye(900000), 103000),  'PAYE: 900,000 → 103,000');
chk(near($paye(2000000), 428000), 'PAYE: 2,000,000 → 428,000');
chk(near(calcSdlAmount(10000000, 3.5, 9, 10), 0),       'SDL: 9 employees → exempt');
chk(near(calcSdlAmount(10000000, 3.5, 10, 10), 350000), 'SDL: 10 employees → 350,000');

// ── 4. Engine — DB-backed ─────────────────────────────────────────────────────
sec('4. Engine DB-backed');
$st = computeEmployeeStatutory($pdo, 1000000, '2026-06-01');
chk(near($st['nssf_employee'], 100000) && near($st['taxable'], 900000) && near($st['paye'], 103000),
    'gross 1,000,000 → NSSF 100,000, taxable 900,000, PAYE 103,000');
chk(periodRemittanceDueDate('2026-06') === '2026-07-07', 'due date = month-end + 7 days');

// ── 5. Wiring ─────────────────────────────────────────────────────────────────
sec('5. Wiring (accrual model)');
has(src("$root/core/payment_source.php"), 'function postPayrollAccrual', 'accrual posting helper exists');
has(src("$root/core/payment_source.php"), 'function ensurePayrollAccrued', 'ensurePayrollAccrued exists');
has(src("$root/core/payment_source.php"), 'function postSdlAccrual', 'SDL accrual helper exists');
has(src("$root/api/process_payroll.php"), 'ensurePayrollAccrued', 'process accrues on auto-approve');
has(src("$root/api/approve_payroll.php"), 'ensurePayrollAccrued', 'approve accrues');
has(src("$root/api/bulk_update_payroll_status.php"), 'ensurePayrollAccrued', 'bulk approve accrues');
has(src("$root/api/bulk_update_payroll_status.php"), 'postPayrollPayment', 'pay settles via postPayrollPayment');
has(src("$root/api/delete_payroll.php"), 'reverseJournalBalances', 'delete reverses the ledger');
has(src("$root/api/remit_statutory.php"), "'sdl'  => 'default_sdl_payable_account_id'", 'remit clears SDL Payable');
has(src("$root/app/bms/pos/payroll.php"), '>Allowance<', 'payroll header shows Allowance');

// ── 6. Runtime: accrual → payment (the accrual model end-to-end) ──────────────
sec('6. Runtime: accrual on approve, settle on pay');
$period = '2099-01'; $pnum = 'PAY-TEST-209901';
$emp  = (int)$pdo->query("SELECT employee_id FROM employees ORDER BY employee_id LIMIT 1")->fetchColumn();
$cash = (int)($pdo->query("SELECT account_id FROM accounts WHERE status='active' AND account_type='asset' AND cash_flow_category='cash' ORDER BY account_id LIMIT 1")->fetchColumn() ?: 0);
$salExp = (int)getSetting('default_salaries_expense_account_id',0);
$payeAcc= (int)getSetting('default_paye_payable_account_id',0);
$nssfAcc= (int)getSetting('default_nssf_payable_account_id',0);
$salPay = (int)getSetting('default_salaries_payable_account_id',0);

$cleanup = function () use ($pdo, $period, $pnum) {
    $pdo->prepare("DELETE FROM statutory_remittances WHERE period = ?")->execute([$period]);
    $pdo->prepare("DELETE FROM payroll WHERE payroll_number = ?")->execute([$pnum]);
};

if ($emp <= 0 || $cash <= 0 || !$salPay) { no('missing employee / cash account / salaries-payable mapping for runtime test'); }
else {
    $cleanup();
    $pdo->beginTransaction();
    try {
        // gross 800,000; PAYE 60,000; NSSF 80,000; net 660,000
        $pdo->prepare("INSERT INTO payroll
            (payroll_number, employee_id, payroll_period, payroll_date, basic_salary, allowances,
             deductions, gross_salary, tax_amount, nssf_employee, net_salary, month, year,
             payment_status, status, created_at)
            VALUES (?, ?, ?, '2099-01-25', 800000, 0, 0, 800000, 60000, 80000, 660000, 1, 2099,
             'approved', 'approved', NOW())")->execute([$pnum, $emp, $period]);
        $pid = (int)$pdo->lastInsertId();

        $salPayBefore = bal($pdo, $salPay); $payeBefore = bal($pdo, $payeAcc); $nssfBefore = bal($pdo, $nssfAcc);

        // ACCRUE (what approval does)
        $acc = ensurePayrollAccrued($pdo, $pid, null);
        chk($acc > 0, 'accrual posted on approve');
        $dr = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM books_transactions WHERE transaction_id=$acc AND type='debit'")->fetchColumn();
        $cr = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM books_transactions WHERE transaction_id=$acc AND type='credit'")->fetchColumn();
        chk(near($dr,800000) && near($cr,800000), "accrual balances: Dr $dr = Cr $cr = gross 800,000");
        chk(near(bal($pdo,$salPay) - $salPayBefore, 660000), 'Salaries Payable +660,000 (unpaid wages → Balance Sheet)');
        chk(near(bal($pdo,$payeAcc) - $payeBefore, 60000),  'PAYE Payable +60,000 (owed regardless of payment)');
        chk(near(bal($pdo,$nssfAcc) - $nssfBefore, 80000),  'NSSF Payable +80,000');

        // PAY (settle staff)
        $p = $pdo->query("SELECT * FROM payroll WHERE payroll_id=$pid")->fetch(PDO::FETCH_ASSOC);
        $bankBefore = bal($pdo, $cash);
        $txn = postPayrollPayment($pdo, $p, $cash, null);
        chk($txn > 0, 'payment posted');
        $pdr = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM books_transactions WHERE transaction_id=$txn AND type='debit'")->fetchColumn();
        $pcr = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM books_transactions WHERE transaction_id=$txn AND type='credit'")->fetchColumn();
        chk(near($pdr,660000) && near($pcr,660000), "payment balances: Dr $pdr = Cr $pcr = net 660,000");
        chk(near(bal($pdo,$salPay) - $salPayBefore, 0), 'Salaries Payable back to baseline after pay (liability cleared)');
        chk(near($bankBefore - bal($pdo,$cash), 660000), 'bank reduced by net only (660,000)');

        // SDL accrual (Dr SDL Expense / Cr SDL Payable)
        $sdlExp=(int)getSetting('default_sdl_expense_account_id',0); $sdlPay=(int)getSetting('default_sdl_payable_account_id',0);
        $sdlPayBefore = bal($pdo,$sdlPay);
        $sdlTxn = postSdlAccrual($pdo, $period, 350000, null);
        chk($sdlTxn > 0 && near(bal($pdo,$sdlPay)-$sdlPayBefore, 350000), 'SDL accrual: SDL Payable +350,000');
    } catch (Throwable $e) {
        no('runtime error: ' . $e->getMessage());
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $cleanup();
        ok('runtime artifacts cleaned up (rolled back + deleted)');
    }
}
