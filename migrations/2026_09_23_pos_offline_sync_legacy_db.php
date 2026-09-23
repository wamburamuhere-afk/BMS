<?php
/**
 * migrations/2026_09_23_pos_offline_sync_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_23_pos_offline_sync.php onto the legacy
 * (non-tenant) database. See 2026_09_08_pos_network_printer_legacy_db.php for
 * why this pairing exists.
 *
 * Degrades gracefully: if a table doesn't exist on the legacy database that is
 * outside this migration's job to create, it logs and skips rather than
 * blocking every other host's deploy.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS offline-sync columns on the legacy database...\n";

try {
    $hasSales = (bool)$pdo->query("SHOW TABLES LIKE 'pos_sales'")->fetch();
    if (!$hasSales) {
        echo "  · pos_sales does not exist on this database — skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // pos_sales.client_uuid
    if (!$pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'client_uuid'")->fetch()) {
        $pdo->exec("ALTER TABLE pos_sales ADD COLUMN client_uuid VARCHAR(36) NULL AFTER shift_id");
        echo "  + pos_sales.client_uuid added.\n";
    } else {
        echo "  · pos_sales.client_uuid already exists.\n";
    }
    $uxExists = $pdo->query(
        "SELECT 1 FROM information_schema.statistics
          WHERE table_schema = DATABASE()
            AND table_name   = 'pos_sales'
            AND index_name   = 'ux_pos_sales_client_uuid'
          LIMIT 1"
    )->fetch();
    if (!$uxExists) {
        try {
            $pdo->exec("ALTER TABLE pos_sales ADD UNIQUE KEY ux_pos_sales_client_uuid (client_uuid)");
            echo "  + ux_pos_sales_client_uuid unique index added.\n";
        } catch (PDOException $idxE) {
            echo "  ! Could not add ux_pos_sales_client_uuid: " . $idxE->getMessage() . " (skipped)\n";
        }
    } else {
        echo "  · ux_pos_sales_client_uuid already exists.\n";
    }

    // pos_sales.sold_at
    if (!$pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'sold_at'")->fetch()) {
        $pdo->exec("ALTER TABLE pos_sales ADD COLUMN sold_at DATETIME NULL AFTER client_uuid");
        echo "  + pos_sales.sold_at added.\n";
    } else {
        echo "  · pos_sales.sold_at already exists.\n";
    }

    // pos_sale_payments.client_uuid
    $hasPayTable = (bool)$pdo->query("SHOW TABLES LIKE 'pos_sale_payments'")->fetch();
    if ($hasPayTable) {
        if (!$pdo->query("SHOW COLUMNS FROM pos_sale_payments LIKE 'client_uuid'")->fetch()) {
            $pdo->exec("ALTER TABLE pos_sale_payments ADD COLUMN client_uuid VARCHAR(36) NULL AFTER notes");
            echo "  + pos_sale_payments.client_uuid added.\n";
        } else {
            echo "  · pos_sale_payments.client_uuid already exists.\n";
        }
        $uxPay = $pdo->query(
            "SELECT 1 FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name   = 'pos_sale_payments'
                AND index_name   = 'ux_pos_sale_payments_client_uuid'
              LIMIT 1"
        )->fetch();
        if (!$uxPay) {
            try {
                $pdo->exec("ALTER TABLE pos_sale_payments ADD UNIQUE KEY ux_pos_sale_payments_client_uuid (client_uuid)");
                echo "  + ux_pos_sale_payments_client_uuid unique index added.\n";
            } catch (PDOException $idxE) {
                echo "  ! Could not add ux_pos_sale_payments_client_uuid: " . $idxE->getMessage() . " (skipped)\n";
            }
        } else {
            echo "  · ux_pos_sale_payments_client_uuid already exists.\n";
        }
    }

    // cash_register_transactions.client_uuid
    $hasCrt = (bool)$pdo->query("SHOW TABLES LIKE 'cash_register_transactions'")->fetch();
    if ($hasCrt) {
        if (!$pdo->query("SHOW COLUMNS FROM cash_register_transactions LIKE 'client_uuid'")->fetch()) {
            $pdo->exec("ALTER TABLE cash_register_transactions ADD COLUMN client_uuid VARCHAR(36) NULL");
            echo "  + cash_register_transactions.client_uuid added.\n";
        } else {
            echo "  · cash_register_transactions.client_uuid already exists.\n";
        }
        $uxCrt = $pdo->query(
            "SELECT 1 FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name   = 'cash_register_transactions'
                AND index_name   = 'ux_crt_client_uuid'
              LIMIT 1"
        )->fetch();
        if (!$uxCrt) {
            try {
                $pdo->exec("ALTER TABLE cash_register_transactions ADD UNIQUE KEY ux_crt_client_uuid (client_uuid)");
                echo "  + ux_crt_client_uuid unique index added.\n";
            } catch (PDOException $idxE) {
                echo "  ! Could not add ux_crt_client_uuid: " . $idxE->getMessage() . " (skipped)\n";
            }
        } else {
            echo "  · ux_crt_client_uuid already exists.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
