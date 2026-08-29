<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: company calendar (leave_types.count_working_days_only)...\n";

try {
    // public_holidays already exists in the live schema (holiday_id, holiday_name,
    // holiday_date, holiday_type enum('national','regional','religious','company'),
    // recurring, country, region, description, status enum('active','inactive'),
    // created_by, created_at) — but it was never created by a migration file and had
    // ZERO code references anywhere before this task. Reusing it as-is rather than
    // creating a competing table; this migration only adds what's genuinely missing.
    $hasTable = $pdo->query("SHOW TABLES LIKE 'public_holidays'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasTable) {
        // Fresh environment that never had the orphaned table — create it fully so
        // app/bms/pos/company_calendar.php has something to work with everywhere.
        $pdo->exec("
            CREATE TABLE public_holidays (
                holiday_id     INT AUTO_INCREMENT PRIMARY KEY,
                holiday_name   VARCHAR(100) NOT NULL,
                holiday_date   DATE NOT NULL,
                holiday_type   ENUM('national','regional','religious','company') DEFAULT 'national',
                recurring      TINYINT(1) DEFAULT 1,
                country        VARCHAR(50) DEFAULT 'Tanzania',
                region         VARCHAR(50) NULL,
                description    TEXT NULL,
                status         ENUM('active','inactive') DEFAULT 'active',
                created_by     INT NULL,
                created_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_holiday_date (holiday_date),
                KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "Created public_holidays table (none existed).\n";
    } else {
        echo "public_holidays already exists — reusing it, no changes needed.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM leave_types LIKE 'count_working_days_only'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        echo "leave_types.count_working_days_only already exists — skipping.\n";
    } else {
        // Opt-in, defaults to 0 so every EXISTING leave type keeps counting calendar
        // days exactly as before (leaveDaysFor() behaves identically when this is 0) —
        // this migration changes nothing about currently-applied leave until an admin
        // deliberately flips a type on via app/bms/pos/leave_types.php.
        $pdo->exec("
            ALTER TABLE leave_types
            ADD COLUMN count_working_days_only TINYINT(1) NOT NULL DEFAULT 0
                AFTER carry_over_days
        ");
        echo "Added leave_types.count_working_days_only (default 0 — unchanged behaviour).\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
