<?php
/**
 * Location engine — CLI regression test.
 *
 * Covers: class lint/wiring, live repository reads on the imported
 * Tanzania frame, data-driven select/freetext mode, hierarchy validation,
 * sync idempotency (re-run inserts nothing), and the options API endpoint
 * exercised through its real code path.
 *
 * Run: php tests/test_location_engine_cli.php
 */
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/Location/bootstrap.php';
global $pdo;

$pass = 0; $fail = 0;
function ok(bool $cond, string $msg): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[32m✅\033[0m $msg\n"; }
    else       { $fail++; echo "  \033[31m❌\033[0m $msg\n"; }
}
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$repo    = new LocationRepository($pdo);
$service = new LocationService($repo);

// ─────────────────────────────────────────────────────────────────────
section('1. Engine files lint-clean');
foreach ([
    'core/Location/LocationRepository.php',
    'core/Location/LocationService.php',
    'core/Location/LocationSyncService.php',
    'core/Location/Providers/LocationProviderInterface.php',
    'core/Location/Providers/MtaaCsvProvider.php',
    'api/location/options.php',
    'api/location/sync.php',
] as $f) {
    $out = shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../' . $f) . ' 2>&1');
    ok(strpos((string)$out, 'No syntax errors') !== false, "$f lint-clean");
}

// ─────────────────────────────────────────────────────────────────────
section('2. Repository reads the imported Tanzania frame (live)');
$countries = $repo->countries();
ok(count($countries) === 10, '10 East African countries listed (got ' . count($countries) . ')');
ok($countries[0]['name'] === 'Tanzania', 'Tanzania is listed first');
$tz = $repo->findCountryByName('Tanzania');
$ke = $repo->findCountryByName('Kenya');
ok($tz !== null && $ke !== null, 'Tanzania and Kenya resolvable by name');

$tzRow = null;
foreach ($countries as $c) { if ($c['name'] === 'Tanzania') $tzRow = $c; }
ok((int)$tzRow['has_regions'] === 1, 'Tanzania flagged has_regions=1 (select mode)');
$keRow = null;
foreach ($countries as $c) { if ($c['name'] === 'Kenya') $keRow = $c; }
ok((int)$keRow['has_regions'] === 0, 'Kenya flagged has_regions=0 (free-text mode)');

$regions = $repo->regionsOf((int)$tz['id']);
ok(count($regions) === 31, 'Tanzania has all 31 regions (got ' . count($regions) . ')');

$dar = null;
foreach ($regions as $r) { if ($r['name'] === 'Dar es Salaam') $dar = $r; }
ok($dar !== null, 'Dar es Salaam region present');

$districts = $repo->districtsOf((int)$dar['id']);
ok(count($districts) === 5, 'Dar es Salaam has its 5 districts (got ' . count($districts) . ')');

$ilala = null;
foreach ($districts as $d) { if (stripos($d['name'], 'Ilala') !== false) $ilala = $d; }
ok($ilala !== null, 'Ilala District present');

$wards = $repo->wardsOf((int)$ilala['id']);
ok(count($wards) >= 20, 'Ilala has a full ward list (got ' . count($wards) . ')');

$kariakoo = null;
foreach ($wards as $w) { if (strcasecmp($w['name'], 'Kariakoo') === 0) $kariakoo = $w; }
ok($kariakoo !== null, 'Kariakoo ward imported under Ilala');

$villages = $repo->villagesOf((int)$kariakoo['id']);
ok(count($villages) >= 1, 'Kariakoo has streets/villages (got ' . count($villages) . ')');

$totWards = (int)$pdo->query("SELECT COUNT(*) FROM wards WHERE is_active = 1 AND district_id IS NOT NULL")->fetchColumn();
$totVills = (int)$pdo->query("SELECT COUNT(*) FROM villages WHERE is_active = 1")->fetchColumn();
ok($totWards > 3900, "full ward coverage imported ($totWards active wards)");
ok($totVills > 16000, "full street/village coverage imported ($totVills villages)");
$legacy = (int)$pdo->query("SELECT COUNT(*) FROM wards WHERE district_id IS NULL AND is_active = 1")->fetchColumn();
ok($legacy === 0, 'legacy council-keyed ward rows are deactivated');

// ─────────────────────────────────────────────────────────────────────
section('3. Data-driven entry mode + hierarchy validation');
ok($service->modeForCountryName('Tanzania') === LocationService::MODE_SELECT, 'Tanzania → select mode');
ok($service->modeForCountryName('Kenya') === LocationService::MODE_FREETEXT, 'Kenya → free-text mode (no regions imported yet)');
ok($service->modeForCountryName('Germany') === LocationService::MODE_FREETEXT, 'unknown country → free-text mode');

$chainOk = false;
try {
    $chainOk = $service->validateChain((int)$tz['id'], (int)$dar['id'], (int)$ilala['id'], (int)$kariakoo['id'], (int)$villages[0]['id']);
} catch (Throwable $e) { /* fail below */ }
ok($chainOk === true, 'valid chain Tanzania→Dar→Ilala→Kariakoo→street passes');

$arusha = null;
foreach ($regions as $r) { if ($r['name'] === 'Arusha') $arusha = $r; }
$arushaDistricts = $repo->districtsOf((int)$arusha['id']);
$threw = false;
try {
    // Kariakoo ward (Ilala) claimed to be under an Arusha district → must throw
    $service->validateChain((int)$tz['id'], (int)$arusha['id'], (int)$arushaDistricts[0]['id'], (int)$kariakoo['id']);
} catch (InvalidArgumentException $e) { $threw = true; }
ok($threw, 'cross-district ward is rejected server-side');

$threw = false;
try { $service->validateChain((int)$tz['id'], 999999); } catch (InvalidArgumentException $e) { $threw = true; }
ok($threw, 'unknown region id is rejected');

// ─────────────────────────────────────────────────────────────────────
section('4. Sync is idempotent (re-run inserts nothing)');
$sync = new LocationSyncService($pdo);
$report = $sync->sync(new MtaaCsvProvider());
ok($report['wards_inserted'] === 0, 're-sync inserted 0 wards (got ' . $report['wards_inserted'] . ')');
ok($report['villages_inserted'] === 0, 're-sync inserted 0 villages (got ' . $report['villages_inserted'] . ')');
ok(count($report['districts_unmatched']) === 0, 'no unmatched mainland districts (got ' . count($report['districts_unmatched']) . ')');
ok(count($report['regions_matched']) === 26, 'all 26 dataset regions matched');
$logged = (int)$pdo->query("SELECT COUNT(*) FROM location_sync_log WHERE status = 'success'")->fetchColumn();
ok($logged >= 1, "sync runs are audited in location_sync_log ($logged success rows)");

// ─────────────────────────────────────────────────────────────────────
section('5. Options endpoint answers through its real code path');
function callOptions(array $get): array {
    $_GET = $get;
    $_SESSION['user_id'] = $_SESSION['user_id'] ?? 1; // simulate logged-in user
    ob_start();
    include __DIR__ . '/../api/location/options.php';
    $out = ob_get_clean();
    return json_decode($out, true) ?: [];
}
$res = callOptions(['level' => 'countries']);
ok(($res['success'] ?? false) && count($res['results']) === 10, 'level=countries returns 10 countries');
$tzOpt = null;
foreach ($res['results'] as $o) { if ($o['text'] === 'Tanzania') $tzOpt = $o; }
ok($tzOpt !== null && $tzOpt['has_regions'] === 1, 'countries payload carries has_regions for mode switching');

$res = callOptions(['level' => 'regions', 'parent_id' => (string)$tz['id']]);
ok(($res['success'] ?? false) && count($res['results']) === 31, 'level=regions returns the 31 regions');

$res = callOptions(['level' => 'wards', 'parent_id' => (string)$ilala['id'], 'q' => 'Kariakoo']);
ok(($res['success'] ?? false) && count($res['results']) === 1 && $res['results'][0]['text'] === 'Kariakoo',
   'level=wards honours the q search filter');

$res = callOptions(['level' => 'nonsense']);
ok(($res['success'] ?? true) === false, 'invalid level is rejected');

// ─────────────────────────────────────────────────────────────────────
echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail ? "\033[31m$fail\033[0m" : "\033[32m0\033[0m") . "\n";
exit($fail ? 1 : 0);
