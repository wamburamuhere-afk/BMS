<?php
/**
 * 2026_06_05_note_attachments.php
 * -------------------------------
 * Attachment storage for Credit Notes and Debit Notes, modelled exactly on the
 * GRN attachment table (purchase_receipt_attachments). Each row is one named
 * document + its uploaded file, attached to a note.
 *
 * Additive, idempotent (CREATE TABLE IF NOT EXISTS), no data touched.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: note attachment tables...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS debit_note_attachments (
            attachment_id  INT AUTO_INCREMENT PRIMARY KEY,
            debit_note_id  INT NOT NULL,
            file_name      VARCHAR(255) NOT NULL,
            file_path      VARCHAR(255) NOT NULL,
            file_type      VARCHAR(100) NULL,
            file_size      INT NULL,
            uploaded_by    INT NULL,
            uploaded_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            description    TEXT NULL,
            INDEX idx_dna_note (debit_note_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + debit_note_attachments table ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS credit_note_attachments (
            attachment_id   INT AUTO_INCREMENT PRIMARY KEY,
            credit_note_id  INT NOT NULL,
            file_name       VARCHAR(255) NOT NULL,
            file_path       VARCHAR(255) NOT NULL,
            file_type       VARCHAR(100) NULL,
            file_size       INT NULL,
            uploaded_by     INT NULL,
            uploaded_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            description     TEXT NULL,
            INDEX idx_cna_note (credit_note_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + credit_note_attachments table ready.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
