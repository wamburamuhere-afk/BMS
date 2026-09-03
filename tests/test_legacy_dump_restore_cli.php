<?php
/**
 * Legacy dump upgrade — CLI test
 *   php tests/test_legacy_dump_restore_cli.php
 *
 * WHY. Fixing bms_write_dump() on 2026-09-03 made NEW dumps restorable. It did
 * nothing for the files already on disk — and every backup taken before that
 * date uses `INSERT INTO t VALUES(...)` with no column list, so it supplies a
 * value for GENERATED columns. MySQL rejects those rows, and because
 * mysqli::multi_query STOPS at the first failing statement, every table after
 * the first offender is silently skipped. A restore reports "1 error" while
 * having loaded only part of the database. That is how a live recovery stopped
 * at product_stocks with 211 of 303 tables loaded, and why restoring
 * auto_backup_2026-08-31 still failed after the writer was fixed.
 *
 * bms_upgrade_legacy_dump() converts such a file in memory. This suite proves it
 * against a REAL database: build a legacy-format dump by hand, restore it raw
 * (must fail), restore it upgraded (must succeed and match row-for-row).
 *
 * Also covers the second failure found the same day: SHOW CREATE VIEW embeds
 * DEFINER=`someuser`@`localhost`, so a tenant restoring another account's dump
 * gets "Access denied; you need the SYSTEM_USER privilege". Dumps must be
 * portable between accounts.
 *
 * Creates and drops one throwaway database. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/includes/config.php";
require_once "$root/core/backup.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

$db     = 'bms_legacydump_' . bin2hex(random_bytes(4));
$madeDb = false;

register_shutdown_function(function () use (&$db, &$madeDb) {
    global $pass, $fail;
    if ($madeDb) {
        try {
            (new PDO('mysql:host=' . DB_SERVER, DB_USERNAME, DB_PASSWORD,
                     [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
                ->exec("DROP DATABASE IF EXISTS `$db`");
        } catch (Throwable $e) {}
    }
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});

echo "\n\033[1mLegacy dump upgrade\033[0m\n";

function loadSql(string $sql, string $db): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, $db);
    if ($m->connect_error) return ['connect: ' . $m->connect_error];
    $errors = [];
    if (!$m->multi_query($sql)) {
        $errors[] = $m->error;
    } else {
        while (true) {
            if ($r = $m->store_result()) $r->free();
            if ($m->errno) $errors[] = $m->error;
            if (!$m->more_results()) break;
            if (!$m->next_result()) { if ($m->errno) $errors[] = $m->error; break; }
        }
    }
    $m->close();
    return $errors;
}

// ═══════════════════════════════════════════════════════════════════════════
section('0. Fixture — a table with a generated column, and awkward data');
// ═══════════════════════════════════════════════════════════════════════════

try {
    $admin = new PDO('mysql:host=' . DB_SERVER, DB_USERNAME, DB_PASSWORD,
                     [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
    $madeDb = true;
} catch (Throwable $e) {
    echo "  \033[31mCannot create a scratch database: {$e->getMessage()}\033[0m\n";
    $fail++; return;
}

$p = new PDO("mysql:host=" . DB_SERVER . ";dbname=$db;charset=utf8mb4",
             DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$p->exec("CREATE TABLE `product_stocks` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    reserved DECIMAL(12,2) NOT NULL DEFAULT 0,
    available_quantity DECIMAL(12,2) AS (quantity - reserved) STORED,
    note TEXT NULL
) ENGINE=InnoDB");
// Sorts AFTER product_stocks, so in a legacy restore it is one of the tables
// that silently never loads.
$p->exec("CREATE TABLE `products` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(80) NOT NULL
) ENGINE=InnoDB");

$rows = [
    ['Cement', 100, 15, 'bags'],
    ['Sand', 42.5, 0.5, null],
    // The values that break a naive splitter: commas, quotes, backslashes,
    // newlines, and a doubled quote.
    ["O'Brien, Sons & Co", 7, 2, "line1\nline2, with comma \\ backslash 'quoted'"],
    ['Steel "grade A", 12mm', 3, 1, "tab\tand ''doubled''"],
];
$ins = $p->prepare("INSERT INTO product_stocks (name, quantity, reserved, note) VALUES (?,?,?,?)");
foreach ($rows as $r) $ins->execute($r);
$p->exec("INSERT INTO products (label) VALUES ('after-the-offender')");

$expectStock = (int)$p->query("SELECT COUNT(*) FROM product_stocks")->fetchColumn();
$expectProd  = (int)$p->query("SELECT COUNT(*) FROM products")->fetchColumn();
$expectAvail = $p->query("SELECT SUM(available_quantity) FROM product_stocks")->fetchColumn();
ok($expectStock === 4 && $expectProd === 1, "fixture built ($expectStock stock rows, $expectProd product row)");

// ═══════════════════════════════════════════════════════════════════════════
section('1. Build a dump in the OLD format (what every pre-fix backup contains)');
// ═══════════════════════════════════════════════════════════════════════════

$legacy = "-- BMS Database Backup\nSET FOREIGN_KEY_CHECKS=0;\n";
foreach (['product_stocks', 'products'] as $t) {
    $create = $p->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
    $legacy .= "\nDROP TABLE IF EXISTS `$t`;\n" . $create[1] . ";\n\n";
    foreach ($p->query("SELECT * FROM `$t`", PDO::FETCH_ASSOC) as $row) {
        $vals = array_map(fn($v) => $v === null ? 'NULL' : $p->quote($v), $row);
        $legacy .= "INSERT INTO `$t` VALUES(" . implode(',', $vals) . ");\n";   // no column list
    }
}
$legacy .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
ok(strpos($legacy, 'INSERT INTO `product_stocks` VALUES(') !== false, 'legacy dump has no column list (as the real ones do)');

// ═══════════════════════════════════════════════════════════════════════════
section('2. NEGATIVE CONTROL — the legacy dump must fail, and truncate');
// ═══════════════════════════════════════════════════════════════════════════

// Plant a marker AFTER the dump was captured. If the restore reached the
// `products` section it would DROP the table and the marker would be gone.
// If the marker survives, the restore never got that far — which is the whole
// danger: the operator is told "1 error" while later tables keep whatever stale
// content they had, silently diverging from the backup they think they restored.
$p->exec("INSERT INTO products (label) VALUES ('MARKER-planted-after-dump')");

$errs = loadSql($legacy, $db);
$sawGenerated = false;
foreach ($errs as $e) if (stripos($e, 'generated column') !== false) $sawGenerated = true;
ok($sawGenerated, 'restoring it raw fails on the generated column, exactly as in production');
ok((int)$p->query("SELECT COUNT(*) FROM product_stocks")->fetchColumn() === 0,
   'and product_stocks is empty');
$marker = (int)$p->query("SELECT COUNT(*) FROM products WHERE label = 'MARKER-planted-after-dump'")->fetchColumn();
ok($marker === 1,
   'AND everything after the offender was never executed — the marker survives, so `products` still holds STALE data the backup does not contain. This is the silent divergence.');
$p->exec("DELETE FROM products WHERE label = 'MARKER-planted-after-dump'");

// ═══════════════════════════════════════════════════════════════════════════
section('3. The same dump, upgraded, restores completely');
// ═══════════════════════════════════════════════════════════════════════════

$up = bms_upgrade_legacy_dump($legacy);
ok($up['rows'] === 4, "upgrade rewrote {$up['rows']} row(s) (expected 4)");
ok($up['tables'] === ['product_stocks'], 'only the table with a generated column was touched');
ok(strpos($up['sql'], 'INSERT INTO `product_stocks` (`id`,`name`,`quantity`,`reserved`,`note`) VALUES(') !== false,
   'rewritten INSERT carries a column list with the generated column removed');
ok(strpos($up['sql'], "INSERT INTO `products` VALUES(") !== false,
   'a table with NO generated column is left byte-for-byte alone');

$errs2 = loadSql($up['sql'], $db);
ok(empty($errs2), 'upgraded dump restores with NO errors' . ($errs2 ? ' — got: ' . implode(' | ', array_slice($errs2, 0, 2)) : ''));

$gotStock = (int)$p->query("SELECT COUNT(*) FROM product_stocks")->fetchColumn();
$gotProd  = (int)$p->query("SELECT COUNT(*) FROM products")->fetchColumn();
ok($gotStock === $expectStock, "every stock row restored ($gotStock/$expectStock)");
ok($gotProd === $expectProd,   "the table after the offender restored too ($gotProd/$expectProd)");

$gotAvail = $p->query("SELECT SUM(available_quantity) FROM product_stocks")->fetchColumn();
ok((float)$gotAvail === (float)$expectAvail, "generated column recomputed correctly ($gotAvail == $expectAvail)");

// The data itself must survive intact — this is where a naive value splitter
// silently corrupts rows.
$got = $p->query("SELECT name, note FROM product_stocks ORDER BY id")->fetchAll(PDO::FETCH_NUM);
$allMatch = true;
foreach ($rows as $i => $r) {
    if (($got[$i][0] ?? null) !== $r[0] || ($got[$i][1] ?? null) !== $r[3]) {
        $allMatch = false;
        echo "        \033[31mrow $i: got [" . var_export($got[$i][0] ?? null, true)
           . ", " . var_export($got[$i][1] ?? null, true) . "]\033[0m\n";
    }
}
ok($allMatch, 'commas, quotes, doubled quotes, backslashes, tabs and newlines all survived byte-for-byte');

// ═══════════════════════════════════════════════════════════════════════════
section('4. The value splitter refuses to guess');
// ═══════════════════════════════════════════════════════════════════════════

ok(bms_split_sql_values("1,'a','b'") === ['1', "'a'", "'b'"], 'splits a simple row');
ok(bms_split_sql_values("1,'a,b',2") === ['1', "'a,b'", '2'], 'a comma inside a string is not a separator');
ok(bms_split_sql_values("1,'it''s',2") === ['1', "'it''s'", '2'], "a doubled quote stays inside the string");
ok(bms_split_sql_values("1,'a\\'b',2") === ['1', "'a\\'b'", '2'], 'a backslash-escaped quote stays inside the string');
ok(bms_split_sql_values("1,CONCAT('a','b'),2") === ['1', "CONCAT('a','b')", '2'], 'commas inside a function call are not separators');
ok(bms_split_sql_values("1,'unterminated") === null, 'an unterminated string returns NULL rather than a wrong split');
ok(bms_split_sql_values("1,(2,3") === null, 'unbalanced parentheses return NULL rather than a wrong split');

// A row the splitter cannot parse must be passed through untouched, never
// rewritten into something plausible-but-wrong.
$weird = "SET FOREIGN_KEY_CHECKS=0;\nCREATE TABLE `t` (\n`a` int,\n`b` int GENERATED ALWAYS AS (a+1) STORED\n);\n"
       . "INSERT INTO `t` VALUES(1,'unterminated);\n";
$w = bms_upgrade_legacy_dump($weird);
ok(strpos($w['sql'], "INSERT INTO `t` VALUES(1,'unterminated);") !== false,
   'an unparseable INSERT is passed through untouched, not corrupted');

// ═══════════════════════════════════════════════════════════════════════════
section('5. Dumps must be portable between MySQL accounts (the SYSTEM_USER error)');
// ═══════════════════════════════════════════════════════════════════════════

$viewSql = "CREATE ALGORITHM=UNDEFINED DEFINER=`bejundas`@`localhost` SQL SECURITY DEFINER VIEW `v` AS select 1";
$portable = bms_portable_view_sql($viewSql);
ok(stripos($portable, 'DEFINER=') === false, 'DEFINER is stripped from CREATE VIEW');
ok(stripos($portable, 'SQL SECURITY INVOKER') !== false, 'SQL SECURITY DEFINER becomes INVOKER');
ok(stripos($portable, 'VIEW `v`') !== false, 'the view itself is otherwise unchanged');

// Real views from this database must come out portable too.
$views = $p->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM);
$tmp = sys_get_temp_dir() . '/bms_legacy_view_' . getmypid() . '.sql';
bms_write_dump($p, $tmp);
$dumped = file_get_contents($tmp);
@unlink($tmp);
ok(stripos($dumped, 'DEFINER=') === false,
   'a freshly written dump contains no DEFINER at all (' . count($views) . ' view(s) in fixture)');
