<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: HR contract auto-close notification event...\n";

try {
    // notification_events row for the check_hr_expiry.php auto-close cascade
    // (contract end_date already passed, nobody manually terminated it).
    $seed = $pdo->prepare("
        INSERT IGNORE INTO notification_events
            (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $seed->execute([
        'hr_contract_expired_autoclosed',
        'Employee contract auto-closed',
        'An employee contract passed its end date with no renewal and was automatically closed, possibly deactivating the employee',
        'Human Resources', 'hr_expiry_alerts', 'view', 'high', 0,
    ]);
    echo "  + notification_events seeded (" . $seed->rowCount() . " new).\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
