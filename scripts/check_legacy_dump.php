<?php
/**
 * scripts/check_legacy_dump.php
 * -----------------------------
 * Report whether a .sql backup is restorable, and optionally write an upgraded
 * copy. Read-only against the database — it never connects to MySQL at all.
 *
 *   php scripts/check_legacy_dump.php backups/bms_backup_2026-08-31.sql
 *   php scripts/check_legacy_dump.php backups/old.sql --write=backups/old.fixed.sql
 *   php scripts/check_legacy_dump.php --all backups/
 *
 * WHY THIS EXISTS. Dumps written before 2026-09-03 use
 * `INSERT INTO t VALUES(...)` with no column list, which supplies a value for
 * GENERATED columns. MySQL rejects those rows, and because a restore stops at
 * the first failing statement, EVERY TABLE AFTER THE FIRST OFFENDER is silently
 * skipped — the operator is told "1 error" while the database is left partly
 * restored and partly stale. api/backup_actions.php now upgrades such files
 * automatically in memory, so restoring through the UI is safe; this tool is for
 * checking what you have BEFORE you need it, which is the only time it helps.
 */

$root = dirname(__DIR__);
require_once "$root/core/backup.php";

$args   = array_slice($argv, 1);
$write  = null;
$all    = false;
$paths  = [];

foreach ($args as $a) {
    if ($a === '--all')                      { $all = true; continue; }
    if (str_starts_with($a, '--write='))     { $write = substr($a, 8); continue; }
    $paths[] = $a;
}

if (!$paths) {
    fwrite(STDERR, "usage: php scripts/check_legacy_dump.php <file.sql|dir> [--all] [--write=out.sql]\n");
    exit(1);
}

$files = [];
foreach ($paths as $p) {
    if (is_dir($p))       { foreach (glob(rtrim($p, '/\\') . '/*.sql') ?: [] as $f) $files[] = $f; }
    elseif (is_file($p))  { $files[] = $p; }
    else { fwrite(STDERR, "not found: $p\n"); exit(1); }
}
if (!$files) { fwrite(STDERR, "no .sql files found\n"); exit(1); }
if (!$all && count($files) > 1) $files = [$files[0]];

$needUpgrade = 0;

printf("\n%-46s %10s  %s\n", 'FILE', 'SIZE', 'VERDICT');
printf("%s\n", str_repeat('-', 92));

foreach ($files as $f) {
    $sql = @file_get_contents($f);
    if ($sql === false) { printf("%-46s %10s  %s\n", basename($f), '?', 'UNREADABLE'); continue; }

    $size = number_format(strlen($sql) / 1048576, 1) . ' MB';

    // A dump that did not finish writing is worse than a legacy one — restoring
    // a truncated file silently loses everything past the cut.
    $complete = str_contains(substr($sql, -200), 'SET FOREIGN_KEY_CHECKS=1;');

    $up      = bms_upgrade_legacy_dump($sql);
    $legacy  = $up['rows'] > 0;
    $definer = stripos($sql, 'DEFINER=') !== false;

    $verdict = [];
    if (!$complete) $verdict[] = "\033[31mINCOMPLETE (no end marker — do not restore)\033[0m";
    if ($legacy)    $verdict[] = "\033[33mLEGACY: {$up['rows']} row(s) in " . count($up['tables']) . " table(s) need rewriting\033[0m";
    if ($definer)   $verdict[] = "\033[33mDEFINER present (fails for another MySQL account)\033[0m";
    if (!$verdict)  $verdict[] = "\033[32mOK — restorable as-is\033[0m";

    if ($legacy || $definer) $needUpgrade++;

    printf("%-46s %10s  %s\n", basename($f), $size, implode('; ', $verdict));
    if ($legacy) {
        foreach ($up['tables'] as $t) printf("%-46s %10s    - %s\n", '', '', $t);
    }

    if ($write !== null) {
        if (@file_put_contents($write, $up['sql']) === false) {
            fwrite(STDERR, "could not write $write\n"); exit(1);
        }
        echo "\n  upgraded copy written to: $write\n";
        echo "  (the original is untouched)\n";
    }
}

echo "\n";
if ($needUpgrade > 0) {
    echo "$needUpgrade file(s) need upgrading.\n";
    echo "Restoring through the application handles this automatically — the upgrade\n";
    echo "happens in memory and the file on disk is never modified. Use --write only if\n";
    echo "you want a corrected copy to restore with the `mysql` client by hand.\n";
} else {
    echo "All checked files are restorable as-is.\n";
}
