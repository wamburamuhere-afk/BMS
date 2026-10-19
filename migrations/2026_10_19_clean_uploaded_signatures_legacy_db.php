<?php
/**
 * migrations/2026_10_19_clean_uploaded_signatures_legacy_db.php
 *
 * Legacy-DB mirror of migrations/tenant/2026_10_19_clean_uploaded_signatures.php:
 * cleans every uploaded signature already stored (paper removed, ink kept,
 * transparent PNG). Idempotent — originals kept as <name>.orig.<ext>.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/signature_cleaner.php';
global $pdo;

echo "Starting migration: clean uploaded signatures...\n";
try {
    if (!(bool)$pdo->query("SHOW TABLES LIKE 'user_signatures'")->fetchColumn()) {
        echo "  · no user_signatures table — nothing to do.\n";
    } elseif (!sigCleanerSupported()) {
        echo "  ! GD is not available — signatures left as they are.\n";
    } else {
        $r = sigCleanStoredUploads($pdo, ROOT_DIR);
        echo "  + cleaned {$r['cleaned']}, already clean {$r['skipped']}, file missing {$r['missing']}.\n";
        foreach ($r['refused'] as $id => $why) echo "  · signature #$id left as is: $why\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
