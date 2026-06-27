<?php
/**
 * migrations/2026_06_26_user_sessions_region.php
 * Adds region column to user_sessions (ip-api regionName).
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Migration: user_sessions.region column...\n";

if (!$pdo->query("SHOW COLUMNS FROM user_sessions LIKE 'region'")->fetch()) {
    $pdo->exec("ALTER TABLE user_sessions ADD COLUMN region VARCHAR(100) NULL DEFAULT NULL AFTER city");
    echo "  + user_sessions.region added.\n";
} else {
    echo "  ~ user_sessions.region already exists, skipped.\n";
}

echo "Migration complete.\n";
