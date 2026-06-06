<?php
/**
 * tests/test_payroll_statutory_cli.php
 * ------------------------------------
 * Pure unit tests for core/payroll_tax.php — PAYE (progressive, on gross−NSSF)
 * and SDL. No database required: the arithmetic functions are exercised directly
 * against the figures in the TRA "Taxes and Duties at a Glance 2024/25" document.
 *
 * Run:  php tests/test_payroll_statutory_cli.php
 */

require __DIR__ . '/../core/payroll_tax.php';

$passed = 0; $failed = 0;
function check(string $label, $expected, $actual): void {
    global $passed, $failed;
    $ok = (is_float($expected) || is_int($expected))
        ? abs((float)$expected - (float)$actual) < 0.005
        : $expected === $actual;
    if ($ok) { $passed++; echo "  PASS  $label\n"; }
    else     { $failed++; echo "  FAIL  $label — expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; }
}

$brackets = defaultTanzaniaPayeBrackets();
$paye = fn($taxable) => calcProgressiveTax((float)$taxable, $brackets)['tax'];

echo "PAYE — progressive on taxable income (gross − NSSF), 2024/25 bands\n";
check('taxable 0           → 0',        0.0,      $paye(0));
check('taxable 270,000     → 0 (NIL)',  0.0,      $paye(270000));
check('taxable 520,000     → 20,000',   20000.0,  $paye(520000));   // 250k @ 8%
check('taxable 760,000     → 68,000',   68000.0,  $paye(760000));   // +240k @ 20%
check('taxable 1,000,000   → 128,000',  128000.0, $paye(1000000));  // +240k @ 25%
check('taxable 900,000     → 103,000',  103000.0, $paye(900000));   // 68,000 + 140k @ 25%
check('taxable 2,000,000   → 428,000',  428000.0, $paye(2000000));  // 128,000 + 1,000k @ 30%

echo "\nFull employee chain — gross 1,000,000 @ 10% NSSF\n";
$gross = 1000000.0;
$nssf  = round($gross * PR_DEFAULT_NSSF_EMPLOYEE_RATE / 100, 2);
$taxable = $gross - $nssf;
check('NSSF (employee 10%) → 100,000',  100000.0, $nssf);
check('taxable (gross−NSSF)→ 900,000',  900000.0, $taxable);
check('PAYE on 900,000     → 103,000',  103000.0, $paye($taxable));

echo "\nSDL — employer 3.5%, only when ≥ 10 employees\n";
check('9 employees  → exempt (0)',       0.0,      calcSdlAmount(10000000, PR_DEFAULT_SDL_RATE, 9,  PR_DEFAULT_SDL_MIN_EMPLOYEES));
check('10 employees → 3.5% of 10,000,000 = 350,000', 350000.0, calcSdlAmount(10000000, PR_DEFAULT_SDL_RATE, 10, PR_DEFAULT_SDL_MIN_EMPLOYEES));
check('25 employees → 3.5% of 8,000,000 = 280,000',  280000.0, calcSdlAmount(8000000,  PR_DEFAULT_SDL_RATE, 25, PR_DEFAULT_SDL_MIN_EMPLOYEES));

echo "\n" . str_repeat('─', 60) . "\n";
echo "RESULT: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
