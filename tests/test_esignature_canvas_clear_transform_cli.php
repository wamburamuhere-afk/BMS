<?php
/**
 * E-Signatures — Draw Signature canvas "Clear" leaving ink behind
 *   php tests/test_esignature_canvas_clear_transform_cli.php
 *
 * User-reported: on the Draw Signature modal (app/constant/document/
 * e_signatures.php), clicking "Clear" only wiped the top of the canvas —
 * strokes lower down remained visible. Closing the modal (Cancel) and
 * reopening "Draw Signature" always gave a clean canvas.
 *
 * Root cause: initSignaturePad() applies ctx.scale(dpr, dpr) once, for
 * crisp high-DPI drawing. clearSignature() then called
 * ctx.clearRect(0, 0, canvas.width, canvas.height) — but canvas.width/
 * height are PHYSICAL bitmap pixels, and passing them through a context
 * that still has the dpr scale transform active clears in the wrong
 * coordinate space (it happens to still fully clear in current Chromium,
 * which clips an oversized rect to the canvas bounds — but that is an
 * accident of one engine's clipping behaviour, not correct by
 * construction, and is exactly the class of bug that produces partial/
 * stale visual clears on other rendering pipelines, e.g. remote desktop
 * sessions, older engines, or unusual zoom/DPI combinations). Reopening
 * the modal always worked because openDrawSignatureModal() clones the
 * canvas into a brand-new DOM node — a hard reset unrelated to
 * clearSignature()'s own correctness.
 *
 * Fix: both clearSignature() and initSignaturePad()'s own initial clear
 * now reset the context transform to identity (ctx.setTransform(1,0,0,1,0,0)
 * wrapped in save()/restore() to preserve strokeStyle/lineWidth/lineCap)
 * before calling clearRect — the universally-correct way to clear a
 * canvas regardless of any active transform, DPI, or browser zoom.
 *
 * This is a source-pattern regression guard, not a live HTTP test — the
 * fix was verified live in a real browser session (dev.bms.local) via
 * direct pixel-level inspection: drew a full-width, full-height signature
 * at devicePixelRatio 1 and 2, scanned every pixel in the bitmap before
 * and after clearSignature(), confirmed zero ink pixels remained in both
 * cases, and confirmed drawing style (strokeStyle/lineWidth/lineCap) and
 * the ability to draw again survive the clear unchanged.
 *
 * Exit 0 = all checks pass. Exit 1 = a regression slipped in.
 */

$passes = 0; $failures = 0;
function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $passes, $failures; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$passes\033[0m\nFailures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});

echo "\n\033[1m═══ E-Signatures — Draw Signature canvas Clear fully wipes regardless of DPI transform ═══\033[0m\n";

$root = dirname(__DIR__);
$file = $root . '/app/constant/document/e_signatures.php';

head('Syntax');
$res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1');
(strpos((string)$res, 'No syntax errors detected') !== false)
    ? ok('e_signatures.php — no syntax errors')
    : bad('e_signatures.php — ' . trim((string)$res));

$src = file_get_contents($file) ?: '';

head('clearSignature() resets the transform to identity before clearing');
if (preg_match('/function clearSignature\(\)\s*\{(.*?)\n\}/s', $src, $m)) {
    $body = $m[1];
    str_contains($body, 'ctx.save()')
        ? ok('clearSignature() saves context state before touching the transform')
        : bad('clearSignature() no longer calls ctx.save() — style state could leak/reset unexpectedly');
    str_contains($body, 'ctx.setTransform(1, 0, 0, 1, 0, 0)') || str_contains($body, 'ctx.setTransform(1,0,0,1,0,0)')
        ? ok('clearSignature() resets to the identity transform before clearRect')
        : bad('clearSignature() no longer resets the transform — the original DPI-scale clear bug is back');
    str_contains($body, 'ctx.clearRect(0, 0, canvas.width, canvas.height)')
        ? ok('clearSignature() still clears the full physical bitmap')
        : bad('clearSignature() no longer clears using canvas.width/height');
    str_contains($body, 'ctx.restore()')
        ? ok('clearSignature() restores context state after clearing (preserves the dpr scale + drawing style)')
        : bad('clearSignature() no longer calls ctx.restore() — subsequent drawing could use the wrong transform');
} else {
    bad('clearSignature() function not found — cannot verify the fix');
}

head('initSignaturePad()\'s own initial clear uses the same safe pattern');
if (preg_match('/ctx\.scale\(dpr, dpr\);(.*?)\/\/ Configure drawing style/s', $src, $m)) {
    $initClearBody = $m[1];
    str_contains($initClearBody, 'ctx.setTransform(1, 0, 0, 1, 0, 0)')
        ? ok('initSignaturePad() also resets transform to identity before its initial clearRect')
        : bad('initSignaturePad()\'s initial clear does not reset transform — inconsistent with clearSignature()');
} else {
    bad('Could not locate initSignaturePad()\'s initial-clear block — source structure may have changed');
}

head('Drawing style setup (strokeStyle/lineWidth/lineCap) still configured after the clear block');
foreach (["ctx.strokeStyle = '#000000'", 'ctx.lineWidth = 2.5', "ctx.lineCap = 'round'"] as $needle) {
    str_contains($src, $needle)
        ? ok("still configures: $needle")
        : bad("missing: $needle — drawing style setup may have regressed");
}
