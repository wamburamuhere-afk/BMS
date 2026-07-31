<?php
/**
 * Payroll — "Process Payroll" modal auto-closing on preview refresh
 *   php tests/test_payroll_process_modal_autoclose_cli.php
 *
 * footer.php wires a global $(document).ajaxSuccess handler that closes
 * whatever modal is currently open whenever ANY non-GET AJAX call on the
 * page returns {success: true} — unless that modal carries
 * data-no-autoclose="true". The Process Payroll modal
 * (app/bms/pos/payroll.php, #processPayrollModal) didn't have that
 * attribute, but its own Payroll Period / Department / Employment Status
 * fields fire a POST to api/preview_payroll.php on change (just to refresh
 * the preview table) which also returns {success: true}. The global
 * handler read that as "a save just happened" and closed the modal, even
 * though nothing had actually been submitted.
 *
 * Fix: add data-no-autoclose="true" to #processPayrollModal, the same
 * opt-out already used by app/includes/ai_generate.php's #aiGenModal.
 *
 * This is a source-pattern regression guard, not a live HTTP test — the
 * page requires an authenticated session to render, so the fix was also
 * confirmed live in a real browser session (opening Process Payroll,
 * changing Payroll Period, confirming the modal stays open and the
 * preview table refreshes).
 *
 * Exit 0 = all checks pass. Exit 1 = a regression slipped in.
 */

$passes = 0; $failures = 0;
function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $passes, $failures; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$passes\033[0m\nFailures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});

echo "\n\033[1m═══ Payroll — Process Payroll modal no longer auto-closes on preview refresh ═══\033[0m\n";

$root       = dirname(__DIR__);
$payrollFile = $root . '/app/bms/pos/payroll.php';
$footerFile  = $root . '/footer.php';
$previewFile = $root . '/api/preview_payroll.php';

head('Syntax');
foreach ([$payrollFile, $footerFile, $previewFile] as $f) {
    $res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1');
    (strpos((string)$res, 'No syntax errors detected') !== false)
        ? ok(basename($f) . ' — no syntax errors')
        : bad(basename($f) . ' — ' . trim((string)$res));
}

$payrollSrc = file_get_contents($payrollFile) ?: '';
$footerSrc  = file_get_contents($footerFile) ?: '';
$previewSrc = file_get_contents($previewFile) ?: '';

head('#processPayrollModal opts out of the global auto-close handler');
(preg_match('/<div class="modal fade" id="processPayrollModal"[^>]*data-no-autoclose="true"/', $payrollSrc) === 1)
    ? ok('#processPayrollModal carries data-no-autoclose="true"')
    : bad('#processPayrollModal is missing data-no-autoclose="true" — preview refresh will re-close it');

head('Global auto-close handler in footer.php still honours the opt-out (regression guard for the mechanism itself)');
str_contains($footerSrc, "ajaxSuccess")
    ? ok('footer.php still wires the global ajaxSuccess auto-close handler')
    : bad('footer.php no longer has the ajaxSuccess handler — test assumptions are stale, re-check the fix');
str_contains($footerSrc, "getAttribute('data-no-autoclose') !== 'true'")
    ? ok("footer.php's handler still checks data-no-autoclose before hiding a modal")
    : bad("footer.php's handler no longer checks data-no-autoclose — the opt-out attribute would be ignored");

head('Confirms the trigger condition: preview endpoint returns the same {success:true} shape as a real save');
str_contains($previewSrc, "'success' => true") || str_contains($previewSrc, '"success" => true') || str_contains($previewSrc, '"success":true')
    ? ok('api/preview_payroll.php returns success:true on a normal preview (this is why the opt-out is necessary)')
    : bad('api/preview_payroll.php response shape changed — re-verify the auto-close trigger still applies');

head('Preview fields still wired to previewAndCalculatePayroll() (unchanged behaviour, only the close is suppressed)');
foreach (['input_payroll_period', 'previewAndCalculatePayroll()'] as $needle) {
    str_contains($payrollSrc, $needle)
        ? ok("payroll.php still contains \"$needle\"")
        : bad("payroll.php no longer contains \"$needle\" — preview wiring may have changed, re-verify manually");
}

head('Existing opt-out pattern (ai_generate.php) untouched — confirms this fix follows established convention');
$aiGenFile = $root . '/app/includes/ai_generate.php';
if (is_file($aiGenFile)) {
    str_contains(file_get_contents($aiGenFile) ?: '', 'data-no-autoclose="true"')
        ? ok('app/includes/ai_generate.php still carries the same data-no-autoclose="true" pattern')
        : bad('app/includes/ai_generate.php lost its data-no-autoclose="true" — unrelated regression');
} else {
    ok('app/includes/ai_generate.php not present in this checkout — skipping cross-check');
}
