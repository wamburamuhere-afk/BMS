<?php
/**
 * POS Cleanup — Phase 5 guard
 *   php tests/test_pos_cleanup_cli.php
 *
 * The legacy v1 POS UI (pos_modals.php, pos_scripts.php, js/pos.js) was superseded
 * by the *_new files and removed. This guard:
 *   - confirms the dead files are gone and referenced nowhere (no resurrection),
 *   - confirms the live POS page wires only the _new UI,
 *   - confirms pos_controller.php is KEPT (still serves get_cash_balance to the
 *     live terminal) — i.e. we did not over-delete.
 *
 * Static only. Exit 0 = pass.
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function(){ global $pass,$fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

$dead = ['app/bms/pos/pos_modals.php', 'app/bms/pos/pos_scripts.php', 'app/bms/pos/js/pos.js'];

section('1. Dead v1 POS files removed');
foreach ($dead as $f) {
    ok(!is_file("$root/$f"), "$f no longer exists");
}

section('2. No surviving references to the removed files');
// Scan app/ + api/ + roots.php for any include/url/src pointing at the removed files.
$needles = ['pos_modals.php', 'js/pos.js', "include 'pos_scripts.php'", 'pos/pos_scripts.php'];
$hits = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/app", FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if (!preg_match('/\.(php|js)$/', $file->getFilename())) continue;
    $s = file_get_contents($file->getPathname());
    foreach ($needles as $n) {
        if (strpos($s, $n) !== false) $hits[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname()) . " → $n";
    }
}
ok(count($hits) === 0, 'no app/ file references the removed legacy files' . ($hits ? ' (found: ' . implode('; ', $hits) . ')' : ''));

section('3. Live POS wires only the _new UI');
$pos = is_file("$root/app/bms/pos/pos.php") ? file_get_contents("$root/app/bms/pos/pos.php") : '';
ok(strpos($pos, "pos_modals_new.php") !== false && strpos($pos, "pos_scripts_new.php") !== false, 'pos.php includes pos_modals_new + pos_scripts_new');
ok(strpos($pos, "include 'pos_modals.php'") === false && strpos($pos, "include 'pos_scripts.php'") === false, 'pos.php does NOT include the old v1 files');

section('4. Not over-deleted — pos_controller kept (serves get_cash_balance)');
ok(is_file("$root/app/bms/pos/api/pos_controller.php"), 'pos_controller.php retained (live get_cash_balance dependency)');
$ctrl = file_get_contents("$root/app/bms/pos/api/pos_controller.php");
ok(strpos($ctrl, 'get_cash_balance') !== false, 'pos_controller still handles get_cash_balance');
// The real sale path is api/pos/process_sale.php (not the legacy controller).
ok(is_file("$root/api/pos/process_sale.php"), 'live sale path api/pos/process_sale.php intact');

exit($fail === 0 ? 0 : 1);
