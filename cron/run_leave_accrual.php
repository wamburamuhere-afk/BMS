<?php
/**
 * cron/run_leave_accrual.php
 * --------------------------
 * Seeds the current year's leave_balances (entitlement + carry-over from last year),
 * once per day. Self-contained + fail-silent — included from header.php like the
 * recurring / document-expiry crons; never blocks a page load.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/leave_balance.php';

if (php_sapi_name() !== 'cli' && function_exists('get_setting')) {
    if (get_setting('leave_accrual_last_run') === date('Y-m-d')) return;   // already ran today
}

global $pdo;
try {
    $summary = leaveYearRollover($pdo, (int)date('Y'));
    if (function_exists('save_setting')) save_setting('leave_accrual_last_run', date('Y-m-d'));
    if (php_sapi_name() === 'cli') echo "Leave accrual complete: {$summary['rows']} balance row(s) for {$summary['year']}.\n";
} catch (Throwable $e) {
    error_log('run_leave_accrual error: ' . $e->getMessage());
}
