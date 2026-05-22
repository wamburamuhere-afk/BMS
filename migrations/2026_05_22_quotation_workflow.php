<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: quotation approval workflow (pending -> reviewed -> approved)...\n";

try {
    // Add a column to quotations only when it is missing (idempotent).
    $addCol = function (string $name, string $ddl) use ($pdo) {
        $exists = $pdo->query("SHOW COLUMNS FROM quotations LIKE '$name'")->fetch();
        if (!$exists) {
            $pdo->exec("ALTER TABLE quotations ADD COLUMN $ddl");
            echo "Added column $name to quotations.\n";
        } else {
            echo "Column $name already present.\n";
        }
    };

    $addCol('reviewed_by',        'reviewed_by INT NULL');
    $addCol('reviewed_at',        'reviewed_at DATETIME NULL');
    $addCol('approved_by',        'approved_by INT NULL');
    $addCol('approved_at',        'approved_at DATETIME NULL');
    $addCol('converted_to_so_id', 'converted_to_so_id INT NULL');

    // The status column is an ENUM that does not yet include 'reviewed'.
    $statusCol = $pdo->query("SHOW COLUMNS FROM quotations LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($statusCol && strpos($statusCol['Type'], "'reviewed'") === false) {
        $pdo->exec("
            ALTER TABLE quotations MODIFY COLUMN status
            ENUM('draft','pending','reviewed','approved','processing','shipped','delivered','completed','cancelled')
            NULL DEFAULT 'pending'
        ");
        echo "Added 'reviewed' to the quotations.status ENUM (default is now 'pending').\n";
    } else {
        echo "status ENUM already supports 'reviewed'.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
