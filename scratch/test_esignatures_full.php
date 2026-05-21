<?php
/**
 * E-Signatures Full Test Suite
 * Visit: http://localhost/bms/scratch/test_esignatures_full.php
 * Tests: DB tables, saved records, file existence, all API endpoints
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

// Auto-login as first admin if session is not active (standard scratch file pattern)
if (empty($_SESSION['user_id'])) {
    $admin = $pdo->query("SELECT user_id FROM users WHERE role_id = 1 AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($admin) {
        $_SESSION['user_id'] = $admin['user_id'];
        $_SESSION['role_id'] = 1;
    } else {
        die('<p style="color:red;font-family:monospace">No active admin user found in the database.</p>');
    }
}
$userId = $_SESSION['user_id'];

// ── helpers ───────────────────────────────────────────────────────────────────
function ok($msg)   { echo "<tr><td>✅</td><td>$msg</td></tr>\n"; }
function fail($msg) { echo "<tr><td>❌</td><td><strong>$msg</strong></td></tr>\n"; }
function warn($msg) { echo "<tr><td>⚠️</td><td>$msg</td></tr>\n"; }
function section($title) {
    echo "</table><h3 style='margin-top:28px;border-bottom:2px solid #0d6efd;padding-bottom:6px'>$title</h3>
          <table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;width:100%;font-family:monospace;font-size:13px'>\n";
}
function tableExists(PDO $pdo, string $table): bool {
    $r = $pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn();
    return (bool)$r;
}
function callApi(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_COOKIE         => 'PHPSESSID=' . session_id(),
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($body, true);
    return ['code' => $code, 'body' => $body, 'json' => $json];
}

$base = rtrim(buildUrl(''), '/');

echo "<!DOCTYPE html><html><head><meta charset='utf-8'>
<title>E-Signatures Test Suite</title>
<style>
  body{font-family:Arial,sans-serif;padding:20px;max-width:1100px}
  table{width:100%;border-collapse:collapse;margin-bottom:10px}
  td,th{border:1px solid #dee2e6;padding:7px 10px;vertical-align:top}
  th{background:#f8f9fa}
  h2{color:#0d6efd}
  h3{color:#198754}
  pre{background:#f8f9fa;padding:8px;border-radius:4px;overflow-x:auto;font-size:12px}
  .badge-ok{background:#d1e7dd;color:#0f5132;padding:2px 8px;border-radius:4px}
  .badge-fail{background:#f8d7da;color:#842029;padding:2px 8px;border-radius:4px}
</style></head><body>
<h2>🔍 E-Signatures Full Test Suite</h2>
<p>User ID: <strong>$userId</strong> &nbsp;|&nbsp; Session: <code>" . session_id() . "</code></p>
<table>";

// ── 1. Database Tables ────────────────────────────────────────────────────────
section('1. Database Tables');

$tables = ['user_signatures', 'document_signatures', 'documents'];
foreach ($tables as $t) {
    if (tableExists($pdo, $t)) ok("Table <code>$t</code> exists");
    else fail("Table <code>$t</code> MISSING — run migration first");
}

// ── 2. user_signatures — records for this user ────────────────────────────────
section('2. Saved Signatures in DB (user_id = ' . $userId . ')');

if (tableExists($pdo, 'user_signatures')) {
    $stmt = $pdo->prepare("SELECT id, signature_type, file_path, thumbnail_path, status, created_at FROM user_signatures WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $sigs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($sigs)) {
        warn("No signatures found in DB for this user — upload or draw one first");
    } else {
        ok(count($sigs) . " signature(s) found in DB");
        echo "<tr><td colspan='2'>";
        echo "<table style='width:100%'><tr><th>ID</th><th>Type</th><th>Status</th><th>file_path</th><th>File on disk?</th><th>Created</th></tr>";
        foreach ($sigs as $s) {
            $diskPath  = ROOT_DIR . '/' . ltrim($s['file_path'], '/');
            $fileOk    = file_exists($diskPath);
            $fileCell  = $fileOk ? '<span class="badge-ok">YES</span>' : '<span class="badge-fail">MISSING</span>';
            echo "<tr>
                <td>{$s['id']}</td>
                <td>{$s['signature_type']}</td>
                <td>{$s['status']}</td>
                <td><small>{$s['file_path']}</small></td>
                <td>$fileCell</td>
                <td>{$s['created_at']}</td>
            </tr>";
        }
        echo "</table></td></tr>";
    }

    // Check upload directory
    $uploadDir = ROOT_DIR . '/uploads/signatures/' . $userId;
    if (is_dir($uploadDir)) {
        $files = glob($uploadDir . '/*');
        ok("Upload directory exists: <code>uploads/signatures/$userId/</code> — " . count($files) . " file(s)");
    } else {
        warn("Upload directory not yet created: <code>uploads/signatures/$userId/</code>");
    }
}

// ── 3. document_signatures ────────────────────────────────────────────────────
section('3. Document Signatures (applied records)');

if (tableExists($pdo, 'document_signatures')) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM document_signatures WHERE signed_by = ? OR requested_by = ?");
    $stmt->execute([$userId, $userId]);
    $count = $stmt->fetchColumn();

    if ($count == 0) {
        warn("No document_signatures records for this user yet (expected — sign a document first)");
    } else {
        ok("$count document_signatures record(s) found for this user");
        $stmt = $pdo->prepare("SELECT ds.*, d.document_name FROM document_signatures ds LEFT JOIN documents d ON d.id = ds.document_id WHERE ds.signed_by = ? OR ds.requested_by = ? ORDER BY ds.created_at DESC LIMIT 10");
        $stmt->execute([$userId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<tr><td colspan='2'><pre>" . htmlspecialchars(json_encode($rows, JSON_PRETTY_PRINT)) . "</pre></td></tr>";
    }
}

// ── 4. API Endpoints — HTTP status + response ─────────────────────────────────
// Release the PHP session file lock before making cURL requests.
// Without this, every API that calls session_start() via roots.php will block
// waiting for the lock held by this script, causing HTTP 0 (timeout).
session_write_close();

section('4. API Endpoints');

$apis = [
    'GET My Signatures (DataTable)'   => "$base/api/get_user_signatures.php?draw=1&start=0&length=5",
    'GET Pending Signatures'          => "$base/api/get_pending_signatures.php?draw=1&start=0&length=5",
    'GET Signature History'           => "$base/api/get_signature_history.php?draw=1&start=0&length=5",
    'GET Signatures List (select)'    => "$base/api/document/get_user_signatures_list.php",
    'GET Documents (for wizard)'      => "$base/api/get_documents.php?draw=1&start=0&length=5",
];

foreach ($apis as $label => $url) {
    $r = callApi($url);
    if ($r['code'] !== 200) {
        fail("$label — HTTP {$r['code']} <small>($url)</small>");
        continue;
    }
    if ($r['json'] === null) {
        fail("$label — HTTP 200 but response is NOT valid JSON<br><pre>" . htmlspecialchars(substr($r['body'], 0, 300)) . "</pre>");
        continue;
    }
    $j = $r['json'];
    if (isset($j['error'])) {
        fail("$label — HTTP 200 but error: <code>" . htmlspecialchars($j['error']) . "</code>");
        continue;
    }

    // DataTable APIs
    if (isset($j['recordsTotal'])) {
        $total = $j['recordsTotal'];
        $rows  = count($j['data'] ?? []);
        if ($total > 0) {
            ok("$label — ✅ recordsTotal=$total, rows returned=$rows <small class='badge-ok'>DATA FOUND</small>");
        } else {
            warn("$label — HTTP 200, valid JSON, but recordsTotal=0 (no records yet)");
        }
    } else {
        // Simple array (signatures list)
        $count = is_array($j) ? count($j) : '?';
        if ($count > 0) ok("$label — ✅ $count item(s) returned");
        else warn("$label — HTTP 200, valid JSON array, but empty (no active signatures yet)");
    }
}

// ── 5. Write API Endpoints (simulated POST check — no actual write) ────────────
section('5. Write API Endpoints (existence check)');

$writeApis = [
    'Upload Signature'         => 'api/document/upload_signature.php',
    'Save Drawn Signature'     => 'ajax/save_drawn_signature.php',
    'Apply Signature'          => 'api/document/apply_signature.php',
    'Delete Signature'         => 'api/document/delete_signature.php',
    'Quick Upload Document'    => 'api/document/quick_upload_document.php',
    'Get Signatures List'      => 'api/document/get_user_signatures_list.php',
];

foreach ($writeApis as $label => $path) {
    $fullPath = ROOT_DIR . '/' . $path;
    if (file_exists($fullPath)) {
        ok("$label — file exists at <code>$path</code>");
    } else {
        fail("$label — FILE MISSING: <code>$path</code>");
    }
}

// ── 6. CSRF Token check ───────────────────────────────────────────────────────
section('6. CSRF Protection');

if (!empty($_SESSION['csrf_token'])) {
    ok("CSRF token exists in session: <code>" . substr($_SESSION['csrf_token'], 0, 16) . "...</code>");
} else {
    // Generate it now
    csrf_token();
    ok("CSRF token generated on demand: <code>" . substr($_SESSION['csrf_token'], 0, 16) . "...</code>");
}

// Check header.php has CSRF_TOKEN const
$headerContent = file_get_contents(ROOT_DIR . '/header.php');
if (strpos($headerContent, 'CSRF_TOKEN') !== false && strpos($headerContent, 'ajaxSetup') !== false) {
    ok("header.php has <code>const CSRF_TOKEN</code> and <code>$.ajaxSetup</code> — all AJAX calls will send the token automatically");
} else {
    fail("header.php missing CSRF_TOKEN global or $.ajaxSetup — CSRF protection not active for AJAX calls");
}

// ── 7. Summary ────────────────────────────────────────────────────────────────
section('7. Quick Summary & Next Steps');

echo "<tr><td colspan='2'>
<ol>
  <li>If <strong>user_signatures table is missing</strong> → run <a href='../migrations/runner.php' target='_blank'>migrations/runner.php</a></li>
  <li>If <strong>document_signatures table is missing</strong> → same, run migrations/runner.php (2026_05_21 migration)</li>
  <li>If <strong>recordsTotal = 0 in API #4</strong> but DB check (section 2) shows records → API was previously broken stub, now fixed — reload the e_signatures page</li>
  <li>If <strong>file on disk = MISSING</strong> but DB has a record → the upload saved to DB but file write failed (check uploads/signatures/ folder permissions)</li>
  <li>If <strong>all green</strong> → go to <a href='../e_signatures' target='_blank'>E-Signatures page</a> and verify tables load</li>
</ol>
</td></tr>";

echo "</table><p style='color:#6c757d;font-size:12px'>Test completed — " . date('Y-m-d H:i:s') . "</p></body></html>";
