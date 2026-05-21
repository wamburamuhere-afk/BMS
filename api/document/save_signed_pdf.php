<?php
/**
 * save_signed_pdf.php — stores a client-signed PDF and records a legally
 * defensible audit trail (ESIGN/UETA-aligned).
 *
 * Integrity:  SHA-256 of the original and of the final signed file are computed
 *             SERVER-SIDE here — the client-reported hashes are never trusted.
 * Intent:     the signed request is rejected unless a consent statement is present.
 * Audit:      signer IP, user-agent, consent text/time and an ordered event log
 *             are persisted on document_signatures.
 */
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

/** Drop a script-blocking .htaccess into an uploads folder (idempotent). */
function ensureUploadHtaccess(string $dir): void {
    $htaccess = rtrim($dir, '/\\') . '/.htaccess';
    if (is_file($htaccess)) {
        return;
    }
    file_put_contents($htaccess,
        "<FilesMatch \"\\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)\$\">\n" .
        "    Require all denied\n" .
        "</FilesMatch>\n" .
        "Options -ExecCGI\n" .
        "RemoveHandler .php .phtml .php5\n" .
        "RemoveType .php .phtml .php5\n"
    );
}

try {
    // 1. Auth
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    // 2. Permission
    if (!canCreate('documents')) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }

    // 3. Method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // 4. CSRF
    csrf_check();

    // 5. Input
    $original_document_id = intval($_POST['original_document_id'] ?? 0);
    $signature_id         = intval($_POST['signature_id']         ?? 0);
    $position             = $_POST['signature_position']          ?? 'custom';
    $consent_text         = trim($_POST['consent_text']           ?? '');
    $signing_reference    = trim($_POST['signing_reference']      ?? '');
    $viewed_at            = trim($_POST['viewed_at']              ?? '');
    $consent_at           = trim($_POST['consent_accepted_at']    ?? '');

    $file = $_FILES['signed_pdf_file'] ?? null;

    if (!$original_document_id || !$signature_id || !$file) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    // Intent check — a signature without recorded consent is not legally defensible.
    if ($consent_text === '') {
        echo json_encode(['success' => false, 'message' => 'Consent is required before a document can be signed']);
        exit;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error: ' . $file['error']]);
        exit;
    }

    $allowed_positions = ['custom', 'bottom_left', 'bottom_center', 'bottom_right'];
    if (!in_array($position, $allowed_positions, true)) {
        $position = 'custom';
    }

    // Validate real MIME type (magic bytes — never trust the browser)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file['tmp_name']);
    if ($realMime !== 'application/pdf') {
        echo json_encode(['success' => false, 'message' => 'Uploaded file is not a valid PDF']);
        exit;
    }

    // Size limit: 40 MB
    if ($file['size'] > 40 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Signed PDF exceeds 40 MB limit']);
        exit;
    }

    // Verify original document exists (need file_path to hash the "before" state)
    $stmt = $pdo->prepare("SELECT id, document_name, category_id, file_path FROM documents WHERE id = ?");
    $stmt->execute([$original_document_id]);
    $original = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$original) {
        echo json_encode(['success' => false, 'message' => 'Original document not found']);
        exit;
    }

    // Verify signature belongs to current user and is active
    $stmt = $pdo->prepare("SELECT id FROM user_signatures WHERE id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$signature_id, $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Signature not found or not active']);
        exit;
    }

    // ── Save the signed PDF ──────────────────────────────────────────────────
    $upload_dir = __DIR__ . '/../../uploads/documents/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    ensureUploadHtaccess($upload_dir);

    $filename  = bin2hex(random_bytes(16)) . '_signed.pdf';
    $db_path   = 'uploads/documents/' . $filename;
    $full_path = $upload_dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $full_path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save signed PDF']);
        exit;
    }

    // ── Integrity: server-side SHA-256 (authoritative) ───────────────────────
    $hash_algorithm = 'sha256';
    $hash_after     = hash_file('sha256', $full_path) ?: null;

    $hash_before = null;
    if (!empty($original['file_path'])) {
        $origFull = ROOT_DIR . '/' . ltrim($original['file_path'], '/');
        if (is_file($origFull)) {
            $hash_before = hash_file('sha256', $origFull) ?: null;
        }
    }

    // Signing reference — accept the client's value, else generate one.
    if ($signing_reference === '' || strlen($signing_reference) > 64) {
        $signing_reference = 'SIG-' . strtoupper(bin2hex(random_bytes(6)));
    }

    $file_size   = $file['size'];
    $signed_name = rtrim($original['document_name']) . ' (Signed)';
    $userId      = $_SESSION['user_id'];
    $ip          = $_SERVER['REMOTE_ADDR']     ?? '0.0.0.0';
    $userAgent   = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);

    // Ordered audit event log (signing_applied is server-stamped & authoritative)
    $event_log = json_encode([
        ['event' => 'document_viewed',  'at' => ($viewed_at  !== '' ? $viewed_at  : null), 'source' => 'client'],
        ['event' => 'consent_accepted', 'at' => ($consent_at !== '' ? $consent_at : null), 'source' => 'client'],
        ['event' => 'signature_applied','at' => date('c'), 'source' => 'server', 'ip' => $ip, 'user_agent' => $userAgent],
    ]);

    // Insert the signed document record
    $stmt = $pdo->prepare("
        INSERT INTO documents
            (document_name, description, file_path, original_filename,
             file_size, file_type, category_id, version, uploaded_by, access_level)
        VALUES (?, ?, ?, ?, ?, 'pdf', ?, '1.0', ?, 'private')
    ");
    $stmt->execute([
        $signed_name,
        'Digitally signed — original: ' . $original['document_name'],
        $db_path,
        $filename,
        $file_size,
        $original['category_id'] ?: null,
        $userId,
    ]);
    $new_document_id = (int)$pdo->lastInsertId();

    // Record in document_signatures — update a pending request if one exists
    $stmt = $pdo->prepare("
        SELECT id FROM document_signatures
        WHERE document_id = ? AND signed_by = ? AND status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$original_document_id, $userId]);
    $pending = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pending) {
        $pdo->prepare("
            UPDATE document_signatures
            SET signature_id = ?, signature_position = ?, ip_address = ?, user_agent = ?,
                hash_algorithm = ?, hash_before = ?, hash_after = ?, signing_reference = ?,
                signed_document_id = ?, consent_text = ?, consent_accepted_at = NOW(),
                event_log = ?, status = 'signed', signed_at = NOW()
            WHERE id = ?
        ")->execute([
            $signature_id, $position, $ip, $userAgent,
            $hash_algorithm, $hash_before, $hash_after, $signing_reference,
            $new_document_id, $consent_text, $event_log, $pending['id'],
        ]);
        $signature_record_id = (int)$pending['id'];
    } else {
        $pdo->prepare("
            INSERT INTO document_signatures
                (document_id, signature_id, requested_by, signed_by, signature_position,
                 ip_address, user_agent, hash_algorithm, hash_before, hash_after,
                 signing_reference, signed_document_id, consent_text, consent_accepted_at,
                 event_log, status, signed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'signed', NOW())
        ")->execute([
            $original_document_id, $signature_id, $userId, $userId, $position,
            $ip, $userAgent, $hash_algorithm, $hash_before, $hash_after,
            $signing_reference, $new_document_id, $consent_text, $event_log,
        ]);
        $signature_record_id = (int)$pdo->lastInsertId();
    }

    logActivity($pdo, $userId,
        "Signed document '{$original['document_name']}' — saved as document ID: $new_document_id ($signing_reference)");
    logAudit($pdo, $userId, 'sign_document', [
        'activity_type' => 'sign',
        'description'   => "Applied e-signature to '{$original['document_name']}' (ref $signing_reference)",
        'entity_type'   => 'document',
        'entity_id'     => $original_document_id,
        'new_values'    => [
            'signed_document_id' => $new_document_id,
            'hash_after'         => $hash_after,
            'signing_reference'  => $signing_reference,
        ],
    ]);

    echo json_encode([
        'success'             => true,
        'message'             => 'Document signed successfully',
        'new_document_id'     => $new_document_id,
        'signature_record_id' => $signature_record_id,
        'signing_reference'   => $signing_reference,
        'hash_after'          => $hash_after,
    ]);

} catch (Exception $e) {
    error_log('save_signed_pdf.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred while signing the document']);
}
