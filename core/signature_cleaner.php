<?php
/**
 * core/signature_cleaner.php — turn an uploaded signature photo/scan into a
 * clean ink-only PNG: the paper (grey, cream, shadows, smudges) becomes
 * transparent, so on a white document it looks exactly like a drawn signature.
 *
 * "Smart" parts:
 *  - the paper colour is measured locally (block percentiles, interpolated), so
 *    uneven phone-camera light and shadows disappear, not just a fixed "grey";
 *  - ink keeps its own colour (blue stays blue) with soft anti-aliased edges;
 *  - isolated specks are dropped, the empty margin is trimmed, big photos shrink;
 *  - JPEG phone photos are turned upright (EXIF orientation);
 *  - an image that is clearly not a signature (a poster, a photo, dark paper)
 *    is refused with a clear reason instead of being mangled.
 *
 * Self-contained (GD only) so the upload API and the per-tenant migration can
 * both use it. Uploaded signatures only — drawn/typed ones are already clean.
 */

if (!function_exists('sigCleanerSupported')) {
    function sigCleanerSupported(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagepng')
            && function_exists('imagecolorallocatealpha');
    }
}

if (!function_exists('sigCleanSignature')) {
    /**
     * Clean $src (PNG/JPEG file) and write a transparent PNG to $dest.
     * Returns true on success; on failure returns false and sets $error to a
     * user-facing reason (nothing is written).
     */
    function sigCleanSignature(string $src, string $dest, ?string &$error = null): bool
    {
        $error = null;
        if (!sigCleanerSupported()) { $error = 'Image processing (GD) is not available on this server.'; return false; }
        $raw = @file_get_contents($src);
        $img = $raw !== false ? @imagecreatefromstring($raw) : false;
        if (!$img) { $error = 'The file is not a readable PNG or JPEG image.'; return false; }

        // Phone photos: honour EXIF orientation so the signature is upright.
        if (function_exists('exif_read_data') && substr($raw, 0, 2) === "\xFF\xD8") {
            $exif = @exif_read_data($src);
            $rot  = [3 => 180, 6 => 270, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
            if ($rot) { $r = imagerotate($img, $rot, 0); if ($r) { imagedestroy($img); $img = $r; } }
        }
        unset($raw);

        // Work at most 800 px on the long side — plenty for a signature, light on memory.
        $w = imagesx($img); $h = imagesy($img);
        $scale = min(1, 800 / max($w, $h));
        $W = max(1, (int)round($w * $scale)); $H = max(1, (int)round($h * $scale));
        $flat = imagecreatetruecolor($W, $H);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));   // transparency → white paper
        imagealphablending($flat, true);
        imagecopyresampled($flat, $img, 0, 0, 0, 0, $W, $H, $w, $h);
        imagedestroy($img);

        // Luminance of every pixel.
        $N = $W * $H;
        $L = [];
        for ($y = 0; $y < $H; $y++) {
            for ($x = 0; $x < $W; $x++) {
                $c = imagecolorat($flat, $x, $y);
                $L[] = (int)((($c >> 16 & 255) * 299 + ($c >> 8 & 255) * 587 + ($c & 255) * 114) / 1000);
            }
        }

        // Local paper brightness: 90th percentile per block (ink rarely fills 90 % of a block).
        $B  = max(12, (int)round(max($W, $H) / 20));
        $gx = (int)ceil($W / $B); $gy = (int)ceil($H / $B);
        $bg = [];
        for ($by = 0; $by < $gy; $by++) {
            for ($bx = 0; $bx < $gx; $bx++) {
                $hist = array_fill(0, 256, 0); $n = 0;
                $y1 = min($H, ($by + 1) * $B); $x1 = min($W, ($bx + 1) * $B);
                for ($y = $by * $B; $y < $y1; $y++) {
                    $row = $y * $W;
                    for ($x = $bx * $B; $x < $x1; $x++) { $hist[$L[$row + $x]]++; $n++; }
                }
                $want = (int)ceil($n * 0.9); $acc = 0; $v = 255;
                for ($i = 0; $i < 256; $i++) { $acc += $hist[$i]; if ($acc >= $want) { $v = $i; break; } }
                $bg[$by][$bx] = $v;
            }
        }
        $all = []; foreach ($bg as $r) foreach ($r as $v) $all[] = $v;
        sort($all);
        if ($all[(int)(count($all) / 2)] < 90) { $error = 'The background is too dark. Sign on white or light paper and take the photo in good light.'; return false; }

        // Smooth paper-level map: the block grid upscaled by GD (bilinear), so the
        // paper level follows shadows and uneven light across the photo.
        $grid = imagecreatetruecolor($gx, $gy);
        for ($by = 0; $by < $gy; $by++) for ($bx = 0; $bx < $gx; $bx++) imagesetpixel($grid, $bx, $by, $bg[$by][$bx]);
        $map = imagecreatetruecolor($W, $H);
        imagecopyresampled($map, $grid, 0, 0, 0, 0, $W, $H, $gx, $gy);
        imagedestroy($grid);

        // Opacity from how much darker than the local paper a pixel is:
        // < 14 % darker = paper (transparent), > 32 % = ink (opaque), soft in between.
        $A = array_fill(0, $N, 0);   // 0 = transparent … 255 = opaque
        $ink = 0;
        for ($y = 0; $y < $H; $y++) {
            for ($x = 0; $x < $W; $x++) {
                $i = $y * $W + $x; $p = max(1, imagecolorat($map, $x, $y) & 255);
                $d = ($p - $L[$i]) / $p;
                if ($d <= 0.14) continue;
                $A[$i] = $d >= 0.32 ? 255 : (int)round(($d - 0.14) / 0.18 * 255);
                if ($A[$i] >= 128) $ink++;
            }
        }
        unset($L); imagedestroy($map);

        if ($ink < 40) { $error = 'No signature was found in the image. Sign clearly with a dark pen and try again.'; return false; }
        if ($ink / $N > 0.22) { $error = 'This image does not look like a signature (too much of it is dark or coloured). Upload only the signature on plain paper.'; return false; }

        // Drop isolated specks (paper texture, dust): a pixel needs 2+ marked neighbours.
        $keep = $A;
        for ($y = 0; $y < $H; $y++) {
            for ($x = 0; $x < $W; $x++) {
                $i = $y * $W + $x;
                if (!$A[$i]) continue;
                $nb = 0;
                for ($dy = -1; $dy <= 1 && $nb < 2; $dy++) {
                    $yy = $y + $dy; if ($yy < 0 || $yy >= $H) continue;
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $xx = $x + $dx;
                        if (($dx || $dy) && $xx >= 0 && $xx < $W && $A[$yy * $W + $xx]) { if (++$nb >= 2) break; }
                    }
                }
                if ($nb < 2) $keep[$i] = 0;
            }
        }
        $A = $keep; unset($keep);

        // Trim to the ink, keeping a small margin. Rows/columns with a lone faint
        // pixel do not stretch the box.
        $rows = array_fill(0, $H, 0); $cols = array_fill(0, $W, 0);
        for ($y = 0; $y < $H; $y++) for ($x = 0; $x < $W; $x++) if ($A[$y * $W + $x] >= 128) { $rows[$y]++; $cols[$x]++; }
        $minY = 0; while ($minY < $H - 1 && $rows[$minY] < 2) $minY++;
        $maxY = $H - 1; while ($maxY > $minY && $rows[$maxY] < 2) $maxY--;
        $minX = 0; while ($minX < $W - 1 && $cols[$minX] < 2) $minX++;
        $maxX = $W - 1; while ($maxX > $minX && $cols[$maxX] < 2) $maxX--;
        $pad = max(6, (int)round(max($maxX - $minX, $maxY - $minY) * 0.04));
        $minX = max(0, $minX - $pad); $minY = max(0, $minY - $pad);
        $maxX = min($W - 1, $maxX + $pad); $maxY = min($H - 1, $maxY + $pad);
        $ow = $maxX - $minX + 1; $oh = $maxY - $minY + 1;

        $out = imagecreatetruecolor($ow, $oh);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        // While copying the ink, measure what a signature does NOT have: several
        // colour families (a poster's gold/red/blue), many half-tone pixels
        // (photo gradients) and lots of light-coloured "ink".
        $opaque = 0; $soft = 0; $light = 0; $sat = 0; $hue = array_fill(0, 12, 0);
        for ($y = $minY; $y <= $maxY; $y++) {
            for ($x = $minX; $x <= $maxX; $x++) {
                $a = $A[$y * $W + $x];
                if (!$a) continue;
                $c = imagecolorat($flat, $x, $y);
                $r = $c >> 16 & 255; $g = $c >> 8 & 255; $b = $c & 255;
                imagesetpixel($out, $x - $minX, $y - $minY, imagecolorallocatealpha($out, $r, $g, $b, 127 - (int)round($a / 255 * 127)));
                if ($a < 128) { $soft++; continue; }
                $opaque++;
                if ($r * 299 + $g * 587 + $b * 114 >= 128000) $light++;
                $mx = max($r, $g, $b); $dl = $mx - min($r, $g, $b);
                if ($dl > 50) {
                    $sat++;
                    $hh = $mx === $r ? fmod(($g - $b) / $dl, 6) : ($mx === $g ? ($b - $r) / $dl + 2 : ($r - $g) / $dl + 4);
                    $hue[(int)(fmod($hh * 60 + 360, 360) / 30) % 12]++;
                }
            }
        }
        imagedestroy($flat);
        $best = 0;   // largest share of coloured ink inside one 90° hue window
        for ($k = 0; $k < 12; $k++) $best = max($best, $hue[$k] + $hue[($k + 1) % 12] + $hue[($k + 2) % 12]);
        $signs = (int)($sat > $opaque * 0.15 && $best < $sat * 0.85)   // many colours
               + (int)($soft > $opaque * 0.18)                          // photo-like gradients
               + (int)($light > $opaque * 0.30)                         // pale artwork
               + (int)($opaque > $ow * $oh * 0.13);                     // dense, page-like content
        if ($signs >= 2) {
            imagedestroy($out);
            $error = 'This image does not look like a signature (it has pictures or several colours). Upload only the signature on plain paper.';
            return false;
        }

        $tmp = $dest . '.tmp' . bin2hex(random_bytes(3));
        $ok = imagepng($out, $tmp, 9);
        imagedestroy($out);
        if (!$ok || !@rename($tmp, $dest)) { @unlink($tmp); $error = 'Could not save the cleaned signature.'; return false; }
        return true;
    }
}

