<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: external-signer support on document_signatures...\n";

try {
    // requested_by / signed_by already exist and already distinguish "who
    // requested" from "who signs" — but both are strictly int FKs to the
    // internal `users` table. These three columns are what's actually
    // missing: capturing a signer who has no user account at all.
    $columns = [
        'signer_name'  => "VARCHAR(150) NULL AFTER signed_by",
        'signer_email' => "VARCHAR(255) NULL AFTER signer_name",
        'signer_type'  => "ENUM('internal','external') NOT NULL DEFAULT 'internal' AFTER signer_email",
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

    // Single-use, expiring public link tokens — same convention as
    // csrf_token(): bin2hex(random_bytes(32)), only the hash is stored.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS document_signature_tokens (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            signature_id INT          NOT NULL,
            token_hash   VARCHAR(64)  NOT NULL,
            expires_at   DATETIME     NOT NULL,
            used_at      DATETIME     NULL,
            created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_signature_id (signature_id),
            UNIQUE KEY uniq_token_hash (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Table document_signature_tokens created (or already exists).\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
