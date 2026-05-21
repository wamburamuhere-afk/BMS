<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: document expiry tracking (dates, notifications, dedup, permission)...\n";

try {
    // 1. documents.issue_date
    $col = $pdo->query("SHOW COLUMNS FROM documents LIKE 'issue_date'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE documents ADD COLUMN issue_date DATE NULL DEFAULT NULL AFTER version");
        echo "Column documents.issue_date added.\n";
    } else {
        echo "Column documents.issue_date already exists, skipping.\n";
    }

    // 2. documents.expire_date
    $col = $pdo->query("SHOW COLUMNS FROM documents LIKE 'expire_date'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE documents ADD COLUMN expire_date DATE NULL DEFAULT NULL AFTER issue_date");
        echo "Column documents.expire_date added.\n";
    } else {
        echo "Column documents.expire_date already exists, skipping.\n";
    }

    // 3. notifications.document_id — links an alert back to a document
    //    (mirrors the existing optional loan_id / customer_id columns)
    $col = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'document_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN document_id INT NULL DEFAULT NULL AFTER customer_id");
        echo "Column notifications.document_id added.\n";
    } else {
        echo "Column notifications.document_id already exists, skipping.\n";
    }

    // 4. document_expiry_reminders — dedup table so a milestone fires only once per document
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS document_expiry_reminders (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            milestone   INT NOT NULL,
            sent_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_doc_milestone (document_id, milestone),
            KEY idx_document_id (document_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "Table document_expiry_reminders ready.\n";

    // 5. RBAC permission row — drives who receives the expiry notifications.
    //    Appears automatically in user_roles.php under the "Documents" module.
    //    Ticking the VIEW checkbox for a role makes its users receive the alerts.
    $stmt = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = 'document_expiry_alerts'");
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        $pdo->prepare("
            INSERT INTO permissions (permission_name, page_key, page_name, description, module_name, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([
            '',
            'document_expiry_alerts',
            'Document Expiry Alerts',
            'Tick VIEW to let this role receive notifications when documents are nearing their expiry date.',
            'Documents'
        ]);
        echo "Permission 'document_expiry_alerts' added.\n";
    } else {
        echo "Permission 'document_expiry_alerts' already exists, skipping.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
