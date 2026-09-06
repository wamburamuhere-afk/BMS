<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: tender Form of Tender auto-draft...\n";

try {
    $cols = [
        // NULL until first drafted/saved — distinguishes "never opened this
        // tab" from "user cleared the letter", though the UI never actually
        // offers the latter.
        'form_of_tender_html' => "MEDIUMTEXT NULL DEFAULT NULL",
        'form_of_tender_date' => "DATE NULL DEFAULT NULL",
        // Facile's "Bid Validity (days)" field, defaulting the same way
        // (90) — BMS's tenders table had no equivalent column at all before
        // this phase needed one to draft the validity-period paragraph.
        'bid_validity_days'   => "INT NOT NULL DEFAULT 90",
    ];
    foreach ($cols as $col => $def) {
        $check = $pdo->query("SHOW COLUMNS FROM tenders LIKE '$col'")->fetch();
        if (!$check) {
            $pdo->exec("ALTER TABLE tenders ADD COLUMN $col $def");
            echo "  - tenders.$col added.\n";
        } else {
            echo "  - tenders.$col already present, skipped.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
