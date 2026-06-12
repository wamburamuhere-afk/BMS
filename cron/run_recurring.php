<?php
/**
 * cron/run_recurring.php
 * ----------------------
 * Generates every due recurring document. Self-contained and fail-silent so it can
 * never break a page load. Included (throttled to once per day) from header.php,
 * exactly like cron/check_document_expiry.php, and can also be run from the CLI or
 * triggered on demand via api/account/run_recurring_now.php.
 *
 * Generated documents are created in their normal pending/draft state — nothing is
 * posted or paid automatically.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/recurring.php';

if (!function_exists('run_recurring_documents')) {
    function run_recurring_documents(PDO $pdo): array {
        try {
            return recurringRunAll($pdo);
        } catch (Throwable $e) {
            error_log('run_recurring_documents error: ' . $e->getMessage());
            return ['generated' => 0, 'skipped' => 0, 'error' => true];
        }
    }
}

// Throttle: only the first request of the day actually runs the engine (the same
// guard pattern as the document-expiry cron). The setting write marks it done.
if (php_sapi_name() !== 'cli' && function_exists('get_setting')) {
    if (get_setting('recurring_last_run') === date('Y-m-d')) {
        return;   // already ran today
    }
}

global $pdo;
$summary = run_recurring_documents($pdo);
if (function_exists('save_setting')) {
    save_setting('recurring_last_run', date('Y-m-d'));
}

if (php_sapi_name() === 'cli') {
    echo "Recurring run complete: {$summary['generated']} generated, {$summary['skipped']} skipped.\n";
}
