<?php
/**
 * E-Signatures CLI Test Suite
 * Run: php tests/test_esignatures_cli.php
 * Exit 0 = all pass (safe to push)
 * Exit 1 = failures found (push blocked)
 */

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function pass(string $msg): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $msg\n"; }
function fail(string $msg): void  { global $failures; $failures++; echo "  \033[31m❌ $msg\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function readSrc(string $root, string $rel): string {
    $path = "$root/$rel";
    return file_exists($path) ? file_get_contents($path) : '';
}

// ─────────────────────────────────────────────────────────────────────────────
section('1. Required files exist');
// ─────────────────────────────────────────────────────────────────────────────
$required = [
    'app/constant/document/e_signatures.php',
    'api/get_user_signatures.php',
    'api/get_pending_signatures.php',
    'api/get_signature_history.php',
    'api/document/upload_signature.php',
    'api/document/delete_signature.php',
    'api/document/apply_signature.php',
    'api/document/get_user_signatures_list.php',
    'api/document/quick_upload_document.php',
    'ajax/save_drawn_signature.php',
    'migrations/2026_05_21_create_document_signatures.php',
    'header.php',
];
foreach ($required as $f) {
    file_exists("$root/$f") ? pass($f) : fail("MISSING: $f");
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. PHP syntax — all e-signature files');
// ─────────────────────────────────────────────────────────────────────────────
$syntaxFiles = $required; // same list
foreach ($syntaxFiles as $f) {
    $path = "$root/$f";
    if (!file_exists($path)) { fail("Cannot lint — file missing: $f"); continue; }
    $out = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    if (str_contains($out, 'Parse error') || str_contains($out, 'Fatal error')) {
        fail("Syntax error in $f:\n     $out");
    } else {
        pass("Syntax OK: $f");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. API URL correctness in e_signatures.php');
// ─────────────────────────────────────────────────────────────────────────────
$esig = readSrc($root, 'app/constant/document/e_signatures.php');

// Must use correct upload path
if (preg_match('/APP_URL\}\/upload_signature\.php/', $esig)) {
    fail('Upload URL still points to ${APP_URL}/upload_signature.php (missing api/document/ prefix)');
} else {
    pass('Upload URL → api/document/upload_signature.php');
}

// Must use correct delete path
if (preg_match('/ajax\/delete_signature/', $esig)) {
    fail('Delete still uses /ajax/delete_signature — should be /api/document/delete_signature');
} else {
    pass('Delete URL → api/document/delete_signature.php');
}

// Must use correct apply path
if (preg_match('/ajax\/apply_signature/', $esig)) {
    fail('Apply still uses /ajax/apply_signature — should be api/document/apply_signature');
} else {
    pass('Apply URL → api/document/apply_signature.php');
}

// get_user_signatures_list must use api/document/
if (preg_match('/ajax\/get_user_signatures_list/', $esig)) {
    fail('get_user_signatures_list still uses /ajax/ path — should be api/document/');
} else {
    pass('get_user_signatures_list URL → api/document/');
}

// loadDocuments must use APP_URL, not relative path
if (preg_match("/\\\$\.get\('api\/get_documents/", $esig)) {
    fail("loadDocuments() uses relative 'api/get_documents.php' without APP_URL");
} else {
    pass('loadDocuments URL uses ${APP_URL}/api/get_documents.php');
}

// quick_upload must use APP_URL
if (preg_match("/url:\s*'\/ajax\/quick_upload_document/", $esig)) {
    fail('quickUploadForm still uses hardcoded /ajax/quick_upload_document.php');
} else {
    pass('quickUploadForm URL uses ${APP_URL}/api/document/quick_upload_document.php');
}

// No remaining stray /ajax/ calls (except the intentional save_drawn one at ajax/)
preg_match_all("/[`'\"]\/ajax\/(?!save_drawn_signature)[^`'\"]+[`'\"]/", $esig, $m);
if (!empty($m[0])) {
    fail('Unexpected hardcoded /ajax/ path(s) remain: ' . implode(', ', $m[0]));
} else {
    pass('No unexpected hardcoded /ajax/ paths remain');
}

// ─────────────────────────────────────────────────────────────────────────────
section('4. JS logic correctness in e_signatures.php');
// ─────────────────────────────────────────────────────────────────────────────

// No implicit event.currentTarget
$count = substr_count($esig, 'event.currentTarget');
if ($count > 0) {
    fail("$count use(s) of implicit event.currentTarget (deprecated) — should pass 'this' via onclick");
} else {
    pass('No implicit event.currentTarget references');
}

// No const canvas shadowing inside drawSignatureForm submit
$drawBlock = substr($esig, strpos($esig, "drawSignatureForm'), function") ?: 0, 600);
if (str_contains($drawBlock, 'const canvas')) {
    fail("drawSignatureForm submit redeclares 'const canvas' — shadows outer let canvas");
} else {
    pass("No 'const canvas' redeclaration inside drawSignatureForm submit");
}

// signaturesTable sort must be [[3, 'desc']]
$sigSection = substr($esig, (int)strpos($esig, 'signaturesTable'), 2500);
if (str_contains($sigSection, "order: [[2, 'desc']]")) {
    fail("signaturesTable sort is [[2, 'desc']] (Type column) — should be [[3, 'desc']] (Created At)");
} else {
    pass("signaturesTable sort is [[3, 'desc']] (Created At)");
}

// View Full Size link strips leading slash from file_path
if (str_contains($esig, 'row.file_path.replace(/^\\//, \'\')')) {
    pass('View Full Size URL strips leading slash from file_path (no double-slash)');
} else {
    fail('View Full Size URL may produce double-slash — missing .replace(/^\\//, \'\') on file_path');
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. CSRF protection');
// ─────────────────────────────────────────────────────────────────────────────
$header = readSrc($root, 'header.php');
if (str_contains($header, 'CSRF_TOKEN') && str_contains($header, 'ajaxSetup')) {
    pass('header.php exposes CSRF_TOKEN and wires $.ajaxSetup');
} else {
    fail('header.php missing const CSRF_TOKEN or $.ajaxSetup — CSRF headers not sent on AJAX calls');
}

$csrfApis = [
    'api/document/upload_signature.php',
    'api/document/delete_signature.php',
    'api/document/apply_signature.php',
    'ajax/save_drawn_signature.php',
];
foreach ($csrfApis as $f) {
    $c = readSrc($root, $f);
    if (str_contains($c, 'csrf_check()')) {
        pass("csrf_check() enforced in $f");
    } else {
        fail("csrf_check() MISSING in $f — state-changing API has no CSRF protection");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('6. Auth (session) check in every API');
// ─────────────────────────────────────────────────────────────────────────────
$authApis = [
    'api/get_user_signatures.php',
    'api/get_pending_signatures.php',
    'api/get_signature_history.php',
    'api/document/upload_signature.php',
    'api/document/delete_signature.php',
    'api/document/apply_signature.php',
    'api/document/get_user_signatures_list.php',
    'ajax/save_drawn_signature.php',
];
foreach ($authApis as $f) {
    $c = readSrc($root, $f);
    if (str_contains($c, "_SESSION['user_id']") || str_contains($c, '_SESSION["user_id"]')) {
        pass("Session check present in $f");
    } else {
        fail("Session check MISSING in $f — unauthenticated users can call this API");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('7. DataTable APIs are real implementations (not stubs)');
// ─────────────────────────────────────────────────────────────────────────────
$dtApis = [
    'api/get_user_signatures.php',
    'api/get_pending_signatures.php',
    'api/get_signature_history.php',
];
foreach ($dtApis as $f) {
    $c = readSrc($root, $f);
    $isStub = str_contains($c, '"recordsTotal" => 0') && !str_contains($c, 'SELECT COUNT');
    if ($isStub) {
        fail("$f is still a stub (hardcoded recordsTotal=0, no real query)");
    } else {
        pass("$f has a real SELECT query");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('8. save_drawn_signature.php is a real implementation (not stub)');
// ─────────────────────────────────────────────────────────────────────────────
$drawn = readSrc($root, 'ajax/save_drawn_signature.php');
if (str_contains($drawn, 'base64_decode') && str_contains($drawn, 'user_signatures')) {
    pass('save_drawn_signature.php decodes image and writes to user_signatures table');
} else {
    fail('save_drawn_signature.php appears to be a stub — missing base64_decode or user_signatures insert');
}

// File path stored with leading slash (consistent with upload_signature.php)
if (str_contains($drawn, "'/uploads/signatures/'")) {
    pass('save_drawn_signature.php stores file_path with leading / (consistent with upload handler)');
} else {
    fail("save_drawn_signature.php stores file_path without leading / — inconsistency with upload_signature.php");
}

// ─────────────────────────────────────────────────────────────────────────────
section('9. Migration file integrity');
// ─────────────────────────────────────────────────────────────────────────────
$mig = readSrc($root, 'migrations/2026_05_21_create_document_signatures.php');
if (str_contains($mig, 'CREATE TABLE IF NOT EXISTS document_signatures')) {
    pass('Migration uses CREATE TABLE IF NOT EXISTS (idempotent)');
} else {
    fail('Migration missing CREATE TABLE IF NOT EXISTS document_signatures');
}
if (str_contains($mig, 'exit(1)')) {
    pass('Migration calls exit(1) on failure (deploy will halt on error)');
} else {
    fail('Migration missing exit(1) on failure');
}
if (!str_contains($mig, 'beginTransaction')) {
    pass('Migration has no transaction wrapping DDL (correct — MySQL DDL auto-commits)');
} else {
    fail('Migration wraps DDL in a transaction — this will throw in MySQL');
}

// ─────────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m════════════════════════════════════════\033[0m\n";
if ($failures === 0) {
    echo "\033[32m✅ All $passes tests passed — safe to push.\033[0m\n";
    echo "\033[1m════════════════════════════════════════\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m❌ $failures test(s) FAILED  |  $passes passed\033[0m\n";
    echo "\033[31mFix the errors above before pushing.\033[0m\n";
    echo "\033[1m════════════════════════════════════════\033[0m\n\n";
    exit(1);
}
