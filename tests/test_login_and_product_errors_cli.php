<?php
/**
 * Hotfix 2026-10-25 — login crash + product-save error messages
 *   php tests/test_login_and_product_errors_cli.php
 *
 * A. A REAL successful login through actions/login.php (which loads only
 *    includes/config.php, not roots.php) returns JSON — and the pre-fix file,
 *    which called orgLanguage() there, is proven to crash (Sentry
 *    24e12596b5b54502bcd125c3bfd00a40). A wrong password never reached that line,
 *    which is why a source-only check missed it.
 * B. create/update_product answer a too-large photo with a clear message
 *    (post_max_size overflow and upload_max_filesize), never a silent drop.
 * C. The product page names the photo's size, handles 413, and checks whether a
 *    product was saved before reporting a lost connection; its JS compiles.
 * Temporary user and session files are removed afterwards. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function(){ global $pass,$fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; exit($fail ? 1 : 0); });

// Runs PHP code in a child process from $cwd; returns its stdout.
function child(string $cwd, string $code): string {
    $file = tempnam(sys_get_temp_dir(), 'bmsq') . '.php';
    file_put_contents($file, "<?php\n" . $code);
    $out = shell_exec('cd ' . escapeshellarg($cwd) . ' && php ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    return trim((string)$out);
}

section('A. Real successful login through actions/login.php');
$uname = 'zz_hotfix_login_' . bin2hex(random_bytes(3));
$pw = 'Hotfix2026!';
$pdo->prepare("INSERT INTO users (username, password, role_id, is_active, first_name, last_name) VALUES (?, ?, 1, 1, 'Hotfix', 'Test')")
    ->execute([$uname, password_hash($pw, PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();
$post = '$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["HTTP_HOST"]="localhost"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; '
      . '$_POST=["username"=>' . var_export($uname, true) . ',"password"=>' . var_export($pw, true) . '];';
try {
    $out = child("$root/actions", $post . ' include "login.php";');
    $j = json_decode($out, true);
    ok(is_array($j) && ($j['success'] ?? false) === true, 'fixed login.php: correct password → {"success":true}');

    // Same request against the pre-fix file from main, to prove this test catches the bug.
    $old = shell_exec('git -C ' . escapeshellarg($root) . ' show 274117fd:actions/login.php 2>NUL');
    if ($old && strpos($old, 'orgLanguage()') !== false) {
        file_put_contents("$root/actions/_zz_old_login_probe.php", $old);
        $outOld = child("$root/actions", $post . ' include "_zz_old_login_probe.php";');
        @unlink("$root/actions/_zz_old_login_probe.php");
        ok(json_decode($outOld, true) === null && stripos($outOld, 'orgLanguage') !== false, 'pre-fix login.php crashes on the same request (Call to undefined function orgLanguage)');
    } else {
        ok(true, 'pre-fix file not available in this checkout — regression probe skipped');
    }
    ok(strpos(file_get_contents("$root/actions/login.php"), 'orgLanguage(') === false, 'login.php no longer calls orgLanguage()');
    ok(strpos(file_get_contents("$root/header.php"), '$__bms_org_lang = orgLanguage();') !== false, 'header.php still applies the organization language after login');
} finally {
    $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$uid]);
    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$uid]);
}

section('B. Too-large photo gets a clear answer');
$adminId = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
// Logged-in admin session handed to the endpoint via a session file + cookie.
$sess = function (string $extra) use ($adminId) {
    return 'session_id("zzhotfix' . getmypid() . '"); session_start(); $_SESSION["user_id"]=' . $adminId . '; $_SESSION["role_id"]=1; $_SESSION["is_admin"]=true; session_write_close(); '
         . '$_COOKIE[session_name()]=session_id(); $_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["HTTP_HOST"]="localhost"; ' . $extra;
};
foreach (['create_product.php', 'update_product.php'] as $ep) {
    // post_max_size overflow: PHP hands the script empty $_POST/$_FILES and the real length.
    $o = child("$root/api", $sess('$_POST=[]; $_FILES=[]; $_SERVER["CONTENT_LENGTH"]=(string)(9*1048576); include "' . $ep . '";'));
    $j = json_decode(substr($o, strpos($o, '{') ?: 0), true);
    ok(($j['success'] ?? null) === false && strpos($j['message'] ?? '', 'The photo is too large (9 MB). The maximum is 2 MB.') !== false, "$ep: request over post_max_size → \"The photo is too large (9 MB)…\"");
    // upload_max_filesize: file dropped with UPLOAD_ERR_INI_SIZE.
    $o = child("$root/api", $sess('$_POST=["product_id"=>"1","product_name"=>"X"]; $_FILES=["product_image"=>["name"=>"a.jpg","type"=>"image/jpeg","tmp_name"=>"","error"=>UPLOAD_ERR_INI_SIZE,"size"=>0]]; include "' . $ep . '";'));
    $j = json_decode(substr($o, strpos($o, '{') ?: 0), true);
    ok(($j['success'] ?? null) === false && ($j['message'] ?? '') === 'The photo is too large. The maximum is 2 MB.', "$ep: photo over upload_max_filesize → refused with the photo message, not saved without it");
}
foreach (glob(session_save_path() . '/sess_zzhotfix*') ?: [] as $f) @unlink($f);
$sw = include "$root/lang/sw.php";
foreach (['The photo is too large (%s MB). The maximum is 2 MB.', 'The photo is too large. The maximum is 2 MB.', 'This photo is %s MB.', 'Connection Lost',
          'The connection was briefly lost, but the product was saved.', 'The connection to the server was lost and the product was not saved. Please try again.',
          'The connection to the server was lost. Please check the products list, then try again if the change is not there.'] as $k) {
    ok(!empty($sw[$k]), "sw: $k");
}

section('C. Product page error handling');
$js = file_get_contents("$root/app/bms/product/product_create_footer.php");
ok(strpos($js, 'xhr.status === 413') !== false, 'a 413 from the web server is reported as a too-large photo');
ok(strpos($js, "api/search_products.php") !== false && strpos($js, 'saved_despite_connection') !== false, 'a lost reply checks whether the product was saved before reporting failure');
ok(substr_count($js, 'imageTooLargeText()') >= 3, 'picker and server refusals both name the photo size');
$html = child($root, 'require "roots.php"; $_SESSION["user_id"]=' . $adminId . '; ob_start(); include "app/bms/product/product_create_footer.php"; echo ob_get_clean();');
preg_match_all('#<script>(.*?)</script>#s', $html, $m);
$bad = [];
foreach ($m[1] as $i => $block) {
    $tmpJs = tempnam(sys_get_temp_dir(), 'bmsjs') . '.js';
    file_put_contents($tmpJs, $block);
    $nodeOut = []; exec('node --check ' . escapeshellarg($tmpJs) . ' 2>&1', $nodeOut, $nodeRc);
    @unlink($tmpJs);
    if ($nodeRc !== 0) $bad[] = "block $i: " . implode(' ', array_slice($nodeOut, 0, 4));
}
ok(count($m[1]) > 0 && !$bad, 'rendered product page scripts compile (node --check, ' . count($m[1]) . ' block(s))' . ($bad ? ': ' . implode(' | ', $bad) : ''));
