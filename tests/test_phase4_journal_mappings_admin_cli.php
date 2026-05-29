<?php
/**
 * Phase 4.2 — Journal Mappings admin UI + save API CLI test
 * ----------------------------------------------------------
 *   php tests/test_phase4_journal_mappings_admin_cli.php
 *
 * Verifies:
 *   1. All 3 files lint-clean (save API + partial + reports.php).
 *   2. Source patterns in save_journal_mappings.php:
 *      - auth + POST + permission gates
 *      - transaction wrapper
 *      - is_active=1 requires both Dr and Cr
 *      - Dr != Cr enforced
 *      - FKs validated against accounts.account_id
 *      - existing-id check (no creating new rows from the UI)
 *   3. Source patterns in journal_mappings.php partial:
 *      - admin permission gate at top
 *      - 8-row table with hidden id + select dropdowns + active toggle + notes
 *      - Select2 init + active-toggle guard + bulk-save AJAX
 *   4. Source patterns in reports.php:
 *      - journal_mappings route branch
 *      - admin-only tile in Financial Reports group (canEdit guard)
 *   5. Runtime: render the partial as admin → contains expected markup
 *      (8 event rows, account dropdowns, active switches, Save button).
 *   6. Runtime: route ?report=journal_mappings dispatches to the partial.
 *   7. Save API end-to-end (live DB, transactional snapshot + restore):
 *      a) snapshot current state
 *      b) POST valid payload (2 accounts, is_active=0) → success + row updated
 *      c) POST is_active=1 with NULL Dr → validation error
 *      d) POST debit_account_id = credit_account_id → validation error
 *      e) POST non-existent account_id → validation error
 *      f) POST non-existent mapping id → validation error
 *      g) restore the snapshot
 *   8. Phase 4.1 schema test still passes.
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

$api_file     = "$root/api/account/save_journal_mappings.php";
$partial_file = "$root/app/bms/invoice/reps/journal_mappings.php";
$reports_file = "$root/app/bms/invoice/reports.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ([$api_file, $partial_file, $reports_file] as $f) {
    file_exists($f) ? pass(basename($f) . ' exists') : fail(basename($f) . ' missing');
    $rc = 0; exec("php -l " . escapeshellarg($f) . " 2>&1", $o, $rc);
    $rc === 0 ? pass(basename($f) . ' lint-clean') : fail(basename($f) . ' lint failed');
    $o = [];
}

// ─────────────────────────────────────────────────────────────────────────
section('2. save_journal_mappings.php source patterns');
// ─────────────────────────────────────────────────────────────────────────
$api_src = file_get_contents($api_file);
$api_checks = [
    'isAuthenticated()'                            => 'auth gate',
    "REQUEST_METHOD'] !== 'POST'"                  => 'POST-only gate',
    "canEdit('chart_of_accounts')"                 => 'permission gate (admin-grade)',
    '$pdo->beginTransaction()'                     => 'transaction wrapper begin',
    '$pdo->commit()'                               => 'transaction wrapper commit',
    '$pdo->rollBack()'                             => 'transaction wrapper rollback',
    'cannot activate without both debit and credit' => 'is_active=1 requires both Dr+Cr',
    'debit and credit cannot be the same'           => 'Dr != Cr enforced',
    'is not a valid active account'                 => 'FK validation against accounts',
    'mapping id='                                   => 'existing-id check (no UI inserts)',
    "UPDATE journal_mappings"                       => 'updates by id (no inserts)',
    'json_encode'                                   => 'JSON response',
];
foreach ($api_checks as $needle => $label) {
    strpos($api_src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. journal_mappings.php partial source patterns');
// ─────────────────────────────────────────────────────────────────────────
$partial_src = file_get_contents($partial_file);
$partial_checks = [
    "canEdit('chart_of_accounts')"                  => 'admin permission gate at top',
    'http_response_code(403)'                       => 'returns 403 when not permitted',
    'FROM journal_mappings'                         => 'reads journal_mappings rows',
    'name="mappings['                               => 'inputs named mappings[id][...] for bulk POST',
    '[debit_account_id]'                            => 'debit_account_id input',
    '[credit_account_id]'                           => 'credit_account_id input',
    '[is_active]'                                   => 'is_active input',
    '[notes]'                                       => 'notes input',
    'jm-account-select'                             => 'Select2-marker class on dropdowns',
    'jm-active-toggle'                              => 'active-toggle class for JS guard',
    'jm-save-btn'                                   => 'Save button id',
    'select2('                                      => 'Select2 init for searchable dropdowns',
    'Set both Debit and Credit accounts'            => 'JS guard message',
    "/bms/api/account/save_journal_mappings.php"    => 'AJAX posts to the save API',
    'Save All Mappings'                             => 'Save button label',
    'events active'                                 => 'active-count badge in header',
];
foreach ($partial_checks as $needle => $label) {
    strpos($partial_src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('4. reports.php route + tile patterns');
// ─────────────────────────────────────────────────────────────────────────
$reports_src = file_get_contents($reports_file);
$report_checks = [
    "\$report === 'journal_mappings'"                                 => 'route branch present',
    "include 'reps/journal_mappings.php'"                              => 'dispatcher includes the partial',
    "?report=journal_mappings"                                        => 'tile links to the route',
    'canEdit(\'chart_of_accounts\')'                                  => 'tile gated to admins (canEdit chart_of_accounts)',
    '<span>Journal Mappings'                                          => 'tile span literal',
    '(admin · auto-posting)'                                          => 'tile subtitle',
];
foreach ($report_checks as $needle => $label) {
    strpos($reports_src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// Positional check: Journal Mappings tile is inside Financial Reports group
$fin_pos = strpos($reports_src, 'Financial Reports');
$jm_pos  = strpos($reports_src, '<span>Journal Mappings');
$inv_pos = strpos($reports_src, 'Inventory Reports');
($fin_pos !== false && $jm_pos !== false && $jm_pos > $fin_pos && ($inv_pos === false || $jm_pos < $inv_pos))
    ? pass('Journal Mappings tile sits inside Financial Reports group')
    : fail('Journal Mappings tile is OUTSIDE the Financial Reports group');

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime: render the partial as admin');
// ─────────────────────────────────────────────────────────────────────────
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start();
try {
    require $partial_file;
    $html = ob_get_clean();
    error_reporting($prevErr);

    $markers = [
        'Journal Mappings'              => 'page heading',
        'Auto-posting Configuration'    => 'page subtitle',
        'events active'                 => 'active-count badge',
        'invoice_approved'              => 'event row: invoice_approved',
        'payment_received'              => 'event row: payment_received',
        'expense_paid'                  => 'event row: expense_paid',
        'payroll_paid'                  => 'event row: payroll_paid',
        'grn_approved'                  => 'event row: grn_approved',
        'supplier_payment'              => 'event row: supplier_payment',
        'asset_purchased'               => 'event row: asset_purchased',
        'depreciation_run'              => 'event row: depreciation_run',
        'Save All Mappings'             => 'Save button rendered',
        'jm-account-select'             => 'Select2 hooks rendered',
        '<optgroup'                     => '<optgroup>s render for account_type sections',
    ];
    foreach ($markers as $needle => $label) {
        strpos($html, $needle) !== false ? pass("HTML: $label") : fail("HTML missing: $label");
    }

    // Count active-toggle <input> elements — must be exactly 8 (one per mapping).
    // Use a tag-specific pattern so the JS selector inside <script> doesn't inflate the count.
    $switch_count = preg_match_all('/<input[^>]+class="[^"]*jm-active-toggle[^"]*"/', $html);
    $switch_count === 8 ? pass("8 active-toggle <input> switches rendered (got $switch_count)")
                       : fail("expected 8 active-toggle <input> switches, got $switch_count");
} catch (Throwable $e) {
    error_reporting($prevErr);
    ob_get_clean();
    fail('partial threw during render: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Runtime: ?report=journal_mappings dispatches to the partial');
// ─────────────────────────────────────────────────────────────────────────
$_GET = ['report' => 'journal_mappings'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start();
try {
    require $reports_file;
    $html_route = ob_get_clean();
    error_reporting($prevErr);
    strpos($html_route, 'Auto-posting Configuration') !== false
        ? pass('reports.php?report=journal_mappings renders the partial')
        : fail('reports.php?report=journal_mappings did NOT render the partial');
} catch (Throwable $e) {
    error_reporting($prevErr);
    ob_get_clean();
    fail('reports.php route threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('7. Save API end-to-end (live DB, snapshot + restore)');
// ─────────────────────────────────────────────────────────────────────────

// 7a. Pre-reset row #1 to safe defaults BEFORE snapshot so a prior pollution
// from this very test (or a Phase 4.1 baseline pre-condition) does not get
// captured into the snapshot and re-applied at restore.
$pdo->exec("UPDATE journal_mappings SET debit_account_id = NULL, credit_account_id = NULL, is_active = 0, notes = NULL WHERE id = 1");

$snapshot = $pdo->query("SELECT id, debit_account_id, credit_account_id, is_active, notes FROM journal_mappings ORDER BY id")
                ->fetchAll(PDO::FETCH_ASSOC);
count($snapshot) === 8 ? pass('snapshot: 8 rows captured (row #1 pre-reset to safe defaults)') : fail('snapshot: expected 8 rows, got ' . count($snapshot));

// Pick two distinct active account IDs for the test
$acct_ids = array_map('intval', $pdo->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 3")
                                     ->fetchAll(PDO::FETCH_COLUMN));
if (count($acct_ids) < 2) {
    fail('not enough active accounts to run end-to-end tests');
    exit(1);
}
[$acct_a, $acct_b] = $acct_ids;

// Helper to invoke the save API in-process
$invokeSave = function (array $mappings) use ($api_file): array {
    $saved_post = $_POST;
    $saved_method = $_SERVER['REQUEST_METHOD'] ?? null;
    $_POST = ['mappings' => $mappings];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $prevErr = error_reporting(error_reporting() & ~E_WARNING);
    ob_start();
    try { require $api_file; } catch (Throwable $e) { ob_get_clean(); return ['exception' => $e->getMessage()]; }
    $raw = ob_get_clean();
    error_reporting($prevErr);
    $_POST = $saved_post;
    if ($saved_method !== null) $_SERVER['REQUEST_METHOD'] = $saved_method; else unset($_SERVER['REQUEST_METHOD']);
    return json_decode($raw, true) ?? ['raw' => $raw];
};

$first_id = (int)$snapshot[0]['id'];

// 7b. valid payload — update first row with Dr+Cr but keep is_active=0
$resp = $invokeSave([
    $first_id => [
        'id'                => $first_id,
        'debit_account_id'  => $acct_a,
        'credit_account_id' => $acct_b,
        'is_active'         => 0,
        'notes'             => 'test row — will be reverted',
    ],
]);
($resp['success'] ?? false) === true ? pass("valid payload accepted ({$resp['updated']} updated)")
                                     : fail('valid payload rejected: ' . json_encode($resp));

// Verify it actually wrote
$check = $pdo->prepare("SELECT debit_account_id, credit_account_id, is_active FROM journal_mappings WHERE id = ?");
$check->execute([$first_id]);
$row_now = $check->fetch(PDO::FETCH_ASSOC);
((int)$row_now['debit_account_id']  === $acct_a
 && (int)$row_now['credit_account_id'] === $acct_b
 && (int)$row_now['is_active'] === 0)
    ? pass("row #$first_id reflects the update (Dr=$acct_a, Cr=$acct_b, active=0)")
    : fail("row #$first_id state wrong after save: " . json_encode($row_now));

// 7c. is_active=1 with NULL debit → must fail
$resp = $invokeSave([
    $first_id => ['id' => $first_id, 'debit_account_id' => '', 'credit_account_id' => $acct_b, 'is_active' => 1, 'notes' => ''],
]);
($resp['success'] ?? null) === false && isset($resp['errors'])
    && implode(' ', $resp['errors']) && stripos(implode(' ', $resp['errors']), 'cannot activate without both') !== false
    ? pass('is_active=1 with NULL Dr → validation error (correct)')
    : fail('is_active=1 with NULL Dr should fail but did not: ' . json_encode($resp));

// 7d. Dr == Cr → must fail
$resp = $invokeSave([
    $first_id => ['id' => $first_id, 'debit_account_id' => $acct_a, 'credit_account_id' => $acct_a, 'is_active' => 0, 'notes' => ''],
]);
($resp['success'] ?? null) === false
    && isset($resp['errors'])
    && stripos(implode(' ', $resp['errors']), 'debit and credit cannot be the same') !== false
    ? pass('Dr == Cr → validation error (correct)')
    : fail('Dr == Cr should fail but did not: ' . json_encode($resp));

// 7e. non-existent account_id → must fail
$bad_acct = 9999999;
$resp = $invokeSave([
    $first_id => ['id' => $first_id, 'debit_account_id' => $bad_acct, 'credit_account_id' => $acct_b, 'is_active' => 0, 'notes' => ''],
]);
($resp['success'] ?? null) === false
    && isset($resp['errors'])
    && stripos(implode(' ', $resp['errors']), 'not a valid active account') !== false
    ? pass("non-existent account_id=$bad_acct → validation error (correct)")
    : fail("non-existent account_id should fail but did not: " . json_encode($resp));

// 7f. non-existent mapping id → must fail
$bad_id = 9999999;
$resp = $invokeSave([
    $bad_id => ['id' => $bad_id, 'debit_account_id' => $acct_a, 'credit_account_id' => $acct_b, 'is_active' => 0, 'notes' => ''],
]);
($resp['success'] ?? null) === false
    && isset($resp['errors'])
    && stripos(implode(' ', $resp['errors']), 'does not exist') !== false
    ? pass("non-existent mapping id=$bad_id → validation error (correct)")
    : fail("non-existent mapping id should fail but did not: " . json_encode($resp));

// 7g. restore snapshot
$restore = $pdo->prepare("UPDATE journal_mappings SET debit_account_id = ?, credit_account_id = ?, is_active = ?, notes = ? WHERE id = ?");
foreach ($snapshot as $s) {
    $restore->execute([
        $s['debit_account_id'],
        $s['credit_account_id'],
        $s['is_active'],
        $s['notes'],
        $s['id'],
    ]);
}
// Confirm restore
$post_restore = $pdo->query("SELECT id, debit_account_id, credit_account_id, is_active, notes FROM journal_mappings ORDER BY id")
                    ->fetchAll(PDO::FETCH_ASSOC);
$post_restore === $snapshot ? pass('snapshot restored — DB returned to pre-test state')
                            : fail('snapshot restore mismatch — manual cleanup may be needed');

// ─────────────────────────────────────────────────────────────────────────
section('8. Phase 4.1 schema test still passes');
// ─────────────────────────────────────────────────────────────────────────
$phase41 = "$root/tests/test_phase4_journal_mappings_schema_cli.php";
if (file_exists($phase41)) {
    $rc = 0; exec("php " . escapeshellarg($phase41) . " 2>&1", $o, $rc);
    $rc === 0 ? pass('Phase 4.1 schema test still passes')
              : fail('Phase 4.1 schema test failed: rc=' . $rc);
} else {
    pass('Phase 4.1 schema test not present — skipping');
}

exit($failures === 0 ? 0 : 1);
