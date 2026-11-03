<?php
/**
 * Backup retention policy — CLI test
 *   php tests/test_backup_retention_cli.php
 *
 * bms_apply_retention() (core/backup.php) replaced an age-only rule that, on
 * production 2026-10-31, deleted BOTH older BJP backups and left a single one.
 * Policy (backup_plan.md §3): snapshots 48 h; nightly 7 days; first of each
 * week 4 weeks; first of each month 12 months; pre_restore/uploaded 30 days;
 * the 3 newest nightly and 3 newest snapshots ALWAYS; manual and unknown files
 * never touched. Dates come from the file NAME, never the mtime.
 *
 * No database needed. Uses a fixed clock and temp folders. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/core/backup.php";
require_once "$root/core/tenant_backups.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

$tz   = new DateTimeZone('Africa/Dar_es_Salaam');
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_retention_' . bin2hex(random_bytes(4));
register_shutdown_function(function () use ($work) {
    global $pass, $fail;
    if (is_dir($work)) bms_delete_dir($work);
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});

/** Fresh empty folder. */
function folder(string $name): string
{
    global $work;
    $d = "$work/$name/";
    mkdir($d, 0755, true);
    return $d;
}
/** File name for $kind at a given time. */
function fname(string $kind, int $ts): string
{
    global $tz;
    $d = (new DateTime('@' . $ts))->setTimezone($tz);
    return $kind === 'uploaded'
        ? 'uploaded_' . $d->format('Ymd_His') . '_auto_backup_x.zip'
        : $kind . '_' . $d->format('Y-m-d_H-i-s') . '.zip';
}
function put(string $dir, string $name): void { file_put_contents($dir . $name, 'x'); }
function names(string $dir): array
{
    $n = array_map('basename', array_merge(glob($dir . '*.zip') ?: [], glob($dir . '*.sql') ?: []));
    sort($n); return $n;
}

// 2026-10-31 00:30 Dar es Salaam — a nightly run.
$now = (new DateTime('2026-10-31 00:30:00', $tz))->getTimestamp();
$H = 3600; $D = 86400;

echo "\n\033[1mBackup retention policy\033[0m\n";

// ═══════════════════════════════════════════════════════════════════════════
section('1. Reading the date from the file name');
// ═══════════════════════════════════════════════════════════════════════════

$i = bms_backup_file_info('auto_backup_2026-10-17_11-52-03.zip');
ok($i && $i['kind'] === 'auto_backup' && $i['ts'] === (new DateTime('2026-10-17 11:52:03', $tz))->getTimestamp(),
   'auto_backup_2026-10-17_11-52-03.zip → nightly, 17 Oct 11:52:03 Dar es Salaam');
ok((bms_backup_file_info('auto_snapshot_2026-10-31_06-30-00.zip')['kind'] ?? '') === 'auto_snapshot', 'snapshot recognised');
ok((bms_backup_file_info('pre_restore_2026-09-28_14-45-08.sql')['kind'] ?? '') === 'pre_restore', 'pre_restore (.sql) recognised');
ok((bms_backup_file_info('uploaded_20260928_144505_auto_backup_2026-09-28_08-47-34.zip')['kind'] ?? '') === 'uploaded',
   'uploaded_… recognised by its upload time (real production name)');
ok(bms_backup_file_info('bms_backup_2026-10-13_21-22-18.zip') === null, 'manual backup → not automatic → never deleted');
ok(bms_backup_file_info('auto_backup_garbage.zip') === null && bms_backup_file_info('notes.zip') === null
   && bms_backup_file_info('auto_backup_2026-13-45_99-99-99.zip.bak') === null,
   'unrecognised names → never deleted');

// ═══════════════════════════════════════════════════════════════════════════
section('2. A year of history, one run');
// ═══════════════════════════════════════════════════════════════════════════

$dir = folder('year');
for ($d = 0; $d <= 400; $d++) put($dir, fname('auto_backup', $now - $d * $D));          // nightly
for ($h = 6; $h <= 5 * 24; $h += 6) put($dir, fname('auto_snapshot', $now - $h * $H));   // 6-hourly, 5 days
foreach ([10, 40] as $d) put($dir, fname('pre_restore', $now - $d * $D));
foreach ([5, 31] as $d)  put($dir, fname('uploaded', $now - $d * $D));
put($dir, 'bms_backup_2025-01-01_10-00-00.zip');    // manual, 2 years old
put($dir, 'notes.zip');

