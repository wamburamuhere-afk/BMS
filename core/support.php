<?php
/**
 * core/support.php — Help & Support Center (support_center_plan.md).
 * Control-DB schema for BJP-facing support plus the platform-level support settings.
 * Staff-to-admin requests (HLP-) live in the tenant DB and are added in Phase 4.
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/platform_settings.php';

if (!function_exists('supportEnsureSchema')) {
    /** Idempotent: CREATE TABLE IF NOT EXISTS for every control-DB support table. Returns created/checked table names. */
    function supportEnsureSchema(PDO $pdo, string $db): array
    {
        $q = fn(string $t) => "`{$db}`.`{$t}`";
        $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
        $ddl = [
            'support_categories' => "CREATE TABLE IF NOT EXISTS {$q('support_categories')} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_en VARCHAR(120) NOT NULL, name_sw VARCHAR(120) NULL,
                icon VARCHAR(40) NULL, sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) {$opt}",
            'support_articles' => "CREATE TABLE IF NOT EXISTS {$q('support_articles')} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category_id INT NULL,
                title_en VARCHAR(255) NOT NULL, title_sw VARCHAR(255) NULL,
                body_en MEDIUMTEXT NOT NULL, body_sw MEDIUMTEXT NULL,
                simple_body_en MEDIUMTEXT NULL, simple_body_sw MEDIUMTEXT NULL,
                video_url VARCHAR(500) NULL, is_faq TINYINT(1) NOT NULL DEFAULT 0,
                page_keys JSON NULL,
                status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
                views INT NOT NULL DEFAULT 0, helpful_yes INT NOT NULL DEFAULT 0, helpful_no INT NOT NULL DEFAULT 0,
                updated_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_status (status), KEY idx_category (category_id)) {$opt}",
            'support_tickets' => "CREATE TABLE IF NOT EXISTS {$q('support_tickets')} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ref_no VARCHAR(20) NOT NULL,
                audience ENUM('company','bjp') NOT NULL DEFAULT 'bjp',
                tenant_id INT NOT NULL, tenant_user_id INT NOT NULL,
                user_name VARCHAR(191) NOT NULL, user_email VARCHAR(191) NULL, user_role VARCHAR(100) NULL,
                subject VARCHAR(255) NOT NULL, category VARCHAR(60) NOT NULL DEFAULT 'other',
                priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
                status ENUM('open','in_progress','waiting_on_customer','resolved','closed') NOT NULL DEFAULT 'open',
                page_url VARCHAR(500) NULL, page_key VARCHAR(64) NULL, article_id INT NULL,
                product_key VARCHAR(40) NULL, plan_code VARCHAR(60) NULL, browser VARCHAR(255) NULL,
                forwarded_from VARCHAR(20) NULL,
                assigned_to INT NULL,
                first_response_at DATETIME NULL, resolved_at DATETIME NULL, closed_at DATETIME NULL,
                rating TINYINT NULL, rating_comment VARCHAR(500) NULL,
                last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ref (ref_no),
                KEY idx_tenant_status (tenant_id, status), KEY idx_tenant_user (tenant_id, tenant_user_id),
                KEY idx_audience_status (audience, status, last_activity_at)) {$opt}",
            'support_ticket_messages' => "CREATE TABLE IF NOT EXISTS {$q('support_ticket_messages')} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                author_type ENUM('tenant','bjp','system') NOT NULL,
                author_id INT NULL, author_name VARCHAR(191) NULL,
                body TEXT NOT NULL, is_internal TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ticket (ticket_id, created_at)) {$opt}",
            'support_attachments' => "CREATE TABLE IF NOT EXISTS {$q('support_attachments')} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL, message_id INT NOT NULL, tenant_id INT NOT NULL,
                stored_path VARCHAR(500) NOT NULL, original_name VARCHAR(255) NOT NULL,
                mime VARCHAR(100) NOT NULL, size_bytes INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_message (message_id), KEY idx_ticket (ticket_id)) {$opt}",
            'support_canned_replies' => "CREATE TABLE IF NOT EXISTS {$q('support_canned_replies')} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(191) NOT NULL, body TEXT NOT NULL, created_by INT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) {$opt}",
            'support_notices' => "CREATE TABLE IF NOT EXISTS {$q('support_notices')} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(191) NOT NULL, body VARCHAR(1000) NOT NULL,
                level ENUM('info','maintenance','incident') NOT NULL DEFAULT 'info',
                target ENUM('all','product','plan','tenant') NOT NULL DEFAULT 'all',
                target_value VARCHAR(64) NULL,
                starts_at DATETIME NOT NULL, ends_at DATETIME NULL,
                created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_window (starts_at, ends_at)) {$opt}",
            'support_search_log' => "CREATE TABLE IF NOT EXISTS {$q('support_search_log')} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NULL, query VARCHAR(255) NOT NULL, results_count INT NOT NULL DEFAULT 0,
                product_key VARCHAR(40) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_results (results_count, created_at)) {$opt}",
        ];
        foreach ($ddl as $sql) {
            $pdo->exec($sql);
        }
        return array_keys($ddl);
    }
}

