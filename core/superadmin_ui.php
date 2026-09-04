<?php
/**
 * core/superadmin_ui.php
 * -----------------------
 * Shared chrome for the platform panel — one header, reused by every page under
 * app/superadmin/ instead of each page repeating its own toolbar markup (which is
 * how tenants.php, features.php, profile.php, tenant_new.php and tenant_view.php
 * all looked before this file existed: five copies of the same plain bar, already
 * drifting from each other).
 *
 * Visually modelled on the tenant-side header.php — same two-bar structure, same
 * blue family, same avatar-dropdown-on-the-right shape — so the panel reads as
 * the same product a tenant sees, not a bolted-on afterthought. Kept as its own
 * file rather than reusing header.php directly: header.php is gated on a signed-in
 * TENANT session and renders that tenant's own permission-driven nav, neither of
 * which exists for a superadmin request (superadmin sessions and tenant sessions
 * are deliberately separate — core/superadmin_auth.php).
 *
 * Public API:
 *   renderSuperadminHeader(string $active, ?array $me = null): void
 */

require_once __DIR__ . '/superadmin_auth.php';

if (!function_exists('renderSuperadminHeader')) {
    /**
     * @param string $active  'dashboard' | 'tenants' | 'features' | 'profile'
     *                        (tenant_new.php / tenant_view.php pass 'tenants' —
     *                        they are reached FROM the tenant list and have no
     *                        nav slot of their own; each shows its own contextual
     *                        title/back-link below this header instead).
     * @param ?array $me      currentSuperadmin() row; fetched here if omitted.
     */
    function renderSuperadminHeader(string $active, ?array $me = null): void
    {
        $me   = $me ?? currentSuperadmin();
        $name = trim((string)($me['name'] ?? ''));
        $initial = strtoupper(substr($name !== '' ? $name : 'A', 0, 1));

        $navItem = function (string $key, string $url, string $icon, string $label) use ($active) {
            $cls = 'nav-link' . ($active === $key ? ' active' : '');
            echo '<li class="nav-item"><a class="' . $cls . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
               . '<i class="bi ' . $icon . ' me-1"></i>' . $label . '</a></li>';
        };
        ?>
        <style>
            .sa-header-wrapper { position: sticky; top: 0; z-index: 1030; box-shadow: 0 4px 15px rgba(0,0,0,.15); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
            .sa-top-bar { background: linear-gradient(135deg, #0a3d8f 0%, #0b5ed7 100%); padding: 7px 0; }
            .sa-bottom-bar.navbar { background: #0d6efd; padding: 0; box-shadow: none; }
            .sa-bottom-bar .container-fluid { padding-top: .15rem; padding-bottom: .15rem; }
            .sa-bottom-bar .nav-link { color: rgba(255,255,255,.85) !important; font-size: .88rem; font-weight: 500; padding: .5rem .9rem !important; border-radius: 6px; transition: background .15s ease, color .15s ease; }
            .sa-bottom-bar .nav-link:hover { color: #fff !important; background: rgba(255,255,255,.12); }
            .sa-bottom-bar .nav-link.active { color: #fff !important; background: rgba(255,255,255,.18); font-weight: 700; }
            .sa-brand-badge { width: 34px; height: 34px; border-radius: 9px; background: rgba(255,255,255,.16); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
            .sa-avatar { width: 30px; height: 30px; border-radius: 50%; background: rgba(255,255,255,.22); display: inline-flex; align-items: center; justify-content: center; font-weight: 700; color: #fff; font-size: .8rem; flex-shrink: 0; }
            .sa-user-toggle { cursor: pointer; border-radius: 8px; padding: 3px 8px 3px 3px; transition: background .15s ease; }
            .sa-user-toggle:hover { background: rgba(255,255,255,.12); }
            .sa-user-toggle::after { display: none; }
            .sa-bottom-bar .dropdown-menu { border-top: 3px solid #0d6efd; }
            @media (max-width: 991px) {
                .sa-bottom-bar .navbar-nav { padding: .4rem 0; }
                .sa-user-toggle .d-xl-block { display: none !important; }
            }
        </style>

        <div class="sa-header-wrapper">
            <div class="sa-top-bar">
                <div class="container-fluid px-3 px-md-4 d-flex align-items-center">
                    <a href="<?= saUrl('dashboard') ?>" class="d-flex align-items-center text-white text-decoration-none">
                        <span class="sa-brand-badge me-2"><i class="bi bi-shield-lock-fill text-white"></i></span>
                        <span class="fw-bold d-flex align-items-center" style="font-size:1.02rem;letter-spacing:-.2px;">
                            <span class="d-none d-sm-inline">Platform Administration</span>
                            <span class="d-inline d-sm-none">Platform Admin</span>
                            <span class="badge bg-light text-primary ms-2" style="font-size:.55rem;vertical-align:middle;">SUPERADMIN</span>
                        </span>
                    </a>
                    <div class="ms-auto d-flex align-items-center gap-2 text-white-50">
                        <i class="bi bi-calendar3 opacity-75"></i>
                        <span class="d-none d-md-inline small"><?= date('l, d M Y') ?></span>
                        <span class="d-inline d-md-none small"><?= date('d M Y') ?></span>
                    </div>
                </div>
            </div>
            <nav class="sa-bottom-bar navbar navbar-expand-lg navbar-dark">
                <div class="container-fluid px-3 px-md-4">
                    <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#saNav" aria-controls="saNav" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="saNav">
                        <ul class="navbar-nav me-auto align-items-lg-center">
                            <?php
                            $navItem('dashboard', saUrl('dashboard'), 'bi-speedometer2', 'Dashboard');
                            $navItem('tenants',   saUrl('tenants'),   'bi-building',     'Tenants');
                            $navItem('features',  saUrl('features'), 'bi-grid',         'Modules');
                            ?>
                            <li class="nav-item ms-lg-2 my-1">
                                <a href="<?= saUrl('tenants/new') ?>" class="btn btn-sm btn-light text-primary fw-bold px-3">
                                    <i class="bi bi-plus-circle me-1"></i> New Company
                                </a>
                            </li>
                        </ul>
                        <ul class="navbar-nav">
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle sa-user-toggle d-flex align-items-center" href="#" id="saUserDrop" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="sa-avatar me-2"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="d-none d-xl-block text-start">
                                        <span class="d-block" style="font-size:.85rem;line-height:1.1;"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="text-white-50" style="font-size:10px;text-transform:uppercase;letter-spacing:.03em;">Operator</span>
                                    </span>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="saUserDrop">
                                    <li class="px-3 py-2 border-bottom">
                                        <div class="fw-bold" style="font-size:.85rem;"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="text-muted text-truncate" style="font-size:.72rem;max-width:220px;"><?= htmlspecialchars((string)($me['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    </li>
                                    <li><a class="dropdown-item py-2<?= $active === 'profile' ? ' active' : '' ?>" href="<?= saUrl('profile') ?>"><i class="bi bi-person-gear me-2"></i> My Account</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item py-2 text-danger fw-bold" href="<?= saUrl('logout') ?>"><i class="bi bi-box-arrow-right me-2"></i> Sign out</a></li>
                                </ul>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>
        </div>
        <?php
    }
}
