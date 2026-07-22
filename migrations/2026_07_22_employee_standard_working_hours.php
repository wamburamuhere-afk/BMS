<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: employees.standard_working_hours...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM employees LIKE 'standard_working_hours'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        echo "employees.standard_working_hours already exists — skipping.\n";
    } else {
        // The employee's own contracted hours per day, set at registration — overtime is
        // measured against this instead of one company-wide constant, since part-time and
        // full-time employees don't share the same expected shift length. Defaults to 8 so
        // every existing employee behaves exactly as before until someone edits their profile.
        $pdo->exec("
            ALTER TABLE employees
            ADD COLUMN standard_working_hours DECIMAL(4,2) NOT NULL DEFAULT 8.00
                AFTER hourly_rate
        ");
        echo "Added employees.standard_working_hours (default 8.00).\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
