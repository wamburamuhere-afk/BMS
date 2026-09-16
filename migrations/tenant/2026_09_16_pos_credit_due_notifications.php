<?php
/**
 * migrations/tenant/2026_09_16_pos_credit_due_notifications.php
 *
 * pos_credit_receivables_plan.md Phase 4 — registers the two events that
 * cron/run_notification_checks.php's new "POS credit due-date reminders"
 * block dispatches through: coming due (3-days-before / on the due date) and
 * overdue (day it goes overdue, then every 7 days while still open). Who
 * actually receives them is entirely governed by the existing
 * notification_rules admin settings page — this migration only makes the
 * two events selectable there, same seeding pattern as every other
 * dispatchEvent() event in this codebase (e.g. restaurant.reservation_upcoming).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: seed pos_credit.due_soon / pos_credit.overdue notification events...\n";

try {
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
