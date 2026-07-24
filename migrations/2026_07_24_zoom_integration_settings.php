<?php
/**
 * 2026_07_24_zoom_integration_settings.php
 * -----------------------------------------
 * Phase 1 of the Zoom Video-Conferencing Integration (plan: zoom.md). Purely
 * ADDITIVE: system_settings rows for the Zoom Server-to-Server OAuth config
 * (disabled by default — feature ships OFF) + permission row 'zoom_settings'
 * (admin-only panel, mirrors 'ai_assistant').
 *
 * Nothing existing is changed; with zoom_enabled = 0 the feature is invisible.
 * Idempotent (guarded upserts / INSERT IGNORE-equivalent).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Zoom integration settings...\n";

try {
    // ── 1. Settings (disabled by default) ──────────────────────────────────
    $settings = [
        ['zoom_enabled',            '0', 'zoom', 'Master switch for the Zoom integration (0/1)'],
        ['zoom_account_id',         '',  'zoom', 'Zoom Server-to-Server OAuth Account ID'],
        ['zoom_client_id',          '',  'zoom', 'Zoom Server-to-Server OAuth Client ID'],
        ['zoom_client_secret_enc',  '',  'zoom', 'Encrypted Zoom Server-to-Server OAuth Client Secret (never plaintext)'],
        ['zoom_access_token_enc',  '',  'zoom', 'Encrypted cached OAuth access token (internal — auto-managed)'],
        ['zoom_token_expires_at',  '0', 'zoom', 'Unix timestamp the cached access token expires (internal — auto-managed)'],
    ];
    $up = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, description, updated_at)
        VALUES (:k, :v, :g, '0', :d, NOW())
        ON DUPLICATE KEY UPDATE setting_group = VALUES(setting_group), description = VALUES(description)
    ");
    foreach ($settings as [$k, $v, $g, $d]) {
        $up->execute([':k' => $k, ':v' => $v, ':g' => $g, ':d' => $d]);
    }
    echo "  + " . count($settings) . " zoom_* settings seeded (feature OFF by default).\n";

    // ── 2. Permission row ────────────────────────────────────────────────────
    $exists = $pdo->prepare("SELECT 1 FROM permissions WHERE page_key = 'zoom_settings'");
    $exists->execute();
    if (!$exists->fetchColumn()) {
        $pdo->prepare("
            INSERT INTO permissions (permission_name, page_key, page_name, description, module_name, is_hidden, created_at)
            VALUES ('', 'zoom_settings', 'Zoom Integration', 'Configure Zoom Server-to-Server OAuth for meeting video links', 'Settings', 0, NOW())
        ")->execute();
        echo "  + permission 'zoom_settings' seeded.\n";
    } else {
        echo "  · permission 'zoom_settings' already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
