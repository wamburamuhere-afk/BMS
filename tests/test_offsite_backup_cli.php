<?php
/**
 * Off-site backup (rclone → Google Drive, encrypted) — CLI test
 *   php tests/test_offsite_backup_cli.php
 *
 * core/offsite_backup.php + cron/offsite_backup.php upload every new nightly
 * auto_backup_*.zip to an rclone `crypt` remote, verify it, then apply off-site
 * retention (30 days / first of month 12 months / 3 newest always).
 *
 * Sections 1–3 need nothing. Section 4 runs the REAL script against a real
 * rclone with a crypt remote over a local folder (stands in for Google Drive);
 * it is skipped when no rclone binary is found (set BMS_TEST_RCLONE=<path> or
 * put rclone on PATH). No database needed. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/core/backup.php";
require_once "$root/core/offsite_backup.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

$tz   = new DateTimeZone('Africa/Dar_es_Salaam');
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_offsite_' . bin2hex(random_bytes(4));
mkdir($work, 0755, true);
register_shutdown_function(function () use ($work) {
    global $pass, $fail;
    if (is_dir($work)) bms_delete_dir($work);
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});

function fname(string $kind, int $ts): string
{
    global $tz;
    return $kind . '_' . (new DateTime('@' . $ts))->setTimezone($tz)->format('Y-m-d_H-i-s') . '.zip';
}
function put(string $path, string $body = 'x'): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    file_put_contents($path, $body);
}

$H = 3600; $D = 86400;
$now = (new DateTime('2026-10-31 01:30:00', $tz))->getTimestamp();

echo "\n\033[1mOff-site backup (rclone crypt)\033[0m\n";

// ═══════════════════════════════════════════════════════════════════════════
section('1. Off-site retention rules');
// ═══════════════════════════════════════════════════════════════════════════

$n = [];
for ($i = 0; $i <= 40; $i++) $n[$i] = fname('auto_backup', $now - $i * $D - $H);   // nightly 00:30
$del = bmsOffsiteRetentionPlan(array_values($n), $now);
ok(!array_intersect($del, array_slice($n, 0, 31)), 'every nightly of the last 30 days is kept');
ok(in_array($n[31], $del, true) && in_array($n[35], $del, true) && count($del) === 9, 'nightlies older than 30 days are deleted (Sep 22–30)');
ok(!in_array($n[40], $del, true) && str_contains($n[40], '2026-09-21'),
   'the oldest September backup it holds (Sep 21, 40 days) is kept as that month\'s monthly');

$old = [
    fname('auto_backup', (new DateTime('2025-09-01 00:30:00', $tz))->getTimestamp()),   // > 12 months
    fname('auto_backup', (new DateTime('2025-12-01 00:30:00', $tz))->getTimestamp()),   // monthly, 11 months
    fname('auto_backup', (new DateTime('2025-12-02 00:30:00', $tz))->getTimestamp()),   // 2nd of month
];
$del = bmsOffsiteRetentionPlan(array_merge($old, array_slice($n, 0, 5)), $now);
ok($del === [$old[0], $old[2]], 'monthly kept for 12 months; older monthly and non-first days deleted');

$stale = [fname('auto_backup', $now - 500 * $D), fname('auto_backup', $now - 501 * $D),
          fname('auto_backup', $now - 502 * $D), fname('auto_backup', $now - 503 * $D)];
$del = bmsOffsiteRetentionPlan($stale, $now);
ok(count($del) === 1 && $del[0] === $stale[3], 'backups stopped 500 days ago: the 3 newest are never deleted');

$foreign = ['bms_backup_2020-01-01_00-00-00.zip', 'auto_snapshot_2020-01-01_00-00-00.zip',
            'pre_restore_2020-01-01_00-00-00.zip', 'auto_backup_2020-01-01_00-00-00.sql', 'notes.txt',
            fname('auto_backup', $now - 400 * $D)];
ok(bmsOffsiteRetentionPlan($foreign, $now) === [], 'only auto_backup_*.zip is ever deleted — anything else is never touched');
ok(bmsOffsiteRetentionPlan([], $now) === [], 'empty folder → nothing to delete');

// 400 nights: the server keeps its own set (bms_apply_retention); off-site gets
// each night's file. Off-site must always hold everything the server holds,
// and never delete a file the uploader would send again (≤ 7 days).
$srv = "$work/sim_server/";
mkdir($srv, 0755, true);
$remote = []; $superset = true; $churn = false; $maxRemote = 0;
$start = (new DateTime('2026-01-01 00:30:00', $tz))->getTimestamp();
for ($day = 0; $day < 400; $day++) {
    $t = $start + $day * $D;
    $f = fname('auto_backup', $t);
    put($srv . $f);
    bms_apply_retention($srv, $t);
    $remote[$f] = true;
    $runAt = $t + $H;
    foreach (bmsOffsiteRetentionPlan(array_keys($remote), $runAt) as $d) {
        unset($remote[$d]);
        if ($runAt - bms_backup_file_info($d)['ts'] <= 7 * $D) $churn = true;
    }
    foreach (array_map('basename', glob($srv . 'auto_backup_*.zip')) as $s) {
        if (!isset($remote[$s])) $superset = false;
    }
    $maxRemote = max($maxRemote, count($remote));
}
ok($superset, 'over 400 nights, off-site always holds every backup the server still holds');
ok(!$churn, 'off-site never deletes a file the uploader would send again (no re-upload loop)');
ok($maxRemote <= 44, "off-site stays bounded: at most $maxRemote files per folder (30 daily + ≤ 13 monthly)");

// ═══════════════════════════════════════════════════════════════════════════
section('2. Which local files go off-site');
// ═══════════════════════════════════════════════════════════════════════════

$b = "$work/backups1";
$fresh = fname('auto_backup', $now - $H);
put("$b/$fresh");
put("$b/t1/$fresh");
put("$b/t22/" . fname('auto_backup', $now - 6 * $D));
put("$b/_platform/$fresh");
put("$b/t1/" . fname('auto_backup', $now - 8 * $D));         // older than 7 days
put("$b/t1/" . fname('auto_snapshot', $now - $H));           // snapshot
put("$b/t1/bms_backup_" . date('Y-m-d_H-i-s', $now) . '.zip'); // manual
put("$b/t1/" . fname('pre_restore', $now - $H));
put("$b/t1/$fresh.Ab12Cd");                                   // libzip temp name (still writing)
put("$b/t1/" . fname('auto_backup', $now + 2 * $D));          // clock in the future
put("$b/tmp/$fresh");                                         // not a backup folder
put("$b/t1x/$fresh");
$c = bmsOffsiteLocalCandidates($b, $now);
ok($c === ["_platform/$fresh", $fresh, "t1/$fresh", 't22/' . fname('auto_backup', $now - 6 * $D)],
   'main install, tenants and control DB nightlies of the last 7 days — nothing else');
ok(!array_filter($c, fn($p) => str_contains($p, 'snapshot') || str_contains($p, 'bms_backup') || str_contains($p, 'pre_restore')),
   'snapshots, manual and pre-restore files never leave the server');
ok(!in_array("tmp/$fresh", $c, true) && !in_array("t1x/$fresh", $c, true), 'folders other than main / t{id} / _platform are ignored');
ok(bmsOffsiteLocalCandidates("$work/empty_nowhere", $now) === [], 'missing backups folder → empty list, no error');

// ═══════════════════════════════════════════════════════════════════════════
section('3. Script guards (no rclone)');
// ═══════════════════════════════════════════════════════════════════════════

$php    = escapeshellarg(PHP_BINARY);
$script = escapeshellarg("$root/cron/offsite_backup.php");
exec("$php $script 2>&1", $o, $code);
ok($code === 2, 'no --host → usage, exit 2');
exec("$php $script --host=\"bad host;rm\" 2>&1", $o, $code);
ok($code === 2, 'unsafe --host rejected, exit 2');

$b3 = "$work/backups3";
put("$b3/t1/" . fname('auto_backup', time() - $H));
$o = [];
exec("$php $script --host=h.test --rclone=" . escapeshellarg("$work/no_such_rclone") . ' --backups=' . escapeshellarg($b3) . ' --no-wait 2>&1', $o, $code);
ok($code === 3, 'rclone missing → exit 3 (not configured)');
$log = (string)@file_get_contents("$b3/_platform/offsite.log");
ok(str_contains($log, 'NOT CONFIGURED — rclone is not installed'), 'the reason is written to backups/_platform/offsite.log');
$st = json_decode((string)@file_get_contents("$b3/_platform/offsite_status.json"), true);
ok(($st['status'] ?? '') === 'not_configured' && ($st['host'] ?? '') === 'h.test', 'offsite_status.json records the last run for monitoring');
ok(!is_file("$b3/.backup_run.lock"), 'never creates the backup run lock (a root-owned lock would block the www-data backup)');
$o = [];
exec("$php $script --host=h.test --check --rclone=" . escapeshellarg("$work/no_such_rclone") . ' 2>&1', $o, $code);
ok($code === 3 && str_contains(implode("\n", $o), 'NOT READY'), '--check reports NOT READY, exit 3');

// ═══════════════════════════════════════════════════════════════════════════
section('4. Real rclone, encrypted remote');
// ═══════════════════════════════════════════════════════════════════════════

$rclone = getenv('BMS_TEST_RCLONE') ?: '';
if ($rclone === '') {
    $w = trim((string)@shell_exec(DIRECTORY_SEPARATOR === '/' ? 'command -v rclone 2>/dev/null' : 'where rclone 2>NUL'));
    $rclone = $w !== '' ? strtok($w, "\r\n") : '';
}
if ($rclone === '' || !is_file($rclone)) {
    echo "  (skipped — no rclone binary; set BMS_TEST_RCLONE to run)\n";
    return;
}

$drive = "$work/drive";        // stands in for Google Drive
mkdir($drive, 0755, true);
$obsc = trim((string)shell_exec(escapeshellarg($rclone) . ' obscure test-password-123'));
$conf = "$work/rclone.conf";
file_put_contents($conf, "[plain]\ntype = local\n\n[bms-offsite]\ntype = crypt\nremote = " . str_replace('\\', '/', $drive)
    . "\nfilename_encryption = standard\ndirectory_name_encryption = true\npassword = $obsc\n");
$cfg = ['rclone' => $rclone, 'config' => $conf, 'remote' => 'bms-offsite', 'host' => 'bms.test', 'backups' => ''];

ok(bmsOffsiteCheck($cfg) === null, 'crypt remote accepted');
ok(str_contains((string)bmsOffsiteCheck(['remote' => 'plain'] + $cfg), "not 'crypt'"), 'a plain (unencrypted) remote is refused');
ok(str_contains((string)bmsOffsiteCheck(['remote' => 'nope'] + $cfg), 'is not configured'), 'an unknown remote is refused');

$t = time();
$b4 = "$work/backups4";
$files = [
    fname('auto_backup', $t - $H),
    't1/' . fname('auto_backup', $t - $H),
    't1/' . fname('auto_backup', $t - $D - $H),
    't7/' . fname('auto_backup', $t - $H),
    '_platform/' . fname('auto_backup', $t - $H),
];
foreach ($files as $i => $p) put("$b4/$p", "PK-zip-body-$i-" . str_repeat('secret tenant data ', 200));
put("$b4/t1/" . fname('auto_snapshot', $t - $H), 'snapshot');
put("$b4/t1/" . fname('auto_backup', $t - 9 * $D), 'too old');

$base = "$php $script --host=bms.test --rclone=" . escapeshellarg($rclone) . ' --config=' . escapeshellarg($conf)
      . ' --backups=' . escapeshellarg($b4) . ' --no-wait 2>&1';
$o = [];
exec(str_replace(' --no-wait', ' --check --no-wait', $base), $o, $code);
ok($code === 0 && str_contains(implode("\n", $o), 'OK — off-site is ready'), '--check: ready, exit 0');

$o = [];
exec($base, $o, $code);
$out = implode("\n", $o);
ok($code === 0, 'first run exits 0');
ok(str_contains($out, 'Finished: 5 uploaded, 0 removed'), 'uploads exactly the 5 nightlies (main, 2 tenants, control) — not the snapshot or the 9-day-old file');

$remoteList = bmsOffsiteListRemote(['backups' => $b4] + $cfg, 'bms-offsite:bms.test');
$got = array_keys($remoteList['files']); sort($got);
$want = $files; sort($want);
ok($got === $want, 'off-site layout mirrors backups/ (main, t1, t7, _platform)');

$raw = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($drive, FilesystemIterator::SKIP_DOTS)) as $f) $raw[] = $f->getPathname();
$segments = [];
foreach ($raw as $p) {
    foreach (preg_split('#[\\\\/]#', substr($p, strlen($drive) + 1)) as $s) $segments[$s] = true;
}
$plainNames = array_filter(array_keys($segments),
    fn($s) => in_array($s, ['bms.test', 't1', 't7', '_platform'], true) || str_contains($s, 'auto_backup'));
ok(count($raw) === 5 && count($segments) >= 6 && !$plainNames, 'on "Google Drive" every file and folder name is encrypted');
ok(!array_filter($raw, fn($p) => str_contains((string)file_get_contents($p), 'secret tenant data')), 'file contents are encrypted (no plaintext)');

$back = "$work/restored";
$r = bmsOffsiteRclone($cfg, ['copy', 'bms-offsite:bms.test/t1', $back]);
$same = $r['code'] === 0;
foreach (glob("$b4/t1/auto_backup_*.zip") as $p) {
    if (str_contains(basename($p), date('Y-m-d', $t - 9 * $D))) continue;
    $same = $same && is_file("$back/" . basename($p)) && md5_file($p) === md5_file("$back/" . basename($p));
}
ok($same, 'restore: files decrypted from off-site are byte-identical to the originals');

$o = [];
exec($base, $o, $code);
ok($code === 0 && str_contains(implode("\n", $o), 'Finished: 0 uploaded, 0 removed'), 'second run uploads nothing (already off-site)');

// An off-site file is never overwritten, even if the local file changed.
$changed = "$b4/t7/" . fname('auto_backup', $t - $H);
file_put_contents($changed, 'tampered');
exec($base, $o, $code);
$r = bmsOffsiteRclone($cfg, ['cat', 'bms-offsite:bms.test/t7/' . basename($changed)]);
ok(str_starts_with($r['out'], 'PK-zip-body-3'), 'an existing off-site copy is never overwritten');

// Retention off-site: seed old copies, then run.
$seed = "$work/seed/t1";
$oldNames = [
    fname('auto_backup', $t - 400 * $D),                                                        // monthly, > 12 months
    fname('auto_backup', (new DateTime(date('Y-m', $t - 70 * $D) . '-01 00:30:00', $tz))->getTimestamp()), // monthly
    fname('auto_backup', (new DateTime(date('Y-m', $t - 70 * $D) . '-02 00:30:00', $tz))->getTimestamp()), // 2nd of that month
    'bms_backup_2020-01-01_00-00-00.zip',                                                        // manual, never touched
    fname('auto_backup', $t - 2 * $D - $H),   // with t1's two uploads: 3 recent, so the
                                              // 3-newest floor does not cover the old ones
];
foreach ($oldNames as $nm) put("$seed/$nm", 'old');
bmsOffsiteRclone($cfg, ['copy', $seed, 'bms-offsite:bms.test/t1']);
bmsOffsiteRclone($cfg, ['copy', "$work/seed/t1", 'bms-offsite:bms.test/zz_other']);   // unknown folder
$o = [];
exec($base, $o, $code);
$out = implode("\n", $o);
$after = array_keys(bmsOffsiteListRemote($cfg, 'bms-offsite:bms.test')['files']);
ok($code === 0 && str_contains($out, '2 removed by retention'), 'run reports the retention deletions');
ok(!in_array('t1/' . $oldNames[0], $after, true) && !in_array('t1/' . $oldNames[2], $after, true),
   'off-site: 400-day-old and non-first-of-month copies deleted');
ok(in_array('t1/' . $oldNames[1], $after, true), 'off-site: first-of-month copy kept');
ok(in_array('t1/bms_backup_2020-01-01_00-00-00.zip', $after, true), 'off-site: a non-automatic file is never deleted');
ok(count(array_filter($after, fn($p) => str_starts_with($p, 'zz_other/'))) === count($oldNames), 'off-site: an unknown folder is never touched');

$st = json_decode((string)file_get_contents("$b4/_platform/offsite_status.json"), true);
ok(($st['status'] ?? '') === 'ok' && ($st['deleted'] ?? -1) === 2 && ($st['remote_files'] ?? 0) === count($after),
   'status file: ok, deletions and current off-site file count');

// Upload failure → no retention, exit 1.
$cfgBad = "$work/rclone_bad.conf";
file_put_contents($cfgBad, "[bms-offsite]\ntype = crypt\nremote = " . str_replace('\\', '/', "$work/file_not_dir.txt") . "/x\npassword = $obsc\n");
put("$work/file_not_dir.txt", 'x');
$o = [];
exec(str_replace(escapeshellarg($conf), escapeshellarg($cfgBad), $base), $o, $code);
ok($code === 1 && str_contains(implode("\n", $o), 'FAILED —'), 'unreachable destination → FAILED in the log, exit 1');
