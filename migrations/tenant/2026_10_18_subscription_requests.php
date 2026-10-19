<?php
/**
 * migrations/tenant/2026_10_18_subscription_requests.php
 *
 * A company's Subscription request is now kept as a request (subscription_requests)
 * that BJP staff turn into a quotation from Quotations — no quotation is created
 * automatically any more. Creates the table, and points the
 * 'subscription.request_received' notification at quotation makers (it went to
 * invoice makers) with the new wording — only while it still has the original
 * seeded wording, so an event BJP already edited is left alone. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/subscription_requests.php';
global $pdo;

echo "Starting migration: subscription_requests...\n";
try {
    srEnsureTable($pdo);
    echo "  + subscription_requests ready.\n";
    $n = $pdo->prepare("UPDATE notification_events
                           SET description = 'A company sent a BMS subscription request from its Subscription page — open Quotations → Requests from systems to prepare the quotation.',
                               page_key = 'quotations'
                         WHERE event_key = 'subscription.request_received'
                           AND description = 'A company chose a BMS subscription period in My Subscription — an approved quotation is ready to invoice.'");
    $n->execute();
    echo $n->rowCount() ? "  + notification event updated.\n" : "  · notification event already up to date (or edited).\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
