<?php
/**
 * migrations/tenant/2026_10_16_subscription_request_notification.php
 *
 * Seeds the 'subscription.request_received' notification event: a company chose a
 * subscription period in My Subscription and an approved quotation was created
 * (customer_subscription_plan.md Phase 5). Goes to users who can create invoices.
 * Only BJP's own install ever fires it. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting migration: seed subscription.request_received notification event...\n";
try {
    $exists = $pdo->prepare("SELECT 1 FROM notification_events WHERE event_key = ?");
    $exists->execute(['subscription.request_received']);
    if ($exists->fetchColumn()) {
        echo "  · already present.\n";
    } else {
        $pdo->prepare("
            INSERT INTO notification_events (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware, is_active, created_at)
            VALUES ('subscription.request_received', 'Subscription request received',
                    'A company chose a BMS subscription period in My Subscription — an approved quotation is ready to invoice.',
                    'Sales', 'invoices', 'create', 'medium', 0, 1, NOW())
        ")->execute();
        echo "  + seeded.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
