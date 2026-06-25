<?php
/**
 * core/payroll_tax.php
 * --------------------
 * Central statutory-payroll engine for Tanzania:
 *   - PAYE  : progressive income tax on  (gross − employee NSSF)  [Income Tax Act, 1st Schedule]
 *   - NSSF  : employee statutory pension contribution (pre-tax)   [default 10% of gross]
 *   - SDL   : Skills Development Levy, EMPLOYER cost, 3.5% of total
 *             monthly gross, payable only when the employer has ≥ 10 employees.
 *
 * Design goals (why this file exists):
 *   1. ONE source of truth — the same math previously duplicated inline in
 *      api/process_payroll.php and api/payroll/calculate_tax.php now lives here.
 *   2. Config-driven — every rate comes from payroll_settings / tax_brackets,
 *      so a statutory change is a settings edit, never a code change.
 *   3. PERIOD-DATED — PAYE brackets are resolved as-of the payroll period date,
 *      not "today", so re-running an old month uses the table that applied THEN.
 *   4. Testable — the arithmetic is split into pure functions (no DB) that the
 *      CLI test exercises directly; the PDO wrappers only fetch config.
 *
 * Mirrors the defensive style of core/salary_structure.php: no roots.php
 * include, every symbol guarded by function_exists so it is safe to require
 * more than once.
 */

// ─────────────────────────────────────────────────────────────────────────────
//  Defaults (used to seed config and as the safety fallback if config is absent)
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('defaultTanzaniaPayeBrackets')) {
    /**
     * Mainland Tanzania resident monthly PAYE bands, 2024/25
     * (TRA "Taxes and Duties at a Glance 2024/25", §4.0). Stored as MARGINAL
     * bands — the cumulative "TZS x plus y% of excess" table is mathematically
     * identical to taxing each band at its own rate.
     *
     * @return array<int,array{min_income:float,max_income:?float,tax_rate:float,bracket_name:string}>
     */
    function defaultTanzaniaPayeBrackets(): array
    {
        return [
            ['min_income' => 0,        'max_income' => 270000,  'tax_rate' => 0,  'bracket_name' => 'Band 1 (0 – 270,000)'],
            ['min_income' => 270000,   'max_income' => 520000,  'tax_rate' => 8,  'bracket_name' => 'Band 2 (270,001 – 520,000)'],
            ['min_income' => 520000,   'max_income' => 760000,  'tax_rate' => 20, 'bracket_name' => 'Band 3 (520,001 – 760,000)'],
            ['min_income' => 760000,   'max_income' => 1000000, 'tax_rate' => 25, 'bracket_name' => 'Band 4 (760,001 – 1,000,000)'],
            ['min_income' => 1000000,  'max_income' => null,    'tax_rate' => 30, 'bracket_name' => 'Band 5 (above 1,000,000)'],
        ];
    }
}

if (!defined('PR_DEFAULT_NSSF_EMPLOYEE_RATE')) define('PR_DEFAULT_NSSF_EMPLOYEE_RATE', 10.0);
if (!defined('PR_DEFAULT_SDL_RATE'))           define('PR_DEFAULT_SDL_RATE', 3.5);
if (!defined('PR_DEFAULT_SDL_MIN_EMPLOYEES'))  define('PR_DEFAULT_SDL_MIN_EMPLOYEES', 10);

// ─────────────────────────────────────────────────────────────────────────────
//  Pure calculators (no DB — unit-testable)
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('calcProgressiveTax')) {
    /**
     * Progressive (marginal) tax on a taxable amount given a set of bands.
     * Each band taxes only the slice of income that falls inside it.
     *
     * @param float $taxable  Taxable income (already net of NSSF for PAYE).
     * @param array $brackets Rows with min_income, max_income (null = no ceiling),
     *                        tax_rate (percent). Order is normalised here.
     * @return array{tax:float, breakdown:array<int,array{band:string,min:float,max:?float,rate:float,taxed:float,tax:float}>}
     */
    function calcProgressiveTax(float $taxable, array $brackets): array
    {
        $taxable = max(0.0, $taxable);

        // Normalise + sort ascending by lower bound so banding is deterministic
        // regardless of how config rows were entered.
        usort($brackets, static fn($a, $b) => ((float)($a['min_income'] ?? 0)) <=> ((float)($b['min_income'] ?? 0)));

        $tax = 0.0;
        $breakdown = [];
        foreach ($brackets as $b) {
            $min  = (float)($b['min_income'] ?? 0);
            if ($taxable <= $min) continue;                      // nothing reaches this band
            $rate = (float)($b['tax_rate'] ?? 0);
            $max  = ($b['max_income'] === null || $b['max_income'] === '') ? null : (float)$b['max_income'];

            $upper  = ($max === null) ? $taxable : min($taxable, $max);
            $banded = $upper - $min;
            if ($banded <= 0) continue;

            $bandTax = round($banded * $rate / 100, 2);
            $tax += $bandTax;
            $breakdown[] = [
                'band'  => (string)($b['bracket_name'] ?? ''),
                'min'   => $min,
                'max'   => $max,
                'rate'  => $rate,
                'taxed' => round($banded, 2),
                'tax'   => $bandTax,
            ];
        }
        return ['tax' => round($tax, 2), 'breakdown' => $breakdown];
    }
}

