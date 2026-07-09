<?php
/**
 * 2026_07_09_leaves_total_days_decimal.php
 * ----------------------------------------
 * Leaves module — Phase 3 support: `leaves.total_days` is INT while
 * `leaves.days_count` is DECIMAL(5,2).
 *
 * Half-day leave has always been fractional in intent, but the Half Day dropdown
 * never reached the database (the column did not exist and the field was not in
 * the INSERT), so the truncation never showed. Now that half_day + leave_hours
 * are stored, a 3.5-hour leave computes to 0.44 days — which INT truncates to 0.
 * `total_days` is what the list badge and the summary cards display, so the leave
 * would read "0 days".
 *
 * Widening INT -> DECIMAL(5,2) preserves every existing whole-number value.
 * Idempotent: skipped when the column is already DECIMAL.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: widen leaves.total_days to DECIMAL(5,2)...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM `leaves` LIKE 'total_days'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "Migration failed: leaves.total_days is missing.\n";
        exit(1);
    }

    if (stripos($col['Type'], 'decimal') !== false) {
        echo "  = leaves.total_days is already {$col['Type']}, skipped.\n";
    } else {
        $before = (int)$pdo->query("SELECT COUNT(*) FROM `leaves`")->fetchColumn();
        $sum    = (float)$pdo->query("SELECT COALESCE(SUM(total_days),0) FROM `leaves`")->fetchColumn();

        $pdo->exec("ALTER TABLE `leaves` MODIFY COLUMN `total_days` DECIMAL(5,2) NOT NULL DEFAULT 0");
        echo "  + leaves.total_days: {$col['Type']} -> DECIMAL(5,2).\n";

        $after    = (int)$pdo->query("SELECT COUNT(*) FROM `leaves`")->fetchColumn();
        $sumAfter = (float)$pdo->query("SELECT COALESCE(SUM(total_days),0) FROM `leaves`")->fetchColumn();
        if ($after !== $before || abs($sumAfter - $sum) > 0.001) {
            echo "Migration failed: row count or total changed ($before/$sum -> $after/$sumAfter).\n";
            exit(1);
        }
        echo "  ✓ $after row(s) intact, total unchanged ($sumAfter).\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
