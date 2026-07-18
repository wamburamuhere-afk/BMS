<?php
/**
 * duplicate_created_document.php — clones an existing "created" (letter/memo)
 * document into a brand new, separate draft: its own row, its own allocated
 * reference number, and its own freshly server-rendered PDF (not a byte
 * copy of the old one — the old PDF's letterhead would still show the
 * SOURCE document's reference number, disagreeing with the new row's own
 * document_code from the moment it's created). The source document is left
 * completely untouched.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/code_generator.php';
require_once __DIR__ . '/../../core/document_merge.php';
require_once __DIR__ . '/../../core/document_letter_pdf.php';
global $pdo;

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
        throw new Exception('Access Denied: you do not have permission to create documents');
    }
    csrf_check();

    $source_id = intval($_POST['document_id'] ?? 0);
    if (!$source_id) {
        throw new Exception('Invalid document');
    }

    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND source = 'created'");
    $stmt->execute([$source_id]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$source) {
        throw new Exception('Document not found');
    }
    if ((int)$source['uploaded_by'] !== (int)$_SESSION['user_id'] && !isAdmin()) {
        http_response_code(403);
        throw new Exception('Access denied: you can only duplicate your own created documents');
    }

    $document_code  = nextCode($pdo, 'LTR');
    $new_name_field = trim($source['document_name'] . ' (Copy)');

    // Same "To: X" convention save_created_document.php uses/parses.
    $recipient = '';
    if (!empty($source['description']) && strpos($source['description'], 'To: ') === 0) {
        $recipient = substr($source['description'], 4);
    }

    $project_name = null;
    $contract_number = null;
    if ($source['project_id']) {
        $pr = $pdo->prepare("SELECT project_name, contract_number FROM projects WHERE project_id = ?");
        $pr->execute([$source['project_id']]);
        if ($prow = $pr->fetch(PDO::FETCH_ASSOC)) {
            $project_name    = $prow['project_name'] ?? '';
            $contract_number = $prow['contract_number'] ?? '';
        }
    }

    // Re-resolve merge tokens with the NEW document_code (any {{document_code}}
    // the source already had literally baked into its body text was resolved
    // once at the source's own save time and can't be un-resolved — but the
    // letterhead's Ref line, rendered fresh below from this document_code,
    // will always be correct regardless).
    $merge_ctx = [
        'document_code'     => $document_code,
        'subject'           => $new_name_field,
        'recipient'         => $recipient,
        'recipient_address' => $source['recipient_address'] ?? '',
        'date'              => date('d M Y'),
        'sender_name'       => trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
        'sender_role'       => $_SESSION['user_role'] ?? '',
        'project_name'      => $project_name,
        'contract_number'   => $contract_number,
    ];
    $new_content = resolveDocumentVariables((string)$source['content'], $merge_ctx);

    $upload_dir = __DIR__ . '/../../uploads/documents/';
    if (!file_exists($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        throw new Exception('Failed to create upload directory');
    }
    $safe_name = bin2hex(random_bytes(16)) . '.pdf';
    $target    = $upload_dir . $safe_name;
    $new_path  = 'uploads/documents/' . $safe_name;

    $file_size = generateLetterPdf($pdo, [
        'document_code'     => $document_code,
        'letter_date'       => date('Y-m-d'),
        'use_letterhead'    => $source['use_letterhead'] ?? 1,
        'recipient'         => $recipient,
        'recipient_address' => $source['recipient_address'] ?? '',
        'subject'           => $new_name_field,
        'content'           => $new_content,
        'signature_align'   => $source['signature_align'] ?? 'left',
    ], $target);

    $ins = $pdo->prepare("
        INSERT INTO documents (
            document_name, description, content, file_path, original_filename,
            file_size, file_type, category_id, document_code, version,
            issue_date, access_level, uploaded_by, project_id, source,
            use_letterhead, recipient_address, signature_align, custom_sender_info
        ) VALUES (?, ?, ?, ?, ?, ?, 'pdf', ?, ?, '1.0', ?, ?, ?, ?, 'created', ?, ?, ?, ?)
    ");
    $ins->execute([
        $new_name_field,
        $source['description'],
        $new_content,
        $new_path,
        $new_name_field . '.pdf',
        $file_size,
        $source['category_id'],
        $document_code,
        date('Y-m-d'),
        $source['access_level'] ?: 'private',
        $_SESSION['user_id'],
        $source['project_id'],
        $source['use_letterhead'] ?? 1,
        $source['recipient_address'] ?? null,
        $source['signature_align'] ?? 'left',
        $source['custom_sender_info'] ?? null,
    ]);
    $new_id = (int)$pdo->lastInsertId();

    // Carry over who a restricted/private document was shared with — without
    // this, duplicating a document shared with 5 people silently produced a
    // copy shared with nobody.
    $pdo->prepare("
        INSERT INTO document_assignees (document_id, user_id, assigned_by, assigned_at)
        SELECT ?, user_id, assigned_by, assigned_at FROM document_assignees WHERE document_id = ?
    ")->execute([$new_id, $source_id]);

    logAudit($pdo, $_SESSION['user_id'], 'duplicate_document', [
        'activity_type' => 'create',
        'description'   => "Duplicated document #{$source_id} ({$source['document_code']}) as #{$new_id} ($document_code)",
        'entity_type'   => 'document',
        'entity_id'     => $new_id,
    ]);

    echo json_encode([
        'success'       => true,
        'message'       => 'Document duplicated.',
        'document_id'   => $new_id,
        'document_code' => $document_code,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
