<?php
/**
 * 2026_07_09_employees_inactivation_reason.php
 * ---------------------------------------------
 * Employee inactivation plan (Phase 2) — see employee_inactivation_plan.md.
 *
 * api/inactivate_employee.php already accepts a free-text reason note but
 * had nowhere to store it — only the audit_log description carried it,
 * unqueryable for a list page. inactive_employees.php needs to show it
 * directly next to who/when.
 *
 * Additive + idempotent.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: employees.inactivation_reason...\n";

try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `employees` LIKE 'inactivation_reason'");
    $st->execute();
    if ($st->fetch()) {
        echo "  = employees.inactivation_reason already present, skipped.\n";
    } else {
        $pdo->exec("ALTER TABLE `employees` ADD COLUMN `inactivation_reason` VARCHAR(255) NULL AFTER `employment_status`");
        echo "  + employees.inactivation_reason added.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
