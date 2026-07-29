<?php
/**
 * POS Settings + Color Setting — split out of system_settings.php into
 * their own standalone, independently-delegable pages.
 *
 * User-reported: these two lived as tabs buried inside the "Admin" page
 * (system_settings.php), gated only by the single 'system_settings'
 * permission — no way to grant one without the other, or without the rest
 * of Admin. Moved to their own pages/permissions (pos_config_settings,
 * color_settings), matching how Company Profile/Tax/Payments already work,
 * and linked directly from the Settings dropdown (not nested under Admin).
 *
 * Fix:
 *   1. New pages: app/constant/settings/pos_config_settings.php,
 *      app/constant/settings/color_settings.php — each gated by its own
 *      autoEnforcePermission() call, no redundant isAdmin() block (genuinely
 *      delegable from the start).
 *   2. Removed the "Color Setting" and "POS Settings" tabs (nav buttons,
 *      tab-panes, and their save_colors/save_pos POST handlers) from
 *      system_settings.php entirely.
 *   3. header.php links to both new pages directly under the Settings
 *      dropdown's "System Configuration" section (siblings of Company
 *      Profile), not nested inside Admin.
 *   4. roots.php routes 'pos_config_settings' and 'color_settings'.
 *   5. migrations/2026_07_29_pos_color_settings_split.php seeds the two new
 *      permission rows (module 'Settings', mirrors zoom_settings/ai_assistant).
 *
 * Run: php tests/test_pos_color_settings_split_cli.php
 *   Exit 0 = all pass · Exit 1 = a regression slipped in.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root   = dirname(__DIR__);
$isLive = is_file("$root/includes/config.php");

if ($isLive) {
    require_once "$root/roots.php";
    require_once "$root/core/project_scope.php";
}

$failures = 0;
$passes   = 0;

function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }
function readSrc($root, $rel) { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }

echo "\n\033[1m═══ POS Settings + Color Setting — split into standalone pages ═══\033[0m\n";

$posFile   = 'app/constant/settings/pos_config_settings.php';
$colorFile = 'app/constant/settings/color_settings.php';
$settingsFile = 'app/constant/settings/system_settings.php';
$migFile   = 'migrations/2026_07_29_pos_color_settings_split.php';

section('1. New files exist and lint clean');
foreach ([$posFile, $colorFile, $settingsFile, 'header.php', 'roots.php', $migFile] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    check($rc === 0, "$f — no syntax errors", "$f — php -l failed: " . implode(' ', $out));
}

$posSrc      = readSrc($root, $posFile);
$colorSrc    = readSrc($root, $colorFile);
$settingsSrc = readSrc($root, $settingsFile);
$headerSrc   = readSrc($root, 'header.php');
$rootsSrc    = readSrc($root, 'roots.php');

section('2. New pages are gated by their own permission, genuinely delegable (no redundant isAdmin() block)');
check(str_contains($posSrc, "autoEnforcePermission('pos_config_settings')"), 'pos_config_settings.php enforces its own permission', 'pos_config_settings.php missing its permission gate');
check(!preg_match('/if\s*\(\s*!\s*isAdmin\(\)\s*\)/', $posSrc), 'pos_config_settings.php has no redundant isAdmin() block', 'pos_config_settings.php still hard-blocks non-admins, defeating delegation');
check(str_contains($colorSrc, "autoEnforcePermission('color_settings')"), 'color_settings.php enforces its own permission', 'color_settings.php missing its permission gate');
check(!preg_match('/if\s*\(\s*!\s*isAdmin\(\)\s*\)/', $colorSrc), 'color_settings.php has no redundant isAdmin() block', 'color_settings.php still hard-blocks non-admins, defeating delegation');

section('3. New pages preserve the exact same settings keys as before (nothing renamed/dropped)');
check(str_contains($posSrc, "save_setting('pos_discount_type'"), 'pos_config_settings.php still saves pos_discount_type', 'pos_discount_type is no longer saved — POS discount preference would silently stop working');
$colorKeys = [
    'print_template_color_po_navy', 'print_template_color_pret_navy', 'print_template_color_dbn_navy',
    'print_template_color_rfq_striped', 'print_template_color_do_manifest', 'print_template_color_so_confirmation',
    'print_template_color_qt_noir', 'print_template_color_inv_summit', 'print_template_color_dn_depot',
    'print_template_color_cn_ledger', 'print_template_color_sr_intake',
];
$missingKeys = array_filter($colorKeys, fn($k) => !str_contains($colorSrc, $k));
check(empty($missingKeys), 'all 11 print-template color families are present in color_settings.php', 'missing color keys: ' . implode(', ', $missingKeys));
check(substr_count($colorSrc, "input type=\"color\"") === 33, 'all 33 color pickers carried over (' . substr_count($colorSrc, 'input type="color"') . ' found)', 'expected exactly 33 color <input> fields, found ' . substr_count($colorSrc, 'input type="color"'));
check(preg_match('/^\s*\'print_template_color_\w+\'\s*=>/m', $colorSrc) === 1 || str_contains($colorSrc, "!preg_match('/^#[0-9A-Fa-f]{6}\$/'"), 'the hex-validation-falls-back-to-default safeguard is preserved', 'the #rrggbb validation/fallback logic is missing — an unsanitised value could reach save_setting()');

section('4. Old tabs, nav buttons and save handlers fully removed from system_settings.php');
foreach ([
    'id="colors-tab"'    => 'the Color Setting tab nav button',
    'id="pos-tab"'       => 'the POS Settings tab nav button',
    'id="colors"'        => 'the Color Setting tab-pane',
    'id="pos"'           => 'the POS Settings tab-pane',
    "save_colors"        => 'the save_colors POST handler',
    "save_pos"           => 'the save_pos POST handler',
    'pos_discount_type'  => 'the pos_discount_type field',
    'print_template_color_' => 'any print_template_color_* field',
] as $needle => $label) {
    check(!str_contains($settingsSrc, $needle), "system_settings.php no longer contains $label", "system_settings.php still contains $label — the split is incomplete");
}

section('5. header.php links to both new pages under System Configuration (not nested inside Admin)');
check(str_contains($headerSrc, "getUrl('pos_config_settings')"), 'header.php links to pos_config_settings', 'header.php has no link to the new POS Settings page');
check(str_contains($headerSrc, "getUrl('color_settings')"), 'header.php links to color_settings', 'header.php has no link to the new Color Setting page');
// Both new links must sit between the "System Configuration" header and the
// "Business Settings" header, not after it (which would misplace them).
$sysConfigPos = strpos($headerSrc, 'System Configuration');
$bizSettingsPos = strpos($headerSrc, 'Business Settings');
$posLinkPos = strpos($headerSrc, "getUrl('pos_config_settings')");
$colorLinkPos = strpos($headerSrc, "getUrl('color_settings')");
check($sysConfigPos !== false && $bizSettingsPos !== false && $posLinkPos > $sysConfigPos && $posLinkPos < $bizSettingsPos,
    'POS Settings link sits within the System Configuration section', 'POS Settings link is outside the System Configuration section');
check($colorLinkPos > $sysConfigPos && $colorLinkPos < $bizSettingsPos,
    'Color Setting link sits within the System Configuration section', 'Color Setting link is outside the System Configuration section');

section('6. roots.php routes both new pages');
check(preg_match("#'pos_config_settings'\s*=>\s*SETTINGS_DIR\s*\.\s*'/pos_config_settings\.php'#", $rootsSrc) === 1, "roots.php routes 'pos_config_settings'", "roots.php is missing the pos_config_settings route");
check(preg_match("#'color_settings'\s*=>\s*SETTINGS_DIR\s*\.\s*'/color_settings\.php'#", $rootsSrc) === 1, "roots.php routes 'color_settings'", "roots.php is missing the color_settings route");

section('7. system_settings.php still balanced (no orphaned markup from the removal)');
$divOpen  = substr_count($settingsSrc, '<div');
$divClose = substr_count($settingsSrc, '</div>');
check($divOpen === $divClose, "div tags balanced ($divOpen open / $divClose close)", "div tags UNBALANCED ($divOpen open / $divClose close) — the tab-pane removal likely broke the markup");
$formOpen  = substr_count($settingsSrc, '<form');
$formClose = substr_count($settingsSrc, '</form>');
check($formOpen === 5 && $formClose === 5, "exactly 5 forms remain: General/Email/SMS/Collection/Security ($formOpen open / $formClose close)", "expected 5 forms remaining, found $formOpen open / $formClose close");

if (!$isLive) {
    echo "\n  \033[33m⊘\033[0m  Skipping live section (no includes/config.php — not a live install)\n";
} else {
    section('8. Live — the two new permission rows exist, and both pages render cleanly for an admin');
    global $pdo;
    try {
        foreach (['pos_config_settings', 'color_settings'] as $key) {
            $found = (bool)$pdo->query("SELECT 1 FROM permissions WHERE page_key = " . $pdo->quote($key))->fetchColumn();
            check($found, "permission row '$key' exists in the DB", "permission row '$key' is missing — run migrations/2026_07_29_pos_color_settings_split.php");
        }

        $_SESSION['user_id'] = 4;
        $_SESSION['role_id'] = (int)$pdo->query("SELECT role_id FROM users WHERE user_id = 4")->fetchColumn();
        unset($_SESSION['is_admin'], $_SESSION['scope']);
        loadUserScope(4);

        foreach ([$posFile => 'pos_config_settings.php', $colorFile => 'color_settings.php'] as $file => $label) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET = [];
            ob_start();
            try {
                include "$root/$file";
            } catch (Throwable $e) {
                ob_end_clean();
                fail("$label threw: " . $e->getMessage());
                continue;
            }
            $out = ob_get_clean();
            check(strpos($out, 'Warning') === false && strpos($out, 'Fatal error') === false && strpos($out, 'Deprecated') === false,
                "$label renders for an admin with no PHP warnings/fatals",
                "$label emitted warnings/errors: " . substr(strip_tags($out), 0, 300));
        }
    } catch (Throwable $e) {
        fail('Live section threw: ' . $e->getMessage());
    }
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
