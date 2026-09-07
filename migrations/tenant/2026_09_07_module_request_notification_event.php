<?php
/**
 * migrations/tenant/2026_09_07_module_request_notification_event.php
 *
 * Registers the 'module_request_decided' event_key (tenant_module_control_
 * plan.md, Phase C) so core/module_requests.php's decideModuleRequest() can
 * notify a company's admins via the existing dispatchEvent() engine.
 *
 * page_key = 'available_modules' deliberately has NO row in `permissions` —
 * it doesn't need one. usersWithPermission() (core/notify.php) resolves every
 * admin unconditionally (role_id=1 / is_admin=1), regardless of whether the
 * page_key it's asked about exists as a real permission row, which is exactly
 * who this event is for: the company's own admin(s), not a role-configurable
 * audience. Purely additive (INSERT IGNORE-equivalent), applied to every
 * tenant database by core/tenant_migration_runner.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: module_request_decided notification event...\n";

try {
    $exists = $pdo->prepare("SELECT 1 FROM notification_events WHERE event_key = 'module_request_decided'");
    $exists->execute();
    if (!$exists->fetchColumn()) {
        $pdo->prepare("
            INSERT INTO notification_events
                (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware, is_active, created_at)
            VALUES ('module_request_decided', 'Module request approved or declined',
                    'Notifies company admins when a superadmin decides a module access request.',
                    'Settings', 'available_modules', 'view', 'high', 0, 1, NOW())
        ")->execute();
        echo "  + notification_events 'module_request_decided' seeded.\n";
    } else {
        echo "  · notification_events 'module_request_decided' already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
