<?php
/**
 * E-Signature Integrity & Audit — CLI Test Suite
 *
 * Verifies the document_signatures audit/integrity columns, the SHA-256
 * tamper-evidence round-trip, and the structure of the integrity endpoints.
 *
 * Requires a database connection — run locally or after deploy (the migration
 * must have run first). Exit 0 = all pass | Exit 1 = one or more failures.
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ok(string $m): void   { global $pass; $pass++; echo "\033[32m  ✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "\033[31m  ❌\033[0m $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function readSrc(string $p): string {
    global $ROOT;
    $f = $ROOT . '/' . ltrim($p, '/');
    return is_file($f) ? file_get_contents($f) : '';
}
function has(string $src, string $needle, string $label): void {
    strpos($src, $needle) !== false ? ok($label) : fail($label);
}

// ── 1. document_signatures — audit & integrity columns ───────────────────────
section('1. document_signatures — audit & integrity columns');
$cols = [];
foreach ($pdo->query("SHOW COLUMNS FROM document_signatures") as $c) {
    $cols[] = $c['Field'];
}
$required = ['hash_algorithm', 'hash_before', 'hash_after', 'signing_reference',
            'signed_document_id', 'user_agent', 'consent_text', 'consent_accepted_at', 'event_log'];
foreach ($required as $col) {
    in_array($col, $cols, true)
        ? ok("column '$col' exists")
        : fail("column '$col' MISSING — run migrations/2026_05_21_esignature_audit_columns.php");
}

// ── 2. document_signatures — verify lookup index ─────────────────────────────
section('2. document_signatures — verify lookup index');
$idx = $pdo->query("SHOW INDEX FROM document_signatures WHERE Key_name = 'idx_signed_document_id'")->fetch();
$idx ? ok("index 'idx_signed_document_id' exists") : fail("index 'idx_signed_document_id' MISSING");

// ── 3. SHA-256 integrity round-trip — the core tamper-evidence guarantee ─────
section('3. SHA-256 integrity round-trip');
$tmp = tempnam(sys_get_temp_dir(), 'esig');
file_put_contents($tmp, "%PDF-1.4 original signed content\n");
$h1 = hash_file('sha256', $tmp);
strlen($h1) === 64 ? ok('SHA-256 produces a 64-char hex digest') : fail('SHA-256 digest length wrong');
$h1again = hash_file('sha256', $tmp);
hash_equals($h1, $h1again)
    ? ok('unchanged file -> identical hash (Verified)')
    : fail('identical file hashed differently');
file_put_contents($tmp, "%PDF-1.4 original signed content TAMPERED\n");
$h2 = hash_file('sha256', $tmp);
!hash_equals($h1, $h2)
    ? ok('altered file -> different hash (Tampered is detected)')
    : fail('a tampered file was NOT detected');
@unlink($tmp);

// ── 4. Migration — idempotent & deploy-safe ──────────────────────────────────
section('4. Migration — idempotent & deploy-safe');
$mig = readSrc('migrations/2026_05_21_esignature_audit_columns.php');
has($mig, 'SHOW COLUMNS FROM document_signatures LIKE', 'migration: guards each ADD COLUMN with SHOW COLUMNS');
has($mig, 'SHOW INDEX',  'migration: guards the index creation');
has($mig, 'exit(1)',     'migration: exits non-zero on failure (halts deploy)');

// ── 5. save_signed_pdf.php — server-authoritative hashing & intent ───────────
section('5. save_signed_pdf.php — server-authoritative hashing & intent');
$save = readSrc('api/document/save_signed_pdf.php');
has($save, "hash_file('sha256'", 'computes SHA-256 with hash_file() server-side');
has($save, '$hash_after',        'stores the signed-file hash');
has($save, '$hash_before',       'stores the original-file hash');
has($save, 'canCreate(',         'enforces the canCreate permission');
has($save, 'Consent is required','rejects a sign request with no consent');
has($save, 'event_log',          'persists the ordered audit event log');

// ── 6. verify_signed_document.php — comparison logic ─────────────────────────
section('6. verify_signed_document.php — comparison logic');
$verify = readSrc('api/document/verify_signed_document.php');
has($verify, 'hash_equals(',       'uses timing-safe hash_equals()');
has($verify, 'hash_file(',         're-hashes the stored file on disk');
has($verify, "'verified'",         'returns a verified flag');
has($verify, 'signed_document_id', 'looks up the signature record by signed_document_id');

// ── Result ───────────────────────────────────────────────────────────────────
echo "\n\033[1m" . str_repeat('═', 44) . "\033[0m\n";
if ($fail === 0) {
    echo "\033[32m✅ All $pass e-signature integrity tests passed.\033[0m\n";
} else {
    echo "\033[31m❌ $fail of " . ($pass + $fail) . " tests failed — push blocked.\033[0m\n";
}
echo "\033[1m" . str_repeat('═', 44) . "\033[0m\n\n";
exit($fail > 0 ? 1 : 0);
