<?php
/**
 * Assets — "Run Depreciation" proposal modal auto-closing on preview
 *   php tests/test_asset_depreciation_modal_autoclose_cli.php
 *
 * Same root cause and same fix as the Payroll "Process Payroll" modal
 * (tests/test_payroll_process_modal_autoclose_cli.php): footer.php's
 * global $(document).ajaxSuccess handler closes whatever modal is
 * currently open whenever ANY non-GET AJAX call on the page returns
 * {success: true}, unless that modal carries data-no-autoclose="true".
 *
 * #depProposalModal (app/bms/operations/assets.php) didn't have that
 * attribute. Clicking "Preview" calls loadDepPreview(), which POSTs to
 * api/assets/run_depreciation.php with mode=preview — that endpoint
 * returns {success: true, mode: 'preview', proposal: ...} on a normal
 * preview. The global handler read that as "a save just happened" and
 * closed the modal immediately after the preview table rendered, before
 * the user could ever see or click the now-enabled "Post Depreciation"
 * button — reported as two symptoms ("preview closes the form" and
 * "Post Depreciation doesn't work") that were actually one bug.
 *
 * Fix: add data-no-autoclose="true" to #depProposalModal, the same
 * opt-out already used by #processPayrollModal and #aiGenModal.
 *
 * This is a source-pattern regression guard, not a live HTTP test — the
 * page requires an authenticated session to render, so the fix was also
 * confirmed live in a real browser session.
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

echo "\n\033[1m═══ Assets — Run Depreciation proposal modal no longer auto-closes on preview ═══\033[0m\n";

$root       = dirname(__DIR__);
$assetsFile = $root . '/app/bms/operations/assets.php';
$footerFile = $root . '/footer.php';
$runDepFile = $root . '/api/assets/run_depreciation.php';

head('Syntax');
foreach ([$assetsFile, $footerFile, $runDepFile] as $f) {
    $res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1');
    (strpos((string)$res, 'No syntax errors detected') !== false)
        ? ok(basename($f) . ' — no syntax errors')
        : bad(basename($f) . ' — ' . trim((string)$res));
}

$assetsSrc = file_get_contents($assetsFile) ?: '';
$footerSrc = file_get_contents($footerFile) ?: '';
$runDepSrc = file_get_contents($runDepFile) ?: '';

head('#depProposalModal opts out of the global auto-close handler');
(preg_match('/<div class="modal fade" id="depProposalModal"[^>]*data-no-autoclose="true"/', $assetsSrc) === 1)
    ? ok('#depProposalModal carries data-no-autoclose="true"')
    : bad('#depProposalModal is missing data-no-autoclose="true" — preview will re-close it');

head('Global auto-close handler in footer.php still honours the opt-out (regression guard for the mechanism itself)');
str_contains($footerSrc, "ajaxSuccess")
    ? ok('footer.php still wires the global ajaxSuccess auto-close handler')
    : bad('footer.php no longer has the ajaxSuccess handler — test assumptions are stale, re-check the fix');
str_contains($footerSrc, "getAttribute('data-no-autoclose') !== 'true'")
    ? ok("footer.php's handler still checks data-no-autoclose before hiding a modal")
    : bad("footer.php's handler no longer checks data-no-autoclose — the opt-out attribute would be ignored");

head('Confirms the trigger condition: preview mode returns the same {success:true} shape as a real post');
str_contains($runDepSrc, "'success' => true, 'mode' => 'preview'")
    ? ok("api/assets/run_depreciation.php's preview branch returns success:true (this is why the opt-out is necessary)")
    : bad('api/assets/run_depreciation.php preview response shape changed — re-verify the auto-close trigger still applies');

head('Preview/Post wiring unchanged (only the close is suppressed)');
foreach (['function loadDepPreview()', 'function postDepreciation()', "mode: 'preview'", "mode: 'post'"] as $needle) {
    str_contains($assetsSrc, $needle)
        ? ok("assets.php still contains \"$needle\"")
        : bad("assets.php no longer contains \"$needle\" — depreciation wiring may have changed, re-verify manually");
}

head('Post Depreciation button still starts disabled until a preview populates rows (unchanged safety behaviour)');
str_contains($assetsSrc, 'id="dep_post_btn" onclick="postDepreciation()" disabled')
    ? ok('#dep_post_btn still starts disabled in the markup')
    : bad('#dep_post_btn no longer starts disabled — could allow posting before a preview is run');

head('Existing opt-out pattern (AI Assistant) untouched — confirms this fix follows established convention');
$aiGenFile = $root . '/app/includes/ai_generate.php';
if (is_file($aiGenFile)) {
    str_contains(file_get_contents($aiGenFile) ?: '', 'data-no-autoclose="true"')
        ? ok('app/includes/ai_generate.php still carries the same data-no-autoclose="true" pattern')
        : bad('app/includes/ai_generate.php lost its data-no-autoclose="true" — unrelated regression');
} else {
    ok('app/includes/ai_generate.php not present in this checkout — skipping cross-check');
}
