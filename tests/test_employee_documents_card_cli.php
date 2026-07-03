<?php
/**
 * Employee Documents card upgrade on employee_details.php (Tier 2, Phase 2.2
 * closeout) CLI test.
 *   php tests/test_employee_documents_card_cli.php
 *
 * Phase 2.2 spec item 4 required the Documents card on employee_details.php to
 * show new-system typed/expiring documents (table + Upload button) above the
 * untouched legacy JSON documents (D9), with a "Legacy files" divider only
 * when legacy docs exist. Proves: legacy-only renders identically to before
 * (plus the divider), new-only renders the table with no legacy noise,
 * mixed renders both, and an employee with neither shows one clean empty
 * state (no duplicate "no documents" messages).
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'render') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)($argv[3] ?? 4); $_SESSION['username'] = 'admin';
    $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/employee_details';
    $_GET['id'] = (int)($argv[2] ?? 0);
    require "$root/app/bms/pos/employee_details.php";
    exit;
}

require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function render($emp, $uid) { return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $emp $uid 2>&1"); }
function noErr($html) { foreach (['Fatal error', 'Parse error', 'Uncaught', 'Unknown column', 'SQLSTATE', 'Call to a member function', 'Call to undefined'] as $e) if (stripos($html, $e) !== false) return false; return true; }

$ids = [];
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();
    $type_id = (int)$pdo->query("SELECT doc_type_id FROM employee_document_types WHERE type_name = 'CV/Resume'")->fetchColumn();

    $mk = function ($tag) use ($pdo) {
        $pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, employment_status, created_at)
                     VALUES ('__EDC', '$tag', '__EDC-$tag', 'active', NOW())");
        return (int)$pdo->lastInsertId();
    };

    // ── Fixture 1: legacy-only (no new-system row) ───────────────────────────
    $ids['legacy'] = $mk('Legacy');
    $pdo->prepare("UPDATE employees SET documents = ? WHERE employee_id = ?")
        ->execute([json_encode(['cv' => 'uploads/employees/fake_cv.pdf']), $ids['legacy']]);

    // ── Fixture 2: new-system only ────────────────────────────────────────────
    $ids['new'] = $mk('New');
    $pdo->prepare("INSERT INTO employee_documents (employee_id, doc_type_id, document_name, file_path, original_filename, file_size, status, created_by)
                   VALUES (?, ?, 'New System Doc', 'uploads/employee_docs/fake.pdf', 'fake.pdf', 10, 'active', ?)")
        ->execute([$ids['new'], $type_id, $admin_uid]);

    // ── Fixture 3: mixed ───────────────────────────────────────────────────────
    $ids['mixed'] = $mk('Mixed');
    $pdo->prepare("UPDATE employees SET documents = ? WHERE employee_id = ?")
        ->execute([json_encode(['id' => 'uploads/employees/fake_id.pdf']), $ids['mixed']]);
    $pdo->prepare("INSERT INTO employee_documents (employee_id, doc_type_id, document_name, file_path, original_filename, file_size, status, created_by)
                   VALUES (?, ?, 'Mixed System Doc', 'uploads/employee_docs/fake2.pdf', 'fake2.pdf', 10, 'active', ?)")
        ->execute([$ids['mixed'], $type_id, $admin_uid]);

    // ── Fixture 4: neither ─────────────────────────────────────────────────────
    $ids['empty'] = $mk('Empty');

    // ── Assertions ─────────────────────────────────────────────────────────────
    $html = render($ids['legacy'], $admin_uid);
    ok(noErr($html), "legacy-only: no PHP/SQL errors");
    ok(strpos($html, 'CV / Resume') !== false, "legacy-only: legacy card still renders (unchanged)");
    ok(strpos($html, 'Legacy files') !== false, "legacy-only: Legacy files divider shown");
    ok(strpos($html, 'New System Doc') === false, "legacy-only: no new-system rows leak in");
    ok(substr_count($html, 'No documents uploaded for this employee') === 0, "legacy-only: no false empty-state message");

    $html = render($ids['new'], $admin_uid);
    ok(noErr($html), "new-only: no PHP/SQL errors");
    ok(strpos($html, 'New System Doc') !== false, "new-only: new-system table row renders");
    ok(strpos($html, 'Legacy files') === false, "new-only: no Legacy files divider when there's nothing legacy");
    ok(strpos($html, 'Upload Document') !== false, "new-only: Upload Document button present (canCreate)");
    ok(substr_count($html, 'No documents uploaded for this employee') === 0, "new-only: no false empty-state message");

    $html = render($ids['mixed'], $admin_uid);
    ok(noErr($html), "mixed: no PHP/SQL errors");
    ok(strpos($html, 'Mixed System Doc') !== false, "mixed: new-system row renders");
    ok(strpos($html, 'Legacy files') !== false && strpos($html, 'ID Copy') !== false, "mixed: legacy card renders under the divider");
    ok(substr_count($html, 'No documents uploaded for this employee') === 0, "mixed: no false empty-state message");

    $html = render($ids['empty'], $admin_uid);
    ok(noErr($html), "empty: no PHP/SQL errors");
    ok(substr_count($html, 'No documents uploaded for this employee') === 1, "empty: exactly one clean empty-state message (no duplicate)");
    ok(strpos($html, 'Legacy files') === false, "empty: no stray divider");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    foreach ($ids as $id) {
        if ($id) {
            $pdo->exec("DELETE FROM employee_documents WHERE employee_id = $id");
            $pdo->exec("DELETE FROM employees WHERE employee_id = $id");
        }
    }
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
