<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: register received_invoices permission...\n";

try {
    $existing = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
    $existing->execute(['received_invoices']);

    if (!$existing->fetch()) {
        $pdo->prepare("
            INSERT INTO permissions (page_key, permission_name, module_name)
            VALUES (?, ?, ?)
        ")->execute(['received_invoices', 'Received Invoices', 'Finance']);
        echo "Permission row inserted.\n";
    } else {
        echo "Permission row already exists, skipping.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
