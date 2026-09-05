<?php
/**
 * 2026_07_03_attendance_capture.php
 * ---------------------------------
 * Smart attendance capture (GPS+Selfie and QR) — admin-controlled foundation.
 *
 *  1. system_settings feature flags (default OFF — admin turns a method ON in
 *     Admin ▸ Settings ▸ Attendance before any self-capture works).
 *  2. attendance — capture-side columns (method, coords, selfie, geofence, device).
 *  3. attendance_sites — geofence config (name, coords, radius) so GPS punches can
 *     be validated against a real physical location (no lat/lng existed anywhere).
 *  4. employee_credentials — per-employee QR badge token (webauthn column reserved
 *     for a later fingerprint phase; unused for now).
 *  5. attendance + attendance_audit_log → InnoDB so a self-capture punch writes the
 *     row and its audit atomically.
 *  6. permissions — page keys for the new pages.
 *
 * Additive + idempotent. With every flag OFF, attendance behaves exactly as today.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: smart attendance capture (GPS+Selfie, QR)...\n";

try {
    /* 1. Feature flags + geofence defaults ------------------------------------ */
    $settings = [
        ['attendance_method_manual',          'on',  'attendance', 'Allow HR/manager manual marking of attendance'],
        ['attendance_method_gps',             'off', 'attendance', 'Allow employees to self clock-in via GPS + selfie'],
        ['attendance_method_qr',              'off', 'attendance', 'Allow QR-badge kiosk check-in'],
        ['attendance_selfie_required',        'on',  'attendance', 'Require a selfie photo on GPS clock-in'],
        ['attendance_geofence_radius_default','150', 'attendance', 'Default allowed distance (metres) from a site for a valid GPS punch'],
    ];
    $s = $pdo->prepare(
        "INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_group, is_public, description, updated_at)
         VALUES (?, ?, ?, 'FALSE', ?, NOW())"
    );
    foreach ($settings as $row) {
        $s->execute([$row[0], $row[1], $row[2], $row[3]]);
        echo "  + setting '{$row[0]}' ensured (= {$row[1]}).\n";
    }

    /* 2. attendance capture columns ------------------------------------------- */
    $cols = [
        'capture_method'     => "ENUM('manual','gps','qr') NOT NULL DEFAULT 'manual'",
        'check_in_lat'       => "DECIMAL(10,7) NULL",
        'check_in_lng'       => "DECIMAL(10,7) NULL",
        'check_out_lat'      => "DECIMAL(10,7) NULL",
        'check_out_lng'      => "DECIMAL(10,7) NULL",
        'selfie_path'        => "VARCHAR(255) NULL",
        'device_id'          => "VARCHAR(100) NULL",
        'site_id'            => "INT NULL",
        'is_within_geofence' => "TINYINT(1) NULL",
        'captured_by_self'   => "TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($cols as $col => $def) {
        if (!$pdo->query("SHOW COLUMNS FROM attendance LIKE '$col'")->fetch()) {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN `$col` $def");
            echo "  + attendance.$col added.\n";
        } else {
            echo "  = attendance.$col already exists.\n";
        }
    }

    /* 3. attendance_sites — geofence config ----------------------------------- */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS attendance_sites (
            site_id       INT NOT NULL AUTO_INCREMENT,
            site_name     VARCHAR(150) NOT NULL,
            project_id    INT NULL,
            latitude      DECIMAL(10,7) NOT NULL,
            longitude     DECIMAL(10,7) NOT NULL,
            radius_meters INT NOT NULL DEFAULT 150,
            status        ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
            created_by    INT NULL,
            created_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (site_id),
            KEY idx_project (project_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "  + table attendance_sites ensured.\n";

    /* 4. employee_credentials — QR token (webauthn reserved) ------------------ */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_credentials (
            credential_id INT NOT NULL AUTO_INCREMENT,
            employee_id   INT NOT NULL,
            type          ENUM('qr','webauthn') NOT NULL DEFAULT 'qr',
            token         VARCHAR(255) NULL,
            public_key    TEXT NULL,
            counter       BIGINT NOT NULL DEFAULT 0,
            status        ENUM('active','revoked','deleted') NOT NULL DEFAULT 'active',
            created_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (credential_id),
            UNIQUE KEY uniq_token (token),
            KEY idx_employee (employee_id),
            KEY idx_type (type),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "  + table employee_credentials ensured.\n";

    /* 5. Convert attendance tables to InnoDB for atomic punch+audit ----------- */
    foreach (['attendance', 'attendance_audit_log'] as $tbl) {
        $engine = $pdo->query("
            SELECT ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tbl'
        ")->fetchColumn();
        if ($engine && strtoupper($engine) !== 'INNODB') {
            $pdo->exec("ALTER TABLE `$tbl` ENGINE=InnoDB");
            echo "  ~ $tbl converted $engine → InnoDB.\n";
        } else {
            echo "  = $tbl already InnoDB.\n";
        }
    }

    /* 6. Permission keys for the new pages ------------------------------------ */
    $perms = [
        ['attendance_settings', 'Attendance Settings',   'Enable/disable smart attendance capture methods and manage site geofences', 'System Settings'],
        ['attendance_clockin',  'Attendance Clock-In',   'Employee self clock-in (GPS + selfie)',                                     'Human Resources'],
        ['attendance_kiosk',    'Attendance Kiosk',      'Shared-device QR badge check-in kiosk',                                     'Human Resources'],
        ['attendance_badge',    'Attendance QR Badges',  'Generate/print employee QR attendance badges',                              'Human Resources'],
    ];
    $p = $pdo->prepare("INSERT IGNORE INTO permissions (page_key, page_name, description, module_name) VALUES (?, ?, ?, ?)");
    foreach ($perms as $row) {
        $p->execute($row);
        echo ($p->rowCount() > 0 ? "  + permission '{$row[0]}' inserted.\n" : "  = permission '{$row[0]}' already present.\n");
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
