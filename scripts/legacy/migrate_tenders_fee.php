<?php
require 'roots.php';
global $pdo;

try {
    $pdo->exec("ALTER TABLE tenders ADD COLUMN participation_fee_document VARCHAR(255) DEFAULT NULL AFTER participation_fee_amount");
    echo "Added column: participation_fee_document\n";
} catch (Exception $e) {
    echo "Error or Column already exists: " . $e->getMessage() . "\n";
}

try {
    // Add columns for submission documents if they don't exist
    $pdo->exec("ALTER TABLE tenders ADD COLUMN submission_document_tzs VARCHAR(255) DEFAULT NULL AFTER tender_amount_tzs");
    $pdo->exec("ALTER TABLE tenders ADD COLUMN submission_document_usd VARCHAR(255) DEFAULT NULL AFTER tender_amount_usd");
    echo "Added submission document columns\n";
} catch (Exception $e) { }

?>
