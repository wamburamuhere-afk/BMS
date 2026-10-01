<?php
/**
 * Migration: 2026_10_01_restore_sale_dates_from_receipt
 *
 * On 2026-09-28 ~11:49-11:52, an unknown operation rewrote sale_date to
 * 2026-09-28 11:50 for POS sales that actually happened in Sep 17-25.
 * The receipt_number encodes the real date (RCP-YYYYMMDD-NNNN), so we can
 * restore the date portion for every affected row.  The exact time of day
 * cannot be recovered; rows are restored to noon (12:00:00) on the correct
 * date, which is accurate enough for financial period reporting.
 *
 * Criterion: sale_date BETWEEN 2026-09-28 11:49:00 AND 11:52:00
 *            AND receipt_number date portion < 20260928
 * Idempotent — a row whose sale_date was already restored (correct date) will
 * not match the criterion and is skipped.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: restore sale_dates from receipt numbers...\n";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Count affected rows first so we can report clearly.
    $count = $pdo->query(
        "SELECT COUNT(*) FROM pos_sales
          WHERE sale_date BETWEEN '2026-09-28 11:49:00' AND '2026-09-28 11:52:00'
            AND receipt_number REGEXP '^RCP-[0-9]{8}-'
            AND SUBSTRING(receipt_number, 5, 8) < '20260928'"
    )->fetchColumn();

    if ($count == 0) {
        echo "  · No affected sales found — nothing to restore.\n";
    } else {
        echo "  · Found $count affected sale(s) — restoring dates from receipt numbers...\n";

        $stmt = $pdo->prepare(
            "UPDATE pos_sales
                SET sale_date = STR_TO_DATE(
                    CONCAT(SUBSTRING(receipt_number, 5, 8), ' 12:00:00'),
                    '%Y%m%d %H:%i:%s'
                )
              WHERE sale_date BETWEEN '2026-09-28 11:49:00' AND '2026-09-28 11:52:00'
                AND receipt_number REGEXP '^RCP-[0-9]{8}-'
                AND SUBSTRING(receipt_number, 5, 8) < '20260928'"
        );
        $stmt->execute();
        echo "  · Updated " . $stmt->rowCount() . " sale(s).\n";
    }

    // Note: cash_register_shifts.start_time rewrite cannot be recovered
    // automatically because the original value is not stored anywhere.
    // The affected shift(s) with start_time = '2026-09-28 11:49:xx' should
    // be corrected manually in the admin panel if the original time matters
    // for shift-close reconciliation.
    echo "  · NOTE: shift start_time(s) rewritten to 2026-09-28 11:49 cannot be\n";
    echo "          auto-restored — check admin > Shifts if reconciliation is affected.\n";

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
