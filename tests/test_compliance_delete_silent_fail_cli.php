<?php
/**
 * Compliance Documents — delete/edit action buttons silently doing nothing
 *   php tests/test_compliance_delete_silent_fail_cli.php
 *
 * User-reported: on compliance_documents.php, clicking an Actions dropdown
 * item (Delete in particular) "is not properly functioning" — nothing
 * visibly happens.
 *
 * Root cause, confirmed via a live browser session on dev.bms.local:
 * 1. deleteCompliance()'s $.post() call to api/delete_compliance.php had no
 *    .fail()/error handler. api/delete_compliance.php calls
 *    http_response_code(403) when canDelete('compliance') is false. jQuery
 *    treats any non-2xx response as an error and never invokes the success
 *    callback for it — with no .fail() wired up, a permission-denied delete
 *    produced ZERO visible feedback (confirm dialog closes, nothing else
 *    happens). Verified live: forced a mocked 403 and confirmed the OLD code
 *    path showed nothing, the NEW code path shows
 *    Swal.fire('Error!', 'You do not have permission to delete compliance
 *    records.', 'error').
 * 2. The "Edit Record"/"Remove" dropdown items were rendered unconditionally
 *    for every viewer, regardless of canEdit('compliance')/
 *    canDelete('compliance') — unlike the Upload button, which already
 *    correctly checked canCreate('compliance'). Checked the live
 *    role_permissions table: only Admin, Managing Director, and Secretary
 *    (PS) have any 'compliance' grant at all; any other role that gains view
 *    access to this page would see Edit/Remove buttons that silently fail
 *    per bug #1 above.
 * 3. api/delete_compliance.php had no csrf_check() at all, despite being a
 *    state-changing POST endpoint (security.md §21 requires this on every
 *    one).
 *
 * Fix: added .fail() handlers to both deleteCompliance() and
 * editCompliance() that surface a clear SweetAlert2 error (with a specific
 * message for 403/419), gated the Edit/Remove dropdown items behind new
 * $can_edit/$can_delete PHP flags (exposed as CAN_EDIT_COMPLIANCE/
 * CAN_DELETE_COMPLIANCE JS constants, following the same "pass the
 * permission flag from PHP so buttons are hidden when rendered" pattern
 * already used elsewhere), and added the missing csrf_check() to
 * delete_compliance.php.
 *
 * This is a source-pattern regression guard, not a live HTTP test — the
 * page requires an authenticated session to render. The fix was verified
 * live in a real browser session: confirmed a real delete still succeeds
 * end-to-end for an admin (record count dropped, item removed from the
 * list), confirmed the dropdown correctly includes Edit/Remove only when
 * the corresponding flag is true (tested all 4 true/false combinations of
 * the exact gating logic), and confirmed a mocked 403 response now surfaces
 * a clear SweetAlert2 error instead of doing nothing.
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

echo "\n\033[1m═══ Compliance Documents — delete/edit no longer fail silently ═══\033[0m\n";

$root       = dirname(__DIR__);
$pageFile   = $root . '/app/constant/document/compliance_documents.php';
$deleteFile = $root . '/api/delete_compliance.php';

head('Syntax');
foreach ([$pageFile, $deleteFile] as $f) {
    $res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1');
    (strpos((string)$res, 'No syntax errors detected') !== false)
        ? ok(basename($f) . ' — no syntax errors')
        : bad(basename($f) . ' — ' . trim((string)$res));
}

$pageSrc   = file_get_contents($pageFile) ?: '';
$deleteSrc = file_get_contents($deleteFile) ?: '';

head('Permission flags computed server-side and exposed to JS');
str_contains($pageSrc, "\$can_edit   = canEdit('compliance')")
    ? ok('$can_edit is computed via canEdit(\'compliance\')')
    : bad('$can_edit computation missing');
str_contains($pageSrc, "\$can_delete = canDelete('compliance')")
    ? ok('$can_delete is computed via canDelete(\'compliance\')')
    : bad('$can_delete computation missing');
str_contains($pageSrc, 'const CAN_EDIT_COMPLIANCE   = <?= json_encode($can_edit) ?>;')
    ? ok('CAN_EDIT_COMPLIANCE JS constant is wired from $can_edit')
    : bad('CAN_EDIT_COMPLIANCE JS constant missing or not wired to $can_edit');
str_contains($pageSrc, 'const CAN_DELETE_COMPLIANCE = <?= json_encode($can_delete) ?>;')
    ? ok('CAN_DELETE_COMPLIANCE JS constant is wired from $can_delete')
    : bad('CAN_DELETE_COMPLIANCE JS constant missing or not wired to $can_delete');

head('Edit/Remove dropdown items are gated by the permission flags, not unconditional');
str_contains($pageSrc, 'if (CAN_EDIT_COMPLIANCE) {')
    ? ok('Edit Record item is wrapped in an if (CAN_EDIT_COMPLIANCE) check')
    : bad('Edit Record item is no longer permission-gated');
str_contains($pageSrc, 'if (CAN_DELETE_COMPLIANCE) {')
    ? ok('Remove item is wrapped in an if (CAN_DELETE_COMPLIANCE) check')
    : bad('Remove item is no longer permission-gated');
// Both items must sit INSIDE the actionItems-building block, after the two
// permission checks above and before it's returned -- not built directly
// into the dropdown-menu template the way they used to be.
(preg_match('/if \(CAN_EDIT_COMPLIANCE\) \{\s*actionItems \+= `<li>.*?editCompliance/s', $pageSrc) === 1)
    ? ok('Edit Record markup is built inside the CAN_EDIT_COMPLIANCE branch (not the unconditional template)')
    : bad('Edit Record markup no longer sits inside its permission-gated branch');

head('deleteCompliance() surfaces failures instead of doing nothing');
(preg_match('/function deleteCompliance\(id\)\s*\{.*?\.fail\(function\(xhr\)\s*\{/s', $pageSrc) === 1)
    ? ok('deleteCompliance() attaches a .fail() handler to its $.post() call')
    : bad('deleteCompliance() still has no .fail() handler — 403/network errors will be silent again');
str_contains($pageSrc, "xhr.status === 403) msg = 'You do not have permission to delete compliance records.'")
    ? ok('deleteCompliance() gives a specific message for a 403 (permission denied)')
    : bad('deleteCompliance() 403 message missing');
str_contains($pageSrc, "_csrf: CSRF_TOKEN")
    ? ok('deleteCompliance() explicitly sends the CSRF token')
    : bad('deleteCompliance() no longer sends _csrf');

head('editCompliance() surfaces failures instead of doing nothing');
(preg_match('/function editCompliance\(id\)\s*\{.*?\.fail\(function\(xhr\)\s*\{/s', $pageSrc) === 1)
    ? ok('editCompliance() attaches a .fail() handler to its $.getJSON() call')
    : bad('editCompliance() still has no .fail() handler');
str_contains($pageSrc, "Swal.fire('Error!', res.message || 'Could not load this record.', 'error');")
    ? ok('editCompliance() surfaces a res.success === false response as an error too')
    : bad('editCompliance() silently does nothing when res.success is false');

head('api/delete_compliance.php enforces CSRF (was completely missing)');
(preg_match('/canDelete\(\'compliance\'\).*?csrf_check\(\);/s', $deleteSrc) === 1)
    ? ok('delete_compliance.php calls csrf_check() after the permission gate')
    : bad('delete_compliance.php still has no CSRF check — security.md §21 violation');
