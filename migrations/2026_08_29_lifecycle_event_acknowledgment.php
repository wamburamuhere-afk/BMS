<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: employee_lifecycle_events acknowledgment...\n";

try {
    // Warnings and complaints are formal HR actions with real compliance weight,
    // but nothing recorded whether the employee was ever actually informed.
    // Announcements already have a read-receipt (announcement_reads); this gives
    // warnings/complaints the equivalent — an employee-initiated, timestamped
    // acknowledgment via their own My HR (ESS) page, never settable by HR itself.
    $col = $pdo->query("SHOW COLUMNS FROM employee_lifecycle_events LIKE 'acknowledged_at'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        echo "employee_lifecycle_events.acknowledged_at already exists — skipping.\n";
    } else {
        $pdo->exec("
            ALTER TABLE employee_lifecycle_events
            ADD COLUMN acknowledged_at DATETIME NULL AFTER effect_applied_at,
            ADD COLUMN acknowledgment_note VARCHAR(500) NULL AFTER acknowledged_at
        ");
        echo "Added employee_lifecycle_events.acknowledged_at + acknowledgment_note.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
