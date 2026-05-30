<?php
/**
 * 2026_05_30_user_profile_columns.php
 * -----------------------------------
 * Adds the columns the My Profile page writes to but which were missing from
 * `users` (so Update Profile / Change Password / Avatar Upload failed with
 * "Unknown column"):
 *
 *   phone               VARCHAR(30)  NULL  — contact number
 *   avatar              VARCHAR(255) NULL  — stored avatar filename
 *   password_changed_at DATETIME     NULL  — last password change timestamp
 *
 * Idempotent: each ADD COLUMN is guarded by SHOW COLUMNS.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: user profile columns...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
        echo "  ! users table missing — cannot proceed.\n";
        exit(1);
    }

    $columns = [
        'phone'               => "VARCHAR(30) NULL AFTER email",
        'avatar'              => "VARCHAR(255) NULL",
        'password_changed_at' => "DATETIME NULL",
        // The profile UPDATEs set updated_at = NOW(); some installs' users table
        // never had it. Add it so those writes don't fail with "Unknown column".
        'updated_at'          => "DATETIME NULL DEFAULT NULL",
    ];

    foreach ($columns as $col => $spec) {
        $exists = $pdo->query("SHOW COLUMNS FROM users LIKE " . $pdo->quote($col))->fetch();
        if ($exists) {
            echo "  · users.$col already exists, skipping.\n";
        } else {
            $pdo->exec("ALTER TABLE users ADD COLUMN `$col` $spec");
            echo "  + added users.$col.\n";
        }
    }

    // Ensure the avatar upload dir exists and is hardened against script
    // execution (uploads/ is gitignored, so the .htaccess can't be committed).
    $avatarDir = ROOT_DIR . '/uploads/avatars';
    if (!is_dir($avatarDir)) {
        @mkdir($avatarDir, 0755, true);
        echo "  + created uploads/avatars/.\n";
    }
    $ht = $avatarDir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<FilesMatch \"\\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n    Require all denied\n</FilesMatch>\nOptions -ExecCGI\nRemoveHandler .php .phtml .php5\nRemoveType .php .phtml .php5\n");
        echo "  + wrote uploads/avatars/.htaccess (hardening).\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
