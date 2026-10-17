<?php
/**
 * Settings › Admin navigation: the list stays on the left (desktop) and in a
 * left drawer (phone) on System Settings and on every admin page reached from it.
 *
 * Run: php tests/test_settings_admin_drawer_cli.php   (exit 0 = all pass)
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/includes/settings_admin_nav.php";

$failures = 0;
$passes   = 0;
function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

echo "\n\033[1m═══ Settings › Admin drawer ═══\033[0m\n";

$keys = [];
foreach (settingsAdminNavGroups() as [, $items]) foreach ($items as $it) $keys[] = $it['key'];

section('Menu items');
foreach (['general', 'email', 'sms', 'security', 'users', 'user_roles', 'backup_restore', 'available_modules',
          'payment_settings', 'company_profile', 'notification_rules', 'user_projects'] as $k) {
    check(in_array($k, $keys, true), "$k listed", "$k missing from the menu");
}
$hasTenant = function_exists('bmsCurrentTenantId') && bmsCurrentTenantId() !== null;
check(in_array('my_subscription', $keys, true) === $hasTenant, 'My Subscription follows the tenant rule', 'My Subscription shown/hidden wrongly');
check(in_array('zoom_settings', $keys, true) === tenantFeatureEnabled('hr'), 'Zoom follows the hr feature', 'Zoom gate wrong');
check(in_array('ai_settings', $keys, true) === tenantFeatureEnabled('ai_assistant'), 'AI Assistant follows its feature', 'AI gate wrong');
check(count($keys) === count(array_unique($keys)), 'no duplicate items', 'duplicate menu keys');

section('Active item and links');
$list = settingsAdminNavList('users', false, 'desktop');
check(substr_count($list, ' active"') === 1 && str_contains($list, 'active" href="' . getUrl('users') . '" aria-current="page"'),
      'exactly one active item (Users) with aria-current', 'active item wrong');
check(str_contains($list, 'href="' . getUrl('system_settings') . '#tab-email"'), 'General/Email/SMS/Security link to system_settings#tab-…', 'system tab links wrong');
check(!str_contains($list, 'data-bs-toggle="tab"'), 'no tab toggles outside System Settings', 'tab toggles leaked onto other pages');

$desk = settingsAdminDesktopNav('general', true);
check(substr_count($desk, 'data-bs-toggle="tab"') === 4 && str_contains($desk, 'id="email-tab"') && str_contains($desk, 'id="settingsTabs"'),
      'System Settings desktop list keeps the 4 tab toggles', 'System Settings tab toggles missing');
$mob = settingsAdminMobileNav('general', true);
check(substr_count($mob, 'data-sa-tab=') === 4 && !str_contains($mob, 'id="email-tab"'), 'drawer uses data-sa-tab, no duplicate tab ids', 'drawer tab items wrong');
check(str_contains($mob, 'id="saDrawer"') && str_contains($mob, 'offcanvas-start') && str_contains($mob, 'data-bs-target="#saDrawer"'),
      'phone bar opens a left drawer', 'drawer markup wrong');
check(str_contains(settingsAdminDesktopNav('users'), 'd-none d-lg-block') && str_contains(settingsAdminMobileNav('users'), 'sa-bar d-lg-none'),
      'desktop list hidden on phones, bar hidden on desktop', 'breakpoint classes wrong');

section('Script');
$assets = settingsAdminNavAssets(true);
check(str_contains($assets, "location.pathname + location.search + '#tab-'"), 'hash update keeps the page path (safe with <base href>)', 'bare hash replaceState');
check(str_contains($assets, '/^#tab-([a-z_]+)$/'), 'opening system_settings#tab-x selects that tab', 'hash tab selection missing');
check(settingsAdminNavAssets(true) === '', 'assets printed only once per page', 'assets printed twice');

section('Shell is admin-only and balanced');
$_SESSION['is_admin'] = true;
ob_start(); settingsAdminShellOpen('zoom_settings'); echo '<div class="container-fluid">page</div>'; settingsAdminShellClose(); $html = ob_get_clean();
check(substr_count($html, '<div') === substr_count($html, '</div>'), 'open + close produce balanced divs', 'unbalanced divs');
check(str_contains($html, 'sa-shell') && str_contains($html, 'sa-main'), 'admin sees the two-column layout', 'admin layout missing');
$_SESSION['is_admin'] = false;
ob_start(); settingsAdminShellOpen('users'); echo 'page'; settingsAdminShellClose(); $html = ob_get_clean();
check($html === 'page', 'non-admin page renders unchanged', 'shell leaked to a non-admin');
unset($_SESSION['is_admin']);

section('Pages use the shared layout');
$pages = [
    'users' => 'users', 'add_user' => 'users', 'edit_user' => 'users', 'user_roles' => 'user_roles',
    'backup_restore' => 'backup_restore', 'available_modules' => 'available_modules', 'my_subscription' => 'my_subscription',
    'payment_settings' => 'payment_settings', 'company_profile' => 'company_profile', 'notification_rules' => 'notification_rules',
    'zoom_settings' => 'zoom_settings', 'ai_settings' => 'ai_settings', 'user_projects' => 'user_projects',
];
foreach ($pages as $page => $key) {
    $src = (string)file_get_contents("$root/app/constant/settings/$page.php");
    $open = strpos($src, "settingsAdminShellOpen('$key')");
    $close = strpos($src, 'settingsAdminShellClose()');
    $footer = max((int)strrpos($src, 'FOOTER_FILE'), (int)strrpos($src, 'footer.php'));
    check($open !== false && $close !== false && $open < $close && $close < $footer
          && substr_count($src, 'settingsAdminShellOpen(') === 1 && substr_count($src, 'settingsAdminShellClose(') === 1,
          "$page opens ($key) and closes the layout before the footer", "$page layout calls missing or misplaced");
}
$ss = (string)file_get_contents("$root/app/constant/settings/system_settings.php");
check(str_contains($ss, "settingsAdminDesktopNav('general', true)") && str_contains($ss, "settingsAdminMobileNav('general', true)")
      && !str_contains($ss, 'sidebar-group-header'), 'System Settings uses the shared list (old inline list removed)', 'System Settings still has its own list');

echo "\n\033[1mResult: $passes passed, $failures failed\033[0m\n";
exit($failures ? 1 : 0);
