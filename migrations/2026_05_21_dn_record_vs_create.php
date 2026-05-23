<?php
// Migration: split Delivery Notes into Record (inbound) vs Create (outbound)
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: DN record vs create (inbound/outbound)...\n";

try {
    // 1. dn_type — inbound = Record DN (from supplier/sub-contractor),
    //              outbound = Create DN (to supplier/sub-contractor)
    $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'dn_type'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE deliveries ADD COLUMN dn_type ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound' AFTER do_id");
        echo "Column dn_type added (existing rows default to 'inbound').\n";
    } else {
        echo "Column dn_type already exists — skipping.\n";
    }

    // 2. party_type — whether the counterparty is a supplier or a sub-contractor
    $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'party_type'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE deliveries ADD COLUMN party_type ENUM('supplier','subcontractor') NOT NULL DEFAULT 'supplier' AFTER supplier_id");
        echo "Column party_type added.\n";
    } else {
        echo "Column party_type already exists — skipping.\n";
    }

    // 3. subcontractor_id — used when party_type = 'subcontractor'
    $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'subcontractor_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE deliveries ADD COLUMN subcontractor_id INT NULL DEFAULT NULL AFTER party_type");
        echo "Column subcontractor_id added.\n";
    } else {
        echo "Column subcontractor_id already exists — skipping.\n";
    }

    // 4. delivery_attachments — supplier DN scans for Record DN (create if missing)
    $tbl = $pdo->query("SHOW TABLES LIKE 'delivery_attachments'")->fetch();
    if (!$tbl) {
        $pdo->exec("CREATE TABLE delivery_attachments (
            attachment_id INT AUTO_INCREMENT PRIMARY KEY,
            delivery_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_type VARCHAR(100) NULL,
            file_size INT NULL,
            uploaded_by INT NULL,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_delivery (delivery_id)
        ) ENGINE=InnoDB");
        echo "Table delivery_attachments created.\n";
    } else {
        echo "Table delivery_attachments already exists — skipping.\n";
    }

    // 5. Upload folder for supplier DN scans, with PHP-execution guard (.htaccess)
    $dir = __DIR__ . '/../uploads/deliveries';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "Created uploads/deliveries/.\n";
    } else {
        echo "Folder uploads/deliveries/ already exists — skipping.\n";
    }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht,
            "<FilesMatch \"\\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n" .
            "    Require all denied\n" .
            "</FilesMatch>\n" .
            "Options -ExecCGI\n" .
            "RemoveHandler .php .phtml .php5\n" .
            "RemoveType .php .phtml .php5\n"
        );
        echo "Created uploads/deliveries/.htaccess.\n";
    } else {
        echo ".htaccess already exists — skipping.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
