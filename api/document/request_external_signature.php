<?php
/**
 * request_external_signature.php — an internal user names someone OUTSIDE
 * the company (a client/supplier contact, or anyone by name+email) as the
 * signer of a document, instead of signing it themselves.
 *
 * Creates a pending document_signatures row (signer_type='external',
 * signed_by stays NULL until they actually sign) and a single-use, expiring
 * token (same convention as csrf_token(): bin2hex(random_bytes(32)), only
 * the hash is stored), then emails the signer a link to sign_document.php.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mailer.php';

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    if (!canCreate('documents')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to request signatures');
    }
    csrf_check();

    $document_id  = (int)($_POST['document_id'] ?? 0);
    $signer_name  = trim((string)($_POST['signer_name']  ?? ''));
    $signer_email = trim((string)($_POST['signer_email'] ?? ''));

    if ($document_id <= 0) {
        throw new Exception('No document selected');
    }
    if ($signer_name === '') {
        throw new Exception('Signer name is required');
    }
    if ($signer_email === '' || !filter_var($signer_email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('A valid signer email is required');
    }

    $doc = $pdo->prepare("SELECT id, document_name FROM documents WHERE id = ?");
    $doc->execute([$document_id]);
    $document = $doc->fetch(PDO::FETCH_ASSOC);
    if (!$document) {
        throw new Exception('Document not found');
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("
            INSERT INTO document_signatures
                (document_id, requested_by, signed_by, signer_name, signer_email, signer_type, status)
            VALUES (?, ?, NULL, ?, ?, 'external', 'pending')
        ");
        $ins->execute([$document_id, $_SESSION['user_id'], $signer_name, $signer_email]);
        $signature_id = (int)$pdo->lastInsertId();

        $token      = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token);
        $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

        $pdo->prepare("
            INSERT INTO document_signature_tokens (signature_id, token_hash, expires_at)
            VALUES (?, ?, ?)
        ")->execute([$signature_id, $token_hash, $expires_at]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $requested_by_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ($_SESSION['username'] ?? 'A BMS user');
    $sign_url = buildUrl('sign-document') . '?token=' . $token;

    $emailed = sendEmail(
        $signer_email,
        'A document is waiting for your signature',
        '<p>Hello ' . htmlspecialchars($signer_name) . ',</p>'
        . '<p>' . htmlspecialchars($requested_by_name) . ' has sent you a document to review and sign: <strong>'
        . htmlspecialchars($document['document_name']) . '</strong>.</p>'
        . '<p><a href="' . htmlspecialchars($sign_url) . '" style="display:inline-block;padding:10px 20px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px;">Review &amp; Sign Document</a></p>'
        . '<p style="font-size:12px;color:#6c757d;">This link expires in 7 days and can only be used once. If you weren\'t expecting this, you can safely ignore this email.</p>'
    );

    logActivity($pdo, $_SESSION['user_id'], "Requested external signature on '{$document['document_name']}' from $signer_name <$signer_email>");
    logAudit($pdo, $_SESSION['user_id'], 'request_external_signature', [
        'activity_type' => 'create',
        'description'   => "Requested external signature from $signer_name <$signer_email>",
        'entity_type'   => 'document',
        'entity_id'     => $document_id,
    ]);

    echo json_encode([
        'success'       => true,
        'message'       => $emailed
            ? 'Signature request emailed to ' . $signer_email . '.'
            : 'Signature request created, but the email could not be sent (' . mailer_last_error() . '). Share the link manually: ' . $sign_url,
        'signature_id'  => $signature_id,
        'emailed'       => $emailed,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
