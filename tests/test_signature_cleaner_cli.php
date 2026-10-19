<?php
/**
 * tests/test_signature_cleaner_cli.php — uploaded signatures lose their paper.
 *
 *   1. cleaner          — grey paper + shadow, cream + blue ink, transparent PNG,
 *                         pencil: paper transparent, ink kept (colour too), trimmed
 *   2. refusals         — dark photo, blank paper, a colourful poster
 *   3. stored uploads   — the migration helper: JPEG → PNG, rows repointed
 *                         (user_signatures + workflow_signatures), originals kept,
 *                         drawn untouched, non-signature left, idempotent
 *   4. real upload      — multipart POST to api/document/upload_signature.php over
 *                         HTTP (php -S): cleaned PNG stored; a poster is refused
 *
 *   php tests/test_signature_cleaner_cli.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../roots.php';
require_once ROOT_DIR . '/core/signature_cleaner.php';

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $m\n"; } else { $fail++; echo "  ✗ $m\n"; } }
function section($t) { echo "\n── $t\n"; }

$tmp = sys_get_temp_dir() . '/sigclean_' . bin2hex(random_bytes(3));
@mkdir($tmp, 0777, true);

/** A wavy signature stroke + underline. */
function scribble($im, $col, int $w, int $h): void
{
    imagesetthickness($im, max(3, (int)($w / 160)));
    $px = null;
    for ($t = 0; $t < 6.28 * 3; $t += 0.02) {
        $x = (int)($w * 0.15 + $t / 18.8 * $w * 0.7);
        $y = (int)($h * 0.5 + sin($t * 2.3) * $h * 0.18 + cos($t * 5) * $h * 0.06);
        if ($px) imageline($im, $px[0], $px[1], $x, $y, $col);
        $px = [$x, $y];
    }
    imageline($im, (int)($w * .2), (int)($h * .72), (int)($w * .8), (int)($h * .68), $col);
}
function paper(int $w, int $h, array $rgb) { $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb)); return $im; }
/** [alpha 0..127, r, g, b] at a point of a PNG. */
function px(string $f, int $x, int $y): array { $im = imagecreatefrompng($f); $c = imagecolorat($im, $x, $y); return [($c >> 24) & 127, $c >> 16 & 255, $c >> 8 & 255, $c & 255]; }
/** Share of fully transparent pixels, and the darkest opaque pixel's RGB. */
function scan(string $f): array
{
    $im = imagecreatefrompng($f); $w = imagesx($im); $h = imagesy($im); $clear = 0; $dark = null; $dl = 999;
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $c = imagecolorat($im, $x, $y); $a = ($c >> 24) & 127;
        if ($a === 127) { $clear++; continue; }
        $l = (($c >> 16 & 255) + ($c >> 8 & 255) + ($c & 255)) / 3;
        if ($a === 0 && $l < $dl) { $dl = $l; $dark = [$c >> 16 & 255, $c >> 8 & 255, $c & 255]; }
    }
    return ['w' => $w, 'h' => $h, 'clear' => $clear / ($w * $h), 'dark' => $dark];
}
function poster(string $file): void
{
    $im = paper(1000, 1400, [246, 238, 220]);
    $cols = [[200, 40, 40], [210, 170, 60], [20, 40, 120], [230, 200, 120], [160, 30, 30]];
    for ($i = 0; $i < 60; $i++) {
        [$r, $g, $b] = $cols[$i % 5];
        for ($k = 40; $k > 0; $k -= 4) imagefilledellipse($im, ($i * 137) % 1000, ($i * 251) % 1400, $k * 4, $k * 4, imagecolorallocate($im, min(255, $r + $k), min(255, $g + $k), min(255, $b + $k)));
    }
    for ($y = 300; $y < 1100; $y += 40) imagefilledrectangle($im, 150, $y, 850, $y + 14, imagecolorallocate($im, 20, 40, 120));
    imagejpeg($im, $file, 90);
}

