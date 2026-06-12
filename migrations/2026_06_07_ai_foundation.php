<?php
/**
 * 2026_06_07_ai_foundation.php
 * ----------------------------
 * Phase 1 of the AI Assistant (plan: ai_assistant.md). Purely ADDITIVE:
 *   - ai_usage_log table (per-call usage + cost trail, for the cost cap + admin viewer)
 *   - system_settings rows for the AI config (disabled by default — feature ships OFF)
 *   - permissions row 'ai_assistant' (so it appears in the role matrix)
 *
 * Nothing existing is changed; with ai_enabled = 0 the feature is invisible.
 * Idempotent (CREATE TABLE IF NOT EXISTS; INSERT IGNORE / guarded upserts).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: AI Assistant foundation...\n";

try {
    // ── 1. Usage / cost log ────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_usage_log (
            id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id           INT NULL,
            feature           VARCHAR(40)  NOT NULL DEFAULT 'generate'  COMMENT 'generate | ask | summary | test',
            provider          VARCHAR(30)  NULL,
            model             VARCHAR(80)  NULL,
            prompt_tokens     INT          NOT NULL DEFAULT 0,
            completion_tokens INT          NOT NULL DEFAULT 0,
            est_cost          DECIMAL(12,6) NOT NULL DEFAULT 0,
            status            VARCHAR(20)  NOT NULL DEFAULT 'ok'        COMMENT 'ok | error | blocked',
            error             VARCHAR(255) NULL,
            created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_ai_usage_created (created_at),
            KEY ix_ai_usage_user (user_id),
            KEY ix_ai_usage_feature (feature)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
          COMMENT='AI Assistant per-call usage + cost trail'
    ");
    echo "  + ai_usage_log ready.\n";

    // ── 2. Settings (disabled by default) ──────────────────────────────────
    $settings = [
        ['ai_enabled',          '0',            'ai', 'Master switch for the AI Assistant (0/1)'],
        ['ai_provider',         'openai',       'ai', 'openai | anthropic | gemini | openrouter'],
        ['ai_model',            'gpt-4o-mini',  'ai', 'Model id for the chosen provider'],
        ['ai_api_key_enc',      '',             'ai', 'Encrypted provider API key (never plaintext)'],
        ['ai_base_url',         '',             'ai', 'Optional base URL override (OpenRouter / gateway)'],
        ['ai_monthly_cost_cap', '0',            'ai', 'Hard monthly USD cost ceiling; 0 = unlimited'],
        ['ai_temperature',      '0.4',          'ai', 'Default creativity (0..1) for generation'],
    ];
    $up = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, description, updated_at)
        VALUES (:k, :v, :g, '0', :d, NOW())
        ON DUPLICATE KEY UPDATE setting_group = VALUES(setting_group), description = VALUES(description)
    ");
    foreach ($settings as [$k, $v, $g, $d]) {
        $up->execute([':k' => $k, ':v' => $v, ':g' => $g, ':d' => $d]);
    }
    echo "  + " . count($settings) . " ai_* settings seeded (feature OFF by default).\n";

    // ── 3. Permission row ──────────────────────────────────────────────────
    $exists = $pdo->prepare("SELECT 1 FROM permissions WHERE page_key = 'ai_assistant'");
    $exists->execute();
    if (!$exists->fetchColumn()) {
        $pdo->prepare("
            INSERT INTO permissions (permission_name, page_key, page_name, description, module_name, is_hidden, created_at)
            VALUES ('', 'ai_assistant', 'AI Assistant', 'Use the AI Assistant (Ask BMS + Generate with AI)', 'Communication', 0, NOW())
        ")->execute();
        echo "  + permission 'ai_assistant' seeded.\n";
    } else {
        echo "  · permission 'ai_assistant' already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
