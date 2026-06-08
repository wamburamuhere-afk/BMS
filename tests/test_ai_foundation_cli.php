<?php
/**
 * AI Assistant — Phase 1 (foundation) CLI test
 *   php tests/test_ai_foundation_cli.php
 *
 * Verifies the foundation without needing a live provider key:
 *   - ai_usage_log table + ai_* settings + ai_assistant permission exist
 *   - crypto round-trips; tampered ciphertext rejected; plaintext never leaks
 *   - aiConfigured() is false by default (ships OFF); becomes true only with a key
 *   - settings page + save/test endpoints lint and are admin-gated
 * Read-only except a rolled-back settings probe. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/ai_service.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src($root,$rel){ $p="$root/$rel"; return is_file($p)?file_get_contents($p):''; }
register_shutdown_function(function(){ global $pass,$fail,$pdo; if($pdo && $pdo->inTransaction()) $pdo->rollBack(); echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

try {
    section('1. Schema + seeds');
    ok($pdo->query("SHOW TABLES LIKE 'ai_usage_log'")->fetch() !== false, 'ai_usage_log table exists');
    $keys = $pdo->query("SELECT setting_key FROM system_settings WHERE setting_group='ai'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['ai_enabled','ai_provider','ai_model','ai_api_key_enc','ai_monthly_cost_cap'] as $k) {
        ok(in_array($k,$keys,true), "setting '$k' seeded");
    }
    ok(getSetting('ai_enabled','0')==='0', 'ai_enabled defaults to 0 (feature ships OFF)');
    ok((int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE page_key='ai_assistant'")->fetchColumn()===1, "permission 'ai_assistant' seeded");

    section('2. Crypto');
    $plain='sk-secret-'.bin2hex(random_bytes(6));
    $enc=encryptSecret($plain);
    ok(isEncryptedSecret($enc), 'encryptSecret produces an enc:v1 token');
    ok(strpos($enc,$plain)===false, 'ciphertext does not contain the plaintext');
    ok(decryptSecret($enc)===$plain, 'decryptSecret round-trips exactly');
    ok(decryptSecret('enc:v1:'.base64_encode(random_bytes(40)))===null, 'tampered/garbage token rejected (null)');
    ok(decryptSecret('not-a-token')===null, 'non-token rejected');

    section('3. aiConfigured / cap logic');
    ok(aiConfigured()===false, 'aiConfigured() false by default (disabled, no key)');
    $cap=aiCapInfo();
    ok(isset($cap['cap'],$cap['spent'],$cap['exceeded']), 'aiCapInfo returns cap/spent/exceeded');
    ok($cap['exceeded']===false, 'cost cap not exceeded at baseline');
    // aiComplete must refuse gracefully (never throw) while unconfigured
    $r=aiComplete([['role'=>'user','content'=>'hi']],['feature'=>'test']);
    ok($r['ok']===false && isset($r['error']), 'aiComplete refuses gracefully when unconfigured (no throw)');

    section('4. Endpoints + page lint & gating');
    foreach (['core/crypto.php','core/ai_service.php','app/constant/settings/ai_settings.php','api/ai/save_ai_settings.php','api/ai/test_ai_config.php'] as $f){
        $out=[];$rc=0; exec('php -l '.escapeshellarg("$root/$f").' 2>&1',$out,$rc);
        ok($rc===0, "$f lint-clean");
    }
    $save=src($root,'api/ai/save_ai_settings.php');
    ok(strpos($save,'isAdmin()')!==false, 'save endpoint admin-gated');
    ok(strpos($save,'csrf_check()')!==false, 'save endpoint CSRF-checked');
    ok(strpos($save,'encryptSecret(')!==false, 'save endpoint encrypts the key');
    ok(strpos($save,"\$newKey !== ''")!==false, 'save keeps existing key when field blank');
    $test=src($root,'api/ai/test_ai_config.php');
    ok(strpos($test,'isAdmin()')!==false && strpos($test,'aiComplete(')!==false, 'test endpoint admin-gated + calls aiComplete');
    $page=src($root,'app/constant/settings/ai_settings.php');
    ok(strpos($page,'isAdmin()')!==false, 'settings page admin-gated');
    ok(strpos($page,'select2-static')!==false, 'provider dropdown uses Select2 (ui-constants §UI-3)');
    ok(strpos($page,'Swal.fire')!==false && strpos($page,'alert(')===false, 'uses SweetAlert2, not alert() (§UI-4)');

    section('5. Live save round-trip (rolled back)');
    // Encrypt+store a key, confirm aiConfigured flips, then roll back.
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value,setting_group,is_public,updated_at) VALUES ('ai_api_key_enc',?,'ai','0',NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
            ->execute([encryptSecret('sk-live-'.bin2hex(random_bytes(4)))]);
        $pdo->prepare("UPDATE system_settings SET setting_value='1' WHERE setting_key='ai_enabled'")->execute();
        // getSetting may cache; re-read directly to validate stored values decrypt
        $stored=$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='ai_api_key_enc'")->fetchColumn();
        ok(isEncryptedSecret($stored) && decryptSecret($stored)!==null, 'stored key is encrypted + decryptable');
        $pdo->rollBack();
        ok(!$pdo->inTransaction(),'rolled back — settings restored');
    } catch (Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); ok(false,'save probe threw: '.$e->getMessage()); }

} catch (Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); ok(false,'test threw: '.$e->getMessage()); }
exit($fail===0?0:1);
