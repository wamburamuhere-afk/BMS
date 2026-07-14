<?php
/**
 * 2026_07_14_documents_signature_align.php
 * ------------------------------------------
 * "Create Document" feature (Phase 3) — adds `signature_align` to the
 * `documents` table so the signature block's horizontal position (left,
 * center, or right) is a per-letter choice instead of hardcoded. Formal
 * letter styles genuinely differ here (full-block vs modified-block), so
 * this must stay user-selectable rather than fixed.
 *
 * Idempotent (checks SHOW COLUMNS before the ALTER).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: documents.signature_align...\n";

try {
    $hasCol = $pdo->query("SHOW COLUMNS FROM documents LIKE 'signature_align'")->fetch();
    if (!$hasCol) {
        $pdo->exec("ALTER TABLE documents ADD COLUMN signature_align VARCHAR(10) NOT NULL DEFAULT 'left' AFTER recipient_address");
        echo "  + Added documents.signature_align\n";
    } else {
        echo "  = documents.signature_align already present (no-op)\n";
    }

    echo "Migration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
