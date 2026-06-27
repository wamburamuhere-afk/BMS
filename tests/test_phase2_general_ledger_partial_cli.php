<?php
/**
 * Phase 2.2 — General Ledger UI partial CLI test
 * -----------------------------------------------
 *   php tests/test_phase2_general_ledger_partial_cli.php
 *
 * Verifies:
 *   1. File exists + lint-clean.
 *   2. Source contains the agreed structural patterns (permission gate,
 *      server-side account dropdown SQL, AJAX project dropdown via the
 *      shared endpoint, internal API consumption, source-column plain
 *      text per Phase 2.1, opening/closing balance cards, window-total
 *      row, project + scope banners, Phase 4 deferral note).
 *   3. CFI-style "audit trail" wording present.
 *   4. Source column rendered as plain text (no <a href=...> on
 *      entity_type-entity_id values).
 *   5. Runtime "no account picked" state: render partial with no
 *      account_id, verify the "Pick an account" prompt appears.
 *   6. Runtime "account picked" state: render with account_id=2, verify
 *      the Opening Balance, Closing Balance, and at least one detail
 *      row are present.
 *   7. Account dropdown contains <optgroup> headers for Balance Sheet
 *      Accounts AND Income Statement Accounts.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

$failures = 0;
$passes   = 0;

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function readSrc(string $root, string $rel): string {
    $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : '';
}

global $pdo;
$file = "$root/app/bms/invoice/reps/general_ledger.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. File exists + lint-clean');
// ─────────────────────────────────────────────────────────────────────────
file_exists($file) ? pass('app/bms/invoice/reps/general_ledger.php exists') : fail('file missing');
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Source contains agreed structural patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = readSrc($root, 'app/bms/invoice/reps/general_ledger.php');
$checks = [
    "canView('reports')"                                            => 'permission gate at top',
    "FROM accounts a"                                               => 'server-side account dropdown query',
    "WHERE a.status = 'active'"                                     => 'dropdown shows only active accounts',
    "Balance Sheet Accounts"                                        => 'optgroup label: BS accounts',
    "Income Statement Accounts"                                     => 'optgroup label: IS accounts',
    "require __DIR__ . '/../../../../api/account/get_general_ledger.php'" => 'consumes GL API internally',
    "get_projects_for_filter.php"                                   => 'loads project dropdown via shared endpoint',
    'name="account_id"'                                             => 'account_id form field',
    'name="start_date"'                                             => 'start_date form field',
    'name="end_date"'                                               => 'end_date form field',
    'name="project_id"'                                             => 'project_id form field',
    'name="report" value="general_ledger"'                          => 'route key set on form',
    'project_filter_active'                                         => 'project filter banner uses meta key',
    'scoped_project_ids'                                            => 'non-admin scope banner uses meta key',
    'All My Projects'                                               => 'non-admin sees "All My Projects" default',
    'Opening Balance'                                               => 'opening balance card label',
    'Closing Balance'                                               => 'closing balance card label',
    'gl-window-total'                                               => 'window-totals row styled',
    'Window totals'                                                 => 'window totals row text',
    'logReportAction'                                               => 'logs view action',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 40) . "`");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. CFI-style "audit trail" wording + Phase 4 deferral note');
// ─────────────────────────────────────────────────────────────────────────
$wording = [
    'audit trail'                          => '"audit trail" phrase',
    'Phase 4'                              => 'mentions Phase 4 (auto-posting) in deferral note',
    'auto-posting'                         => '"auto-posting" wording',
    'will become clickable'                => 'explains source links will be activated later',
];
foreach ($wording as $needle => $label) {
    strpos($src, $needle) !== false ? pass("phrase present: $label") : fail("phrase missing: $label");
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Source column rendered as plain text (no <a href> on it yet)');
// ─────────────────────────────────────────────────────────────────────────
// The source cell in the table body should just be htmlspecialchars($l['source']).
// We assert by checking that there is no <a> tag wrapping a source value.
$src_cell_pattern = '/<td[^>]*class="[^"]*\bgl-source\b/';
preg_match($src_cell_pattern, $src) === 1
    ? fail('source cell is wrapped in something resembling a link (gl-source class found)')
    : pass('source cell uses no gl-source link wrapper (correct — plain text per Phase 2.1)');

// Look for the htmlspecialchars($l['source']) call in the partial.
strpos($src, "htmlspecialchars(\$l['source'])") !== false
    ? pass('source rendered via htmlspecialchars($l[\'source\']) (plain text)')
    : fail('source rendering pattern missing');

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime — no account picked → "Pick an account" prompt');
// ─────────────────────────────────────────────────────────────────────────
$_GET = [];   // no account_id
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start();
try {
    require $file;
    $html = ob_get_clean();
    error_reporting($prevErr);

    if (strpos($html, 'Pick an account') !== false) {
        pass('"Pick an account" prompt rendered when no account_id provided');
    } else {
        fail('prompt missing — expected "Pick an account above to view its General Ledger."');
    }
    strpos($html, 'General Ledger') !== false ? pass('page title rendered') : fail('page title missing');
    strpos($html, 'name="account_id"') !== false ? pass('account dropdown rendered') : fail('account dropdown missing');
} catch (Throwable $e) {
    error_reporting($prevErr);
    ob_get_clean();
    fail('partial threw with no account_id: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Runtime — account picked → balance cards + detail rendered');
// ─────────────────────────────────────────────────────────────────────────
// Seed two test entries for account_id=2 (Opening Balance Equity) in a
// rolled-back transaction so the GL renders with known figures:
//   opening = 12,000.00 (entry before 2026-01-01)
//   closing = 13,000.00 (+ 1,000 in-period entry on 2026-01-17)
$pdo->beginTransaction();
try {
    $gl6_e = $pdo->prepare("INSERT INTO journal_entries (entry_date,reference_number,description,status,created_by,created_at) VALUES (?,?,?,'posted',4,NOW())");
    $gl6_i = $pdo->prepare("INSERT INTO journal_entry_items (entry_id,account_id,type,amount,description,created_at) VALUES (?,?,?,?,?,NOW())");
    $gl6_e->execute(['2025-12-15','GL-TEST-PRE','GL test seed pre-period']);
    $gl6_pre = (int)$pdo->lastInsertId();
    $gl6_i->execute([$gl6_pre, 3, 'debit',  12000, 'GL test']);
    $gl6_i->execute([$gl6_pre, 2, 'credit', 12000, 'GL test']);
    $gl6_e->execute(['2026-01-17','GL-TEST-IN','GL test seed in-period']);
    $gl6_in = (int)$pdo->lastInsertId();
    $gl6_i->execute([$gl6_in, 3, 'debit',  1000, 'GL test']);
    $gl6_i->execute([$gl6_in, 2, 'credit', 1000, 'GL test']);
} catch (Throwable $e) {
    $pdo->rollBack();
    fail('section 6 seed threw: ' . $e->getMessage());
    goto gl_section7;
}

$_GET = ['account_id' => 2, 'start_date' => '2026-01-01', 'end_date' => '2026-05-31'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start();
try {
    require $file;
    $html = ob_get_clean();
    error_reporting($prevErr);

    $markers = [
        'Opening Balance Equity'      => 'account name in header',
        'Opening Balance'             => 'opening balance card heading',
        'Closing Balance'             => 'closing balance card heading',
        '12,000.00'                   => 'opening balance figure (12,000.00)',
        '13,000.00'                   => 'closing balance figure (13,000.00)',
        'Manual'                      => 'source column shows "Manual" for existing manual entry',
        'Window totals'               => 'window totals row',
    ];
    foreach ($markers as $needle => $label) {
        strpos($html, $needle) !== false ? pass("rendered HTML contains: $label") : fail("rendered HTML missing: $label");
    }
} catch (Throwable $e) {
    error_reporting($prevErr);
    ob_get_clean();
    fail('partial threw with account_id=2: ' . $e->getMessage());
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
gl_section7:

// ─────────────────────────────────────────────────────────────────────────
section('7. Account dropdown has BS + IS optgroups (live DB has both)');
// ─────────────────────────────────────────────────────────────────────────
$_GET = [];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start();
require $file;
$html = ob_get_clean();
error_reporting($prevErr);

strpos($html, '<optgroup label="Balance Sheet Accounts">') !== false
    ? pass('dropdown has Balance Sheet Accounts optgroup')
    : fail('Balance Sheet optgroup missing');
strpos($html, '<optgroup label="Income Statement Accounts">') !== false
    ? pass('dropdown has Income Statement Accounts optgroup')
    : fail('Income Statement optgroup missing');

exit($failures === 0 ? 0 : 1);
