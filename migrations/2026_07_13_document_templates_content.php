<?php
/**
 * 2026_07_13_document_templates_content.php
 * -------------------------------------------
 * "Template-driven creation" for Create Document — lets a user start a new
 * letter from a saved template's rich-text body instead of a blank page.
 *
 * Adds one nullable column to the existing `document_templates` table:
 *   content — the template's HTML body (Summernote output). Existing rows
 *             (all currently file-based: uploaded PDFs like loan agreements,
 *             offer letters) keep content NULL and are unaffected — the new
 *             "Use Template" picker on create_document.php only lists rows
 *             WHERE content IS NOT NULL, so the two template "flavors"
 *             (file-based vs. content-based) coexist in one table without
 *             interfering with each other.
 *
 * Idempotent (checks SHOW COLUMNS before the ALTER).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: document_templates.content...\n";

try {
    $hasContent = $pdo->query("SHOW COLUMNS FROM document_templates LIKE 'content'")->fetch();
    if (!$hasContent) {
        $pdo->exec("ALTER TABLE document_templates ADD COLUMN content LONGTEXT NULL AFTER description");
        echo "  + Added document_templates.content\n";
    } else {
        echo "  = document_templates.content already present (no-op)\n";
    }

    echo "Migration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
