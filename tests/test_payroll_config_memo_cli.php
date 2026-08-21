<?php
/**
 * tests/test_payroll_config_memo_cli.php
 * --------------------------------------
 * Guards the request-scoped memoisation added to core/payroll_tax.php
 * (payrollSetting / activeTaxBrackets) as part of the query-optimization work.
 *
 * The memo exists purely to stop a payroll run re-reading the same config row
 * once per employee. These tests assert that it is a PERFORMANCE change only:
 *
 *   1. Values returned match a direct, uncached read of the same DB row.
 *   2. A missing key still honours EACH caller's own $default — the memo caches
 *      the raw DB result, never the resolved default, so two callers passing
 *      different defaults for the same absent key must not contaminate.
 *   3. Repeated calls collapse to a single query per distinct key / as-of date.
 *   4. activeTaxBrackets still falls back to the seeded defaults for a date with
 *      no matching bands, and memoises per-date rather than globally.
 *
 * Requires a database (reads payroll_settings + tax_brackets).
 *
 * Run:  php tests/test_payroll_config_memo_cli.php
 */

require __DIR__ . '/../core/payroll_tax.php';
require __DIR__ . '/../includes/config.php';

$passed = 0; $failed = 0;
function check(string $label, $expected, $actual): void {
    global $passed, $failed;
    $ok = (is_float($expected) || is_int($expected))
        ? abs((float)$expected - (float)$actual) < 0.005
        : $expected === $actual;
    if ($ok) { $passed++; echo "  PASS  $label\n"; }
    else     { $failed++; echo "  FAIL  $label — expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; }
}

try {
    $pdo = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    echo "SKIP — no database available: " . $e->getMessage() . "\n";
    exit(0);
}

$comSelect = function () use ($pdo): int {
    return (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_ASSOC)['Value'];
};

// ─────────────────────────────────────────────────────────────────────────────
echo "Memoised values match a direct uncached read\n";

$rawSetting = function (string $k) use ($pdo) {
    $s = $pdo->prepare("SELECT setting_value FROM payroll_settings WHERE setting_key = ? LIMIT 1");
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return ($v !== false && $v !== null && $v !== '') ? (string)$v : null;
};

foreach (['nssf_rate', 'nssf_employer_rate', 'working_days_per_month', 'sdl_rate'] as $key) {
    $raw = $rawSetting($key);
    if ($raw === null) { echo "  skip  $key (not seeded)\n"; continue; }
    check("payrollSetting('$key') matches DB", $raw, (string)payrollSetting($pdo, $key, '__MISSING__'));
}

// Repeat calls must keep returning the same value, not drift.
$first = (string)payrollSetting($pdo, 'nssf_rate', '__MISSING__');
for ($i = 0; $i < 5; $i++) {
    check("payrollSetting('nssf_rate') stable on repeat #$i", $first, (string)payrollSetting($pdo, 'nssf_rate', '__MISSING__'));
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA missing key honours each caller's own default (no cross-contamination)\n";

$absent = '__memo_test_absent_key__';
check('first caller gets its default',   'ALPHA', payrollSetting($pdo, $absent, 'ALPHA'));
check('second caller gets ITS default',  'BETA',  payrollSetting($pdo, $absent, 'BETA'));
check('third caller, null default',      null,    payrollSetting($pdo, $absent, null));
check('first default again, unchanged',  'ALPHA', payrollSetting($pdo, $absent, 'ALPHA'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nRepeated lookups collapse to one query per distinct key\n";

// Warm both keys first so we measure the memo, not the initial read.
payrollSetting($pdo, 'nssf_rate', null);
payrollSetting($pdo, 'nssf_employer_rate', null);
activeTaxBrackets($pdo, '2026-08-01');

$before = $comSelect();
for ($i = 0; $i < 50; $i++) {
    nssfEmployeeRate($pdo);
    nssfEmployerRate($pdo);
    activeTaxBrackets($pdo, '2026-08-01');
}
$after = $comSelect();
check('50 iterations x 3 helpers issue 0 further queries', 0, $after - $before);

// A DISTINCT as-of date must still hit the DB (memo is per-date, not global).
$b4 = $comSelect();
activeTaxBrackets($pdo, '2019-03-15');
$a4 = $comSelect();
check('a new as-of date still queries', true, ($a4 - $b4) >= 1);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nactiveTaxBrackets correctness\n";

$asOf = '2026-08-01';
$rawB = $pdo->prepare("SELECT min_income, max_income, tax_rate, bracket_name FROM tax_brackets
                       WHERE is_active = 1 AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
                       ORDER BY min_income ASC");
$rawB->execute([$asOf, $asOf]);
$truth = $rawB->fetchAll(PDO::FETCH_ASSOC);
$viaHelper = activeTaxBrackets($pdo, $asOf);

if ($truth) {
    check('bands match a direct uncached read', md5(json_encode($truth)), md5(json_encode($viaHelper)));
} else {
    check('empty table falls back to seeded defaults',
        md5(json_encode(defaultTanzaniaPayeBrackets())), md5(json_encode($viaHelper)));
}
check('bands are non-empty', true, count($viaHelper) > 0);

// The engine on top of the memo must still produce the documented figures.
$stat = computeEmployeeStatutory($pdo, 1000000.0, $asOf);
$rate = (float)(payrollSetting($pdo, 'nssf_rate', PR_DEFAULT_NSSF_EMPLOYEE_RATE));
check('computeEmployeeStatutory NSSF follows the configured rate',
    round(1000000.0 * $rate / 100, 2), $stat['nssf_employee']);
check('computeEmployeeStatutory taxable = gross − NSSF',
    round(1000000.0 - $stat['nssf_employee'], 2), $stat['taxable']);
check('computeEmployeeStatutory is deterministic on repeat',
    md5(json_encode($stat)), md5(json_encode(computeEmployeeStatutory($pdo, 1000000.0, $asOf))));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nPasses: $passed\nFailures: $failed\n";
exit($failed > 0 ? 1 : 0);
