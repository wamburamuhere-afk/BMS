<?php
/**
 * migrations/tenant/2026_09_23_pos_offline_sync.php
 *
 * Phase 1 of the offline-first POS sync layer for the Flutter / duka360 app.
 *
 * Adds three idempotency keys (one per write endpoint) and a client-supplied
 * sale timestamp so that:
 *   - Queued sales retried over a flaky connection never create duplicates.
 *   - Offline sales record the cashier's actual sale time, not the sync time.
 *
 * Tables changed:
 *   pos_sales                — client_uuid (idempotency), sold_at (offline timestamp)
 *   pos_sale_payments        — client_uuid (idempotency on credit-sale payment receipts)
 *   cash_register_transactions — client_uuid (idempotency on cash-in / cash-out)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: POS offline-sync columns...\n";

try {
    // --- pos_sales -----------------------------------------------------------
    if (!$pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'client_uuid'")->fetch()) {
        $pdo->exec("ALTER TABLE pos_sales ADD COLUMN client_uuid VARCHAR(36) NULL AFTER shift_id");
        echo "  + pos_sales.client_uuid added.\n";
    } else {
        echo "  · pos_sales.client_uuid already exists.\n";
    }

    // UNIQUE index: enforce server-side deduplication even under concurrent retries.
    $uxExists = $pdo->query(
        "SELECT 1 FROM information_schema.statistics
          WHERE table_schema = DATABASE()
            AND table_name   = 'pos_sales'
            AND index_name   = 'ux_pos_sales_client_uuid'
          LIMIT 1"
    )->fetch();
    if (!$uxExists) {
        // Partial index: NULL rows are excluded from uniqueness in MySQL, so a sale with
        // no client_uuid (web POS, or an app that doesn't send one) never conflicts.
        try {
            $pdo->exec("ALTER TABLE pos_sales ADD UNIQUE KEY ux_pos_sales_client_uuid (client_uuid)");
            echo "  + ux_pos_sales_client_uuid unique index added.\n";
        } catch (PDOException $idxE) {
            // Only fails if duplicates already exist in the column, which can't happen on a
            // new NULL column — log and continue rather than aborting the whole deploy.
            echo "  ! Could not add ux_pos_sales_client_uuid: " . $idxE->getMessage() . " (skipped)\n";
        }
    } else {
        echo "  · ux_pos_sales_client_uuid already exists.\n";
    }

    if (!$pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'sold_at'")->fetch()) {
        $pdo->exec("ALTER TABLE pos_sales ADD COLUMN sold_at DATETIME NULL AFTER client_uuid");
        echo "  + pos_sales.sold_at added.\n";
    } else {
        echo "  · pos_sales.sold_at already exists.\n";
    }

    // --- pos_sale_payments ---------------------------------------------------
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
    } else {
        echo "  · pos_sale_payments table does not exist — skipping.\n";
    }

    // --- cash_register_transactions ------------------------------------------
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
    } else {
        echo "  · cash_register_transactions table does not exist — skipping.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
