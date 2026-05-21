<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: e-signature audit & integrity columns on document_signatures...\n";

try {
    // Columns to add — name => column definition
    $columns = [
        'hash_algorithm'      => "VARCHAR(20)  NULL AFTER signature_position",
        'hash_before'         => "VARCHAR(64)  NULL AFTER hash_algorithm",
        'hash_after'          => "VARCHAR(64)  NULL AFTER hash_before",
        'signing_reference'   => "VARCHAR(64)  NULL AFTER hash_after",
        'signed_document_id'  => "INT          NULL AFTER signing_reference",
        'user_agent'          => "VARCHAR(255) NULL AFTER ip_address",
        'consent_text'        => "TEXT         NULL AFTER user_agent",
        'consent_accepted_at' => "TIMESTAMP    NULL AFTER consent_text",
        'event_log'           => "TEXT         NULL AFTER consent_accepted_at",
    ];

    foreach ($columns as $name => $definition) {
        $exists = $pdo->query("SHOW COLUMNS FROM document_signatures LIKE " . $pdo->quote($name))->fetch();
        if ($exists) {
            echo "  - column '$name' already exists, skipping.\n";
            continue;
        }
        $pdo->exec("ALTER TABLE document_signatures ADD COLUMN $name $definition");
        echo "  + column '$name' added.\n";
    }

    // Index for the Verify endpoint lookup (signed document -> signature record)
    $idx = $pdo->query("SHOW INDEX FROM document_signatures WHERE Key_name = 'idx_signed_document_id'")->fetch();
    if (!$idx) {
        $pdo->exec("ALTER TABLE document_signatures ADD INDEX idx_signed_document_id (signed_document_id)");
        echo "  + index 'idx_signed_document_id' added.\n";
    } else {
        echo "  - index 'idx_signed_document_id' already exists, skipping.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
