<?php
/**
 * Settings › Admin navigation, shared by System Settings and every admin page
 * reached from its list.
 *
 * Desktop (>= 992px): the grouped list stays in a sticky left column.
 * Phone/tablet: an "All settings" bar opens the same list in a left drawer.
 *
 * Admin-only: for anyone else the shell renders nothing, so a page a
 * non-admin can open looks exactly as before.
 */

/** Grouped items, with the same visibility rules System Settings has always used. */
function settingsAdminNavGroups(): array
{
    $projectsEnabled = function_exists('projectsModuleActive') ? projectsModuleActive() : true;
    $feature = fn(string $f) => !function_exists('tenantFeatureEnabled') || tenantFeatureEnabled($f);

    $groups = [
        [t('System Configuration'), [
            ['key' => 'general',  'tab' => 'general',  'icon' => 'bi-building',     'tone' => 'primary', 'title' => t('General'),      'sub' => t('Business identity & basics')],
            ['key' => 'email',    'tab' => 'email',    'icon' => 'bi-envelope',     'tone' => 'info',    'title' => t('Email Config'), 'sub' => t('SMTP & notifications')],
            ['key' => 'sms',      'tab' => 'sms',      'icon' => 'bi-chat-text',    'tone' => 'warning', 'title' => t('SMS Gateway'),  'sub' => t('Providers & settings')],
            ['key' => 'security', 'tab' => 'security', 'icon' => 'bi-shield-lock',  'tone' => 'dark',    'title' => t('Security'),     'sub' => t('Sessions & audits')],
        ]],
        [t('Access & Security'), [
            ['key' => 'users',          'icon' => 'bi-people',           'tone' => 'primary', 'title' => t('Users'),               'sub' => t('Accounts & roles')],
            ['key' => 'user_roles',     'icon' => 'bi-shield-check',     'tone' => 'primary', 'title' => t('Roles & Permissions'), 'sub' => t('What each role can do')],
            ['key' => 'backup_restore', 'icon' => 'bi-cloud-arrow-down', 'tone' => 'indigo',  'title' => t('Backup'),              'sub' => t('Database recovery')],
        ]],
        [t('Plan & Modules'), array_values(array_filter([
            ['key' => 'available_modules', 'icon' => 'bi-grid', 'tone' => 'primary', 'title' => t('Available Modules'), 'sub' => t('What your plan includes, request more')],
            // My Subscription moved out of here 2026-10-17 → top-level "Billing" (service_plans_plan.md D8/D10).
        ]))],
        [t('Business & Finance'), [
            ['key' => 'payment_settings', 'icon' => 'bi-credit-card', 'tone' => 'success', 'title' => t('Payments'),        'sub' => t('Bank & gateway details')],
            ['key' => 'company_profile',  'icon' => 'bi-building',    'tone' => 'warning', 'title' => t('Company Profile'), 'sub' => t('Logo, TIN/VRN, addresses')],
        ]],
        [t('Communication & Integrations'), array_values(array_filter([
            ['key' => 'notification_rules', 'icon' => 'bi-bell-fill', 'tone' => 'danger', 'title' => t('Notification Rules'), 'sub' => t("Who's notified, per event")],
            $feature('hr') ? ['key' => 'zoom_settings', 'icon' => 'bi-camera-video', 'tone' => 'info', 'title' => t('Zoom Integration'), 'sub' => t('Video meeting config')] : null,
            $feature('ai_assistant') ? ['key' => 'ai_settings', 'icon' => 'bi-stars', 'tone' => 'info', 'title' => t('AI Assistant'), 'sub' => t('AI configuration & API key')] : null,
        ]))],
        [$projectsEnabled ? t('Projects') : wLabel('Warehouse Access', 'Shop Access'), [
            ['key' => 'user_projects', 'icon' => 'bi-diagram-3', 'tone' => 'primary',
             'title' => $projectsEnabled ? t('Project Assignments') : wLabel('Warehouse Access', 'Shop Access'),
             'sub'   => $projectsEnabled ? t("Who's assigned to which project") : wLabel('Grant users access to warehouses', 'Grant users access to shops')],
        ]],
    ];
    return array_values(array_filter($groups, fn($g) => !empty($g[1])));
}

function settingsAdminNavFind(string $active): ?array
{
    foreach (settingsAdminNavGroups() as [$g, $items]) {
        foreach ($items as $it) {
            if ($it['key'] === $active) return $it + ['group' => $g];
        }
    }
    return null;
}

