<?php
/**
 * verify_signed_document.php — integrity check for a signed document.
 *
 * Re-hashes the stored signed file with SHA-256 and compares it to the
 * hash recorded at signing time (document_signatures.hash_after).
 * Equal  -> the document is unchanged since it was signed (Verified).
 * Differ -> the file has been altered since signing (Tampered).
 */
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

try {
    // Auth + permission
    if (!isAuthenticated()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    if (!canView('documents')) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }

    $document_id = intval($_GET['document_id'] ?? 0);
    if (!$document_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID']);
        exit;
    }

    // The signed documents row
    $stmt = $pdo->prepare("SELECT id, document_name, file_path FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit;
    }

    // The signature record that produced this signed document
    $stmt = $pdo->prepare("
        SELECT ds.hash_algorithm, ds.hash_before, ds.hash_after, ds.signing_reference,
               ds.signed_at, ds.signature_position,
               CONCAT(u.first_name, ' ', u.last_name) AS signer_name
        FROM document_signatures ds
        LEFT JOIN users u ON u.user_id = ds.signed_by
        WHERE ds.signed_document_id = ? AND ds.status = 'signed'
        ORDER BY ds.id DESC
        LIMIT 1
    ");
    $stmt->execute([$document_id]);
    $sig = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sig) {
        echo json_encode([
            'success' => false,
            'message' => 'No signature record found for this document. It was not signed through the e-signature wizard.',
        ]);
        exit;
    }

    if (empty($sig['hash_after'])) {
        echo json_encode([
            'success'  => true,
            'verified' => null,
            'message'  => 'This document was signed before integrity hashing was enabled — it cannot be verified.',
            'details'  => [
                'signing_reference' => $sig['signing_reference'],
                'signed_at'         => $sig['signed_at'],
                'signer_name'       => trim($sig['signer_name'] ?? ''),
            ],
        ]);
        exit;
    }

    // Re-hash the file on disk
    $fullPath = ROOT_DIR . '/' . ltrim($doc['file_path'], '/');
    if (!is_file($fullPath)) {
        echo json_encode([
            'success'  => true,
            'verified' => false,
            'message'  => 'The signed file is missing from the server — integrity cannot be confirmed.',
            'details'  => [
                'signing_reference' => $sig['signing_reference'],
                'signed_at'         => $sig['signed_at'],
                'signer_name'       => trim($sig['signer_name'] ?? ''),
            ],
        ]);
        exit;
    }

    $algo        = $sig['hash_algorithm'] ?: 'sha256';
    $actual_hash = hash_file($algo, $fullPath);
    $verified    = hash_equals((string)$sig['hash_after'], (string)$actual_hash);

    echo json_encode([
        'success'  => true,
        'verified' => $verified,
        'message'  => $verified
            ? 'Verified — this document is unchanged since it was signed.'
            : 'Tampered — this document has been altered since it was signed.',
        'details'  => [
            'document_name'     => $doc['document_name'],
            'signing_reference' => $sig['signing_reference'],
            'signed_at'         => $sig['signed_at'],
            'signer_name'       => trim($sig['signer_name'] ?? ''),
            'hash_algorithm'    => $algo,
            'expected_hash'     => $sig['hash_after'],
            'actual_hash'       => $actual_hash,
            'original_hash'     => $sig['hash_before'],
        ],
    ]);

} catch (Exception $e) {
    error_log('verify_signed_document.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred during verification']);
}
