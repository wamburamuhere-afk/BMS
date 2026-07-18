<?php
/**
 * save_created_document.php — persists a letter/memo written with the
 * "Create Document" editor (app/constant/document/create_document.php).
 *
 * Every save — draft or final — generates a real, server-rendered PDF (via
 * TCPDF, see core/document_letter_render.php) so the row behaves exactly
 * like every other row in `documents` (previewable, downloadable, and
 * pickable from the existing e-signature wizard). The server generating the
 * PDF itself — rather than trusting a client-uploaded blob — is also the
 * single source of truth: the reference number and signature can't be
 * missing or tampered with client-side. The raw editable HTML is kept in
 * `content` so a draft can be reopened and edited further before it's ever
 * signed. A reference code (e.g. BFS-LTR-0001) is allocated ONCE, on the
 * first save of a given letter, and then kept for every subsequent re-save —
 * nextCode() is never called again for the same document_id, so re-saving a
 * draft repeatedly never burns extra sequence numbers.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/code_generator.php';
require_once __DIR__ . '/../../core/project_scope.php';
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

    $document_id = intval($_POST['document_id'] ?? 0);
    $subject     = trim((string)($_POST['subject'] ?? ''));
    $recipient   = trim((string)($_POST['recipient'] ?? ''));
    $recipient_address = trim((string)($_POST['recipient_address'] ?? ''));
    $content     = (string)($_POST['content'] ?? '');
    $letter_date = trim((string)($_POST['letter_date'] ?? ''));
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $project_id  = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
    $access_level = in_array(($_POST['access_level'] ?? ''), ['private', 'restricted', 'public'], true)
        ? $_POST['access_level'] : 'private';
    $use_letterhead = ($_POST['use_letterhead'] ?? '1') === '1' ? 1 : 0;
    $signature_align = in_array(($_POST['signature_align'] ?? ''), ['left', 'center', 'right'], true)
        ? $_POST['signature_align'] : 'left';
    // NULL = always follow Company Profile automatically (default). Only
    // persisted when the "Customize sender address" toggle was actually on —
    // if the client sends the flag off, this document reverts to auto on
    // every future load/render regardless of what was typed into the (now
    // hidden) custom editor.
    $use_custom_sender = ($_POST['use_custom_sender'] ?? '0') === '1';
    $custom_sender_info = ($use_custom_sender && trim((string)($_POST['custom_sender_info'] ?? '')) !== '')
        ? (string)$_POST['custom_sender_info'] : null;

    if ($subject === '') {
        throw new Exception('Subject is required');
    }
    if (trim(strip_tags($content)) === '') {
        throw new Exception('The letter body cannot be empty');
    }
    if ($project_id !== null && !userCan('project', $project_id)) {
        http_response_code(403);
        throw new Exception('Access denied: this project is not in your assigned scope.');
    }

    // Authoritative safety pass — the client already resolves merge tokens for
    // the preview and the rendered PDF, but resolve again here so the stored
    // body never persists a raw {{token}} (e.g. if a field was filled after a
    // template was applied). Company values come from settings automatically;
    // project name/contract are looked up when the letter is project-linked.
    $merge_ctx = [
        'subject'           => $subject,
        'recipient'         => $recipient,
        'recipient_address' => $recipient_address,
        'date'              => $letter_date !== '' ? date('d M Y', strtotime($letter_date)) : date('d M Y'),
        'sender_name'       => trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
        'sender_role'       => $_SESSION['user_role'] ?? '',
    ];
    if ($project_id !== null) {
        $pr = $pdo->prepare("SELECT project_name, contract_number FROM projects WHERE project_id = ?");
        $pr->execute([$project_id]);
        if ($prow = $pr->fetch(PDO::FETCH_ASSOC)) {
            $merge_ctx['project_name']    = $prow['project_name'] ?? '';
            $merge_ctx['contract_number'] = $prow['contract_number'] ?? '';
        }
    }

    // The PDF is now generated server-side (generateLetterPdf(), below) —
    // reserve its target path here; nothing is uploaded by the client anymore.
    $upload_dir = __DIR__ . '/../../uploads/documents/';
    if (!file_exists($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        throw new Exception('Failed to create upload directory');
    }
    $safe_name = bin2hex(random_bytes(16)) . '.pdf';
    $target    = $upload_dir . $safe_name;
    $db_path   = 'uploads/documents/' . $safe_name;

    if ($document_id > 0) {
        // Re-saving an existing letter — must be one this user created via
        // this editor (source='created'); never let this endpoint touch an
        // uploaded file or someone else's letter.
        $stmt = $pdo->prepare("SELECT uploaded_by, source, file_path, document_code FROM documents WHERE id = ?");
        $stmt->execute([$document_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing || $existing['source'] !== 'created') {
            throw new Exception('Document not found');
        }
        if ((int)$existing['uploaded_by'] !== (int)$_SESSION['user_id'] && !isAdmin()) {
            http_response_code(403);
            throw new Exception('Access denied: you can only edit your own created documents');
        }

        $old_path = $existing['file_path'];

        // Resolve any leftover merge tokens now that we know the document code.
        $merge_ctx['document_code'] = $existing['document_code'] ?? '';
        $content = resolveDocumentVariables($content, $merge_ctx);

        $file_size = generateLetterPdf($pdo, [
            'document_code'     => $existing['document_code'] ?? '',
            'letter_date'       => $letter_date,
            'use_letterhead'    => $use_letterhead,
            'recipient'         => $recipient,
            'recipient_address' => $recipient_address,
            'subject'           => $subject,
            'content'           => $content,
            'signature_align'   => $signature_align,
        ], $target);

        $upd = $pdo->prepare("
            UPDATE documents SET
                document_name = ?, description = ?, content = ?, file_path = ?,
                original_filename = ?, file_size = ?, file_type = 'pdf',
                category_id = ?, project_id = ?, issue_date = ?, access_level = ?,
                use_letterhead = ?, recipient_address = ?, signature_align = ?,
                custom_sender_info = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([
            $subject,
            $recipient !== '' ? ('To: ' . $recipient) : null,
            $content,
            $db_path,
            $subject . '.pdf',
            $file_size,
            $category_id,
            $project_id,
            $letter_date ?: null,
            $access_level,
            $use_letterhead,
            $recipient_address !== '' ? $recipient_address : null,
            $signature_align,
            $custom_sender_info,
            $document_id,
        ]);

        // Best-effort cleanup of the previous PDF revision.
        if ($old_path) {
            $old_full = ROOT_DIR . '/' . ltrim($old_path, '/');
            if (is_file($old_full)) @unlink($old_full);
        }

        logActivity($pdo, $_SESSION['user_id'], "Updated created document: '$subject' ({$existing['document_code']})");

        echo json_encode([
            'success'       => true,
            'message'       => 'Document saved.',
            'document_id'   => $document_id,
            'document_code' => $existing['document_code'],
        ]);
        exit;
    }

    // New letter — allocate its reference code and insert in ONE transaction.
    // nextCode() commits its own tiny transaction the instant no outer one is
    // open, so without this wrapper a failed INSERT below (e.g. a stray
    // duplicate-key collision) would still permanently burn the allocated
    // number with no document ever claiming it — exactly the gap nextCode()
    // is designed to prevent. Wrapping both in the same transaction makes
    // nextCode() share it instead, so a rolled-back insert also rolls back
    // the number.
    $pdo->beginTransaction();
    try {
        $document_code = nextCode($pdo, 'LTR');

        // Resolve any leftover merge tokens now that the document code exists.
        $merge_ctx['document_code'] = $document_code;
        $content = resolveDocumentVariables($content, $merge_ctx);

        $file_size = generateLetterPdf($pdo, [
            'document_code'     => $document_code,
            'letter_date'       => $letter_date,
            'use_letterhead'    => $use_letterhead,
            'recipient'         => $recipient,
            'recipient_address' => $recipient_address,
            'subject'           => $subject,
            'content'           => $content,
            'signature_align'   => $signature_align,
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
            $subject,
            $recipient !== '' ? ('To: ' . $recipient) : null,
            $content,
            $db_path,
            $subject . '.pdf',
            $file_size,
            $category_id,
            $document_code,
            $letter_date ?: null,
            $access_level,
            $_SESSION['user_id'],
            $project_id,
            $use_letterhead,
            $recipient_address !== '' ? $recipient_address : null,
            $signature_align,
            $custom_sender_info,
        ]);
        $document_id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // The generated PDF may already have been written to disk before the
        // failure — clean it up so a failed save doesn't leave an orphaned file.
        if (is_file($target)) @unlink($target);
        throw $e;
    }

    logAudit($pdo, $_SESSION['user_id'], 'create_document', [
        'activity_type' => 'create',
        'description'   => "Created document: '$subject' ($document_code)",
        'entity_type'   => 'document',
        'entity_id'     => $document_id,
    ]);

    echo json_encode([
        'success'       => true,
        'message'       => 'Document created.',
        'document_id'   => $document_id,
        'document_code' => $document_code,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
