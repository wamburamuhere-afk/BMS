<?php
/**
 * 2026_06_28_notification_engine_foundation.php
 * ---------------------------------------------------------------------------
 * Phase 2 of the Smart Notification & Workflow Engine.
 *
 * Adds the foundational data model used by core/notify.php:
 *   - notifications.event_key / category          (extend existing table, additive)
 *   - notification_events    (the event catalog: event_key -> page_key/verb/...)
 *   - notification_dedupe    (generalized "fire once" guard, like document_expiry_reminders)
 *   - notification_log       (audit of every dispatch/delivery)
 *
 * Additive & idempotent. No DDL transactions (MySQL auto-commits DDL).
 * Routing tables (notification_rules) and the email outbox come in later phases.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: notification engine foundation...\n";

try {
    // ── 1. notifications: add event_key + category (additive) ───────────────
    if (!$pdo->query("SHOW COLUMNS FROM notifications LIKE 'event_key'")->fetch()) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN event_key VARCHAR(80) NULL AFTER type");
        echo "  + notifications.event_key added.\n";
    } else {
        echo "  · notifications.event_key already present.\n";
    }
    if (!$pdo->query("SHOW COLUMNS FROM notifications LIKE 'category'")->fetch()) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN category VARCHAR(40) NULL AFTER event_key");
        echo "  + notifications.category added.\n";
    } else {
        echo "  · notifications.category already present.\n";
    }
    // Helpful index for the engine's lookups (ignore if it already exists).
    if (!$pdo->query("SHOW INDEX FROM notifications WHERE Key_name = 'idx_notif_event'")->fetch()) {
        try { $pdo->exec("ALTER TABLE notifications ADD INDEX idx_notif_event (event_key)"); echo "  + index idx_notif_event added.\n"; }
        catch (PDOException $e) { echo "  · index idx_notif_event skipped: " . $e->getMessage() . "\n"; }
    }

    // ── 2. notification_events — the catalog ────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notification_events (
            event_key        VARCHAR(80)  NOT NULL,
            title            VARCHAR(150) NOT NULL,
            description      VARCHAR(255) NULL,
            module           VARCHAR(50)  NULL,
            page_key         VARCHAR(100) NOT NULL,
            required_verb    VARCHAR(20)  NOT NULL DEFAULT 'view',
            default_severity VARCHAR(10)  NOT NULL DEFAULT 'medium',
            scope_aware      TINYINT(1)   NOT NULL DEFAULT 0,
            is_active        TINYINT(1)   NOT NULL DEFAULT 1,
            created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (event_key),
            KEY idx_ne_page (page_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table notification_events ensured.\n";

    // ── 3. notification_dedupe — generalized fire-once guard ────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notification_dedupe (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            dedupe_key  VARCHAR(191) NOT NULL,
            created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dedupe (dedupe_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table notification_dedupe ensured.\n";

    // ── 4. notification_log — audit of dispatch/delivery ────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notification_log (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            event_key         VARCHAR(80)  NULL,
            recipient_user_id INT          NULL,
            channel           VARCHAR(20)  NOT NULL DEFAULT 'inapp',
            status            VARCHAR(20)  NOT NULL DEFAULT 'created',
            entity_type       VARCHAR(50)  NULL,
            entity_id         INT          NULL,
            detail            VARCHAR(255) NULL,
            created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_nl_event (event_key),
            KEY idx_nl_user (recipient_user_id),
            KEY idx_nl_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table notification_log ensured.\n";

    // ── 5. Seed a starter event catalog (idempotent) ────────────────────────
    // Format: event_key, title, description, module, page_key, verb, severity, scope_aware
    $events = [
        ['invoice.overdue',        'Invoice overdue',           'A customer invoice has passed its due date',     'Finance',     'invoices',          'view',    'high',   1],
        ['invoice.needs_review',   'Invoice awaiting review',   'A new invoice needs review/approval',            'Finance',     'invoices',          'review',  'medium', 1],
        ['po.needs_approval',      'Purchase order to approve', 'A purchase order is awaiting approval',          'Procurement', 'purchase_orders',   'approve', 'medium', 1],
        ['grn.pending',           'GRN pending against PO',    'Goods received pending GRN/approval',            'Procurement', 'grn',               'view',    'medium', 1],
        ['quotation.expiring',     'Quotation expiring',        'A quotation is about to expire',                 'Sales',       'quotations',        'view',    'medium', 1],
        ['purchase_return.pending','Purchase return pending',   'A purchase return needs attention',              'Procurement', 'purchase_returns',  'view',    'medium', 1],
        ['sales_return.pending',   'Sales return pending',      'A sales return needs attention',                 'Sales',       'sales_returns',     'view',    'medium', 1],
        ['debit_note.pending',     'Debit note pending',        'A debit note needs attention',                   'Procurement', 'debit_notes',       'view',    'medium', 1],
        ['credit_note.pending',    'Credit note pending',       'A credit note needs attention',                  'Sales',       'credit_notes',      'view',    'medium', 1],
        ['voucher.needs_approval', 'Payment voucher to approve','A payment voucher is awaiting approval',         'Finance',     'payment_vouchers',  'approve', 'medium', 1],
        ['expense.needs_review',   'Expense awaiting review',   'An expense needs review/approval',               'Finance',     'expenses',          'review',  'medium', 1],
        ['tender.deadline',        'Tender deadline approaching','A tender submission deadline is near',          'Tenders',     'tenders',           'view',    'high',   1],
        ['document.expiring',      'Document expiring',         'A tracked document is nearing its expiry',       'Documents',   'document_expiry_alerts','view', 'high',   0],
    ];
    $seed = $pdo->prepare("
        INSERT IGNORE INTO notification_events
            (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $seeded = 0;
    foreach ($events as $e) { $seed->execute($e); $seeded += $seed->rowCount(); }
    echo "  + notification_events seeded ({$seeded} new of " . count($events) . ").\n";

    // ── 6. Master switch setting (default ON for in-app; email gated later) ──
    $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_group, description, updated_at)
                   VALUES ('notif_master_enabled', '1', 'notifications', 'Master on/off for the smart notification engine', NOW())")
        ->execute();
    echo "  + setting notif_master_enabled ensured (=1).\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
