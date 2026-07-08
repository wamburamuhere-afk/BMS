<?php
/**
 * 2026_07_08_department_leadership.php
 * ------------------------------------
 * Department leadership: every department can have ONE leader and ONE assistant
 * leader; a person may lead several departments. Leadership is assigned through
 * the HR Actions "Department Leadership" action (approval-based, like the other
 * lifecycle events), which on approval writes here.
 *
 *   1. departments.assistant_manager_id  — the assistant leader (leader already
 *      lives in departments.manager_id).
 *   2. employee_lifecycle_events.event_type gains 'leadership'.
 *   3. employee_lifecycle_events.leadership_assistant_id — the assistant chosen
 *      on a pending leadership event (leader rides on the existing employee_id,
 *      target department on new_department_id).
 *
 * Additive + idempotent — safe to re-run.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Department leadership migration...\n";

try {
    // 1. departments.assistant_manager_id
    $hasAsst = $pdo->query("SHOW COLUMNS FROM departments LIKE 'assistant_manager_id'")->rowCount() > 0;
    if (!$hasAsst) {
        $pdo->exec("ALTER TABLE departments
                    ADD COLUMN assistant_manager_id INT NULL AFTER manager_id,
                    ADD INDEX idx_assistant_manager (assistant_manager_id)");
        echo "  Added departments.assistant_manager_id.\n";
    } else {
        echo "  departments.assistant_manager_id already present.\n";
    }

    // 2. Extend the lifecycle event_type enum with 'leadership'
    $col = $pdo->query("SHOW COLUMNS FROM employee_lifecycle_events LIKE 'event_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && stripos($col['Type'], "'leadership'") === false) {
        $pdo->exec("ALTER TABLE employee_lifecycle_events
                    MODIFY COLUMN event_type
                    ENUM('promotion','demotion','transfer','award','warning','complaint','resignation','termination','leadership')
                    NOT NULL");
        echo "  Extended event_type enum with 'leadership'.\n";
    } else {
        echo "  event_type enum already includes 'leadership'.\n";
    }

    // 3. employee_lifecycle_events.leadership_assistant_id
    $hasLA = $pdo->query("SHOW COLUMNS FROM employee_lifecycle_events LIKE 'leadership_assistant_id'")->rowCount() > 0;
    if (!$hasLA) {
        $pdo->exec("ALTER TABLE employee_lifecycle_events
                    ADD COLUMN leadership_assistant_id INT NULL AFTER new_department_id");
        echo "  Added employee_lifecycle_events.leadership_assistant_id.\n";
    } else {
        echo "  employee_lifecycle_events.leadership_assistant_id already present.\n";
    }

    echo "Done.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
