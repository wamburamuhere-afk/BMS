<?php
/**
 * Activity-log coverage audit.
 *
 * - View-pages: should log "View X" via logActivity() OR via the
 *   client-side logReportAction() helper (which posts to api/log_activity.php).
 * - State-changing APIs (POST endpoints under api/) must log every
 *   write on the success path. This script flags any API file that
 *   contains an INSERT/UPDATE/DELETE statement but never calls
 *   logActivity().
 *
 * Run from project root:
 *   php scratch/activity_log_audit.php > scratch/activity_log_findings.txt
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

$root = realpath(__DIR__ . '/..');

// ── 1. Walk every PHP file under app/ + api/ ─────────────────────────
function walk($dir) {
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

$appFiles = walk($root . '/app');
$apiFiles = walk($root . '/api');

$ignore_substrings = [
    '/pos/scratch/', '/includes/', '/models/',
    'pos_modals', 'pos_scripts', 'product_create_footer',
    'coming_soon', 'fix_database', 'payroll_migration',
    'debug_payroll_data', 'POSModel', 'pos_controller',
    'customer_display.php',
    // Skip debug/dev files in api/
    '/api/debug_', '/api/test_', '/api/migration_', '/api/temp_', '/api/check_',
    '/api/dn_attachment_helper', // helper, not endpoint
    '/api/helpers/',             // helper library functions; callers log
    'get_invoices.php',          // GET endpoint; contains a batch housekeeping UPDATE
                                 // (auto-mark overdue) that runs on every list load —
                                 // not a user-initiated write, logging each page view is noise.
];

function shouldSkip($path) {
    global $ignore_substrings;
    foreach ($ignore_substrings as $s) if (strpos($path, $s) !== false) return true;
    return false;
}

// ── 2. App pages — flag any that lack a logActivity / logReportAction / logAudit ──
echo "===== APP PAGES — view-pages that DON'T log activity =====\n";
$pageNoLog = [];
foreach ($appFiles as $abs) {
    if (shouldSkip($abs)) continue;
    $rel = str_replace($root . '/', '', $abs);
    $src = @file_get_contents($abs) ?: '';

    // A page is "view-like" if it includes the header
    if (strpos($src, 'header.php') === false && strpos($src, 'includeHeader') === false) continue;

    $logs = (strpos($src, 'logActivity(')      !== false)
         || (strpos($src, 'logReportAction(')  !== false)
         || (strpos($src, 'logAudit(')         !== false);

    if (!$logs) $pageNoLog[] = $rel;
}
foreach ($pageNoLog as $f) echo "  $f\n";
echo "\nTotal: " . count($pageNoLog) . " page(s) with no activity log call.\n\n";


// ── 3. APIs — flag any that write but never log ──────────────────────
echo "===== API ENDPOINTS — write APIs without logActivity() =====\n";
$writeNoLog = [];
foreach ($apiFiles as $abs) {
    if (shouldSkip($abs)) continue;
    $rel = str_replace($root . '/', '', $abs);
    $src = @file_get_contents($abs) ?: '';

    $writes = preg_match('/\b(INSERT INTO|UPDATE\s+\w+\s+SET|DELETE FROM)\b/i', $src);
    if (!$writes) continue;

    $logs = (strpos($src, 'logActivity(') !== false)
         || (strpos($src, 'logAudit(')    !== false);

    if (!$logs) $writeNoLog[] = $rel;
}
foreach ($writeNoLog as $f) echo "  $f\n";
echo "\nTotal: " . count($writeNoLog) . " write API(s) with no log.\n\n";


// ── 4. Module summary ────────────────────────────────────────────────
echo "===== MODULE SUMMARY (write APIs missing logs) =====\n";
$byModule = [];
foreach ($writeNoLog as $f) {
    if (preg_match('#api/([^/]+)/#', $f, $m)) $mod = 'api/' . $m[1];
    else $mod = 'api/(root)';
    $byModule[$mod] = ($byModule[$mod] ?? 0) + 1;
}
ksort($byModule);
foreach ($byModule as $m => $c) echo "  $m : $c missing\n";
