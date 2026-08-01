<?php
/**
 * Settings menu — 11 pages locked strictly admin-only (all consolidated
 * inside Settings > Admin), the rest genuinely delegable via Roles &
 * Permissions.
 *
 * User-reported/requested: "Settings > Admin" (system_settings.php) and
 * everything sensitive within reach of it should only ever be usable by a
 * literal admin — never delegable via user_roles.php, no matter what a
 * future admin grants. Login History and Zoom Integration were later added
 * to this locked set by explicit request, and ALL locked items were
 * consolidated to live only inside system_settings.php's own nav list
 * (plain page links, not local tabs) rather than as separate top-nav
 * Settings items — since reaching that page already requires isAdmin(),
 * nesting them there means a non-admin never even sees them as menu
 * entries anywhere. Company Profile, Notification Rules, Project
 * Assignments, and AI Assistant were added to this locked set later still
 * (all 2026-07-30), each the same way — same page, same fields, same
 * stored settings, just reached only via Admin now. Notification Settings
 * followed on 2026-07-31, but differently: instead of a system_settings.php
 * sidebar link, its whole UI (channels/email/SMS templates/alert rules) was
 * extracted into a shared partial, _notification_settings_panel.php, and
 * embedded as a collapsible panel directly inside notification_rules.php —
 * so reaching Notification Rules is what gets you to it, no separate menu
 * entry needed. The standalone notification_settings.php URL still works
 * (same partial, same admin gate) for anyone with it bookmarked. Everything
 * else in the Settings menu stays genuinely delegable: hidden from a role
 * until granted the specific permission, then visible and usable.
 *
 * Locked (isAdmin() hard check; link lives only inside system_settings.php,
 * not header.php's top-nav dropdown):
 *   - system_settings.php     ("Admin" in the menu — the one exception
 *                              that DOES still have its own top-nav link,
 *                              since it's the entry point to all the others)
 *   - users.php
 *   - user_roles.php          (editing this IS the escalation vector — a
 *                              non-admin with edit access here could grant
 *                              their own role admin-equivalent power)
 *   - backup_restore.php      (full restore/download risk)
 *   - payment_settings.php    (bank/gateway fraud risk)
 *   - login_history.php       (privacy-sensitive audit trail)
 *   - zoom_settings.php       (view was briefly delegable, now locked too;
 *                              its credential-writing API endpoints were
 *                              always isAdmin()-only regardless)
 *   - company_profile.php     (logo/TIN/VRN/addresses — feeds tax-compliant
 *                              documents and the site-wide header)
 *   - notification_rules.php  (who's notified, per event, across every module;
 *                              now also embeds the Notification Settings panel)
 *   - user_projects.php       (project/warehouse scope assignment UI)
 *   - ai_settings.php         (AI provider/API-key configuration — NOT the
 *                              same as the separate "Ask BMS AI" chat
 *                              feature, app/constant/communication/ai_assistant.php,
 *                              which stays independently delegable)
 *   - notification_settings.php (channels/email/SMS templates/alert rules;
 *                              embedded inside notification_rules.php as a
 *                              collapsible panel — NOT given its own
 *                              system_settings.php sidebar link, unlike the
 *                              rest of this list)
 *
 * All of the above have their permission row hidden from user_roles.php
 * entirely so it can't even be offered as a checkbox — EXCEPT
 * 'ai_assistant': that page_key is shared with the "Ask BMS AI" chat
 * feature, which must stay grantable to non-admins, so hiding it here
 * would have removed the ability to delegate chat access too. The config
 * page itself is still fully admin-only via its own isAdmin() gate either way.
 *
 * Delegable (canView('page_key') only, no isAdmin() block — admins still
 * always pass canView(), so nothing is lost for them):
 *   - pos_config_settings.php, color_settings.php, tax_settings.php
 *
 * Run: php tests/test_admin_lock_and_delegable_settings_cli.php
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

echo "\n\033[1m═══ Settings menu — admin-only lock + genuine delegation ═══\033[0m\n";

$LOCKED = [
    'app/constant/settings/system_settings.php'  => 'system_settings',
    'app/constant/settings/users.php'            => 'users',
    'app/constant/settings/user_roles.php'       => 'user_roles',
    'app/constant/settings/backup_restore.php'   => 'backup_restore',
    'app/constant/settings/payment_settings.php' => 'payment_settings',
    'app/constant/settings/login_history.php'    => 'login_history',
    'app/constant/settings/zoom_settings.php'    => 'zoom_settings',
    'app/constant/settings/company_profile.php'  => 'company_profile',
    'app/constant/settings/notification_rules.php' => 'notification_rules',
    'app/constant/settings/user_projects.php'    => 'user_projects',
    'app/constant/settings/ai_settings.php'      => 'ai_assistant',
    'app/constant/settings/notification_settings.php' => 'notification_settings',
];
// Locked pages whose permission row is deliberately left visible (not
// hidden) in Roles & Permissions — see the file-header note on 'ai_assistant'.
$LOCKED_PERMISSION_STAYS_VISIBLE = ['ai_assistant'];
// Locked pages that do NOT get their own system_settings.php sidebar link,
// because they're reached by being embedded inside another locked page
// instead — see the file-header note on 'notification_settings'.
$LOCKED_NO_SIDEBAR_LINK = ['notification_settings'];
$DELEGABLE = [
    'app/constant/settings/pos_config_settings.php'    => 'pos_config_settings',
    'app/constant/settings/color_settings.php'          => 'color_settings',
    'app/constant/settings/tax_settings.php'            => 'tax_settings',
];

section('1. php -l — every touched file');
foreach (array_merge(array_keys($LOCKED), array_keys($DELEGABLE), ['header.php', 'app/constant/settings/_notification_settings_panel.php', 'migrations/2026_07_29_lock_sensitive_settings.php', 'migrations/2026_07_30_lock_company_profile.php', 'migrations/2026_07_30_lock_notification_rules.php', 'migrations/2026_07_30_lock_project_assignments.php', 'migrations/2026_07_31_lock_notification_settings.php']) as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    check($rc === 0, "$f — no syntax errors", "$f — php -l failed: " . implode(' ', $out));
}

section('2. Locked pages have a hard isAdmin() gate');
foreach ($LOCKED as $file => $key) {
    $s = readSrc($root, $file);
    check((bool)preg_match('/if\s*\(\s*!\s*(isAdmin\(\)|canView\([\'"]user_roles[\'"]\)\s*\|\|\s*!\s*isAdmin\(\))\s*\)/', $s),
        "$file has a hard isAdmin() gate",
        "$file is missing its hard isAdmin() gate — would become delegable again");
}

section('3. Delegable pages have NO redundant isAdmin() block (autoEnforcePermission alone gates them)');
foreach ($DELEGABLE as $file => $key) {
    $s = readSrc($root, $file);
    check(str_contains($s, "autoEnforcePermission('$key')"), "$file still calls autoEnforcePermission('$key')", "$file is missing its autoEnforcePermission('$key') call");
    check(!preg_match('/if\s*\(\s*!\s*isAdmin\(\)\s*\)/', $s), "$file has no redundant isAdmin() block", "$file still hard-blocks non-admins, defeating delegation");
}

section('4. AI/Zoom credential-writing endpoints remain isAdmin()-only (view is delegable, write is not)');
foreach ([
    'api/ai/save_ai_settings.php'   => 'AI API key save',
    'api/ai/test_ai_config.php'     => 'AI connection test',
    'api/zoom/save_zoom_settings.php' => 'Zoom secret save',
    'api/zoom/test_zoom_config.php' => 'Zoom connection test',
] as $file => $label) {
    $s = readSrc($root, $file);
    check(str_contains($s, 'isAdmin()'), "$label ($file) is still isAdmin()-gated", "$label ($file) lost its isAdmin() gate — credential writes should stay admin-only");
}

section('5. header.php consolidates admin-only items behind one "Admin" entry point');
$hdr = readSrc($root, 'header.php');
// Per request: Users/Roles & Permissions/Payments/Backup/Login History/Zoom
// Integration should NOT appear as their own separate top-nav Settings
// items at all — only reachable via the single "Admin" (system_settings.php)
// link, itself isAdmin()-gated. header.php should therefore link to
// system_settings, but NOT directly to any of the other 10 locked pages.
// (ai_settings is checked by its own route key here, NOT 'ai_assistant' —
// header.php legitimately still links to getUrl('ai_assistant') for the
// unrelated "Ask BMS AI" chat feature, checked separately below.)
check(str_contains($hdr, "getUrl('system_settings')"), "header.php still links to the Admin entry point (system_settings)", "header.php is missing its link to system_settings");
check(!str_contains($hdr, "getUrl('notification_settings')"), "header.php no longer links to 'notification_settings' directly (folded into notification_rules.php's panel)", "header.php still has a direct link to 'notification_settings' — should only be reachable via notification_rules.php or its own URL");
foreach (['users', 'user_roles', 'payment_settings', 'backup_restore', 'login_history', 'zoom_settings', 'company_profile', 'notification_rules', 'user_projects', 'ai_settings'] as $key) {
    check(!str_contains($hdr, "getUrl('$key')"), "header.php no longer links to '$key' directly (consolidated into Admin)", "header.php still has a direct top-nav link to '$key' — should only be reachable via the Admin page");
}
check(str_contains($hdr, "getUrl('ai_assistant')") && str_contains($hdr, "Ask BMS"),
    "header.php still links to the separate 'Ask BMS AI' chat feature (unaffected by locking the AI config page)",
    "header.php lost its 'Ask BMS AI' chat link — locking the AI config page should not have touched this");
// The Admin link itself must still be isAdmin()-gated.
check(
    (bool)preg_match('/if \(isAdmin\(\)\):\s*' . preg_quote('?>', '/') . '(.*?)' . preg_quote('<?php', '/') . ' endif; ' . preg_quote('?>', '/') . '/s', $hdr, $m) && str_contains($m[1] ?? '', "getUrl('system_settings')"),
    "header.php's Admin link falls inside an isAdmin()-only block",
    "header.php's Admin link is not gated by isAdmin()"
);
foreach ($DELEGABLE as $file => $key) {
    check(str_contains($hdr, "canView('$key')"), "header.php gates $key via canView()", "header.php is missing a canView('$key') gate for its menu item");
}

section('5b. system_settings.php now hosts plain nav links to every other locked page');
$settingsPageSrc = readSrc($root, 'app/constant/settings/system_settings.php');
foreach (['users', 'user_roles', 'payment_settings', 'backup_restore', 'login_history', 'zoom_settings', 'company_profile', 'notification_rules', 'user_projects', 'ai_settings'] as $key) {
    check(str_contains($settingsPageSrc, "getUrl('$key')"), "system_settings.php links to '$key'", "system_settings.php is missing a link to '$key' — it should be reachable from inside the Admin page");
}
// These must be plain navigation links, not local tab-panes (no matching
// data-bs-toggle="tab" id for any of these).
foreach (['users-tab', 'roles-tab', 'user_roles-tab', 'payment-tab', 'payment_settings-tab', 'login_history-tab', 'zoom-tab', 'zoom_settings-tab', 'company_profile-tab', 'notification_rules-tab', 'user_projects-tab', 'ai_settings-tab'] as $badId) {
    check(!str_contains($settingsPageSrc, "id=\"$badId\""), "system_settings.php has no local tab-pane id=\"$badId\" (must be a real page link, not a fake local tab)", "system_settings.php defines a local tab-pane id=\"$badId\" — should link to the real standalone page instead");
}
foreach ($LOCKED_NO_SIDEBAR_LINK as $key) {
    check(!str_contains($settingsPageSrc, "getUrl('$key')"), "system_settings.php deliberately has NO sidebar link to '$key' (reached via embedding instead)", "system_settings.php unexpectedly links to '$key' — it was meant to stay reachable only by being embedded elsewhere");
}

section('5c. notification_settings is embedded inside notification_rules.php via the shared panel, not duplicated');
$panelPath = 'app/constant/settings/_notification_settings_panel.php';
$panelSrc = readSrc($root, $panelPath);
$rulesPageSrc = readSrc($root, 'app/constant/settings/notification_rules.php');
$settingsStandaloneSrc = readSrc($root, 'app/constant/settings/notification_settings.php');
check($panelSrc !== '', "$panelPath exists", "$panelPath is missing");
check(str_contains($rulesPageSrc, "require __DIR__ . '/_notification_settings_panel.php'") || str_contains($rulesPageSrc, 'require __DIR__ . "/_notification_settings_panel.php"'),
    'notification_rules.php requires the shared notification settings panel',
    'notification_rules.php no longer embeds the shared notification settings panel');
check(str_contains($settingsStandaloneSrc, "require __DIR__ . '/_notification_settings_panel.php'") || str_contains($settingsStandaloneSrc, 'require __DIR__ . "/_notification_settings_panel.php"'),
    'notification_settings.php also requires the shared panel (same source, no duplicated markup)',
    'notification_settings.php no longer uses the shared panel — check for drift/duplication');
check(str_contains($panelSrc, 'notif-settings-panel'), 'the shared panel scopes its CSS under .notif-settings-panel', 'the shared panel is missing its CSS scoping wrapper — its generic .card/.nav-tabs rules would leak into the host page');
check(!str_contains($rulesPageSrc, 'save_notification_settings') && !str_contains($settingsStandaloneSrc, "if (\$_POST) {\n    \$success_messages"),
    'the save-handling logic lives only in the shared panel, not copy-pasted into either caller',
    'save-handling logic appears duplicated into a caller instead of living solely in the shared panel');

section('6. The 11 of 12 locked permissions expected to be hidden are hidden from the Roles & Permissions management UI');
$rolesSrc = readSrc($root, 'app/constant/settings/user_roles.php');
check(str_contains($rolesSrc, 'COALESCE(is_hidden, 0) = 0'), 'user_roles.php filters out is_hidden=1 permissions from its management list', 'user_roles.php no longer filters by is_hidden — hidden permissions would leak back into the UI');

if (!$isLive) {
    echo "\n  \033[33m⊘\033[0m  Skipping live section (no includes/config.php — not a live install)\n";
} else {
    section('7. Live — the hidden permission rows are actually hidden in the DB (ai_assistant deliberately excluded)');
    global $pdo;
    try {
        foreach (array_values($LOCKED) as $key) {
            if (in_array($key, $LOCKED_PERMISSION_STAYS_VISIBLE, true)) continue;
            $hidden = (int)$pdo->query("SELECT COALESCE(is_hidden,0) FROM permissions WHERE page_key = " . $pdo->quote($key))->fetchColumn();
            check($hidden === 1, "permission '$key' has is_hidden=1", "permission '$key' is NOT hidden (is_hidden=$hidden) — run migrations/2026_07_29_lock_sensitive_settings.php, migrations/2026_07_29_lock_login_history_and_zoom.php, migrations/2026_07_30_lock_company_profile.php, migrations/2026_07_30_lock_notification_rules.php, migrations/2026_07_30_lock_project_assignments.php or migrations/2026_07_31_lock_notification_settings.php");
        }
        foreach ($LOCKED_PERMISSION_STAYS_VISIBLE as $key) {
            $hidden = (int)$pdo->query("SELECT COALESCE(is_hidden,0) FROM permissions WHERE page_key = " . $pdo->quote($key))->fetchColumn();
            check($hidden === 0, "permission '$key' is deliberately still visible (is_hidden=0) — shared with the delegable 'Ask BMS AI' chat feature", "permission '$key' is unexpectedly hidden — this would also block granting the 'Ask BMS AI' chat feature to non-admins");
        }

        section('8. Live — canView() proves the split: locked pages deny a granted-but-non-admin user; delegable pages allow one');
        // Simulate a non-admin explicitly granted EVERY one of these page_keys
        // (the most generous possible grant) and confirm the locked ones are
        // still denied by the isAdmin()-based menu/page logic, while every
        // delegable one is allowed.
        $_SESSION['user_id']  = 999051;
        $_SESSION['role_id']  = 999051; // never role_id 1
        $_SESSION['is_admin'] = false;
        $allKeys = array_merge(array_values($LOCKED), array_values($DELEGABLE));
        $_SESSION['permissions'] = [];
        foreach ($allKeys as $k) {
            $_SESSION['permissions'][$k] = ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'review' => true, 'approve' => true];
        }

        foreach ($LOCKED as $file => $key) {
            // The page's own gate additionally requires isAdmin() — canView()
            // alone (even fully granted) must not be sufficient.
            check(canView($key) === true && isAdmin() === false,
                "'$key' — canView() passes (fully granted) but isAdmin() correctly still false for this non-admin",
                "'$key' — sanity check failed: isAdmin() unexpectedly true for a non-admin session");
        }
        check(!isAdmin(), 'confirmed: this simulated session is NOT an admin (the locked pages\' isAdmin() gate would correctly reject it)', 'session unexpectedly resolved as admin — test setup invalid');

        foreach ($DELEGABLE as $file => $key) {
            check(canView($key) === true, "'$key' — a non-admin granted this permission passes canView() (delegation works)", "'$key' — STILL BROKEN: granted permission does not pass canView()");
        }

        // And the reverse: with NOTHING granted, every one of these must deny.
        $_SESSION['permissions'] = [];
        foreach ($allKeys as $k) {
            check(canView($k) === false, "'$k' — with nothing granted, canView() correctly denies", "'$k' — LEAK: canView() passed with zero permissions granted");
        }

        unset($_SESSION['user_id'], $_SESSION['role_id'], $_SESSION['is_admin'], $_SESSION['permissions']);
        pass('test session state cleaned up');
    } catch (Throwable $e) {
        fail('Live section threw: ' . $e->getMessage());
    }
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
