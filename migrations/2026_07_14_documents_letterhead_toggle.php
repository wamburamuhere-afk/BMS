<?php
/**
 * 2026_07_14_documents_letterhead_toggle.php
 * -------------------------------------------
 * "Create Document" feature (Phase 2) — adds two nullable/defaulted columns to
 * the existing `documents` table:
 *
 *   use_letterhead    — whether the company header + footer (logo, name,
 *                        address/contact block) print on this letter.
 *                        Defaults to 1 (on) so existing/new letters keep
 *                        today's behaviour unless a user explicitly turns it
 *                        off — e.g. for a letter printed on physical
 *                        pre-printed letterhead paper, where re-printing the
 *                        header/footer digitally would duplicate it.
 *   recipient_address — optional multi-line postal address for the
 *                        recipient, shown under the recipient name only when
 *                        filled in. Not every letter type needs a full
 *                        address block (a quick internal memo doesn't), so
 *                        this stays NULL/empty unless the user writes one.
 *
 * Idempotent (checks SHOW COLUMNS before each ALTER).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: documents.use_letterhead + documents.recipient_address...\n";

try {
    $hasLetterhead = $pdo->query("SHOW COLUMNS FROM documents LIKE 'use_letterhead'")->fetch();
    if (!$hasLetterhead) {
        $pdo->exec("ALTER TABLE documents ADD COLUMN use_letterhead TINYINT(1) NOT NULL DEFAULT 1 AFTER content");
        echo "  + Added documents.use_letterhead\n";
    } else {
        echo "  = documents.use_letterhead already present (no-op)\n";
    }

    $hasRecipientAddress = $pdo->query("SHOW COLUMNS FROM documents LIKE 'recipient_address'")->fetch();
    if (!$hasRecipientAddress) {
        $pdo->exec("ALTER TABLE documents ADD COLUMN recipient_address TEXT NULL AFTER use_letterhead");
        echo "  + Added documents.recipient_address\n";
    } else {
        echo "  = documents.recipient_address already present (no-op)\n";
    }

    echo "Migration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
