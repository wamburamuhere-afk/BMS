<?php
/**
 * submit_external_signature.php — receives the signed PDF from
 * sign_document.php (the public, unauthenticated page). Token-authenticated
 * instead of session-authenticated: the single-use, hashed, expiring token
 * in document_signature_tokens IS the credential here, playing the same
 * role a session + CSRF token plays on every other write endpoint.
 *
 * Mirrors save_signed_pdf.php's integrity/audit approach (server-side
 * SHA-256 of before/after, consent required, ordered event log) so an
 * externally-signed document is exactly as legally defensible as an
 * internally-signed one.
 */
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Method not allowed');
    }

    $token        = trim((string)($_POST['token'] ?? ''));
    $consent_text = trim((string)($_POST['consent_text'] ?? ''));
    $file         = $_FILES['signed_pdf_file'] ?? null;

    if ($token === '' || !$file) {
        throw new Exception('Missing required fields');
    }
    if ($consent_text === '') {
        throw new Exception('Consent is required before a document can be signed');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: ' . $file['error']);
    }

    $token_hash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        SELECT t.id AS token_id, t.expires_at, t.used_at,
               s.id AS signature_id, s.document_id, s.requested_by, s.signer_name, s.signer_email, s.status,
               d.document_name, d.category_id, d.file_path
        FROM document_signature_tokens t
        JOIN document_signatures s ON s.id = t.signature_id
        JOIN documents d ON d.id = s.document_id
        WHERE t.token_hash = ?
        LIMIT 1
    ");
    $stmt->execute([$token_hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || $row['used_at'] !== null || strtotime($row['expires_at']) <= time() || $row['status'] !== 'pending') {
        throw new Exception('This signing link is invalid, expired, or already used');
    }

    // Real MIME check via magic bytes — never trust the browser.
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file['tmp_name']);
    if ($realMime !== 'application/pdf') {
        throw new Exception('Uploaded file is not a valid PDF');
    }
    if ($file['size'] > 40 * 1024 * 1024) {
        throw new Exception('Signed PDF exceeds 40MB limit');
    }

    $upload_dir = __DIR__ . '/../../uploads/documents/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $safe_name = bin2hex(random_bytes(16)) . '_signed.pdf';
    $db_path   = 'uploads/documents/' . $safe_name;
    $target    = $upload_dir . $safe_name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new Exception('Failed to save the signed PDF');
    }

    // Server-side integrity hashes (authoritative — never trust the client's).
    $hash_after  = hash_file('sha256', $target) ?: null;
    $hash_before = null;
    if (!empty($row['file_path'])) {
        $origFull = ROOT_DIR . '/' . ltrim($row['file_path'], '/');
        if (is_file($origFull)) {
            $hash_before = hash_file('sha256', $origFull) ?: null;
        }
    }

    $signing_reference = 'SIG-' . strtoupper(bin2hex(random_bytes(6)));
    $ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
    $event_log = json_encode([
        ['event' => 'signing_link_opened', 'at' => null, 'source' => 'external'],
        ['event' => 'consent_accepted',    'at' => date('c'), 'source' => 'external'],
        ['event' => 'signature_applied',   'at' => date('c'), 'source' => 'server', 'ip' => $ip, 'user_agent' => $userAgent],
    ]);

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("
            INSERT INTO documents
                (document_name, description, file_path, original_filename,
                 file_size, file_type, category_id, version, uploaded_by, access_level)
            VALUES (?, ?, ?, ?, ?, 'pdf', ?, '1.0', ?, 'private')
        ");
        $ins->execute([
            rtrim($row['document_name']) . ' (Signed)',
            'Digitally signed by external party: ' . $row['signer_name'] . ' <' . $row['signer_email'] . '> — original: ' . $row['document_name'],
            $db_path,
            $safe_name,
            $file['size'],
            $row['category_id'] ?: null,
            (int)$row['requested_by'], // no external user account — the record belongs to whoever requested the signature
        ]);
        $new_document_id = (int)$pdo->lastInsertId();

        $pdo->prepare("
            UPDATE document_signatures
            SET status = 'signed', signed_at = NOW(), signed_document_id = ?,
                ip_address = ?, user_agent = ?, hash_algorithm = 'sha256',
                hash_before = ?, hash_after = ?, signing_reference = ?,
                consent_text = ?, consent_accepted_at = NOW(), event_log = ?
            WHERE id = ?
        ")->execute([
            $new_document_id, $ip, $userAgent, $hash_before, $hash_after,
            $signing_reference, $consent_text, $event_log, $row['signature_id'],
        ]);

        $pdo->prepare("UPDATE document_signature_tokens SET used_at = NOW() WHERE id = ?")
            ->execute([$row['token_id']]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_file($target)) @unlink($target);
        throw $e;
    }

    logActivity($pdo, (int)$row['requested_by'],
        "External signer {$row['signer_name']} <{$row['signer_email']}> signed '{$row['document_name']}' — saved as document ID: $new_document_id ($signing_reference)");
    logAudit($pdo, (int)$row['requested_by'], 'sign_document_external', [
        'activity_type' => 'sign',
        'description'   => "External signature applied by {$row['signer_name']} <{$row['signer_email']}> (ref $signing_reference)",
        'entity_type'   => 'document',
        'entity_id'     => $row['document_id'],
        'new_values'    => [
            'signed_document_id' => $new_document_id,
            'hash_after'         => $hash_after,
            'signing_reference'  => $signing_reference,
        ],
    ]);

    echo json_encode([
        'success'           => true,
        'message'           => 'Document signed successfully',
        'new_document_id'   => $new_document_id,
        'signing_reference' => $signing_reference,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
