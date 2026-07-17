<?php
/**
 * 2026_07_17_document_comments_notes_assignees.php
 * --------------------------------------------------
 * Closes two gaps vs WorkDo's Documents module, found during gap analysis:
 *
 *   1. document_comments / document_notes — BMS had zero collaboration
 *      infrastructure on a document (no comments, no notes thread). WorkDo
 *      keeps these as two separate threads per document.
 *   2. document_assignees — documents.access_level was a bare enum
 *      (public/private/restricted) with no link to WHICH users a private or
 *      restricted document is visible to. WorkDo assigns private documents
 *      to specific named staff.
 *
 * All three tables are purely additive; nothing existing is touched.
 * No FK constraints (matches this repo's existing document_* tables), just
 * indexed document_id columns.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: document comments, notes, and assignees...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS document_comments (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT          NOT NULL,
            user_id     INT          NOT NULL,
            comment     TEXT         NOT NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_doc_comments_document (document_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + document_comments table ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS document_notes (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT          NOT NULL,
            user_id     INT          NOT NULL,
            note        TEXT         NOT NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_doc_notes_document (document_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + document_notes table ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS document_assignees (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT          NOT NULL,
            user_id     INT          NOT NULL,
            assigned_by INT          NULL,
            assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_doc_assignees_document (document_id),
            UNIQUE KEY uq_doc_assignee (document_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + document_assignees table ready.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
