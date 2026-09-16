<?php
/**
 * migrations/2026_09_16_pos_credit_due_notifications_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_16_pos_credit_due_notifications.php onto
 * the LEGACY / non-tenant database. See
 * migrations/2026_09_10_pos_product_professional_fields_legacy_db.php for
 * why this mirror exists.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: seed pos_credit.due_soon / pos_credit.overdue notification events on the legacy database...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'notification_events'")->fetch()) {
        echo "  notification_events table not present — notification engine not installed here, skipping.\n";
        exit(0);
    }

    $events = [
        ['pos_credit.due_soon', 'Credit sale due soon', 'A POS credit sale is due for repayment within 3 days, or is due today.', 'medium'],
        ['pos_credit.overdue',  'Credit sale overdue',   'A POS credit sale is past its due date and still not fully repaid.', 'high'],
    ];

    $exists = $pdo->prepare("SELECT 1 FROM notification_events WHERE event_key = ?");
    $insert = $pdo->prepare("
        INSERT INTO notification_events (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware, is_active, created_at)
        VALUES (?, ?, ?, 'POS', 'pos', 'view', ?, 1, 1, NOW())
    ");

    foreach ($events as [$key, $title, $desc, $severity]) {
        $exists->execute([$key]);
        if ($exists->fetchColumn()) {
            echo "  · notification_events row '{$key}' already present.\n";
            continue;
        }
        $insert->execute([$key, $title, $desc, $severity]);
        echo "  + notification_events row '{$key}' seeded.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
