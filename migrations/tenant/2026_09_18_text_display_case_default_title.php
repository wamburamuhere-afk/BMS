<?php
/**
 * migrations/tenant/2026_09_18_text_display_case_default_title.php
 *
 * "Text Display Case" (core/text_display_case.php) — the default mode
 * changes from 'as_typed' to 'title' (Capitalize Each Word), 2026-09-18
 * request. The code-level fallback (textDisplayCaseMode()) already covers
 * a tenant with NO row for this key, but a tenant that already has an
 * explicit 'as_typed' row (every tenant this feature has reached since its
 * 2026-09-18 Phase 0 launch, since the settings page always writes one on
 * save, and the setting is seeded/read-through on first load) needs that
 * row updated too, or the code-level default change has no visible effect
 * for them.
 *
 * Criteria-based, not tenant-id-based: only touches rows still sitting on
 * the OLD default value, so a tenant that already made a deliberate choice
 * (sentence/lower/upper/toggle, or even an explicit 'as_typed' opt-out
 * they chose on purpose) is left alone. Idempotent — a second run matches
 * zero rows.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: text_display_case default -> title...\n";

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE system_settings
           SET setting_value = 'title'
         WHERE setting_key = 'text_display_case'
           AND setting_value = 'as_typed'
    ");
    $stmt->execute();
    echo "  Updated {$stmt->rowCount()} row(s) from 'as_typed' to 'title'.\n";

    $pdo->commit();
    echo "Migration complete.\n";
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
