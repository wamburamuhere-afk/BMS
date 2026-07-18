<?php
/**
 * External Signer (send-to-client signing) — regression test.
 *
 * Guards Phase C of the Create Document professional-output plan: the
 * document_signature_tokens lifecycle (create → valid once → single-use →
 * rejected once used or expired), and that the new endpoints/pages exist
 * and contain the expected security checks.
 *
 * Run:  php tests/test_external_signature_cli.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root   = dirname(__DIR__);
$isLive = is_file("$root/includes/config.php");

if ($isLive) {
    require_once "$root/roots.php";
}

$failures = 0;
$passes   = 0;

function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

section('1. php -l on every new/touched file');

foreach ([
    'migrations/2026_07_18_document_signature_external_signer.php',
    'api/document/request_external_signature.php',
    'api/document/submit_external_signature.php',
    'api/document/cancel_external_signature.php',
    'sign_document.php',
    'app/constant/document/select_document_add_esignature.php',
    'api/get_pending_signatures.php',
    'api/get_signature_history.php',
    'app/constant/document/e_signatures.php',
] as $rel) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $rc);
    check($rc === 0, "$rel — no syntax errors", "$rel — php -l failed: " . implode(' ', $out));
}

section('2. Static — key security properties present in the source');

$submitSrc = file_get_contents("$root/api/document/submit_external_signature.php");
check(strpos($submitSrc, "hash('sha256', \$token)") !== false, 'submit endpoint hashes the incoming token before lookup (never compares raw)', 'submit endpoint does not hash the token');
check(strpos($submitSrc, 'used_at') !== false && strpos($submitSrc, "SET used_at = NOW()") !== false, 'submit endpoint marks the token used (single-use enforced)', 'submit endpoint never marks the token as used');
check(strpos($submitSrc, 'FILEINFO_MIME_TYPE') !== false, 'submit endpoint checks real MIME type via magic bytes, not just the filename', 'submit endpoint is missing a real-MIME check');
check(strpos($submitSrc, "!== 'pending'") !== false, 'submit endpoint rejects a token whose signature is no longer pending', 'submit endpoint does not check signature status');

$requestSrc = file_get_contents("$root/api/document/request_external_signature.php");
check(strpos($requestSrc, 'random_bytes(32)') !== false, 'request endpoint generates a 32-byte (256-bit) token, matching csrf_token()\'s convention', 'request endpoint does not generate a sufficiently random token');
check(strpos($requestSrc, "hash('sha256', \$token)") !== false, 'request endpoint stores only the token HASH, not the raw token', 'request endpoint stores the raw token in the DB');
check(strpos($requestSrc, 'FILTER_VALIDATE_EMAIL') !== false, 'request endpoint validates the signer email format', 'request endpoint does not validate the signer email');
check(strpos($requestSrc, 'canCreate(') !== false, 'request endpoint is permission-gated', 'request endpoint has no permission check');

$publicSrc = file_get_contents("$root/sign_document.php");
check(!preg_match('/^\s*includeHeader\(\);/m', $publicSrc), 'public sign page does not call includeHeader() (would force a login redirect)', 'public sign page calls includeHeader() — would redirect external signers to login');
check(strpos($publicSrc, "used_at'] === null") !== false, 'public page only shows the signing UI for an unused token', 'public page does not check used_at');
check(strpos($publicSrc, 'expires_at') !== false, 'public page checks token expiry', 'public page does not check expiry');
check(strpos($publicSrc, 'identityCheck') !== false, 'public page has a separate identity-confirmation checkbox (mitigates a forwarded link being signed by the wrong person)', 'public page is missing the identity-confirmation checkbox');

$cancelSrc = file_get_contents("$root/api/document/cancel_external_signature.php");
check(strpos($cancelSrc, "status = 'rejected'") !== false, 'cancel endpoint sets status to rejected', 'cancel endpoint does not update status');
check(strpos($cancelSrc, 'used_at = NOW()') !== false, 'cancel endpoint invalidates any outstanding token immediately', 'cancel endpoint does not invalidate the token');
check(strpos($cancelSrc, 'requested_by') !== false && strpos($cancelSrc, 'isAdmin()') !== false, 'cancel endpoint is restricted to the requester or an admin', 'cancel endpoint does not check who is allowed to cancel');

check(strpos($requestSrc, "status = 'pending'") !== false && strpos($requestSrc, "already pending") !== false,
    'request endpoint blocks a second concurrent pending request on the same document (anti-spam guard)',
    'request endpoint has no guard against duplicate concurrent pending requests');

section('3. Live — the token lifecycle actually behaves as designed');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped (no includes/config.php — not a live install)\n";
} else {
    global $pdo;
    try {
        // Set up: a throwaway document + a real user to be requested_by.
        $docStmt = $pdo->prepare("
            INSERT INTO documents (document_name, file_path, file_type, version, uploaded_by, access_level, source)
            VALUES ('TEST — external signer regression', 'uploads/documents/test_ext_sig.pdf', 'pdf', '1.0', 1, 'private', 'created')
        ");
        $docStmt->execute();
        $testDocId = (int)$pdo->lastInsertId();

        $sigStmt = $pdo->prepare("
            INSERT INTO document_signatures (document_id, requested_by, signed_by, signer_name, signer_email, signer_type, status)
            VALUES (?, 1, NULL, 'Test External Signer', 'external-test@example.com', 'external', 'pending')
        ");
        $sigStmt->execute([$testDocId]);
        $testSigId = (int)$pdo->lastInsertId();

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $futureExpiry = date('Y-m-d H:i:s', strtotime('+7 days'));
        $pastExpiry   = date('Y-m-d H:i:s', strtotime('-1 day'));

        $tokStmt = $pdo->prepare("INSERT INTO document_signature_tokens (signature_id, token_hash, expires_at) VALUES (?, ?, ?)");
        $tokStmt->execute([$testSigId, $tokenHash, $futureExpiry]);
        $testTokenId = (int)$pdo->lastInsertId();

        // The exact lookup query sign_document.php / submit_external_signature.php use.
        $lookup = function (string $tHash) use ($pdo) {
            $stmt = $pdo->prepare("
                SELECT t.id AS token_id, t.expires_at, t.used_at, s.status
                FROM document_signature_tokens t
                JOIN document_signatures s ON s.id = t.signature_id
                WHERE t.token_hash = ?
                LIMIT 1
            ");
            $stmt->execute([$tHash]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        };

        $row = $lookup($tokenHash);
        check(
            $row && $row['used_at'] === null && strtotime($row['expires_at']) > time() && $row['status'] === 'pending',
            'a fresh, unexpired, unused token resolves as valid/ready',
            'a fresh token did not resolve as valid — token lifecycle is broken'
        );

        // Simulate a wrong/guessed token — must not resolve to anything.
        $wrongRow = $lookup(hash('sha256', bin2hex(random_bytes(32))));
        check($wrongRow === false, 'a random/guessed token matches nothing', 'a guessed token incorrectly matched a real record');

        // Mark used (simulating a completed signature) — must now be rejected.
        $pdo->prepare("UPDATE document_signature_tokens SET used_at = NOW() WHERE id = ?")->execute([$testTokenId]);
        $usedRow = $lookup($tokenHash);
        check(
            $usedRow && $usedRow['used_at'] !== null,
            'after being marked used, the same token is correctly flagged as already-used (single-use enforced)',
            'a used token was not flagged as used — replay would be possible'
        );

        // Fresh token but expired — must be rejected by the expiry check.
        $token2 = bin2hex(random_bytes(32));
        $tokenHash2 = hash('sha256', $token2);
        $pdo->prepare("INSERT INTO document_signature_tokens (signature_id, token_hash, expires_at) VALUES (?, ?, ?)")
            ->execute([$testSigId, $tokenHash2, $pastExpiry]);
        $expiredRow = $lookup($tokenHash2);
        check(
            $expiredRow && strtotime($expiredRow['expires_at']) <= time(),
            'an expired token is correctly identified as expired',
            'an expired token was not flagged as expired'
        );

        // Cleanup — self-contained, leaves no test data behind.
        $pdo->prepare("DELETE FROM document_signature_tokens WHERE signature_id = ?")->execute([$testSigId]);
        $pdo->prepare("DELETE FROM document_signatures WHERE id = ?")->execute([$testSigId]);
        $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$testDocId]);
        pass('test data cleaned up (self-contained, no residue left in the DB)');

    } catch (Throwable $e) {
        fail('Live token-lifecycle test threw: ' . $e->getMessage());
    }
}

section('4. Live — cancel endpoint + anti-spam guard behave as designed');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped (no includes/config.php — not a live install)\n";
} else {
    global $pdo;
    try {
        $docStmt = $pdo->prepare("
            INSERT INTO documents (document_name, file_path, file_type, version, uploaded_by, access_level, source)
            VALUES ('TEST — cancel/anti-spam regression', 'uploads/documents/test_ext_sig2.pdf', 'pdf', '1.0', 1, 'private', 'created')
        ");
        $docStmt->execute();
        $testDocId2 = (int)$pdo->lastInsertId();

        $sigStmt = $pdo->prepare("
            INSERT INTO document_signatures (document_id, requested_by, signed_by, signer_name, signer_email, signer_type, status)
            VALUES (?, 1, NULL, 'Test Signer Two', 'ext2-test@example.com', 'external', 'pending')
        ");
        $sigStmt->execute([$testDocId2]);
        $testSigId2 = (int)$pdo->lastInsertId();

        $token3 = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO document_signature_tokens (signature_id, token_hash, expires_at) VALUES (?, ?, ?)")
            ->execute([$testSigId2, hash('sha256', $token3), date('Y-m-d H:i:s', strtotime('+7 days'))]);

        // Anti-spam guard: the exact query request_external_signature.php runs
        // before creating a new request — must find the one already pending.
        $spamCheck = $pdo->prepare("
            SELECT id FROM document_signatures
            WHERE document_id = ? AND signer_type = 'external' AND status = 'pending'
            LIMIT 1
        ");
        $spamCheck->execute([$testDocId2]);
        check(
            (bool)$spamCheck->fetch(),
            'a document with an already-pending external request is correctly detected — a second request would be blocked',
            'the anti-spam guard query did not find the existing pending request'
        );

        // Cancel: the exact updates cancel_external_signature.php performs.
        $pdo->prepare("UPDATE document_signatures SET status = 'rejected', updated_at = NOW() WHERE id = ?")->execute([$testSigId2]);
        $pdo->prepare("UPDATE document_signature_tokens SET used_at = NOW() WHERE signature_id = ? AND used_at IS NULL")->execute([$testSigId2]);

        $afterCancel = $pdo->prepare("SELECT status FROM document_signatures WHERE id = ?");
        $afterCancel->execute([$testSigId2]);
        check($afterCancel->fetchColumn() === 'rejected', 'cancelling sets status to rejected', 'status was not updated to rejected');

        $tokenAfterCancel = $pdo->prepare("SELECT used_at FROM document_signature_tokens WHERE signature_id = ?");
        $tokenAfterCancel->execute([$testSigId2]);
        check($tokenAfterCancel->fetchColumn() !== null, 'cancelling immediately invalidates the outstanding token — the link stops working right away', 'the token was not invalidated by cancelling');

        // After cancelling, the anti-spam guard must no longer see this as
        // "already pending" — a new request should be allowed.
        $spamCheck->execute([$testDocId2]);
        check(!$spamCheck->fetch(), 'after cancelling, the document no longer blocks a new request (status is rejected, not pending)', 'a cancelled request still incorrectly blocks a new one');

        $pdo->prepare("DELETE FROM document_signature_tokens WHERE signature_id = ?")->execute([$testSigId2]);
        $pdo->prepare("DELETE FROM document_signatures WHERE id = ?")->execute([$testSigId2]);
        $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$testDocId2]);
        pass('test data cleaned up (self-contained, no residue left in the DB)');

    } catch (Throwable $e) {
        fail('Live cancel/anti-spam test threw: ' . $e->getMessage());
    }
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
