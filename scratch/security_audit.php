<?php
/**
 * One-shot security audit:
 *   - Cross-checks every page under app/ against the permissions table
 *     and getPagePermissionMapping() in core/permissions.php.
 *   - Detects pages that have NO permission gate (anyone logged in can open them).
 *   - Detects pages whose page_key is missing from the DB permissions table
 *     (admin cannot grant it via user_roles.php).
 *
 * Run from project root:
 *   php scratch/security_audit.php > security_findings.txt
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/permissions.php';

global $pdo;

// 1. DB permission keys
$dbKeys = $pdo->query("SELECT page_key, page_name, module_name FROM permissions")->fetchAll(PDO::FETCH_ASSOC);
$dbKeysSet = [];
foreach ($dbKeys as $r) $dbKeysSet[$r['page_key']] = $r;

// 2. Routing map (every file → its declared permission key)
$mapping = getPagePermissionMapping();

// 3. Walk every page under app/
$root = realpath(__DIR__ . '/..');
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app'));
$pages = [];
foreach ($iter as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $pages[] = str_replace($root . '/', '', $f->getPathname());
        // normalize Windows slashes
        $pages[count($pages)-1] = str_replace('\\', '/', end($pages));
    }
}
sort($pages);

$autoEnforced = 0;
$noGate       = [];
$keyMissing   = [];
$mapMissing   = [];

// Files that don't represent a directly-rendered page
$ignore_substrings = [
    'pos_modals', 'pos_scripts', '/includes/', '/models/', '/api/',
    'product_create_footer', 'coming_soon', 'pos_controller', 'POSModel',
    '/pos/scratch/', 'fix_database', 'payroll_migration', 'debug_payroll_data',
    'customer_display.php', // POS customer display screen
];

foreach ($pages as $rel) {
    $skip = false;
    foreach ($ignore_substrings as $sub) if (strpos($rel, $sub) !== false) { $skip = true; break; }
    if ($skip) continue;

    // Identify trivial sub-includes vs full pages by reading a chunk
    $src = @file_get_contents($root . '/' . $rel) ?: '';
    $shortname = basename($rel);

    $hasAuto = preg_match('/autoEnforcePermission\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\)/', $src, $m1);
    $hasReq  = preg_match('/requireViewPermission\(\s*[\'"]([a-z0-9_]+)[\'"]/', $src, $m2);
    $hasCan  = preg_match('/canView\(\s*[\'"]([a-z0-9_]+)[\'"]/', $src, $m3);

    $usedKey = null;
    if ($hasAuto) $usedKey = $m1[1];
    elseif ($hasReq) $usedKey = $m2[1];
    elseif ($hasCan) $usedKey = $m3[1];

    if ($usedKey) {
        $autoEnforced++;
        if (!isset($dbKeysSet[$usedKey])) {
            $keyMissing[] = ['file' => $rel, 'key' => $usedKey];
        }
        if (!isset($mapping[$shortname])) {
            $mapMissing[] = ['file' => $rel, 'shortname' => $shortname, 'key' => $usedKey];
        }
    } else {
        $noGate[] = $rel;
    }
}

echo "===== SECURITY AUDIT — permissions =====\n";
echo "Total pages walked    : " . count($pages) . "\n";
echo "Pages with a gate     : $autoEnforced\n";
echo "Pages with NO gate    : " . count($noGate) . "\n";
echo "Gate key missing in DB: " . count($keyMissing) . "\n";
echo "Filename not in map() : " . count($mapMissing) . "\n";
echo "\n";

if ($keyMissing) {
    echo "===== KEY MISSING FROM DB (admin can NEVER grant these via user_roles.php) =====\n";
    foreach ($keyMissing as $row) echo "  " . $row['file'] . "   key=\"" . $row['key'] . "\"\n";
    echo "\n";
}

if ($noGate) {
    echo "===== PAGES WITH NO PERMISSION GATE (any logged-in user can open) =====\n";
    foreach ($noGate as $f) echo "  $f\n";
    echo "\n";
}

if ($mapMissing) {
    echo "===== FILE NOT IN getPagePermissionMapping() (auto-enforcement only works for the explicit autoEnforcePermission() call, not the routing fallback) =====\n";
    foreach ($mapMissing as $row) echo "  " . $row['file'] . "   filename=\"" . $row['shortname'] . "\"\n";
    echo "\n";
}

echo "===== DB PERMISSION KEYS NEVER REFERENCED BY ANY PAGE =====\n";
$dbAll = array_keys($dbKeysSet);
$used  = [];
foreach ($pages as $rel) {
    $src = @file_get_contents($root . '/' . $rel) ?: '';
    foreach (['canView','canCreate','canEdit','canDelete','canReview','canApprove','autoEnforcePermission','requireViewPermission'] as $fn) {
        if (preg_match_all('/' . $fn . '\(\s*[\'"]([a-z0-9_]+)[\'"]/', $src, $mm)) {
            foreach ($mm[1] as $k) $used[$k] = true;
        }
    }
}
$orphans = array_diff($dbAll, array_keys($used));
foreach ($orphans as $k) {
    $info = $dbKeysSet[$k];
    $nm = $info['page_name'] ?: '(no name)';
    $md = $info['module_name'] ?: '(no module)';
    echo "  key=\"$k\"   name=\"$nm\"   module=\"$md\"\n";
}
