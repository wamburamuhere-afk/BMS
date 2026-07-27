<?php
/**
 * 2026_07_25_meeting_attendees_user_id.php
 * -------------------------------------------
 * Zoom Attendees follow-up: the Role -> User picker no longer requires an
 * attendee to be linked to an employee record (plan: zoom.md follow-up).
 * `meeting_attendees` only knew how to store an employee_id (and it was part
 * of the primary key, NOT NULL) — this adds a nullable `user_id` so a Zoom
 * meeting's attendees can be stored by user identity directly.
 *
 * A meeting's attendees are one type or the other, never mixed: in-person
 * meetings keep storing employee_id exactly as before (zero behavior change);
 * Zoom meetings now store user_id. attendance-marking and the "attended"
 * column stay employee_id-only, since attendance is an HR/employee concept —
 * a user_id-only row simply has no attendance UI, not an error.
 *
 * Idempotent: guarded by the presence of the new `attendee_id` surrogate key
 * (the old composite PRIMARY KEY (meeting_id, employee_id) can't hold a NULL
 * employee_id, so it has to be replaced with a surrogate key either way).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: meeting_attendees.user_id...\n";

try {
    $exists = $pdo->query("SHOW COLUMNS FROM meeting_attendees LIKE 'attendee_id'")->fetch();
    if ($exists) {
        echo "  · meeting_attendees already migrated (attendee_id present).\n";
    } else {
        $pdo->exec("
            ALTER TABLE meeting_attendees
                DROP PRIMARY KEY,
                ADD COLUMN attendee_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY FIRST,
                MODIFY COLUMN employee_id INT NULL,
                ADD COLUMN user_id INT NULL AFTER employee_id,
                ADD CONSTRAINT fk_ma_user FOREIGN KEY (user_id) REFERENCES users(user_id),
                ADD UNIQUE KEY uq_ma_meeting_employee (meeting_id, employee_id),
                ADD UNIQUE KEY uq_ma_meeting_user (meeting_id, user_id)
        ");
        echo "  + attendee_id surrogate key + user_id column + FK + unique keys added.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
