<?php
/**
 * migrations/2026_11_07_invoice_efd_receipts.php
 *
 * BJP's own (root / legacy) database: EFD / VFD receipts staff attach to an invoice, shown
 * to the company on its Billing page (Receipts tab). Body: cdEfdEnsureSchema() in
 * core/customer_documents.php. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/customer_documents.php';
global $pdo;

echo "Starting migration: invoice EFD/VFD receipts...\n";

try {
    cdEfdEnsureSchema($pdo);
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