$deleted = bms_apply_retention($dir, $now);
$left    = names($dir);
$kept    = fn(string $kind) => array_values(array_filter($left, fn($n) => str_starts_with($n, $kind . '_')));

$snaps = $kept('auto_snapshot');
ok(count($snaps) === 8, 'snapshots: the 8 from the last 48 h kept (got ' . count($snaps) . ')');
$oldestSnap = min(array_map(fn($n) => bms_backup_file_info($n)['ts'], $snaps));
ok($now - $oldestSnap <= 48 * $H, 'no kept snapshot is older than 48 h');

$nightly = $kept('auto_backup');
$ages = array_map(fn($n) => intdiv($now - bms_backup_file_info($n)['ts'], $D), $nightly);
ok(count(array_filter($ages, fn($a) => $a <= 7)) === 8, 'nightly: every day of the last 7 days kept (days 0–7)');
ok(max($ages) <= 365, 'nothing older than 12 months kept (oldest ' . max($ages) . ' days)');

$weeklyOk = true;
for ($w = 0; $w < 4; $w++) {
    $wk = (new DateTime('@' . ($now - $w * 7 * $D)))->setTimezone($tz)->format('o-W');
    $has = array_filter($nightly, fn($n) => (new DateTime('@' . bms_backup_file_info($n)['ts']))->setTimezone($tz)->format('o-W') === $wk);
    if (!$has) $weeklyOk = false;
}
ok($weeklyOk, 'weekly: each of the last 4 weeks still has a backup');

$firstsOk = true;
foreach ($nightly as $n) {
    $d = (new DateTime('@' . bms_backup_file_info($n)['ts']))->setTimezone($tz);
    if (intdiv($now - $d->getTimestamp(), $D) > 28 && $d->format('j') !== '1') $firstsOk = false;
}
ok($firstsOk, 'beyond 4 weeks, only the first-of-month backups remain');
ok(count($nightly) <= 8 + 4 + 13, 'total nightly kept is bounded (' . count($nightly) . ' of 401)');

ok(in_array(fname('pre_restore', $now - 10 * $D), $left, true) && !in_array(fname('pre_restore', $now - 40 * $D), $left, true),
   'pre_restore: 10 days old kept, 40 days old deleted');
ok(in_array(fname('uploaded', $now - 5 * $D), $left, true) && !in_array(fname('uploaded', $now - 31 * $D), $left, true),
   'uploaded: 5 days old kept, 31 days old deleted');
ok(in_array('bms_backup_2025-01-01_10-00-00.zip', $left, true), 'the 2-year-old MANUAL backup is untouched');
ok(in_array('notes.zip', $left, true), 'an unrecognised file is untouched');
ok(count($deleted) + count($left) === 401 + 20 + 2 + 2 + 2, 'every file is either kept or reported deleted');

ok(bms_apply_retention($dir, $now) === [], 'running it again deletes nothing (idempotent)');

// ═══════════════════════════════════════════════════════════════════════════
section('3. Backups that stopped are never emptied out');
// ═══════════════════════════════════════════════════════════════════════════

$dir = folder('stopped');
foreach ([60, 61, 62, 63, 64] as $d) put($dir, fname('auto_backup', $now - $d * $D - 2 * $H));
foreach ([10, 11, 12, 13, 14] as $d) put($dir, fname('auto_snapshot', $now - $d * $D));
bms_apply_retention($dir, $now);
$nightly = array_values(array_filter(names($dir), fn($n) => str_starts_with($n, 'auto_backup_')));
$snaps   = array_values(array_filter(names($dir), fn($n) => str_starts_with($n, 'auto_snapshot_')));
ok(count($snaps) === 3, 'all snapshots 10+ days old, yet the 3 newest are kept');
$newest3 = array_map(fn($d) => fname('auto_backup', $now - $d * $D - 2 * $H), [60, 61, 62]);
ok(!array_diff($newest3, $nightly), 'all nightly backups 2 months old, yet the 3 newest are kept');

// ═══════════════════════════════════════════════════════════════════════════
section('4. The production case of 2026-10-31');
// ═══════════════════════════════════════════════════════════════════════════

