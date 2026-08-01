<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../core/permissions.php';

// Phase 2 of security_implementation_plan.md — previously any logged-in
// user could open this page and change notification settings.
autoEnforcePermission('notification_settings');

// Strictly admin-only by request (2026-07-31) — consolidated inside
// notification_rules.php (Settings > Admin > Notification Rules) as a
// collapsible panel, no longer delegable via Roles & Permissions no
// matter what is granted (permission row hidden from that UI). This
// standalone URL still works for admins, mirroring notification_rules.php.
if (!isAdmin()) {
    header('Location: ' . getUrl('unauthorized'));
    exit;
}

require_once __DIR__ . '/../../../header.php';
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-bell"></i> Notification Settings</h2>
            <p class="text-muted">Configure system notifications, alerts, and communication templates</p>
        </div>
    </div>

    <?php require __DIR__ . '/_notification_settings_panel.php'; ?>
</div>

<?php require_once __DIR__ . '/../../../footer.php'; ?>
<?php ob_end_flush(); ?>
