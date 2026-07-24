<?php
/**
 * 2026_07_24_zoom_meeting_columns.php
 * -------------------------------------
 * Phase 3 of the Zoom Video-Conferencing Integration (plan: zoom.md). Purely
 * ADDITIVE columns on the existing `meetings` table — no new table. `venue`
 * is untouched and used only when meeting_type = 'in_person'.
 *
 * Idempotent: every ADD COLUMN is guarded by SHOW COLUMNS first.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Zoom meeting columns...\n";

function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void {
    $chk = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $chk->execute([$column]);
    if ($chk->fetch()) {
        echo "  · $table.$column already present.\n";
        return;
    }
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
    echo "  + $table.$column added.\n";
}

try {
    addColumnIfMissing($pdo, 'meetings', 'meeting_type',        "meeting_type ENUM('in_person','zoom') NOT NULL DEFAULT 'in_person' AFTER venue");
    addColumnIfMissing($pdo, 'meetings', 'host_user_id',        "host_user_id INT NULL AFTER meeting_type");
    addColumnIfMissing($pdo, 'meetings', 'zoom_meeting_id',     "zoom_meeting_id VARCHAR(50) NULL AFTER host_user_id");
    addColumnIfMissing($pdo, 'meetings', 'zoom_join_url',       "zoom_join_url VARCHAR(500) NULL AFTER zoom_meeting_id");
    addColumnIfMissing($pdo, 'meetings', 'zoom_start_url',      "zoom_start_url VARCHAR(500) NULL AFTER zoom_join_url");
    addColumnIfMissing($pdo, 'meetings', 'zoom_password',       "zoom_password VARCHAR(20) NULL AFTER zoom_start_url");
    addColumnIfMissing($pdo, 'meetings', 'zoom_host_video',     "zoom_host_video TINYINT(1) NOT NULL DEFAULT 1 AFTER zoom_password");
    addColumnIfMissing($pdo, 'meetings', 'zoom_participant_video', "zoom_participant_video TINYINT(1) NOT NULL DEFAULT 0 AFTER zoom_host_video");
    addColumnIfMissing($pdo, 'meetings', 'zoom_waiting_room',   "zoom_waiting_room TINYINT(1) NOT NULL DEFAULT 1 AFTER zoom_participant_video");
    addColumnIfMissing($pdo, 'meetings', 'zoom_auto_recording', "zoom_auto_recording TINYINT(1) NOT NULL DEFAULT 0 AFTER zoom_waiting_room");
    addColumnIfMissing($pdo, 'meetings', 'zoom_sync_status',    "zoom_sync_status ENUM('pending','synced','failed') NULL AFTER zoom_auto_recording");

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