/**
 * One list. $mode 'desktop' on System Settings renders the four first items as
 * Bootstrap tab toggles (ids general-tab…); 'drawer' renders them as
 * data-sa-tab links handled by the script below; elsewhere they link to
 * system_settings#tab.
 */
function settingsAdminNavList(string $active, bool $onSystemSettings, string $mode): string
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $sysUrl = getUrl('system_settings');
    $out = '';
    foreach (settingsAdminNavGroups() as [$group, $items]) {
        $out .= '<div class="list-group-item sa-group">' . $h($group) . '</div>';
        foreach ($items as $it) {
            $on   = $it['key'] === $active;
            $cls  = 'list-group-item list-group-item-action sa-item' . ($on ? ' active' : '');
            $body = '<span class="sa-icon bg-' . $it['tone'] . '-soft text-' . $it['tone'] . '"><i class="bi ' . $it['icon'] . '"></i></span>'
                  . '<span class="sa-text"><span class="sa-title">' . $h($it['title']) . '</span><small>' . $h($it['sub']) . '</small></span>';
            $data = ' data-sa-key="' . $h($it['key']) . '"';
            if (isset($it['tab']) && $onSystemSettings && $mode === 'desktop') {
                $out .= '<a class="' . $cls . '" id="' . $h($it['tab']) . '-tab" data-bs-toggle="tab" data-bs-target="#' . $h($it['tab'])
                      . '" href="#tab-' . $h($it['tab']) . '" role="tab" aria-selected="' . ($on ? 'true' : 'false') . '"' . $data . '>' . $body . '</a>';
            } elseif (isset($it['tab']) && $onSystemSettings) {
                $out .= '<a class="' . $cls . '" href="#tab-' . $h($it['tab']) . '" data-sa-tab="' . $h($it['tab']) . '"' . $data . '>' . $body . '</a>';
            } else {
                // #tab-x, not #x: #x would scroll the browser to the tab panel with that id.
                $href = isset($it['tab']) ? $sysUrl . '#tab-' . $it['tab'] : getUrl($it['key']);
                $out .= '<a class="' . $cls . '" href="' . $h($href) . '"' . ($on ? ' aria-current="page"' : '') . $data . '>' . $body . '</a>';
            }
        }
    }
    return $out;
}

/** Desktop column: <div class="col-lg-3">. */
function settingsAdminDesktopNav(string $active, bool $onSystemSettings = false): string
{
    $tablist = $onSystemSettings ? ' id="settingsTabs" role="tablist"' : '';
    return '<div class="col-lg-3 d-none d-lg-block sa-col">'
         . '<div class="card border-0 shadow-sm sa-sticky"><div class="card-body p-0">'
         . '<div class="list-group list-group-flush sa-list"' . $tablist . '>'
         . settingsAdminNavList($active, $onSystemSettings, 'desktop')
         . '</div></div></div></div>';
}

/** Phone/tablet: the "All settings" bar plus the left drawer. */
function settingsAdminMobileNav(string $active, bool $onSystemSettings = false): string
{
    $h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $it = settingsAdminNavFind($active) ?? ['title' => t('System Settings'), 'icon' => 'bi-gear', 'tone' => 'primary', 'group' => t('Settings')];
    return '<button type="button" class="sa-bar d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#saDrawer" aria-controls="saDrawer">'
         . '<span class="sa-bar-menu"><i class="bi bi-list"></i></span>'
         . '<span class="sa-bar-text"><small>' . $h(t('All settings')) . '</small>'
         . '<span class="sa-bar-cur"><i class="bi ' . $it['icon'] . ' text-' . $it['tone'] . ' me-1" id="saBarIcon"></i><span id="saBarTitle">' . $h($it['title']) . '</span></span></span>'
         . '<i class="bi bi-chevron-right sa-bar-go"></i>'
         . '</button>'
         . '<div class="offcanvas offcanvas-start sa-drawer d-lg-none" tabindex="-1" id="saDrawer" aria-labelledby="saDrawerLabel">'
         . '<div class="sa-drawer-head"><div><div class="sa-drawer-title" id="saDrawerLabel"><i class="bi bi-gear-fill me-2"></i>' . $h(t('All settings')) . '</div>'
         . '<small>' . $h(t('System Settings')) . '</small></div>'
         . '<button type="button" class="sa-drawer-close" data-bs-dismiss="offcanvas" aria-label="' . $h(t('Close')) . '"><i class="bi bi-x-lg"></i></button></div>'
         . '<div class="offcanvas-body p-0"><div class="list-group list-group-flush sa-list">'
         . settingsAdminNavList($active, $onSystemSettings, 'drawer')
         . '</div></div></div>';
}

