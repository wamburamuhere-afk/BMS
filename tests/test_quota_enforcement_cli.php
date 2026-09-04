<?php
/**
 * tests/test_quota_enforcement_cli.php — Phase 12.B acceptance gate.
 *
 *   php tests/test_quota_enforcement_cli.php
 *
 * Proves ENFORCEMENT, not just that the data layer computes the right numbers
 * (12.A already proved that):
 *   1. every move_uploaded_file() call in the app is guarded by
 *      assertUploadWithinQuota() within 3 lines above it — a permanent,
 *      automated version of the manual audit that found the exact call sites
 *      during 12.B, so a future upload handler that skips the check is caught
 *      the same way Phase 11's registry test catches a missing page_key
 *   2. add_user.php genuinely refuses at the seat limit, through the real
 *      page, and succeeds again once a seat is freed
 *   3. THREE real upload handlers, in three different feature areas
 *      (document templates, compliance records, employee documents), refuse a
 *      real request once the tenant is over its storage limit — proving the
 *      shared function actually fires inside real handlers, not just that the
 *      function itself is correct in isolation
 *   4. a tenant with no limit set is refused nowhere (today's default for
 *      every real tenant — zero behaviour change)
 *
 * Each handler runs in its own subprocess with real $_SERVER/$_SESSION/
 * $_POST/$_FILES set, the same technique tests/test_feature_gating_cli.php
 * already uses — so the real require chain executes, not a re-implementation
 * of it. Provisions one real throwaway tenant and removes it. CLI ONLY.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ─── Worker: run one real file with a forged request/session ──────────────
// argv: --worker <host> <relative-file> <post-json-b64> <files-json-b64>
if (($argv[1] ?? '') === '--worker') {
    $host   = (string)$argv[2];
    $file   = (string)$argv[3];
    $post   = json_decode((string)base64_decode((string)$argv[4]), true) ?: [];
    $filesA = json_decode((string)base64_decode((string)$argv[5]), true) ?: [];

    $_SERVER['HTTP_HOST']      = $host;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI']    = '/' . $file;
    $_POST                     = $post;

    // Fake $_FILES entries. move_uploaded_file() will fail on these (they are
    // not real HTTP uploads) — irrelevant here, because a refusal must happen
    // in assertUploadWithinQuota(), BEFORE move_uploaded_file() is ever
    // reached. A pass-through case only needs to prove the guard did not
    // block it, not that the rest of the handler's business logic succeeds.
    foreach ($filesA as $key => $meta) {
        $tmp = tempnam(sys_get_temp_dir(), 'quotaworker');
        // Real PDF magic bytes — several handlers verify content, not just the
        // extension (`.claude/security.md` §19 step 2), so a fake needs to pass
        // finfo's real MIME sniff to reach the quota check being tested here.
        file_put_contents($tmp, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n" . str_repeat('x', 32));
        $_FILES[$key] = [
            'name' => $meta['name'], 'type' => $meta['type'] ?? 'application/octet-stream',
            'tmp_name' => $tmp, 'error' => 0, 'size' => $meta['size'],
        ];
    }

    require_once __DIR__ . '/../roots.php';
    // Forge an authenticated admin session for this tenant's own DB — the
    // handlers all call isAuthenticated()/canCreate() before reaching the
    // upload code, and admins bypass role checks the same way real requests do.
    global $pdo;
    $u = $pdo->query("SELECT user_id, role_id FROM users WHERE is_active = 1 ORDER BY user_id LIMIT 1")->fetch();
    $_SESSION['user_id'] = (int)$u['user_id'];
    $_SESSION['role_id'] = (int)$u['role_id'];
    $_SESSION['is_admin'] = true;
    loadUserPermissions((int)$u['role_id']);

    ob_start();
    require __DIR__ . '/../' . $file;
    $out = ob_get_clean();
    fwrite(STDOUT, '[[WORKER_OUTPUT]]' . $out);
    exit(0);
}

// ─── Runner ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/tenant_crypto.php';
require_once __DIR__ . '/../core/tenant_provisioner.php';
require_once __DIR__ . '/../core/tenant_admin.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

echo "\nBMS — Phase 12.B: quota enforcement\n";

// ─────────────────────────────────────────────────────────────────────────────
section('1. Every move_uploaded_file() is guarded — permanent audit');

$excluded = [
    'api/backup_actions.php',                          // restoring a DB must never be quota-blocked
    'app/constant/settings/system_settings.php',       // overwritten branding singleton
    'app/constant/settings/company_profile.php',       // overwritten branding singleton
    'app/constant/profile/profile.php',                // overwritten avatar singleton
];

$root = dirname(__DIR__);
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/api', FilesystemIterator::SKIP_DOTS));
$phpFiles = [];
foreach ($rii as $f) if ($f->getExtension() === 'php') $phpFiles[] = $f->getPathname();
$rii2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($rii2 as $f) if ($f->getExtension() === 'php') $phpFiles[] = $f->getPathname();

$checkedCount = 0;
$guardedCount = 0;
$unguarded = [];
foreach ($phpFiles as $abs) {
    $rel = str_replace('\\', '/', substr($abs, strlen($root) + 1));
    $src = file_get_contents($abs);
    if (!str_contains($src, 'move_uploaded_file(')) continue;
    $checkedCount++;
    $lines = explode("\n", $src);
    foreach ($lines as $i => $line) {
        if (!str_contains($line, 'move_uploaded_file(')) continue;
        $window = implode("\n", array_slice($lines, max(0, $i - 3), 4));
        if (str_contains($window, 'assertUploadWithinQuota(')) {
            $guardedCount++;
        } elseif (!in_array($rel, $excluded, true)) {
            $unguarded[] = "$rel:" . ($i + 1);
        }
    }
}
ok('at least 50 files with move_uploaded_file() were scanned (sanity floor)', $checkedCount >= 50, "found $checkedCount");
ok('at least 55 guarded call sites found (sanity floor)', $guardedCount >= 55, "found $guardedCount");
ok('every non-excluded move_uploaded_file() call is guarded', $unguarded === [], implode(', ', $unguarded));

// ─────────────────────────────────────────────────────────────────────────────
section('2. Provision a real throwaway tenant');

$sub = 'quotaenf' . bin2hex(random_bytes(3));
$r = provisionTenant('Quota Enforcement Co', $sub, "owner@$sub.test", 'Password!123');
ok('tenant provisioned', $r['ok'] === true, (string)($r['error'] ?? ''));
if (!$r['ok']) { echo "\nCannot continue.\n"; exit(1); }
$tenantId = (int)$r['tenant_id'];

register_shutdown_function(function () use ($tenantId) {
    try {
        $t = getTenant($tenantId);
        if ($t) deleteTenant($tenantId, $t['company_name']);
    } catch (Throwable $e) { error_log('quota enforcement test cleanup: ' . $e->getMessage()); }
});

$c = getControlPdo();
$tStmt = $c->prepare("SELECT * FROM tenants WHERE id = ?");
$tStmt->execute([$tenantId]);
$tRow = $tStmt->fetch();
$pw   = decryptTenantSecret((string)$tRow['db_password_encrypted']);
$pdo  = new PDO("mysql:host={$tRow['db_host']};dbname={$tRow['db_name']};charset=utf8mb4",
    $tRow['db_username'], $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
$host = $sub . '.' . $BASE;

/** One simulated request, in its own process. */
function req(string $host, string $file, array $post = [], array $files = []): string {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --worker '
         . escapeshellarg($host) . ' ' . escapeshellarg($file) . ' '
         . escapeshellarg(base64_encode(json_encode($post))) . ' '
         . escapeshellarg(base64_encode(json_encode($files)));
    $out = [];
    exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}
