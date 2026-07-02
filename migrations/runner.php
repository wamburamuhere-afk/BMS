<?php
/**
 * BMS Migration Runner
 *
 * Runs each pending migration as an isolated subprocess.
 * Writes a persistent log to migrations/deploy.log.
 *
 * Usage:
 *   Normal run : php migrations/runner.php
 *   Seed only  : php migrations/runner.php --seed
 *                Marks all existing files as done WITHOUT executing them.
 *                Run once on production for files already applied manually.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../roots.php';
global $pdo;

$seed    = in_array('--seed', $argv ?? []);
$logFile = __DIR__ . '/deploy.log';

// ── Logging helper ────────────────────────────────────────────────────
function log_line(string $msg): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
}

// ── 1. Create tracking table if not exists ────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `migrations` (
        `id`       INT AUTO_INCREMENT PRIMARY KEY,
        `filename` VARCHAR(255) UNIQUE NOT NULL,
        `ran_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── 2. Get already-recorded filenames ─────────────────────────────────
$done = $pdo->query("SELECT filename FROM migrations")
            ->fetchAll(PDO::FETCH_COLUMN, 0);

// ── 3. Scan for dated migration files (e.g. 2026_05_15_*.php) ─────────
$files = glob(__DIR__ . '/[0-9]*.php');
sort($files);

$pending = array_values(array_filter(
    $files,
    fn($f) => !in_array(basename($f), $done)
));

// ── 4. Header ─────────────────────────────────────────────────────────
log_line('=========================================');
log_line('BMS Migration Runner' . ($seed ? ' [SEED MODE]' : ''));
log_line('Pending : ' . count($pending) . ' migration(s)');
log_line('=========================================');

if (empty($pending)) {
    log_line('✓ No pending migrations. Database is up to date.');
    exit(0);
}

$record = $pdo->prepare("INSERT IGNORE INTO migrations (filename) VALUES (?)");

// ── 5. Process each pending migration ────────────────────────────────
foreach ($pending as $file) {
    $name = basename($file);

    // Seed mode — record without executing
    if ($seed) {
        $record->execute([$name]);
        log_line("  ↷  Seeded (not executed): $name");
        continue;
    }

    log_line("  →  Running: $name");

    // Run as isolated subprocess — exit(1) inside migration
    // only kills that subprocess, not the runner
    $output   = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

    // Echo subprocess output into the log
    foreach ($output as $line) {
        log_line("       $line");
    }

    if ($exitCode !== 0) {
        log_line("  ✗  FAILED: $name (exit $exitCode)");
        log_line("  Deployment stopped. Fix the migration and re-push.");
        log_line('=========================================');
        exit(1);
    }

    // Record only after confirmed success
    $record->execute([$name]);
    log_line("  ✓  Done: $name");
}

// ── 6. Footer ─────────────────────────────────────────────────────────
log_line('=========================================');
log_line('Result: SUCCESS — all migrations complete.');
exit(0);