if (!function_exists('sigCleanStoredUploads')) {
    /**
     * Clean every UPLOADED signature already on disk for the connected DB.
     * Idempotent: the original is kept beside it as <name>.orig.<ext>, and a
     * signature whose backup already exists is skipped. A JPEG becomes a PNG
     * (JPEG has no transparency); user_signatures.file_path and every
     * workflow_signatures.sig_path that pointed at it move to the new file.
     * An image that is not a signature is left untouched and reported.
     *
     * Returns ['cleaned' => n, 'skipped' => n, 'missing' => n, 'refused' => [id => reason]].
     */
    function sigCleanStoredUploads(PDO $pdo, string $rootDir): array
    {
        $res = ['cleaned' => 0, 'skipped' => 0, 'missing' => 0, 'refused' => []];
        $rows = $pdo->query("SELECT id, file_path FROM user_signatures WHERE signature_type = 'uploaded' AND file_path IS NOT NULL AND file_path <> ''")->fetchAll(PDO::FETCH_ASSOC);
        $hasWf = (bool)$pdo->query("SHOW TABLES LIKE 'workflow_signatures'")->fetchColumn();
        foreach ($rows as $r) {
            $rel  = $r['file_path'];
            $file = rtrim($rootDir, '/\\') . '/' . ltrim($rel, '/');
            $info = pathinfo($file);
            $ext  = strtolower($info['extension'] ?? '');
            $base = $info['dirname'] . '/' . $info['filename'];
            $backup = $base . '.orig.' . $ext;
            // Already cleaned: a backup exists (any extension — a JPEG became a PNG).
            if (is_file($base . '.orig.png') || is_file($base . '.orig.jpg') || is_file($base . '.orig.jpeg')) { $res['skipped']++; continue; }
            if (!is_file($file) || !in_array($ext, ['png', 'jpg', 'jpeg'], true)) { $res['missing']++; continue; }

            $newFile = $base . '.png';
            $work = $base . '.clean' . bin2hex(random_bytes(3)) . '.png';
            if (!sigCleanSignature($file, $work, $err)) { $res['refused'][(int)$r['id']] = $err; continue; }
            if (!@copy($file, $backup)) { @unlink($work); $res['refused'][(int)$r['id']] = 'could not keep a backup copy'; continue; }
            if (!@rename($work, $newFile)) { @unlink($work); @unlink($backup); $res['refused'][(int)$r['id']] = 'could not write the cleaned file'; continue; }

            if ($newFile !== $file) {   // JPEG → PNG: repoint the rows, keep the old file only as its .orig backup
                $newRel = substr($rel, 0, -strlen($ext)) . 'png';
                $pdo->prepare("UPDATE user_signatures SET file_path = ? WHERE id = ?")->execute([$newRel, (int)$r['id']]);
                if ($hasWf) $pdo->prepare("UPDATE workflow_signatures SET sig_path = ? WHERE sig_path = ?")->execute([$newRel, $rel]);
                @unlink($file);
            }
            $res['cleaned']++;
        }
        return $res;
    }
}
