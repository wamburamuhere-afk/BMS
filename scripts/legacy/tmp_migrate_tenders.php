<?php
require 'includes/config.php';

try {
    // 1. Update existing statuses to uppercase for consistency
    $pdo->exec("UPDATE tenders SET status = 'PENDING' WHERE status = 'pending'");
    $pdo->exec("UPDATE tenders SET status = 'SUBMISSION' WHERE status = 'submitted'");
    $pdo->exec("UPDATE tenders SET status = 'AWARDED' WHERE status = 'awarded'");
    $pdo->exec("UPDATE tenders SET status = 'LOSS' WHERE status = 'lost'");

    // 2. Modify enum column
    $pdo->exec("ALTER TABLE tenders MODIFY COLUMN status ENUM('PENDING','INVITATION','SUBMISSION','OPENING','EVALUATION','POST-QUALIFICATION','NEGOTIATION','AWARDED','LOSS','END TENDER','cancelled') DEFAULT 'PENDING'");

    // 3. Add new columns for the workflow
    $columns = [
        'opening_rates' => "DECIMAL(20,2) NULL",
        'opening_document' => "VARCHAR(255) NULL",
        'evaluation_document' => "VARCHAR(255) NULL",
        'post_qualification_document' => "VARCHAR(255) NULL",
        'award_letter_document' => "VARCHAR(255) NULL",
        'loss_reason' => "TEXT NULL",
        'tender_sum' => "DECIMAL(20,2) NULL",
        'award_date' => "DATE NULL"
    ];

    foreach ($columns as $col => $type) {
        // Check if column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM tenders LIKE '$col'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE tenders ADD COLUMN $col $type");
            echo "Added column $col\n";
        }
    }

    echo "Migration completed successfully.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
