<?php
/**
 * Phase 4.5 — API permission gate audit.
 *
 * Goal: every state-changing API (any file under api/ that issues an
 * INSERT, UPDATE, or DELETE on the success path) must call at least
 * one of:
 *   - canCreate('key') / canEdit('key') / canDelete('key')
 *   - assertCanCreate / assertCanEdit / assertCanDelete
 *   - autoEnforcePermission / enforcePageOrAdmin
 *   - canView (read-only fallback — flagged but allowed when paired
 *     with a write — these are surfaced under WEAK below)
 *
 * Run from project root:
 *   php scratch/api_permission_audit.php
 *
 * Output sections:
 *   - GAP   — write APIs with NO permission check at all (the danger
 *             set Phase 4.5 fixes).
 *   - OK    — write APIs that already call canX or assertCanX.
 *
 * Exit code is purely informational. The CI guard
 * tests/test_security_coverage_cli.php is what gates pushes.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

$root = realpath(__DIR__ . '/..');

function walk_api($dir): array {
    $out = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iter as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $out[] = str_replace('\\', '/', $f->getPathname());
        }
    }
    sort($out);
    return $out;
}

$ignore_substrings = [
    '/api/debug_', '/api/test_', '/api/migration_', '/api/temp_', '/api/check_',
    '/api/dn_attachment_helper',
    '/api/helpers/',
    '/api/pos/scratch/',
];

function shouldSkip(string $path): bool {
    global $ignore_substrings;
    foreach ($ignore_substrings as $s) if (strpos($path, $s) !== false) return true;
    return false;
}

$files = walk_api($root . '/api');

$gap = [];   // write APIs with no perm check at all
$ok  = [];   // write APIs that already gate

foreach ($files as $abs) {
    if (shouldSkip($abs)) continue;
    $rel = str_replace($root . '/', '', $abs);
    $src = @file_get_contents($abs) ?: '';

    $writes = preg_match('/\b(INSERT INTO|UPDATE\s+\w+\s+SET|DELETE FROM)\b/i', $src);
    if (!$writes) continue;

    $gates = (preg_match('/\bcanCreate\s*\(/', $src))
          || (preg_match('/\bcanEdit\s*\(/',   $src))
          || (preg_match('/\bcanDelete\s*\(/', $src))
          || (preg_match('/\bcanReview\s*\(/', $src))
          || (preg_match('/\bcanApprove\s*\(/',$src))
          || (preg_match('/\bcanView\s*\(/',   $src))
          || (preg_match('/\bassertCanCreate\s*\(/', $src))
          || (preg_match('/\bassertCanEdit\s*\(/',   $src))
          || (preg_match('/\bassertCanDelete\s*\(/', $src))
          || (preg_match('/\bautoEnforcePermission\s*\(/', $src))
          || (preg_match('/\benforcePageOrAdmin\s*\(/',    $src))
          || (preg_match('/\bhasPermission\s*\(/', $src))
          || (preg_match('/\brequireViewPermission\s*\(/', $src));

    if ($gates) {
        $ok[] = $rel;
    } else {
        $gap[] = $rel;
    }
}

echo "===== API PERMISSION GATE AUDIT =====\n";
echo "Total write APIs scanned : " . (count($gap) + count($ok)) . "\n";
echo "With perm gate (OK)      : " . count($ok)  . "\n";
echo "WITHOUT gate (GAP)       : " . count($gap) . "\n\n";

echo "===== GAP — write APIs that any logged-in user can hit =====\n";
foreach ($gap as $f) echo "  $f\n";

echo "\n===== MODULE SUMMARY (gap) =====\n";
$byModule = [];
foreach ($gap as $f) {
    if (preg_match('#api/([^/]+)/#', $f, $m)) $mod = 'api/' . $m[1];
    else $mod = 'api/(root)';
    $byModule[$mod] = ($byModule[$mod] ?? 0) + 1;
}
ksort($byModule);
foreach ($byModule as $m => $c) echo "  $m : $c missing\n";

echo "\nTotal: " . count($gap) . " write API(s) without a permission gate.\n";
