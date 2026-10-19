<?php
/**
 * migrations/tenant/2026_10_19_clean_uploaded_signatures.php
 *
 * Uploaded signature images kept their paper (grey, cream, shadows), which then
 * showed as a coloured box on every procurement/sales document that prints the
 * signer's signature. New uploads are now cleaned in upload_signature.php; this
 * cleans the ones already stored (core/signature_cleaner.php → transparent PNG,
 * paper removed, ink kept). Drawn/typed signatures are not touched.
 *
 * Idempotent: each original is kept beside it as <name>.orig.<ext> and a
 * signature with a backup is skipped. Images that are not signatures are left
 * as they are and listed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/signature_cleaner.php';
global $pdo;

echo "Starting tenant migration: clean uploaded signatures...\n";
try {
    if (!(bool)$pdo->query("SHOW TABLES LIKE 'user_signatures'")->fetchColumn()) {
        echo "  · no user_signatures table — nothing to do.\n";
    } elseif (!sigCleanerSupported()) {
        echo "  ! GD is not available — signatures left as they are.\n";
    } else {
        $r = sigCleanStoredUploads($pdo, dirname(__DIR__, 2));
        echo "  + cleaned {$r['cleaned']}, already clean {$r['skipped']}, file missing {$r['missing']}.\n";
        foreach ($r['refused'] as $id => $why) echo "  · signature #$id left as is: $why\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