function refused(string $out): bool {
    return str_contains($out, '"success":false') && str_contains($out, 'Storage limit exceeded');
}
function crashed(string $out): bool {
    return str_contains($out, 'Fatal error') || str_contains($out, 'Parse error');
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. add_user.php — real seat-limit refusal through the real page');

$c->prepare("UPDATE tenants SET max_users = 1 WHERE id = ?")->execute([$tenantId]);
// One user already exists (the owner) — the tenant is already AT its limit.

$r1 = req($host, 'app/constant/settings/add_user.php', [
    'username' => 'newstaff', 'email' => 'newstaff@' . $sub . '.test',
    'first_name' => 'New', 'last_name' => 'Staff', 'role_id' => '1',
    'password' => 'Password!123', 'confirm_password' => 'Password!123',
]);
ok('POSITIVE CONTROL: the worker really ran (no crash)', !crashed($r1), substr($r1, -300));
$countAfterRefusal = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
ok('at the seat limit, add_user.php does NOT create the row', $countAfterRefusal === 1, "count=$countAfterRefusal");

$c->prepare("UPDATE tenants SET max_users = 2 WHERE id = ?")->execute([$tenantId]);
$r2 = req($host, 'app/constant/settings/add_user.php', [
    'username' => 'newstaff2', 'email' => 'newstaff2@' . $sub . '.test',
    'first_name' => 'New', 'last_name' => 'Staff2', 'role_id' => '1',
    'password' => 'Password!123', 'confirm_password' => 'Password!123',
]);
ok('  ...and DOES create it once the limit is raised', !crashed($r2), substr($r2, -300));
$countAfterRoom = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
ok('  ...active user count is now 2', $countAfterRoom === 2, "count=$countAfterRoom");

$c->prepare("UPDATE tenants SET max_users = NULL WHERE id = ?")->execute([$tenantId]);

// ─────────────────────────────────────────────────────────────────────────────
section('4. ajax/toggle_user.php — reactivation is a second seat-limit bypass, gap found and closed');

// Found during the post-implementation gap hunt: add_user.php is the only
// place a NEW account is created, but reactivating an EXISTING deactivated
// one raises the active count exactly the same way, through a completely
// different file that had no idea the quota existed.
$newstaff2Id = (int)$pdo->query("SELECT user_id FROM users WHERE username = 'newstaff2'")->fetchColumn();
$pdo->exec("UPDATE users SET is_active = 0 WHERE user_id = $newstaff2Id");   // back down to 1 active (the owner)
$c->prepare("UPDATE tenants SET max_users = 1 WHERE id = ?")->execute([$tenantId]);

$rt1 = req($host, 'ajax/toggle_user.php', ['user_id' => (string)$newstaff2Id, 'action' => 'activate']);
ok('POSITIVE CONTROL: the worker really ran (no crash)', !crashed($rt1), substr($rt1, -300));
ok('at the seat limit, reactivating is refused', str_contains($rt1, '"success":false')
   && str_contains($rt1, "user limit"), substr($rt1, -300));
$activeAfterRefusal = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
ok('  ...and the row stays inactive', $activeAfterRefusal === 1, "active=$activeAfterRefusal");

$c->prepare("UPDATE tenants SET max_users = 2 WHERE id = ?")->execute([$tenantId]);
$rt2 = req($host, 'ajax/toggle_user.php', ['user_id' => (string)$newstaff2Id, 'action' => 'activate']);
ok('  ...and DOES reactivate once the limit is raised', str_contains($rt2, '"success":true'), substr($rt2, -300));
$activeAfterRoom = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
ok('  ...active count is 2 again', $activeAfterRoom === 2, "active=$activeAfterRoom");

// Deactivating must NEVER be blocked by this check — it only ever frees a
// seat, so gating it would make the limit impossible to recover from.
$rt3 = req($host, 'ajax/toggle_user.php', ['user_id' => (string)$newstaff2Id, 'action' => 'deactivate']);
ok('deactivating is never blocked, even at the limit', str_contains($rt3, '"success":true'), substr($rt3, -300));

$c->prepare("UPDATE tenants SET max_users = NULL WHERE id = ?")->execute([$tenantId]);

// ─────────────────────────────────────────────────────────────────────────────
section('5. Three real upload handlers, three different feature areas');

// Force the tenant deep over its storage limit so ANY new upload is refused —
// isolates the assertion to "does the guard fire", not "is the arithmetic
// right" (12.A already proved the arithmetic against real seeded rows).
$pdo->exec("INSERT INTO documents (document_name, file_path, original_filename, file_size, file_type, uploaded_by)
            VALUES ('filler', 'uploads/filler.bin', 'filler.bin', 5000000, 'bin', 1)");
$c->prepare("UPDATE tenants SET max_storage_mb = 1 WHERE id = ?")->execute([$tenantId]);   // 1 MiB, already 5MB used

$before = (int)$pdo->query("SELECT COUNT(*) FROM document_templates")->fetchColumn();
$rt = req($host, 'api/save_document_template.php',
    ['template_name' => 'Quota Test Template'],
    ['template_file' => ['name' => 'x.pdf', 'size' => 1000]]);
ok('save_document_template.php (Document Templates) refuses over quota',
   refused($rt), substr($rt, -200));
$after = (int)$pdo->query("SELECT COUNT(*) FROM document_templates")->fetchColumn();
ok('  ...and writes no row', $after === $before, "before=$before after=$after");

$before = (int)$pdo->query("SELECT COUNT(*) FROM compliance_records")->fetchColumn();
$rc2 = req($host, 'api/save_compliance.php',
    ['title' => 'Quota Test Compliance'],
    ['doc_file' => ['name' => 'x.pdf', 'size' => 1000]]);
ok('save_compliance.php (Compliance) refuses over quota', refused($rc2), substr($rc2, -200));
$after = (int)$pdo->query("SELECT COUNT(*) FROM compliance_records")->fetchColumn();
ok('  ...and writes no row', $after === $before, "before=$before after=$after");

// Employee documents needs a real employee + doc type row to reach the
// upload code at all — seeded here, not assumed.
$pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, status) VALUES ('Test','Employee','QE-001','active')");
$empId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO employee_document_types (type_name, requires_expiry) VALUES ('Quota Test Doc', 0)");
$docTypeId = (int)$pdo->lastInsertId();

$before = (int)$pdo->query("SELECT COUNT(*) FROM employee_documents")->fetchColumn();
$re = req($host, 'api/add_employee_document.php',
    ['employee_id' => (string)$empId, 'doc_type_id' => (string)$docTypeId, 'document_name' => 'Quota Test Doc'],
    ['file' => ['name' => 'x.pdf', 'size' => 1000]]);
ok('add_employee_document.php (HR) refuses over quota', refused($re), substr($re, -200));
$after = (int)$pdo->query("SELECT COUNT(*) FROM employee_documents")->fetchColumn();
ok('  ...and writes no row', $after === $before, "before=$before after=$after");

// ─────────────────────────────────────────────────────────────────────────────
section('6. Unlimited (today\'s default for every real tenant) refuses nothing');

$c->prepare("UPDATE tenants SET max_storage_mb = NULL WHERE id = ?")->execute([$tenantId]);
$before = (int)$pdo->query("SELECT COUNT(*) FROM document_templates")->fetchColumn();
$ru = req($host, 'api/save_document_template.php',
    ['template_name' => 'Under Unlimited'],
    ['template_file' => ['name' => 'x.pdf', 'size' => 1000]]);
// Not just "not the refusal string" — a crash for an unrelated reason would
// satisfy that too. Requires genuine success AND a real row written, the same
// anti-vacuity discipline as Phase 11.B's positive control.
ok('with no limit set, the handler genuinely succeeds', str_contains($ru, '"success":true'), substr($ru, -200));
$after = (int)$pdo->query("SELECT COUNT(*) FROM document_templates")->fetchColumn();
ok('  ...and a real row is written', $after === $before + 1, "before=$before after=$after");

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n\n";
exit($fail === 0 ? 0 : 1);