$dir = folder('prod');
foreach (['auto_backup_2026-10-13_21-21-25.zip', 'auto_backup_2026-10-17_11-52-03.zip', 'auto_backup_2026-10-31_09-28-17.zip',
          'bms_backup_2026-10-13_21-22-18.zip'] as $n) put($dir, $n);
$prodNow = (new DateTime('2026-10-31 09:28:54', $tz))->getTimestamp();
$deleted = bms_apply_retention($dir, $prodNow);
ok($deleted === [], 'nothing deleted — the old rule deleted the 13 Oct and 17 Oct backups here, leaving one');
ok(count(names($dir)) === 4, 'all three automatic backups and the manual one remain');

// ═══════════════════════════════════════════════════════════════════════════
section('5. The date comes from the name, not the file time');
// ═══════════════════════════════════════════════════════════════════════════

$dir = folder('mtime');
$old = fname('auto_snapshot', $now - 10 * $D);
foreach ([1, 2, 3] as $h) put($dir, fname('auto_snapshot', $now - $h * $H));
put($dir, $old);
touch($dir . $old, $now);                      // freshly copied: mtime says "now"
bms_apply_retention($dir, $now);
ok(!is_file($dir . $old), 'a 10-day-old snapshot with a brand-new file time is still removed');

// ═══════════════════════════════════════════════════════════════════════════
section('6. 400 nights of real use: the folder stays bounded');
// ═══════════════════════════════════════════════════════════════════════════

$dir = folder('sim');
$start = (new DateTime('2025-09-01 00:30:00', $tz))->getTimestamp();
$max = 0; $after = [];
for ($day = 0; $day < 400; $day++) {
    $t = $start + $day * $D;
    put($dir, fname('auto_backup', $t));
    bms_apply_retention($dir, $t);
    foreach ([6, 12, 18] as $h) { put($dir, fname('auto_snapshot', $t + $h * $H)); bms_apply_retention($dir, $t + $h * $H); }
    $count = count(names($dir));
    $max = max($max, $count);
    if ($day >= 370) $after[] = $count;
}
ok($max <= 8 + 4 + 13 + 9, "never more than " . (8 + 4 + 13 + 9) . " files at once (peak $max) — it does not grow with time");
ok(max($after) - min($after) <= 2, 'after a year the count is steady (' . min($after) . '–' . max($after) . ' files)');
$months = array_unique(array_map(fn($n) => (new DateTime('@' . bms_backup_file_info($n)['ts']))->setTimezone($tz)->format('Y-m'),
                                  array_filter(names($dir), fn($n) => str_starts_with($n, 'auto_backup_'))));
ok(count($months) >= 12, 'a backup from each of the last 12 months is still there (' . count($months) . ' months)');

// ═══════════════════════════════════════════════════════════════════════════
section('7. Wiring');
// ═══════════════════════════════════════════════════════════════════════════

// A target whose backup FAILS must keep its old files.
$dir = folder('failing');
$ancient = fname('auto_backup', time() - 400 * $D);
put($dir, $ancient);
bmsBackupRunAll('full', null, null, [[
    'key' => 'tfail', 'label' => 'Failing', 'tenant_id' => null, 'subdomain' => null,
    'db' => ['host' => '127.0.0.1', 'user' => 'x', 'pass' => null, 'name' => 'x'],
    'prefix' => 'tfail/', 'dir' => $dir, 'uploads' => true,
    'error' => 'could not decrypt stored credentials (check TENANT_CRED_KEY)',
]]);
ok(is_file($dir . $ancient), 'a target whose backup failed keeps its old files (retention runs only after a success)');

$src = fn($f) => file_get_contents("$root/$f");
ok(str_contains($src('core/tenant_backups.php'), 'bms_apply_retention($t[\'dir\'])'), 'the scheduled run applies retention per target');
ok(str_contains($src('app/constant/settings/backup_restore.php'), 'bms_apply_retention($backupsDir)'), "the Backup page's daily fallback uses the same policy");
ok(str_contains($src('cron/auto_backup.php'), 'bms_apply_retention($backupsDir)'), 'cron/auto_backup.php uses the same policy');
$all = '';
foreach (['core/backup.php', 'core/tenant_backups.php', 'app/constant/settings/backup_restore.php', 'cron/auto_backup.php', 'api/backup_actions.php'] as $f) $all .= $src($f);
ok(!str_contains($all, 'bms_prune_backups('), 'the old age-only bms_prune_backups() is gone everywhere');