try {
    section('1. Cleaner — paper removed, ink kept');
    ok(sigCleanerSupported(), 'GD is available');

    $im = imagecreatetruecolor(2400, 1600);   // grey paper, phone shadow, noise
    for ($y = 0; $y < 1600; $y++) for ($x = 0; $x < 2400; $x += 4) {
        $g = 200 - (int)(70 * $x / 2400) - (int)(25 * $y / 1600) + mt_rand(-6, 6);
        imagefilledrectangle($im, $x, $y, $x + 3, $y, imagecolorallocate($im, $g, $g, $g - 4));
    }
    scribble($im, imagecolorallocate($im, 35, 35, 40), 2400, 1600);
    imagejpeg($im, "$tmp/grey.jpg", 85);
    $ok = sigCleanSignature("$tmp/grey.jpg", "$tmp/grey.out.png", $err);
    $s = $ok ? scan("$tmp/grey.out.png") : [];
    ok($ok, 'grey paper + shadow JPEG cleans' . ($err ? " ($err)" : ''));
    ok($ok && px("$tmp/grey.out.png", 0, 0)[0] === 127 && px("$tmp/grey.out.png", $s['w'] - 1, $s['h'] - 1)[0] === 127, 'corners are transparent (no grey box)');
    ok($ok && $s['clear'] > 0.6, 'paper, shadow and noise removed (' . round(($s['clear'] ?? 0) * 100) . '% transparent)');
    ok($ok && max($s['dark']) < 90, 'ink stays dark (' . json_encode($s['dark'] ?? null) . ')');
    ok($ok && $s['w'] <= 800 && $s['w'] < 700, "big photo shrunk and trimmed to the ink ({$s['w']}×{$s['h']})");
    ok($ok && mime_content_type("$tmp/grey.out.png") === 'image/png', 'output is a PNG');

    $im = paper(1200, 600, [245, 235, 210]); scribble($im, imagecolorallocate($im, 20, 50, 160), 1200, 600); imagepng($im, "$tmp/blue.png");
    $ok = sigCleanSignature("$tmp/blue.png", "$tmp/blue.out.png", $err); $s = $ok ? scan("$tmp/blue.out.png") : [];
    ok($ok && $s['clear'] > 0.6 && $s['dark'][2] > $s['dark'][0] + 60, 'cream paper removed, blue ink stays blue (' . json_encode($s['dark'] ?? null) . ')');

    $im = imagecreatetruecolor(800, 400); imagealphablending($im, false); imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); imagealphablending($im, true);
    scribble($im, imagecolorallocate($im, 10, 10, 10), 800, 400); imagepng($im, "$tmp/transp.png");
    $ok = sigCleanSignature("$tmp/transp.png", "$tmp/transp.out.png", $err); $s = $ok ? scan("$tmp/transp.out.png") : [];
    ok($ok && $s['clear'] > 0.6 && max($s['dark']) < 40, 'already-transparent PNG stays clean (no black box)');

    $im = paper(1000, 500, [250, 250, 248]); scribble($im, imagecolorallocate($im, 140, 140, 145), 1000, 500); imagejpeg($im, "$tmp/pencil.jpg", 90);
    ok(sigCleanSignature("$tmp/pencil.jpg", "$tmp/pencil.out.png", $err), 'pale pencil signature is accepted' . ($err ? " ($err)" : ''));

    section('2. Refusals — not a signature');
    $im = paper(800, 400, [40, 40, 45]); scribble($im, imagecolorallocate($im, 240, 240, 240), 800, 400); imagejpeg($im, "$tmp/dark.jpg");
    ok(!sigCleanSignature("$tmp/dark.jpg", "$tmp/dark.out.png", $err) && stripos($err, 'too dark') !== false && !is_file("$tmp/dark.out.png"), 'dark photo refused: ' . $err);
    imagejpeg(paper(800, 400, [235, 235, 230]), "$tmp/blank.jpg");
    ok(!sigCleanSignature("$tmp/blank.jpg", "$tmp/blank.out.png", $err) && stripos($err, 'No signature') !== false, 'blank paper refused: ' . $err);
    poster("$tmp/poster.jpg");
    ok(!sigCleanSignature("$tmp/poster.jpg", "$tmp/poster.out.png", $err) && stripos($err, 'does not look like a signature') !== false, 'colourful poster refused: ' . $err);
    file_put_contents("$tmp/junk.png", 'not an image');
    ok(!sigCleanSignature("$tmp/junk.png", "$tmp/junk.out.png", $err) && stripos($err, 'not a readable') !== false, 'unreadable file refused');

    section('3. Stored uploads — migration helper');
    $uid  = (int)$pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();
    $dir  = ROOT_DIR . '/uploads/signatures/' . $uid;
    @mkdir($dir, 0755, true);
    $tag  = bin2hex(random_bytes(4));
    $files = ["sigtest_{$tag}_a.jpg" => "$tmp/grey.jpg", "sigtest_{$tag}_b.png" => "$tmp/blue.png", "sigtest_{$tag}_c.png" => "$tmp/transp.png", "sigtest_{$tag}_d.jpg" => "$tmp/poster.jpg"];
    foreach ($files as $name => $src) copy($src, "$dir/$name");
    $ins = $pdo->prepare("INSERT INTO user_signatures (user_id, signature_type, file_path, status, created_at) VALUES (?, ?, ?, 'active', NOW())");
    $ids = [];
    foreach (['a' => 'uploaded', 'b' => 'uploaded', 'c' => 'drawn', 'd' => 'uploaded'] as $k => $type) {
        $ext = in_array($k, ['a', 'd'], true) ? 'jpg' : 'png';
        $ins->execute([$uid, $type, "/uploads/signatures/$uid/sigtest_{$tag}_$k.$ext"]);
        $ids[$k] = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO workflow_signatures (entity_type, entity_id, action, user_id, user_name, user_role, sig_path, consent_accepted) VALUES ('sigtest', ?, 'approved', ?, 'T', 'T', ?, 1)")
        ->execute([crc32($tag) % 1000000, $uid, "/uploads/signatures/$uid/sigtest_{$tag}_a.jpg"]);

    $r = sigCleanStoredUploads($pdo, ROOT_DIR);
    $path = fn($k) => $pdo->query("SELECT file_path FROM user_signatures WHERE id = {$ids[$k]}")->fetchColumn();
    ok($path('a') === "/uploads/signatures/$uid/sigtest_{$tag}_a.png", 'JPEG signature → PNG path in user_signatures');
    ok($pdo->query("SELECT sig_path FROM workflow_signatures WHERE entity_type = 'sigtest' AND sig_path LIKE '%$tag%'")->fetchColumn() === "/uploads/signatures/$uid/sigtest_{$tag}_a.png", 'workflow_signatures.sig_path repointed (documents keep showing it)');
    ok(is_file("$dir/sigtest_{$tag}_a.png") && is_file("$dir/sigtest_{$tag}_a.orig.jpg") && !is_file("$dir/sigtest_{$tag}_a.jpg"), 'cleaned PNG written, original kept as .orig.jpg');
    ok(px("$dir/sigtest_{$tag}_a.png", 0, 0)[0] === 127, 'stored signature now has a transparent background');
    ok($path('b') === "/uploads/signatures/$uid/sigtest_{$tag}_b.png" && is_file("$dir/sigtest_{$tag}_b.orig.png"), 'PNG signature cleaned in place, original kept as .orig.png');
    ok(md5_file("$dir/sigtest_{$tag}_c.png") === md5_file("$tmp/transp.png") && !is_file("$dir/sigtest_{$tag}_c.orig.png"), 'drawn signature untouched');
    ok(isset($r['refused'][$ids['d']]) && is_file("$dir/sigtest_{$tag}_d.jpg") && $path('d') === "/uploads/signatures/$uid/sigtest_{$tag}_d.jpg", 'poster upload left exactly as it was, and reported');
    $before = md5_file("$dir/sigtest_{$tag}_a.png");
    $r2 = sigCleanStoredUploads($pdo, ROOT_DIR);
    ok(md5_file("$dir/sigtest_{$tag}_a.png") === $before && $r2['cleaned'] === 0 && $r2['skipped'] >= 2 && $path('a') === "/uploads/signatures/$uid/sigtest_{$tag}_a.png", "second run changes nothing (idempotent: cleaned {$r2['cleaned']}, skipped {$r2['skipped']})");

    section('4. Real upload over HTTP');
    $port = 8790 + random_int(0, 150);
    $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', ROOT_DIR], [1 => ['file', "$tmp/srv.out", 'w'], 2 => ['file', "$tmp/srv.err", 'w']], $pp, ROOT_DIR);
    usleep(800000);
    $adminId = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 AND u.is_active = 1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
    $u = $pdo->query("SELECT user_id, username, first_name, last_name, role_id FROM users WHERE user_id = $adminId")->fetch(PDO::FETCH_ASSOC);
    // A session file the server will read: same save path, a known id.
    session_write_close();
    $sid = 'sigtest' . bin2hex(random_bytes(8));
    session_id($sid); session_start();
    $_SESSION = ['user_id' => $adminId, 'username' => $u['username'], 'first_name' => $u['first_name'], 'last_name' => $u['last_name'],
                 'role_id' => (int)$u['role_id'], 'user_role' => 'Admin', 'is_admin' => true, 'csrf_token' => 'sigtest-csrf'];
    session_write_close();
    $upload = function (string $file, string $mime) use ($port, $sid) {
        $ch = curl_init("http://127.0.0.1:$port/api/document/upload_signature.php");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['X-CSRF-Token: sigtest-csrf', 'Cookie: ' . session_name() . '=' . $sid],
            CURLOPT_POSTFIELDS => ['_csrf' => 'sigtest-csrf', 'signature_file' => new CURLFile($file, $mime, basename($file))]]);
        $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$code, $body, json_decode((string)$body, true)];
    };
    $maxBefore = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM user_signatures")->fetchColumn();
    [$c, $body, $j] = $upload("$tmp/grey.jpg", 'image/jpeg');
    ok(($j['success'] ?? false) === true, "grey-paper JPEG upload succeeds (HTTP $c): " . substr((string)$body, 0, 160));
    $row = $pdo->query("SELECT id, file_path FROM user_signatures WHERE id > $maxBefore AND user_id = $adminId AND signature_type = 'uploaded' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $stored = $row ? ROOT_DIR . $row['file_path'] : '';
    ok($row && str_ends_with($row['file_path'], '.png') && is_file($stored), 'stored as a PNG under uploads/signatures/' . $adminId);
    ok($row && is_file($stored) && px($stored, 0, 0)[0] === 127 && scan($stored)['clear'] > 0.6, 'stored upload has a transparent background (paper removed)');
    [$c, $body, $j] = $upload("$tmp/poster.jpg", 'image/jpeg');
    ok(($j['success'] ?? true) === false && stripos($j['message'] ?? '', 'does not look like a signature') !== false, 'poster upload refused with the reason: ' . ($j['message'] ?? $body));
    ok((int)$pdo->query("SELECT COUNT(*) FROM user_signatures WHERE id > $maxBefore")->fetchColumn() === 1, 'refused upload saved nothing');
    proc_terminate($srv); proc_close($srv);
} finally {
    // Clean up: test rows, files, the forged session.
    if (!empty($ids)) $pdo->exec("DELETE FROM user_signatures WHERE id IN (" . implode(',', $ids) . ")");
    if (!empty($tag)) {
        $pdo->exec("DELETE FROM workflow_signatures WHERE entity_type = 'sigtest'");
        foreach (glob(ROOT_DIR . "/uploads/signatures/*/sigtest_{$tag}_*") ?: [] as $f) @unlink($f);
    }
    if (!empty($row)) { $pdo->exec("DELETE FROM user_signatures WHERE id = " . (int)$row['id']); @unlink(ROOT_DIR . $row['file_path']); }
    if (!empty($sid)) { session_id($sid); @session_start(); session_destroy(); }
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
    @rmdir($tmp);
}

echo "\n" . ($fail === 0 ? "ALL PASSED" : "FAILURES") . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
