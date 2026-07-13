<?php
/**
 * duplicate_created_document.php — clones an existing "created" (letter/memo)
 * document into a brand new, separate draft: its own row, its own allocated
 * reference number, and its own copy of the PDF file on disk. The source
 * document is left completely untouched.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/code_generator.php';
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

    // Copy the physical PDF so the new row has a complete, immediately usable
    // file from the moment it's created (it'll be replaced by a fresh render
    // the next time the user actually saves further edits).
    $new_name = null;
    if ($source['file_path']) {
        $src_full = ROOT_DIR . '/' . ltrim($source['file_path'], '/');
        if (is_file($src_full)) {
            $new_name = bin2hex(random_bytes(16)) . '.pdf';
            $dest_full = ROOT_DIR . '/uploads/documents/' . $new_name;
            if (!copy($src_full, $dest_full)) {
                $new_name = null;
            }
        }
    }
    $new_path = $new_name ? ('uploads/documents/' . $new_name) : $source['file_path'];

    $document_code = nextCode($pdo, 'LTR');
    $new_name_field = trim($source['document_name'] . ' (Copy)');

    $ins = $pdo->prepare("
        INSERT INTO documents (
            document_name, description, content, file_path, original_filename,
            file_size, file_type, category_id, document_code, version,
            issue_date, access_level, uploaded_by, project_id, source
        ) VALUES (?, ?, ?, ?, ?, ?, 'pdf', ?, ?, '1.0', ?, ?, ?, ?, 'created')
    ");
    $ins->execute([
        $new_name_field,
        $source['description'],
        $source['content'],
        $new_path,
        $new_name_field . '.pdf',
        $source['file_size'],
        $source['category_id'],
        $document_code,
        date('Y-m-d'),
        $source['access_level'] ?: 'private',
        $_SESSION['user_id'],
        $source['project_id'],
    ]);
    $new_id = (int)$pdo->lastInsertId();

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
