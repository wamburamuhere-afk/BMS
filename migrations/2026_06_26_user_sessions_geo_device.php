<?php
/**
 * 2026_06_26_user_sessions_geo_device.php
 * -----------------------------------------
 * Add GeoIP + parsed device columns to user_sessions so every login
 * records city, country, ISP, org, timezone, browser, OS, and device type.
 * Additive & idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: user_sessions geo/device columns...\n";

try {
    $addCol = function (string $col, string $ddl) use ($pdo) {
        if (!$pdo->query("SHOW COLUMNS FROM user_sessions LIKE " . $pdo->quote($col))->fetch()) {
            $pdo->exec("ALTER TABLE user_sessions ADD COLUMN $ddl");
            echo "  + user_sessions.{$col} added.\n";
        } else {
            echo "  · user_sessions.{$col} already present.\n";
        }
    };

    $addCol('city',         "city         VARCHAR(100) NULL DEFAULT NULL AFTER user_agent");
    $addCol('country',      "country      VARCHAR(100) NULL DEFAULT NULL AFTER city");
    $addCol('country_code', "country_code VARCHAR(5)   NULL DEFAULT NULL AFTER country");
    $addCol('isp',          "isp          VARCHAR(255) NULL DEFAULT NULL AFTER country_code");
    $addCol('org',          "org          VARCHAR(255) NULL DEFAULT NULL AFTER isp");
    $addCol('timezone',     "timezone     VARCHAR(100) NULL DEFAULT NULL AFTER org");
    $addCol('browser',      "browser      VARCHAR(100) NULL DEFAULT NULL AFTER timezone");
    $addCol('os',           "os           VARCHAR(100) NULL DEFAULT NULL AFTER browser");
    $addCol('device_type',  "device_type  VARCHAR(20)  NULL DEFAULT NULL AFTER os");

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
