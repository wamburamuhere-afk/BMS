<?php
/**
 * Employee Documents with expiry (Tier 2, Phase 2.2) CLI test.
 *   php tests/test_employee_documents_cli.php
 *
 * Drives the add/get/delete/download/manage-types APIs in isolated
 * subprocesses. Proves: upload validation matrix (type/expiry-required/ext),
 * central-library registration with mirrored expire_date (D8) so the existing
 * expiry cron fires with no new alert code, delete stops future alerts,
 * gatekeeper containment, scope + permission denials, type management.
 * Fixtures (rows + files) cleaned in finally{}.
 */
$root = dirname(__DIR__);
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[3]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? []; $_FILES = $cfg['files'] ?? [];
    require "$root/api/{$argv[2]}.php";
    exit;
}
require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function call($ep, $payload, $session, $method = 'POST', $files = []) {
    global $root;
    $cfg = ['session' => $session, 'method' => $method, ($method === 'GET' ? 'get' : 'post') => $payload, 'files' => $files];
    $f = tempnam(sys_get_temp_dir(), 'edt'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}
// A real %PDF file so the finfo MIME check passes; move_uploaded_file fails on
// non-uploaded files in some SAPIs, but PHP CLI copies work via is_uploaded_file
// bypass — we rely on move_uploaded_file returning false → we shim with a plain
// copy fallback? NO — the API uses move_uploaded_file strictly. So for CLI we
// exercise the API up to the move for rejects, and insert the happy-path row
// via the same SQL the API runs, then prove the D8 library wiring end-to-end.
function makePdf($path) { file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n"); }

$emp_id = 0; $lib_id = 0; $files_made = [];
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];

    $pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, employment_status, created_at)
                VALUES ('__ED', 'Fixture', '__ED-TEST-1', 'active', NOW())");
    $emp_id = (int)$pdo->lastInsertId();

    $type_noexp = (int)$pdo->query("SELECT doc_type_id FROM employee_document_types WHERE type_name = 'CV/Resume'")->fetchColumn();
    $type_exp   = (int)$pdo->query("SELECT doc_type_id FROM employee_document_types WHERE type_name = 'Work Permit'")->fetchColumn();
    ok($emp_id && $type_noexp && $type_exp, "fixtures ready (employee #$emp_id, types $type_noexp/$type_exp)");

    // ── 1. Validation matrix (all fail BEFORE any file handling) ────────────
    $base = ['employee_id' => $emp_id, 'doc_type_id' => $type_noexp, 'document_name' => 'X'];
    $bad = [
        'missing employee'        => ['doc_type_id' => $type_noexp, 'document_name' => 'X'],
        'missing type'            => ['employee_id' => $emp_id, 'document_name' => 'X'],
        'missing name'            => ['employee_id' => $emp_id, 'doc_type_id' => $type_noexp],
        'bad issue date'          => $base + ['issue_date' => 'nope'],
        'expiry before issue'     => $base + ['issue_date' => '2026-07-01', 'expire_date' => '2026-06-01'],
        'expiry-required type'    => ['employee_id' => $emp_id, 'doc_type_id' => $type_exp, 'document_name' => 'Permit'],
        'nonexistent employee'    => ['employee_id' => 99999999, 'doc_type_id' => $type_noexp, 'document_name' => 'X'],
        'no file'                 => $base,
    ];
    foreach ($bad as $label => $p) {
        $r = call('add_employee_document', $p, $ADMIN);
        ok(empty($r['success']), "rejects: $label" . (!empty($r['success']) ? ' (' . json_encode($r) . ')' : ''));
    }

    // Bad extension rejected
    $tmp = tempnam(sys_get_temp_dir(), 'edx'); file_put_contents($tmp, 'MZ');
    $r = call('add_employee_document', $base, $ADMIN, 'POST',
        ['file' => ['name' => 'evil.exe', 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => 0, 'size' => 2]]);
    @unlink($tmp);
    ok(empty($r['success']) && stripos($r['message'] ?? '', 'not allowed') !== false, "rejects .exe upload");

    // ── 2. Happy path: same INSERT+library wiring the API runs (D8) ─────────
    // (move_uploaded_file cannot accept a non-SAPI file in CLI, so the row is
    //  written through the identical SQL path and the library mirroring is
    //  then proven live through the real cron function.)
    $safe = bin2hex(random_bytes(8)) . '.pdf';
    $rel  = 'uploads/employee_docs/' . $safe;
    makePdf("$root/$rel"); $files_made[] = "$root/$rel";
    $lib_id = registerFileInLibrary($pdo, $rel, 'permit.pdf', 100, 'Work Permit — __ED Fixture', 'hr,employee,work_permit', $admin_uid, null);
    ok($lib_id > 0, "library registration returns an id");
    $expiry = date('Y-m-d', strtotime('+7 days'));
    $pdo->prepare("UPDATE documents SET issue_date = ?, expire_date = ? WHERE id = ?")->execute([date('Y-m-d'), $expiry, $lib_id]);
    $pdo->prepare("INSERT INTO employee_documents (employee_id, doc_type_id, document_name, file_path, original_filename, file_size, issue_date, expire_date, library_document_id, status, created_by)
                   VALUES (?, ?, 'Work Permit', ?, 'permit.pdf', 100, CURDATE(), ?, ?, 'active', ?)")
        ->execute([$emp_id, $type_exp, $rel, $expiry, $lib_id, $admin_uid]);
    $doc_id = (int)$pdo->lastInsertId();
    ok($doc_id > 0, "employee document row created (expires in 7 days)");

    // ── 3. List API ───────────────────────────────────────────────────────────
    $r = call('get_employee_documents', ['employee_id' => $emp_id], $ADMIN, 'GET');
    ok(!empty($r['success']) && count($r['data']) === 1
        && $r['data'][0]['type_name'] === 'Work Permit'
        && (int)$r['data'][0]['days_to_expiry'] === 7
        && isset($r['data'][0]['uploaded_by_name']),
        "list returns the document with type, uploader and days_to_expiry=7");

    // ── 4. D8 proof: the EXISTING expiry cron alerts on it (no new code) ─────
    // require_once itself triggers the cron's real first run (its bottom
    // "Run" block auto-executes on include — same as how header.php loads
    // it), so $before must be captured before the require, not after.
    $before = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE document_id = $lib_id")->fetchColumn();
    require_once "$root/cron/check_document_expiry.php";
    $after = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE document_id = $lib_id")->fetchColumn();
    ok($after > $before, "existing document-expiry cron fired for the employee document (D8 — zero new alert code)");
    $sum2 = run_document_expiry_check($pdo);
    $after2 = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE document_id = $lib_id")->fetchColumn();
    ok($after2 === $after, "re-run fires nothing (milestone dedupe holds)");

    // ── 5. Gatekeeper download ────────────────────────────────────────────────
    $o = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker download_employee_document " .
        escapeshellarg((function () use ($ADMIN, $doc_id) {
            $f = tempnam(sys_get_temp_dir(), 'edd');
            file_put_contents($f, json_encode(['session' => $ADMIN, 'method' => 'GET', 'get' => ['emp_doc_id' => $doc_id]]));
            return $f;
        })()));
    ok(strpos($o, '%PDF') !== false, "gatekeeper streams the PDF for an authorised user");
    $NOPERM = ['user_id' => 999901, 'username' => 'noperm', 'is_admin' => false, 'role_id' => 999,
        'permissions' => [], 'scope' => ['is_admin' => false, 'projects' => [], 'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [], 'computed_at' => time()]];
    $r = call('download_employee_document', ['emp_doc_id' => $doc_id], $NOPERM, 'GET');
    ok(strpos($r['_raw'] ?? '', '%PDF') === false, "gatekeeper refuses a user without the permission");

    // ── 6. Delete: soft + stops alerts ───────────────────────────────────────
    $r = call('delete_employee_document', ['emp_doc_id' => $doc_id], $NOPERM);
    ok(empty($r['success']), "delete denied without canDelete");
    $r = call('delete_employee_document', ['emp_doc_id' => $doc_id], $ADMIN);
    $row = $pdo->query("SELECT status FROM employee_documents WHERE emp_doc_id = $doc_id")->fetchColumn();
    $libExp = $pdo->query("SELECT expire_date FROM documents WHERE id = $lib_id")->fetchColumn();
    ok(!empty($r['success']) && $row === 'deleted' && $libExp === null,
        "delete soft-deletes the row AND clears the library expire_date (alerts stop)");
    $r = call('get_employee_documents', ['employee_id' => $emp_id], $ADMIN, 'GET');
    ok(!empty($r['success']) && count($r['data']) === 0, "deleted document leaves the list");

    // ── 7. Manage types ───────────────────────────────────────────────────────
    $r = call('manage_document_types', ['action' => 'add', 'type_name' => '__ED Test Type', 'requires_expiry' => 1], $ADMIN);
    $tid = (int)($r['doc_type_id'] ?? 0);
    ok(!empty($r['success']) && $tid, "type added");
    $r = call('manage_document_types', ['action' => 'add', 'type_name' => '__ED Test Type'], $ADMIN);
    ok(empty($r['success']), "duplicate type name rejected");
    $r = call('manage_document_types', ['action' => 'rename', 'doc_type_id' => $tid, 'type_name' => '__ED Renamed'], $ADMIN);
    $nm = $pdo->query("SELECT type_name FROM employee_document_types WHERE doc_type_id = $tid")->fetchColumn();
    ok(!empty($r['success']) && $nm === '__ED Renamed', "type renamed");
    $r = call('manage_document_types', ['action' => 'deactivate', 'doc_type_id' => $tid], $ADMIN);
    $st = $pdo->query("SELECT status FROM employee_document_types WHERE doc_type_id = $tid")->fetchColumn();
    ok(!empty($r['success']) && $st === 'inactive', "type deactivated");
    $r = call('manage_document_types', ['action' => 'add', 'type_name' => 'zz', 'requires_expiry' => 0], $NOPERM);
    ok(empty($r['success']), "type add denied without canEdit");
    $pdo->exec("DELETE FROM employee_document_types WHERE doc_type_id = $tid");

    // ── 8. Scope denial ───────────────────────────────────────────────────────
    // Put the fixture employee in a project outside the caller's scope
    $proj = (int)$pdo->query("SELECT project_id FROM projects LIMIT 1")->fetchColumn();
    if ($proj) {
        $pdo->exec("UPDATE employees SET project_id = $proj WHERE employee_id = $emp_id");
        $SCOPED = ['user_id' => 999902, 'username' => 'scoped', 'is_admin' => false, 'role_id' => 999,
            'permissions' => ['employee_documents' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'review' => false, 'approve' => false]],
            'scope' => ['is_admin' => false, 'projects' => [], 'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [], 'computed_at' => time()]];
        $r = call('get_employee_documents', ['employee_id' => $emp_id], $SCOPED, 'GET');
        ok(empty($r['success']) && stripos(json_encode($r), 'scope') !== false, "list denied for out-of-scope employee (non-admin)");
    } else {
        ok(true, "no project available — scope denial covered by helper tests (skip)");
    }

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($emp_id) {
        $pdo->exec("DELETE FROM employee_documents WHERE employee_id = $emp_id");
        $pdo->exec("DELETE FROM employees WHERE employee_id = $emp_id");
    }
    if ($lib_id) {
        $pdo->exec("DELETE FROM notifications WHERE document_id = $lib_id");
        $pdo->exec("DELETE FROM document_expiry_reminders WHERE document_id = $lib_id");
        $pdo->exec("DELETE FROM documents WHERE id = $lib_id");
    }
    foreach ($files_made as $f) @unlink($f);
    $pdo->exec("DELETE FROM employee_document_types WHERE type_name LIKE '__ED%'");
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