if (!function_exists('calcSdlAmount')) {
    /**
     * SDL = rate% of total monthly gross emoluments — but ONLY when the employer
     * has at least $minEmployees employees (Tanzania Mainland: 10 or more).
     * Below the threshold the employer is exempt and SDL is zero.
     */
    function calcSdlAmount(float $totalGross, float $rate, int $employeeCount, int $minEmployees): float
    {
        if ($employeeCount < $minEmployees) return 0.0;
        return round(max(0.0, $totalGross) * $rate / 100, 2);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Config readers (DB)
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('payrollSetting')) {
    /** Read a payroll_settings value by key; $default when the row/table is absent. */
    function payrollSetting(PDO $pdo, string $key, $default = null)
    {
        try {
            $s = $pdo->prepare("SELECT setting_value FROM payroll_settings WHERE setting_key = ? LIMIT 1");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            if ($v !== false && $v !== null && $v !== '') return $v;
        } catch (Throwable $e) { /* table/row absent → default */ }
        return $default;
    }
}

if (!function_exists('nssfEmployeeRate')) {
    function nssfEmployeeRate(PDO $pdo): float
    {
        // Reuse the existing 'nssf_rate' setting (already seeded = 10); fall back to
        // the newer key, then the hard default, so the engine never returns 0 by
        // accident on a server missing the row.
        $v = payrollSetting($pdo, 'nssf_rate', null);
        if ($v === null) $v = payrollSetting($pdo, 'nssf_employee_rate', PR_DEFAULT_NSSF_EMPLOYEE_RATE);
        return (float)$v;
    }
}

if (!function_exists('sdlRate')) {
    function sdlRate(PDO $pdo): float
    {
        return (float)payrollSetting($pdo, 'sdl_rate', PR_DEFAULT_SDL_RATE);
    }
}

if (!function_exists('sdlMinEmployees')) {
    function sdlMinEmployees(PDO $pdo): int
    {
        return (int)payrollSetting($pdo, 'sdl_min_employees', PR_DEFAULT_SDL_MIN_EMPLOYEES);
    }
}

if (!function_exists('nssfEmployerRate')) {
    function nssfEmployerRate(PDO $pdo): float
    {
        $v = payrollSetting($pdo, 'nssf_employer_rate', null);
        return ($v !== null) ? (float)$v : (float)PR_DEFAULT_NSSF_EMPLOYEE_RATE;
    }
}

if (!function_exists('activeTaxBrackets')) {
    /**
     * PAYE bands in force on $asOfDate (YYYY-MM-DD). Falls back to the seeded
     * 2024/25 defaults if the table is empty/missing, so payroll never crashes
     * on a server where the seed migration has not yet run.
     *
     * @param string|null $asOfDate Period date; defaults to today.
     */
    function activeTaxBrackets(PDO $pdo, ?string $asOfDate = null): array
    {
        $asOf = ($asOfDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) ? $asOfDate : date('Y-m-d');
        try {
            $s = $pdo->prepare("
                SELECT min_income, max_income, tax_rate, bracket_name
                  FROM tax_brackets
                 WHERE is_active = 1
                   AND effective_from <= ?
                   AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY min_income ASC
            ");
            $s->execute([$asOf, $asOf]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) return $rows;
        } catch (Throwable $e) { /* fall through to defaults */ }
        return defaultTanzaniaPayeBrackets();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  High-level computations (DB-backed, delegate to the pure calculators)
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('computeEmployeeStatutory')) {
    /**
     * Per-employee statutory bundle for one pay period.
     *   nssf_employee : 10% of gross (pre-tax pension contribution)
     *   taxable       : gross − nssf_employee
     *   paye          : progressive tax on taxable, using the period's bands
     *
     * @param float       $gross The employee's gross for the period (basic + allowances).
     * @param string|null $asOfDate Period date (drives which PAYE table applies).
     * @return array{gross:float,nssf_rate:float,nssf_employee:float,taxable:float,paye:float,paye_breakdown:array}
     */
    function computeEmployeeStatutory(PDO $pdo, float $gross, ?string $asOfDate = null): array
    {
        $gross = max(0.0, $gross);
        $nssfRate = nssfEmployeeRate($pdo);
        $nssf = round($gross * $nssfRate / 100, 2);
        $taxable = max(0.0, $gross - $nssf);
        $paye = calcProgressiveTax($taxable, activeTaxBrackets($pdo, $asOfDate));

        return [
            'gross'          => round($gross, 2),
            'nssf_rate'      => $nssfRate,
            'nssf_employee'  => $nssf,
            'taxable'        => round($taxable, 2),
            'paye'           => $paye['tax'],
            'paye_breakdown' => $paye['breakdown'],
        ];
    }
}

if (!function_exists('computeSdl')) {
    /**
     * Company-level SDL for a period. SDL is an EMPLOYER cost, not deducted from
     * staff: it is rate% of the total gross of all employees, charged only when
     * the headcount meets the statutory minimum.
     *
     * @param float $totalGross   Σ gross of all employees in the period.
     * @param int   $employeeCount Number of employees in the period.
     * @return array{applies:bool,rate:float,min_employees:int,employee_count:int,total_gross:float,amount:float}
     */
    function computeSdl(PDO $pdo, float $totalGross, int $employeeCount): array
    {
        $rate = sdlRate($pdo);
        $min  = sdlMinEmployees($pdo);
        $amt  = calcSdlAmount($totalGross, $rate, $employeeCount, $min);
        return [
            'applies'        => $employeeCount >= $min,
            'rate'           => $rate,
            'min_employees'  => $min,
            'employee_count' => $employeeCount,
            'total_gross'    => round(max(0.0, $totalGross), 2),
            'amount'         => $amt,
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Remittance schedule — the "intelligent" monthly statutory obligations
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('periodRemittanceDueDate')) {
    /**
     * Statutory remittance is due within 7 days after the end of the month
     * (TRA "Taxes and Duties at a Glance 2024/25", PAYE §4.0 & SDL §6.0).
     * @param string $period 'YYYY-MM'
     * @return string|null   due date 'YYYY-MM-DD', or null if $period is malformed
     */
    function periodRemittanceDueDate(string $period): ?string
    {
        $ts = strtotime($period . '-01');
        if ($ts === false) return null;
        return date('Y-m-d', strtotime(date('Y-m-t', $ts) . ' +7 days'));
    }
}

if (!function_exists('syncStatutoryRemittances')) {
    /**
     * Recompute and upsert the PAYE / NSSF / SDL remittance obligations for a period
     * from that period's processed payroll. Idempotent and safe to call repeatedly:
     * a remittance already marked 'paid' is never altered (only 'pending' rows refresh).
     * This is what surfaces "what you owe TRA/NSSF this month and by when".
     *
     * @return array{period:string,due_date:?string,employee_count:int,amounts:array,sdl:array}
     */
    function syncStatutoryRemittances(PDO $pdo, string $period, ?int $userId = null): array
    {
        $agg = $pdo->prepare("SELECT COUNT(*) AS cnt,
                                     COALESCE(SUM(gross_salary),0)                               AS gross,
                                     COALESCE(SUM(tax_amount),0)                                 AS paye,
                                     COALESCE(SUM(nssf_employee + COALESCE(nssf_employer,0)),0)  AS nssf
                                FROM payroll
                               WHERE payroll_period = ? AND payment_status NOT IN ('cancelled','voided')");
        $agg->execute([$period]);
        $r = $agg->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'gross' => 0, 'paye' => 0, 'nssf' => 0];

        $cnt = (int)$r['cnt'];
        $sdl = computeSdl($pdo, (float)$r['gross'], $cnt);
        $due = periodRemittanceDueDate($period);
        $amounts = [
            'paye' => round((float)$r['paye'], 2),
            'nssf' => round((float)$r['nssf'], 2),
            'sdl'  => $sdl['amount'],
        ];

        $up = $pdo->prepare("INSERT INTO statutory_remittances (tax_type, period, amount, due_date, status, created_by, created_at)
                             VALUES (?, ?, ?, ?, 'pending', ?, NOW())
                             ON DUPLICATE KEY UPDATE
                               amount   = IF(status = 'pending', VALUES(amount), amount),
                               due_date = IF(status = 'pending', VALUES(due_date), due_date),
                               updated_at = NOW()");
        foreach ($amounts as $type => $amt) {
            $up->execute([$type, $period, $amt, $due, $userId]);
        }

        return ['period' => $period, 'due_date' => $due, 'employee_count' => $cnt, 'amounts' => $amounts, 'sdl' => $sdl];
    }
}