function settingsAdminNavAssets(bool $onSystemSettings = false): string
{
    static $done = false;
    if ($done) return '';
    $done = true;
    $dark = (($_SESSION['theme'] ?? 'light') === 'dark');
    $css = <<<CSS
<style>
:root{--primary-soft:rgba(13,110,253,.1);--success-soft:rgba(25,135,84,.1);--info-soft:rgba(13,202,240,.1);--warning-soft:rgba(255,193,7,.1);--danger-soft:rgba(220,53,69,.1);--dark-soft:rgba(33,37,41,.1);--indigo-soft:rgba(102,16,242,.1);--indigo:#6610f2}
.bg-primary-soft{background-color:var(--primary-soft)}.bg-success-soft{background-color:var(--success-soft)}.bg-info-soft{background-color:var(--info-soft)}
.bg-warning-soft{background-color:var(--warning-soft)}.bg-danger-soft{background-color:var(--danger-soft)}.bg-dark-soft{background-color:var(--dark-soft)}
.bg-indigo-soft{background-color:var(--indigo-soft)}.text-indigo{color:var(--indigo)}
.sa-shell{padding-top:1.5rem;padding-bottom:1.5rem}
.sa-main{min-width:0}
.sa-main>.container-fluid,.sa-main>.container{max-width:none;padding-left:0!important;padding-right:0!important;margin-top:0!important;padding-top:0!important}
.sa-sticky{position:sticky;top:100px;z-index:100;max-height:calc(100vh - 120px);overflow-y:auto;scrollbar-width:thin}
.sa-list .sa-group{background:#f8f9fa;border:0;padding:.85rem 1.25rem .35rem;font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;font-weight:700;color:#6c757d;pointer-events:none}
.sa-list .sa-item{display:flex;align-items:center;gap:.85rem;padding:.75rem 1.25rem;border:0;border-left:4px solid transparent!important;border-radius:0!important;transition:background-color .2s ease,color .2s ease;color:inherit}
.sa-list .sa-item:hover{background:#f8f9fa;color:var(--bs-primary)}
.sa-list .sa-item.active{background:#fff;color:var(--bs-primary);border-left-color:var(--bs-primary)!important;box-shadow:0 4px 12px rgba(0,0,0,.05)}
.sa-icon{width:38px;height:38px;flex-shrink:0;display:flex;align-items:center;justify-content:center;border-radius:11px;font-size:1.15rem}
.sa-text{min-width:0;display:flex;flex-direction:column}
.sa-title{font-weight:700;font-size:.95rem;line-height:1.25}
.sa-text small{color:#6c757d;font-size:.78rem;line-height:1.3;overflow-wrap:anywhere}
.sa-list .sa-item.active small{color:#6c757d}
.sa-bar{width:100%;display:flex;align-items:center;gap:.75rem;background:#fff;border:1px solid #e3e7ed;border-radius:12px;padding:.6rem .8rem;margin-bottom:1rem;text-align:left;box-shadow:0 2px 6px rgba(15,25,45,.06);color:inherit}
.sa-bar:active{transform:scale(.99)}
.sa-bar-menu{width:38px;height:38px;flex-shrink:0;border-radius:10px;background:var(--primary-soft);color:var(--bs-primary);display:flex;align-items:center;justify-content:center;font-size:1.35rem}
.sa-bar-text{flex:1;min-width:0;display:flex;flex-direction:column}
.sa-bar-text small{font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;font-weight:700;color:var(--bs-primary)}
.sa-bar-cur{font-weight:700;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sa-bar-go{color:#6c757d;flex-shrink:0}
.sa-drawer{width:min(86vw,340px)!important;border:0!important;box-shadow:4px 0 18px rgba(0,0,0,.22)}
.sa-drawer-head{background:#0b4fc4;color:#fff;padding:18px 16px 14px;display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-shrink:0}
.sa-drawer-title{font-weight:700;font-size:1.05rem}
.sa-drawer-head small{opacity:.85;font-size:.78rem}
.sa-drawer-close{width:36px;height:36px;border-radius:50%;border:0;background:rgba(255,255,255,.18);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sa-drawer .offcanvas-body{padding-bottom:calc(16px + env(safe-area-inset-bottom,0px))!important}
.sa-drawer .sa-item{padding:.65rem 1.1rem}
@media (max-width:991.98px){.sa-shell{padding-top:1rem}}
/* Next to the sidebar an 8/4 page split is too narrow until xl, so stack it there. */
@media (min-width:992px) and (max-width:1199.98px){.sa-main .col-lg-8,.sa-main .col-lg-4{flex:0 0 auto;width:100%}}
@media (prefers-reduced-motion:reduce){.sa-list .sa-item{transition:none}}
</style>
CSS;
    if ($dark) {
        $css .= '<style>'
              . '.sa-list .sa-group{background:#1f2328;color:#9aa4ae}'
              . '.sa-list .sa-item{background:transparent;color:#e1e7ec}'
              . '.sa-list .sa-item:hover{background:#2c3137}'
              . '.sa-list .sa-item.active{background:#2c3137}'
              . '.sa-text small,.sa-list .sa-item.active small,.sa-bar-go{color:#9aa4ae}'
              . '.sa-bar{background:#24282d;border-color:#3a3f45;color:#e1e7ec}'
              . '.sa-drawer{background:#24282d;color:#e1e7ec}'
              . '</style>';
    }

    $sys = $onSystemSettings ? 'true' : 'false';
    $js = <<<JS
<script>
(function () {
    var ON_SYS = {$sys};
    function drawer() { return document.getElementById('saDrawer'); }
    function markActive(key) {
        document.querySelectorAll('.sa-drawer .sa-item').forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('data-sa-key') === key);
        });
        var src = document.querySelector('.sa-drawer .sa-item[data-sa-key="' + key + '"]');
        if (!src) return;
        var icon = src.querySelector('.sa-icon i'), tone = src.querySelector('.sa-icon');
        var t = document.getElementById('saBarTitle'), i = document.getElementById('saBarIcon');
        if (t) t.textContent = src.querySelector('.sa-title').textContent;
        if (i && icon && tone) {
            var toneCls = Array.prototype.find.call(tone.classList, function (c) { return c.indexOf('text-') === 0; }) || 'text-primary';
            i.className = icon.className + ' ' + toneCls + ' me-1';
        }
    }
    function closeDrawer() {
        var d = drawer();
        if (d && window.bootstrap) bootstrap.Offcanvas.getOrCreateInstance(d).hide();
    }
    function showTab(tab) {
        var trigger = document.getElementById(tab + '-tab');
        if (!trigger || !window.bootstrap) return false;
        bootstrap.Tab.getOrCreateInstance(trigger).show();
        return true;
    }
    document.addEventListener('DOMContentLoaded', function () {
        if (!ON_SYS) return;
        document.querySelectorAll('.sa-drawer [data-sa-tab]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                showTab(a.getAttribute('data-sa-tab'));
                closeDrawer();
                window.scrollTo({ top: 0 });
            });
        });
        document.querySelectorAll('#settingsTabs [data-bs-toggle="tab"]').forEach(function (tr) {
            tr.addEventListener('shown.bs.tab', function () {
                var key = tr.getAttribute('data-sa-key');
                markActive(key);
                // Full path, not a bare '#…': a bare fragment resolves against <base href>.
                try { history.replaceState(null, '', location.pathname + location.search + '#tab-' + key); } catch (e) {}
            });
        });
        var m = /^#tab-([a-z_]+)$/.exec(location.hash || '');
        if (m && document.getElementById(m[1] + '-tab')) showTab(m[1]);
    });
})();
</script>
JS;
    return $css . $js;
}

/** Opens the two-column layout. Call right before the page's main container. */
function settingsAdminShellOpen(string $active): void
{
    $GLOBALS['__sa_shell_open'] = function_exists('isAdmin') && isAdmin();
    if (!$GLOBALS['__sa_shell_open']) return;
    echo settingsAdminNavAssets(false);
    echo '<div class="container-fluid sa-shell"><div class="row g-4">';
    echo settingsAdminDesktopNav($active, false);
    echo '<div class="col-lg-9 sa-main">';
    echo settingsAdminMobileNav($active, false);
}

/** Closes what settingsAdminShellOpen() opened. Call right before the footer. */
function settingsAdminShellClose(): void
{
    if (empty($GLOBALS['__sa_shell_open'])) return;
    $GLOBALS['__sa_shell_open'] = false;
    echo '</div></div></div>';
}