if (!function_exists('supportSettingDefaults')) {
    /** Platform settings keys for support, with defaults. SLA values are business hours. */
    function supportSettingDefaults(): array
    {
        return [
            'support_email'          => '',
            'support_phone'          => '',
            'support_whatsapp'       => '',
            'support_hours'          => '',
            'support_recipients'     => '',
            'support_autoreply'      => '',
            'support_login_help'     => '',
            'support_sla_urgent'     => '2',
            'support_sla_high'       => '4',
            'support_sla_normal'     => '8',
            'support_sla_low'        => '16',
            'support_autoclose_days' => '5',
            'support_attach_max_mb'  => '5',
        ];
    }
}

if (!function_exists('supportSettings')) {
    /** Current support settings (never throws; missing control DB → defaults). */
    function supportSettings(): array
    {
        $out = [];
        foreach (supportSettingDefaults() as $k => $d) {
            $out[$k] = getPlatformSetting($k, $d);
        }
        return $out;
    }
}

if (!function_exists('supportValidateSettings')) {
    /** Pure validation of a settings form. @return array{ok:bool, errors:array, values:array} */
    function supportValidateSettings(array $in): array
    {
        $errors = [];
        $v = [];
        $txt = fn(string $k, int $max) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);

        $v['support_email'] = $txt('support_email', 191);
        if ($v['support_email'] !== '' && !filter_var($v['support_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['support_email'] = 'Enter a valid support email address.';
        }
        foreach (['support_phone', 'support_whatsapp'] as $k) {
            $v[$k] = $txt($k, 30);
            if ($v[$k] !== '' && !preg_match('/^\+?[0-9 ()-]{7,30}$/', $v[$k])) {
                $errors[$k] = 'Use digits, spaces and an optional leading +.';
            }
        }
        $v['support_hours'] = $txt('support_hours', 191);

        $recips = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($in['support_recipients'] ?? ''))));
        foreach ($recips as $r) {
            if (!filter_var($r, FILTER_VALIDATE_EMAIL)) {
                $errors['support_recipients'] = "'{$r}' is not a valid email address.";
                break;
            }
        }
        $v['support_recipients'] = implode(',', array_unique($recips));
        $v['support_autoreply'] = $txt('support_autoreply', 1000);
        $v['support_login_help'] = $txt('support_login_help', 500);

        $ints = ['support_sla_urgent' => [1, 72], 'support_sla_high' => [1, 120], 'support_sla_normal' => [1, 240],
                 'support_sla_low' => [1, 480], 'support_autoclose_days' => [1, 60], 'support_attach_max_mb' => [1, 20]];
        foreach ($ints as $k => [$min, $max]) {
            $raw = trim((string)($in[$k] ?? supportSettingDefaults()[$k]));
            if (!ctype_digit($raw) || (int)$raw < $min || (int)$raw > $max) {
                $errors[$k] = "Enter a whole number from {$min} to {$max}.";
            }
            $v[$k] = (string)(int)$raw;
        }
        return ['ok' => !$errors, 'errors' => $errors, 'values' => $v];
    }
}
