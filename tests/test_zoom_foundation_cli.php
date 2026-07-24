<?php
/**
 * Zoom Integration — Phase 1 (foundation) CLI test
 *   php tests/test_zoom_foundation_cli.php
 *
 * Verifies the foundation without needing a live Zoom account:
 *   - zoom_* settings + 'zoom_settings' permission exist
 *   - zoomConfigured() reflects the stored config (enabled + all 3 credentials)
 *   - settings page + save/test endpoints lint and are admin-gated
 * Read-only except a rolled-back settings probe. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/zoom_service.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src($root,$rel){ $p="$root/$rel"; return is_file($p)?file_get_contents($p):''; }
register_shutdown_function(function(){ global $pass,$fail,$pdo; if($pdo && $pdo->inTransaction()) $pdo->rollBack(); echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

try {
    section('1. Schema + seeds');
    $keys = $pdo->query("SELECT setting_key FROM system_settings WHERE setting_group='zoom'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['zoom_enabled','zoom_account_id','zoom_client_id','zoom_client_secret_enc'] as $k) {
        ok(in_array($k,$keys,true), "setting '$k' seeded");
    }
    ok(in_array(getSetting('zoom_enabled','0'),['0','1'],true), 'zoom_enabled is a valid 0/1 flag');
    ok((int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE page_key='zoom_settings'")->fetchColumn()===1, "permission 'zoom_settings' seeded");

    section('2. zoomConfigured() logic');
    $rawEnabled = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='zoom_enabled'")->fetchColumn() === '1';
    $rawAccount = trim((string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='zoom_account_id'")->fetchColumn());
    $rawClient  = trim((string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='zoom_client_id'")->fetchColumn());
    $rawSecret  = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='zoom_client_secret_enc'")->fetchColumn();
    $expectConfigured = $rawEnabled && $rawAccount !== '' && $rawClient !== '' && $rawSecret !== '' && decryptSecret($rawSecret) !== null && decryptSecret($rawSecret) !== '';
    ok(is_bool(zoomConfigured()), 'zoomConfigured() returns a boolean');
    ok(zoomConfigured() === $expectConfigured, 'zoomConfigured() reflects the stored config');

    // Disabled-state guard: zoomGetAccessToken must refuse gracefully (no throw, no network call).
    $before = zoomConfigured();
    if ($before) {
        // Temporarily flip enabled off (rolled back) to exercise the false branch too.
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE system_settings SET setting_value='0' WHERE setting_key='zoom_enabled'")->execute();
        $res = zoomGetAccessToken();
        ok($res['success']===false && stripos($res['message'],'not configured')!==false, 'zoomGetAccessToken() refuses gracefully when disabled');
        $pdo->rollBack();
    } else {
        $res = zoomGetAccessToken();
        ok($res['success']===false, 'zoomGetAccessToken() refuses gracefully when unconfigured');
    }

    section('3. Endpoints + page lint & gating');
    foreach (['core/zoom_service.php','app/constant/settings/zoom_settings.php','api/zoom/save_zoom_settings.php','api/zoom/test_zoom_config.php'] as $f){
        $out=[];$rc=0; exec('php -l '.escapeshellarg("$root/$f").' 2>&1',$out,$rc);
        ok($rc===0, "$f lint-clean");
    }
    $save=src($root,'api/zoom/save_zoom_settings.php');
    ok(strpos($save,'isAdmin()')!==false, 'save endpoint admin-gated');
    ok(strpos($save,'csrf_check()')!==false, 'save endpoint CSRF-checked');
    ok(strpos($save,'encryptSecret(')!==false, 'save endpoint encrypts the secret');
    ok(strpos($save,"\$newSecret !== ''")!==false, 'save keeps existing secret when field blank');
    $test=src($root,'api/zoom/test_zoom_config.php');
    ok(strpos($test,'isAdmin()')!==false && strpos($test,'zoomGetAccessToken(')!==false, 'test endpoint admin-gated + calls zoomGetAccessToken');
    $page=src($root,'app/constant/settings/zoom_settings.php');
    ok(strpos($page,'isAdmin()')!==false, 'settings page admin-gated');
    ok(strpos($page,'Swal.fire')!==false && strpos($page,'alert(')===false, 'uses SweetAlert2, not alert() (§UI-4)');

    section('4. Live save round-trip (rolled back)');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value,setting_group,is_public,updated_at) VALUES ('zoom_client_secret_enc',?,'zoom','0',NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
            ->execute([encryptSecret('s2s-secret-'.bin2hex(random_bytes(4)))]);
        $pdo->prepare("UPDATE system_settings SET setting_value='acct-test' WHERE setting_key='zoom_account_id'")->execute();
        $pdo->prepare("UPDATE system_settings SET setting_value='client-test' WHERE setting_key='zoom_client_id'")->execute();
        $pdo->prepare("UPDATE system_settings SET setting_value='1' WHERE setting_key='zoom_enabled'")->execute();
        $stored=$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='zoom_client_secret_enc'")->fetchColumn();
        ok(isEncryptedSecret($stored) && decryptSecret($stored)!==null, 'stored secret is encrypted + decryptable');
        // getSetting() caches once per request (see helpers.php get_setting()), so
        // zoomConfigured() won't see this in-request write — verified via raw SQL instead.
        $rowsNow = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('zoom_enabled','zoom_account_id','zoom_client_id')")->fetchAll(PDO::FETCH_KEY_PAIR);
        ok(($rowsNow['zoom_enabled']??'')==='1' && ($rowsNow['zoom_account_id']??'')==='acct-test' && ($rowsNow['zoom_client_id']??'')==='client-test', 'all 3 credential fields + enabled flag persisted together');
        $pdo->rollBack();
        ok(!$pdo->inTransaction(),'rolled back — settings restored');
    } catch (Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); ok(false,'save probe threw: '.$e->getMessage()); }

} catch (Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); ok(false,'test threw: '.$e->getMessage()); }
exit($fail===0?0:1);
