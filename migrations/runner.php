<?php
/**
 * BMS Migration Runner
 *
 * Called automatically by GitHub Actions after every git pull.
 * Scans migrations/ for new dated files and runs only the ones
 * not yet recorded in the `migrations` tracking table.
 *
 * Usage:
 *   Normal run : php migrations/runner.php
 *   Seed only  : php migrations/runner.php --seed
 *                (marks existing files as done WITHOUT executing them)
 *                Run this ONCE on production for files already applied manually.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

$seed = in_array('--seed', $argv ?? []);

// ── 1. Create tracking table if it doesn't exist ─────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `migrations` (
        `id`       INT AUTO_INCREMENT PRIMARY KEY,
        `filename` VARCHAR(255) UNIQUE NOT NULL,
        `ran_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── 2. Get already-recorded filenames ────────────────────────────────
$done = $pdo->query("SELECT filename FROM migrations")
            ->fetchAll(PDO::FETCH_COLUMN, 0);

// ── 3. Scan folder for dated migration files (e.g. 2026_05_15_*.php) ─
$files = glob(__DIR__ . '/[0-9]*.php');
sort($files); // chronological order by filename

$pending = array_values(array_filter(
    $files,
    fn($f) => !in_array(basename($f), $done)
));

if (empty($pending)) {
    echo "✓ No pending migrations. Database is up to date.\n";
    exit(0);
}

echo ($seed ? '[SEED MODE] ' : '') .
     "Found " . count($pending) . " pending migration(s):\n\n";

$record = $pdo->prepare("INSERT IGNORE INTO migrations (filename) VALUES (?)");

foreach ($pending as $file) {
    $name = basename($file);

    // ── Seed mode: just record, do not execute ──────────────────────
    if ($seed) {
        $record->execute([$name]);
        echo "  ↷ Seeded (not executed): $name\n";
        continue;
    }

    // ── Normal mode: execute the migration file ─────────────────────
    echo "  → Running: $name\n";

    try {
        require $file;

        // Record only after successful execution
        $record->execute([$name]);
        echo "  ✓ Recorded: $name\n\n";

    } catch (Throwable $e) {
        echo "  ✗ FAILED: $name\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "\n  Deployment stopped. Fix the migration and re-push.\n";
        exit(1);
    }
}

echo "All migrations complete.\n";
