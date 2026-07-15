<?php
/**
 * 2026_07_15_document_templates_structure.php
 * ------------------------------------------------------
 * A letter template previously stored only its body (document_templates.content).
 * Saving a carefully-built letter as a template therefore threw away its
 * subject, recipient block, letterhead choice and signature alignment — reused
 * later, it came back as a bare body blob.
 *
 * Adds the structural columns so "Save as Template" captures the WHOLE letter
 * and "Use Template" reproduces it. All nullable/defaulted — existing templates
 * keep working unchanged (they simply carry NULL structure, treated as "no
 * override", so nothing about today's behaviour changes for them).
 *
 * SAFE TO RE-RUN — each column is guarded by SHOW COLUMNS.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add structure columns to document_templates...\n";

$columns = [
    'subject'           => "ADD COLUMN subject VARCHAR(255) NULL AFTER template_name",
    'recipient'         => "ADD COLUMN recipient VARCHAR(255) NULL AFTER subject",
    'recipient_address' => "ADD COLUMN recipient_address TEXT NULL AFTER recipient",
    'use_letterhead'    => "ADD COLUMN use_letterhead TINYINT(1) NULL DEFAULT NULL AFTER recipient_address",
    'signature_align'   => "ADD COLUMN signature_align VARCHAR(10) NULL DEFAULT NULL AFTER use_letterhead",
];

try {
    foreach ($columns as $col => $ddl) {
        $exists = $pdo->query("SHOW COLUMNS FROM document_templates LIKE " . $pdo->quote($col))->fetch();
        if ($exists) {
            echo "  ~ document_templates.$col already exists — skipped.\n";
        } else {
            $pdo->exec("ALTER TABLE document_templates $ddl");
            echo "  + Added document_templates.$col.\n";
        }
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
