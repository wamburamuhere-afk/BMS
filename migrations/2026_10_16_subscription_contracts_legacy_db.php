<?php
/**
 * migrations/2026_10_16_subscription_contracts_legacy_db.php
 *
 * Subscription contracts (customer_subscription_plan.md Phase 6): customer_contracts,
 * vendor_subscription_services.contract_template_id, and the 'subscription.contract_ready'
 * notification event (to users who can create documents). Only BJP's install uses them.
 * Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/subscription_contracts.php';
global $pdo;

echo "Starting migration: subscription_contracts...\n";
try {
    scEnsureSchema($pdo);
    $ex = $pdo->prepare("SELECT 1 FROM notification_events WHERE event_key = 'subscription.contract_ready'");
    $ex->execute();
    if (!$ex->fetchColumn()) {
        $pdo->exec("INSERT INTO notification_events (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware, is_active, created_at)
                    VALUES ('subscription.contract_ready', 'Subscription contract to sign',
                            'A subscription invoice was paid and its contract is ready for BJP to sign.',
                            'Documents', 'documents', 'create', 'medium', 0, 1, NOW())");
        echo "  + notification event seeded\n";
    }
    echo "Migration complete: subscription_contracts.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
