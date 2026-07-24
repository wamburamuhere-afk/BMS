<?php
/**
 * 2026_07_24_zoom_meeting_notification_event.php
 * -------------------------------------------------
 * Phase 6 of the Zoom Video-Conferencing Integration (plan: zoom.md). Registers
 * the 'hr_meeting' event_key (already used inline by
 * api/manage_meeting.php's notifyMeetingAttendees()) into notification_events
 * so it shows up in Settings → Notification Rules and — the actual reason for
 * this migration — so per-user mute preferences (notifUserMuted()) can be
 * checked before a meeting/Zoom-invite notification is sent.
 *
 * Purely ADDITIVE (INSERT IGNORE-equivalent). Meeting notifications keep going
 * out to the meeting's specific attendees (resolved from meeting_attendees),
 * NOT via dispatchEvent()'s permission-based broadcast — that engine resolves
 * recipients as "everyone who can view a page_key", which would leak a Zoom
 * join link to every user with 'meetings' access instead of only the invited
 * attendees. Registering the event still gets us the shared mute-preference
 * check without that broadcast side effect.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Zoom meeting notification event...\n";

try {
    $exists = $pdo->prepare("SELECT 1 FROM notification_events WHERE event_key = 'hr_meeting'");
    $exists->execute();
    if (!$exists->fetchColumn()) {
        $pdo->prepare("
            INSERT INTO notification_events (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware, is_active, created_at)
            VALUES ('hr_meeting', 'Meeting scheduled or cancelled', 'Notifies a meeting''s attendees when it is scheduled, rescheduled, or cancelled (includes the Zoom join link for Zoom meetings).', 'Human Resources', 'meetings', 'view', 'medium', 0, 1, NOW())
        ")->execute();
        echo "  + notification_events 'hr_meeting' seeded.\n";
    } else {
        echo "  · notification_events 'hr_meeting' already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
