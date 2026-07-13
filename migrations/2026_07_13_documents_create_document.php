<?php
/**
 * 2026_07_13_documents_create_document.php
 * -----------------------------------------
 * "Create Document" feature (Phase 1) — write a letter/memo in-app (Summernote
 * editor) instead of only uploading a file. Adds two nullable columns to the
 * existing `documents` table:
 *
 *   content       — the raw editable HTML body, so a draft can be reopened
 *                    and edited again before it's finalized/signed. Every
 *                    save (draft or final) still also renders a real PDF into
 *                    file_path, so the row always behaves like every other
 *                    document in the library (previewable, downloadable,
 *                    signable via the existing e-signature wizard).
 *   document_code — auto-generated sequential reference (e.g. BFS-LTR-0001)
 *                   via the existing core/code_generator.php, shown in the
 *                   letterhead. Only populated for source='created' rows;
 *                   uploaded documents keep it NULL. UNIQUE where present —
 *                   NULL is not compared for uniqueness in MySQL, so existing
 *                   NULL rows are unaffected.
 *
 * Idempotent (checks SHOW COLUMNS before each ALTER).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: documents.content + documents.document_code...\n";

try {
    $hasContent = $pdo->query("SHOW COLUMNS FROM documents LIKE 'content'")->fetch();
    if (!$hasContent) {
        $pdo->exec("ALTER TABLE documents ADD COLUMN content LONGTEXT NULL AFTER description");
        echo "  + Added documents.content\n";
    } else {
        echo "  = documents.content already present (no-op)\n";
    }

    $hasCode = $pdo->query("SHOW COLUMNS FROM documents LIKE 'document_code'")->fetch();
    if (!$hasCode) {
        $pdo->exec("ALTER TABLE documents ADD COLUMN document_code VARCHAR(50) NULL UNIQUE AFTER file_type");
        echo "  + Added documents.document_code (UNIQUE)\n";
    } else {
        echo "  = documents.document_code already present (no-op)\n";
    }

    echo "Migration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
