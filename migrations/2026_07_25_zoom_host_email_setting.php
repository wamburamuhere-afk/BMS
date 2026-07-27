<?php
/**
 * 2026_07_25_zoom_host_email_setting.php
 * ----------------------------------------
 * Fixes a design gap from Phase 1: every Zoom meeting was created under the
 * BMS user's own email as Zoom host, but Zoom's API only accepts a host that
 * is an actual member of the connected Zoom account — so any staff member
 * without their own Zoom seat got "User does not exist" on sync.
 *
 * Correct design (matches zoom_service.php's own header comment: "one
 * company, one Zoom account"): every meeting is created under ONE shared
 * Zoom host email, regardless of which BMS user organizes it. This adds that
 * single setting. Purely additive, idempotent, defaults to '' (feature stays
 * off until an admin fills it in on the Zoom Integration settings page).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Zoom host email setting...\n";

try {
    $up = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, description, updated_at)
        VALUES (:k, :v, :g, '0', :d, NOW())
        ON DUPLICATE KEY UPDATE setting_group = VALUES(setting_group), description = VALUES(description)
    ");
    $up->execute([
        ':k' => 'zoom_host_email',
        ':v' => '',
        ':g' => 'zoom',
        ':d' => 'The single Zoom account email every BMS meeting is created under (staff do not need their own Zoom login)',
    ]);
    echo "  + zoom_host_email setting seeded.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
