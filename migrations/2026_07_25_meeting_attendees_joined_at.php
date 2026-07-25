<?php
/**
 * 2026_07_25_meeting_attendees_joined_at.php
 * ---------------------------------------------
 * Adds click-through join tracking for Zoom meeting attendees. "Attended" was
 * previously a manual, employee_id-only checkbox (see migration
 * 2026_07_25_meeting_attendees_user_id.php) with no signal at all for Zoom
 * attendees (user_id rows). This adds `joined_at`, stamped by
 * api/join_meeting.php the moment an invited attendee clicks "Join Meeting" —
 * an approximate signal (proves they clicked BMS's link, not that they stayed
 * in the Zoom call), separate from the existing manual `attended` flag used
 * for in-person meetings.
 *
 * Purely additive. Idempotent (guarded by column existence check).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: meeting_attendees.joined_at...\n";

try {
    $exists = $pdo->query("SHOW COLUMNS FROM meeting_attendees LIKE 'joined_at'")->fetch();
    if ($exists) {
        echo "  · meeting_attendees.joined_at already present.\n";
    } else {
        $pdo->exec("ALTER TABLE meeting_attendees ADD COLUMN joined_at DATETIME NULL AFTER attended");
        echo "  + joined_at column added.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
