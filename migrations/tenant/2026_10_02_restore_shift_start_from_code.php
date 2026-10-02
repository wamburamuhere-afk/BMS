<?php
/**
 * Migration: 2026_10_02_restore_shift_start_from_code
 *
 * The same 2026-09-28 ~11:49 operation that overwrote pos_sales.sale_date also
 * overwrote cash_register_shifts.start_time. shift_code still encodes the real
 * opening time (SHIFT-YYYYMMDD-HHMMSS-<user_id>), so restore it from there.
 *
 * Only rows stamped inside the 11:49–11:52 window, whose code parses to an
 * earlier time, are touched. Idempotent: a restored row no longer matches.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: restore shift start_time from shift_code...\n";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $r = $pdo->query("SHOW TABLES LIKE 'cash_register_shifts'");
    if (!$r || !$r->fetch()) { echo "  · cash_register_shifts not present — skipping.\n"; exit(0); }

    $parsed = "STR_TO_DATE(SUBSTRING(shift_code, 7, 15), '%Y%m%d-%H%i%s')";
    $where  = "start_time BETWEEN '2026-09-28 11:49:00' AND '2026-09-28 11:52:00'
               AND shift_code REGEXP '^SHIFT-[0-9]{8}-[0-9]{6}-'
               AND $parsed IS NOT NULL
               AND $parsed < start_time";

    $n = (int)$pdo->query("SELECT COUNT(*) FROM cash_register_shifts WHERE $where")->fetchColumn();
    if ($n === 0) {
        echo "  · No affected shifts found.\n";
    } else {
        $st = $pdo->prepare("UPDATE cash_register_shifts SET start_time = $parsed WHERE $where");
        $st->execute();
        echo "  · Restored start_time on " . $st->rowCount() . " shift(s).\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
