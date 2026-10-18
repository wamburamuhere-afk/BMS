<?php
/**
 * Every tenant migration has a legacy-DB mirror (2026-10-18 regression guard)
 *   php tests/test_legacy_migration_mirrors_cli.php
 *
 * The root install (BJP's books on bms/demo) runs on the legacy DB and only
 * gets migrations/*.php. migrations/tenant/2026_10_17_service_plans.php shipped
 * without its mirror, so linking a service to a platform product failed on the
 * root. From 2026-10-13 on, each tenant migration must have
 * migrations/<same name>_legacy_db.php, or say "legacy-mirror: not needed" with
 * a reason in its header. Static only — runs in CI. Exit 0 = pass.
 */
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }

echo "\n── tenant migrations from 2026-10-13 have a legacy mirror ──\n";
foreach (glob("$root/migrations/tenant/*.php") as $f) {
    $b = basename($f, '.php');
    if (strcmp(substr($b, 0, 10), '2026_10_13') < 0) continue;
    $hasMirror = is_file("$root/migrations/{$b}_legacy_db.php") || glob("$root/migrations/*_" . substr($b, 11) . "_legacy_db.php");
    $optedOut  = stripos(file_get_contents($f), 'legacy-mirror: not needed') !== false;
    ok($hasMirror || $optedOut, "$b" . ($optedOut && !$hasMirror ? ' (opted out)' : ''));
}

$m = "$root/migrations/2026_10_18_service_plans_legacy_db.php";
ok(is_file($m) && strpos(file_get_contents($m), 'spEnsureSchema($pdo)') !== false, 'service_plans mirror runs spEnsureSchema()');

echo "\nPasses: $pass\nFailures: $fail\n";
exit($fail === 0 ? 0 : 1);
