<?php
// File: pos_dashboard.php — POS Workspace: Sales History (top) + Dashboard (below)
// scope-audit: skip — reads via api/pos/get_dashboard.php + get_sales.php, both project-scoped
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/pos_nav.php';
autoEnforcePermission('pos');

$page_title = 'POS Workspace';
require_once 'header.php';

// Phase 30 (pos_upgrade_plan.md §9) — this page is the POS hub: header.php's
// old three-item dropdown collapsed to one link into here. posNavGroups()
// is the single gated source of truth for which destination cards render.
$pos_nav_groups = posNavGroups();

$can_create = canCreate('pos');
$can_delete = canDelete('pos');
$can_edit   = canEdit('pos');
// Phase 29 (pos_upgrade_plan.md §9) — Sales Targets is a pos_advanced-only
// tile; genuinely absent from the rendered HTML for a base-'pos' tenant, not
// merely hidden by CSS.
$can_view_targets = canView('pos_advanced');
$can_edit_targets = canEdit('pos_advanced');

logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Viewed POS Workspace');

$currency     = getSetting('currency', 'TZS');
$company_name = getSetting('company_name', 'BMS');
$company_logo = getSetting('company_logo', '');
?>

<div class="container-fluid mt-4" id="posDashboardContainer">

    <!-- ── Page header ── -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0 text-primary"><i class="bi bi-shop me-2"></i><?= t('POS Workspace') ?></h4>
        <div id="posWorkspaceActions" class="d-flex align-items-center gap-2">
            <button id="btnToggleDash" class="btn btn-primary btn-sm pos-workspace-action">
                <i class="bi bi-speedometer2 me-1"></i> <?= t('Sales Dashboard') ?>
            </button>
            <a id="posWorkspaceShiftHistory" href="<?= getUrl('pos/shifts') ?>" class="btn btn-primary btn-sm pos-workspace-action">
                <i class="bi bi-clock-history me-1"></i> <?= t('Shift History') ?>
            </a>
            <a id="posWorkspaceOpenPos" href="<?= getUrl('pos') ?>" class="btn btn-primary btn-sm pos-workspace-action">
                <i class="bi bi-bag-plus me-1"></i> <?= t('Open POS') ?>
            </a>
        </div>
    </div>

    <!-- ═══════════════ POS HUB — destination cards (Phase 30) ═══════════════ -->
    <?php
    $pos_nav_icons = [
        'terminal'      => 'bi-bag-plus',
        'shift_history' => 'bi-clock-history',
        'catalog_setup' => 'bi-tags',
        'restaurant'    => 'bi-egg-fried',
        'settings'      => 'bi-gear',
    ];
    ?>
    <div class="row g-3 mb-4">
        <?php foreach ($pos_nav_groups as $navCard): ?>
        <div class="col-6 col-md-4 col-lg">
            <?php
            // 2026-09-12 (product owner request): clicking "Restaurant" now
            // opens a popup listing its 5 destinations instead of navigating
            // straight to the full restaurant/index.php sub-hub page — the
            // page itself is still exactly where each option lands once
            // picked from the popup.
            $isRestaurantCard = $navCard['key'] === 'restaurant';
            ?>
            <a href="<?= $isRestaurantCard ? '#' : getUrl($navCard['url']) ?>"
               class="text-decoration-none"
               <?= $isRestaurantCard ? 'data-bs-toggle="modal" data-bs-target="#restaurantSubHubModal"' : '' ?>>
                <div class="card border-0 shadow-sm h-100 p-3 pos-hub-card">
                    <div class="d-flex align-items-center gap-3">
                        <div class="fs-3 text-white">
                            <i class="bi <?= safe_output($pos_nav_icons[$navCard['key']] ?? 'bi-grid') ?>"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-white"><?= safe_output($navCard['label']) ?></div>
                            <div class="small text-white-50"><?= safe_output($navCard['description']) ?></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (canView('restaurant_pos')): ?>
    <!-- Restaurant popup — same 5 destinations restaurant/index.php shows,
         via the shared restaurantSubHubCards() so the two never drift apart. -->
    <div class="modal fade" id="restaurantSubHubModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold"><i class="bi bi-shop-window text-primary me-2"></i><?= t('Restaurant') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-2">
                    <div class="row g-3">
                        <?php foreach (restaurantSubHubCards() as $subCard): ?>
                        <div class="col-6 col-md-4">
                            <a href="<?= getUrl($subCard['url']) ?>" class="text-decoration-none">
                                <div class="card border-0 shadow-sm h-100 p-3 pos-hub-card">
                                    <div class="text-center">
                                        <div class="fs-2 text-white mb-2"><i class="bi <?= safe_output($subCard['icon']) ?>"></i></div>
                                        <div class="fw-bold text-white"><?= safe_output($subCard['label']) ?></div>
                                        <div class="small text-white-50"><?= safe_output($subCard['description']) ?></div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <style>
        /* Every hub card shares the same blue treatment and the same size,
           regardless of how many cards a tenant's entitlements produce. */
        .pos-hub-card {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .pos-hub-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.1) !important; }

        /* Mobile — smaller text/icons/padding so 2-per-row (col-6) stays
           comfortable instead of cramped; desktop is untouched by this
           block (only the row/col changes above apply there). */
        @media (max-width: 767.98px) {
            .pos-hub-card { padding: 0.65rem !important; }
            .pos-hub-card .fs-3 { font-size: 1.3rem !important; }
            .pos-hub-card .fw-bold { font-size: 0.82rem; }
            .pos-hub-card .small { font-size: 0.68rem; line-height: 1.25; }
        }

        /* Page-header action row (Sales Dashboard / Shift History / Open
           POS) — all three now the same solid-blue style and, via equal
           flex, the exact same size regardless of label length. Desktop:
           already one row (the header's own flex layout). Mobile: forced
           into one row too (never wrapping/stacking) with each button
           shrinking to an equal share of the width instead. */
        .pos-workspace-action {
            flex: 1 1 0;
            min-width: 0;
            white-space: nowrap;
        }
        @media (max-width: 767.98px) {
            #posWorkspaceActions {
                width: 100%;
                flex-wrap: nowrap !important;
            }
            .pos-workspace-action {
                font-size: 0.7rem;
                padding: 0.4rem 0.3rem;
            }
        }
    </style>

    <!-- ═══════════════════════════════════════════════════════════════════
         Mobile view — desktop layout above is completely untouched by this
         block. Fixes the page shifting/scrolling sideways on a phone: the
         5-button Period filter (Daily/Weekly/.../Yearly) is a Bootstrap
         .btn-group, which never wraps by default, so it forced the whole
         page wider than the screen; the Low Stock / Recent Sales mini-tables
         had no overflow container of their own, so a long row could do the
         same. Both are now contained (their own small internal scroll if
         truly needed, never the page), plus lighter fonts/columns so that
         internal scroll is rarely needed at all.
         ═══════════════════════════════════════════════════════════════════ -->
    <style>
    @media (max-width: 767.98px) {
        /* Defense in depth: even after fixing the specific overflow sources
           below, clip anything that still tries to push wider than the
           screen rather than letting the whole page shift sideways. */
        #posDashboardContainer {
            overflow-x: hidden;
        }
        #periodGroup {
            display: flex !important;
            flex-wrap: wrap;
            gap: 4px;
        }
        #periodGroup .period-btn {
            border-radius: 6px !important;
            font-size: 0.72rem;
            padding: 4px 8px;
        }
        #lowStockTable, #recentSalesTable, #topProducts table, #topCashiers table {
            font-size: 0.72rem;
        }
        /* The S/NO column is the least useful cell on a narrow screen —
           dropping it buys back just enough width that these mini-tables
           fit without ever needing their own internal scroll either. */
        #lowStockTable th:nth-child(1), #lowStockTable td:nth-child(1),
        #recentSalesTable th:nth-child(1), #recentSalesTable td:nth-child(1),
        #topProducts table th:nth-child(1), #topProducts table td:nth-child(1),
        #topCashiers table td:first-child {
            display: none;
        }
        #recentSalesTable .badge, #lowStockTable .badge {
            font-size: 0.62rem;
            padding: 2px 5px;
        }
    }
    </style>

    <!-- ═══════════════════ SALES HISTORY (top) ═══════════════════ -->
    <div id="paneHistory">

        <div class="d-flex align-items-center mb-3">
            <h5 class="mb-0 text-primary"><i class="bi bi-receipt me-2"></i><?= t('Sales History') ?></h5>
        </div>

        <!-- Period filter -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="small text-muted fw-bold text-nowrap"><?= t('Period:') ?></span>
                    <div class="btn-group btn-group-sm" role="group" id="periodGroup">
                        <button type="button" class="btn btn-outline-primary period-btn" data-period="daily"><?= t('Daily') ?></button>
                        <button type="button" class="btn btn-outline-primary period-btn" data-period="weekly"><?= t('Weekly') ?></button>
                        <button type="button" class="btn btn-outline-primary period-btn" data-period="monthly"><?= t('Monthly') ?></button>
                        <button type="button" class="btn btn-outline-primary period-btn" data-period="quarterly"><?= t('Quarterly') ?></button>
                        <button type="button" class="btn btn-primary period-btn" data-period="yearly"><?= t('Yearly') ?></button>
                    </div>
                </div>

                <!-- Daily: single date -->
                <div id="fp-daily" class="filter-panel d-none row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small mb-1"><?= t('Date') ?></label>
                        <input type="date" id="fDay" class="form-control form-control-sm">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary btn-sm apply-btn"><i class="bi bi-funnel me-1"></i> <?= t('Apply') ?></button>
                    </div>
                </div>

                <!-- Weekly: any date in week → Mon–Sun computed -->
                <div id="fp-weekly" class="filter-panel d-none row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small mb-1"><?= t('Any day in week') ?></label>
                        <input type="date" id="fWeekDay" class="form-control form-control-sm">
                    </div>
                    <div class="col-auto d-flex align-items-end">
                        <span id="weekRangeLabel" class="small text-muted mb-2 ms-1"></span>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary btn-sm apply-btn"><i class="bi bi-funnel me-1"></i> <?= t('Apply') ?></button>
                    </div>
                </div>

                <!-- Monthly: month + year -->
                <div id="fp-monthly" class="filter-panel d-none row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small mb-1"><?= t('Month') ?></label>
                        <select id="fMonth" class="form-select form-select-sm">
                            <option value="1"><?= t('January') ?></option><option value="2"><?= t('February') ?></option>
                            <option value="3"><?= t('March') ?></option><option value="4"><?= t('April') ?></option>
                            <option value="5"><?= t('May') ?></option><option value="6"><?= t('June') ?></option>
                            <option value="7"><?= t('July') ?></option><option value="8"><?= t('August') ?></option>
                            <option value="9"><?= t('September') ?></option><option value="10"><?= t('October') ?></option>
                            <option value="11"><?= t('November') ?></option><option value="12"><?= t('December') ?></option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1"><?= t('Year') ?></label>
                        <select id="fMonthYear" class="form-select form-select-sm"></select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary btn-sm apply-btn"><i class="bi bi-funnel me-1"></i> <?= t('Apply') ?></button>
                    </div>
                </div>

                <!-- Quarterly: Q1–Q4 + year -->
                <div id="fp-quarterly" class="filter-panel d-none row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small mb-1"><?= t('Quarter') ?></label>
                        <select id="fQuarter" class="form-select form-select-sm">
                            <option value="1"><?= t('Q1 (Jan–Mar)') ?></option>
                            <option value="2"><?= t('Q2 (Apr–Jun)') ?></option>
                            <option value="3"><?= t('Q3 (Jul–Sep)') ?></option>
                            <option value="4"><?= t('Q4 (Oct–Dec)') ?></option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1"><?= t('Year') ?></label>
                        <select id="fQuarterYear" class="form-select form-select-sm"></select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary btn-sm apply-btn"><i class="bi bi-funnel me-1"></i> <?= t('Apply') ?></button>
                    </div>
                </div>

                <!-- Yearly: year selector only -->
                <div id="fp-yearly" class="filter-panel row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small mb-1"><?= t('Year') ?></label>
                        <select id="fYear" class="form-select form-select-sm"></select>
                    </div>
                    <div class="col-auto">
                        <button id="btnFilter" class="btn btn-primary btn-sm apply-btn"><i class="bi bi-funnel me-1"></i> <?= t('Apply') ?></button>
                    </div>
                </div>

            </div>
        </div>

        <!-- Stat cards -->
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                    <div class="fs-4 fw-bold text-primary" id="stat-net">—</div>
                    <div class="small text-muted"><?= t('Net Sales (excl. VAT)') ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                    <div class="fs-4 fw-bold text-primary" id="stat-count">—</div>
                    <div class="small text-muted"><?= t('Completed Sales') ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                    <div class="fs-4 fw-bold text-primary" id="stat-returns">—</div>
                    <div class="small text-muted"><?= t('Returns') ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                    <div class="fs-4 fw-bold text-primary" id="stat-voided">—</div>
                    <div class="small text-muted"><?= t('Voided') ?></div>
                </div>
            </div>
        </div>

        <!-- Toolbar: Copy | CSV | Print | Show: -->
        <div class="row mb-3 d-print-none">
            <div class="col-12">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="d-flex flex-wrap shadow-sm bg-white" style="border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
                        <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="copyTable()" style="background:#fff;height:38px;">
                            <i class="bi bi-clipboard text-info me-1"></i> <?= t('Copy') ?>
                        </button>
                        <div class="bg-light d-none d-sm-block" style="width:1px;height:38px;"></div>
                        <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="exportCSV()" style="background:#fff;height:38px;">
                            <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> <?= t('CSV') ?>
                        </button>
                        <div class="bg-light d-none d-sm-block" style="width:1px;height:38px;"></div>
                        <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="printTable()" style="background:#fff;height:38px;">
                            <i class="bi bi-printer text-primary me-1"></i> <?= t('Print') ?>
                        </button>
                    </div>
                    <div class="d-flex align-items-center bg-white shadow-sm px-2 py-1 d-print-none" style="border:1px solid #dee2e6;border-radius:8px;height:38px;">
                        <span class="small text-muted me-2 text-nowrap"><?= t('Show:') ?></span>
                        <select class="form-select form-select-sm border-0 fw-bold p-0" id="pageLenSelect" style="width:55px;background:transparent;">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Desktop table -->
        <div id="tableView">
            <table id="posSalesTable" class="table table-hover align-middle w-100">
                <thead>
                    <tr class="text-primary border-bottom">
                        <th class="text-primary"><?= t('S/NO') ?></th>
                        <th class="text-primary"><?= t('Receipt') ?></th>
                        <th class="text-primary"><?= t('Date') ?></th>
                        <th class="text-primary"><?= t('Customer') ?></th>
                        <th class="text-primary text-end"><?= t('Total') ?></th>
                        <th class="text-primary"><?= t('Payment') ?></th>
                        <th class="text-primary"><?= t('Status') ?></th>
                        <th class="text-primary text-end"><?= t('Actions') ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <!-- Mobile card view -->
        <div id="cardView" class="row g-2 d-none"></div>

    </div><!-- /paneHistory -->

    <!-- ═══════════════════ DASHBOARD (toggle) ═══════════════════ -->
    <div id="paneDashboard" class="mt-2 d-none">

        <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
            <h5 class="mb-0 text-primary"><i class="bi bi-speedometer2 me-2"></i><?= t('Sales Dashboard') ?></h5>
            <button class="btn btn-sm btn-outline-primary ms-auto" id="btnRefreshDash" onclick="loadDashboard()">
                <i class="bi bi-arrow-clockwise me-1"></i> <?= t('Refresh') ?>
            </button>
        </div>

        <!-- KPI cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                    <div class="fs-5 fw-bold text-primary" id="stat-today-net">—</div>
                    <div class="small text-muted"><?= t('Today — Net Sales') ?></div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                    <div class="fs-5 fw-bold text-primary" id="stat-today-count">—</div>
                    <div class="small text-muted"><?= t('Today — Sales') ?></div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                    <div class="fs-5 fw-bold text-primary" id="stat-today-aov">—</div>
                    <div class="small text-muted"><?= t('Avg Sale (AOV)') ?></div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                    <div class="fs-5 fw-bold text-primary" id="stat-today-items">—</div>
                    <div class="small text-muted"><?= t('Items Sold Today') ?></div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                    <div class="fs-5 fw-bold text-primary" id="stat-month-net">—</div>
                    <div class="small text-muted"><?= t('This Month — Net') ?></div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                    <div class="fs-5 fw-bold text-primary" id="stat-low-stock">—</div>
                    <div class="small text-muted"><?= t('Low-Stock Items') ?></div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-lg-8">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 fw-bold text-primary">
                        <i class="bi bi-graph-up me-1"></i> <?= t('Sales Trend (last 14 days)') ?>
                    </div>
                    <div class="card-body">
                        <canvas id="trendChart" height="110"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 fw-bold text-primary">
                        <i class="bi bi-trophy me-1"></i> <?= t('Top Products (this month)') ?>
                    </div>
                    <div class="card-body p-2">
                        <div id="topProducts" class="small text-center text-muted py-3">
                            <span class="spinner-border spinner-border-sm me-1"></span> <?= t('Loading…') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Phase 29 (pos_upgrade_plan.md §9) — Damage/Shrinkage, Top Cashiers (base 'pos'), Sales Targets (pos_advanced) -->
        <div class="row g-3 mt-1">
            <div class="col-12 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 fw-bold text-primary">
                        <i class="bi bi-box-seam me-1"></i> <?= t('Damage / Shrinkage (this month)') ?>
                    </div>
                    <div class="card-body p-2">
                        <div id="damageShrinkage" class="small text-center text-muted py-3">
                            <span class="spinner-border spinner-border-sm me-1"></span> <?= t('Loading…') ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 fw-bold text-primary">
                        <i class="bi bi-person-badge me-1"></i> <?= t('Top Performing Cashiers') ?>
                    </div>
                    <div class="card-body p-2">
                        <div id="topCashiers" class="small text-center text-muted py-3">
                            <span class="spinner-border spinner-border-sm me-1"></span> <?= t('Loading…') ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($can_view_targets): ?>
            <div class="col-12 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 fw-bold text-primary d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-bullseye me-1"></i> <?= t('Sales Target (this month)') ?></span>
                        <?php if ($can_edit_targets): ?>
                        <button class="btn btn-sm btn-outline-primary" id="btnSetTarget"><i class="bi bi-pencil-square"></i></button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-2">
                        <div id="salesTarget" class="small text-center text-muted py-3">
                            <span class="spinner-border spinner-border-sm me-1"></span> <?= t('Loading…') ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-12 col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 fw-bold text-primary">
                        <i class="bi bi-exclamation-triangle me-1"></i> <?= t('Low Stock') ?>
                    </div>
                    <div class="card-body p-0">
                        <div id="lowStockSpinner" class="small text-center text-muted py-3">
                            <span class="spinner-border spinner-border-sm me-1"></span> <?= t('Loading…') ?>
                        </div>
                        <div id="lowStockWrap" class="d-none table-responsive">
                            <table id="lowStockTable" class="table table-sm table-hover align-middle w-100 mb-0">
                                <thead>
                                    <tr class="text-primary">
                                        <th class="text-primary text-center" style="width:40px"><?= t('S/NO') ?></th>
                                        <th class="text-primary"><?= t('Product') ?></th>
                                        <th class="text-primary text-end"><?= t('In Stock') ?></th>
                                        <th class="text-primary text-end"><?= t('Min') ?></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 fw-bold text-primary">
                        <i class="bi bi-clock-history me-1"></i> <?= t('Recent Sales') ?>
                    </div>
                    <div class="card-body p-0">
                        <div id="recentSalesSpinner" class="small text-center text-muted py-3">
                            <span class="spinner-border spinner-border-sm me-1"></span> <?= t('Loading…') ?>
                        </div>
                        <div id="recentSalesWrap" class="d-none table-responsive">
                            <table id="recentSalesTable" class="table table-sm table-hover align-middle w-100 mb-0">
                                <thead>
                                    <tr class="text-primary">
                                        <th class="text-primary text-center" style="width:40px"><?= t('S/NO') ?></th>
                                        <th class="text-primary"><?= t('Receipt') ?></th>
                                        <th class="text-primary"><?= t('Customer') ?></th>
                                        <th class="text-primary text-end"><?= t('Total') ?></th>
                                        <th class="text-primary"><?= t('Status') ?></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /paneDashboard -->
</div>

<!-- ═══ Return modal ═══ -->
<?php if ($can_create): ?>
<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-return-left me-1"></i> <?= t('Process Return / Refund') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="returnForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="original_sale_id" id="ret_sale_id">
                    <div id="ret-message" class="mb-2"></div>
                    <div class="mb-2 small text-muted"><?= t('Receipt') ?> <span class="fw-bold" id="ret_receipt">—</span> · <span id="ret_customer">—</span></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr class="text-primary">
                                    <th class="text-primary"><?= t('Product') ?></th>
                                    <th class="text-primary text-end"><?= t('Sold') ?></th>
                                    <th class="text-primary text-end"><?= t('Returnable') ?></th>
                                    <th class="text-primary" style="width:120px"><?= t('Return Qty') ?></th>
                                </tr>
                            </thead>
                            <tbody id="ret_lines"></tbody>
                        </table>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Refund Method') ?> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="refund_method" id="ret_method" required>
                                <option value="cash"><?= t('Cash') ?></option>
                                <option value="card"><?= t('Card') ?></option>
                                <option value="mobile_money"><?= t('Mobile Money') ?></option>
                                <option value="bank_transfer"><?= t('Bank Transfer') ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Reason') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="reason" id="ret_reason" placeholder="<?= t('e.g. defective, wrong item') ?>" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> <?= t('Process Return') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══ Receive Payment modal ═══ -->
<?php if ($can_edit): ?>
<div class="modal fade" id="receiveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-1"></i> <?= t('Receive Payment') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="receiveForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="sale_id" id="rcv_sale_id">
                    <div id="rcv-message" class="mb-2"></div>
                    <div class="mb-2 small text-muted"><?= t('Receipt') ?> <span class="fw-bold" id="rcv_receipt">—</span> · <span id="rcv_customer">—</span></div>
                    <div class="d-flex justify-content-between mb-3 p-2 rounded" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                        <span class="text-muted"><?= t('Balance due') ?></span>
                        <span class="fw-bold text-primary" id="rcv_balance">0.00</span>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Amount') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" id="rcv_amount" min="0" step="any" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Method') ?> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="payment_method" id="rcv_method" required>
                                <option value="cash"><?= t('Cash') ?></option>
                                <option value="card"><?= t('Card') ?></option>
                                <option value="mobile_money"><?= t('Mobile Money') ?></option>
                                <option value="bank_transfer"><?= t('Bank Transfer') ?></option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Reference (optional)') ?></label>
                            <input type="text" class="form-control" name="reference" id="rcv_reference" placeholder="<?= t('e.g. M-Pesa code') ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> <?= t('Record Payment') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══ Set Sales Target modal (Phase 29, pos_advanced only) ═══ -->
<?php if ($can_view_targets && $can_edit_targets): ?>
<div class="modal fade" id="targetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-bullseye me-1"></i> <?= t('Set Sales Target') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="targetForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="warehouse_id" id="tgt_warehouse_id" value="0">
                    <div id="tgt-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Month') ?> <span class="text-danger">*</span></label>
                        <input type="month" class="form-control" name="period_month" id="tgt_month" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Target Amount') ?> <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="target_amount" id="tgt_amount" min="0" step="any" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> <?= t('Save Target') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const DASH_URL    = '<?= buildUrl('api/pos/get_dashboard.php') ?>';
const GET_SALES   = '<?= buildUrl('api/pos/get_sales.php') ?>';
const GET_ITEMS   = '<?= buildUrl('api/pos/get_sale_items.php') ?>';
const VOID_URL    = '<?= buildUrl('api/pos/void_sale.php') ?>';
const RETURN_URL  = '<?= buildUrl('api/pos/create_return.php') ?>';
const RECEIVE_URL = '<?= buildUrl('api/pos/receive_payment.php') ?>';
const RECEIPT_URL = '<?= buildUrl('api/pos/print_receipt.php') ?>';
const TARGET_URL  = '<?= buildUrl('api/pos/save_sales_target.php') ?>';
const CURRENCY    = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
const CAN_CREATE  = <?= json_encode($can_create) ?>;
const CAN_DELETE  = <?= json_encode($can_delete) ?>;
const CAN_EDIT    = <?= json_encode($can_edit) ?>;
const CAN_EDIT_TARGETS = <?= json_encode($can_view_targets && $can_edit_targets) ?>;
<?php
$_print_username = htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$_print_role     = htmlspecialchars($_SESSION['user_role'] ?? 'User');
$_print_year     = date('Y');
?>
const PRINT_USER  = '<?= addslashes($_print_username) ?>';
const PRINT_ROLE  = '<?= addslashes($_print_role) ?>';
const PRINT_YEAR  = '<?= $_print_year ?>';
const CO_NAME     = '<?= addslashes(htmlspecialchars($company_name)) ?>';
const CO_LOGO     = '<?= addslashes(getUrl($company_logo)) ?>';

// ══════════════════════ TRANSLATED STRINGS (t()) ══════════════════════
const T = {
    error: <?= json_encode(t('Error')) ?>,
    loadFailed: <?= json_encode(t('Load failed.')) ?>,
    serverErrorLoadingSales: <?= json_encode(t('Server error loading sales.')) ?>,
    dashboardFailed: <?= json_encode(t('Dashboard failed to load.')) ?>,
    serverErrorRetry: <?= json_encode(t('Server error — click Refresh to retry.')) ?>,
    refresh: <?= json_encode(t('Refresh')) ?>,
    loading: <?= json_encode(t('Loading…')) ?>,
    noSalesThisMonth: <?= json_encode(t('No sales this month')) ?>,
    netSalesLabel: <?= json_encode(t('Net Sales')) ?>,
    salesHistoryPrintTitle: <?= json_encode(t('POS Sales History')) ?>,
    viewReceipt: <?= json_encode(t('View Receipt')) ?>,
    receivePayment: <?= json_encode(t('Receive Payment')) ?>,
    returnRefund: <?= json_encode(t('Return / Refund')) ?>,
    voidSaleLabel: <?= json_encode(t('Void Sale')) ?>,
    noRecordsFound: <?= json_encode(t('No records found')) ?>,
    allStockAboveMinimum: <?= json_encode(t('All stock above minimum.')) ?>,
    noRecentSales: <?= json_encode(t('No recent sales.')) ?>,
    noSalesFoundPeriod: <?= json_encode(t('No sales found for the selected period.')) ?>,
    noMatchingRecords: <?= json_encode(t('No matching records.')) ?>,
    showingXofY: <?= json_encode(t('Showing _START_–_END_ of _TOTAL_')) ?>,
    due: <?= json_encode(t('due')) ?>,
    paid: <?= json_encode(t('paid')) ?>,
    salesHistoryReportTitle: <?= json_encode(t('POS Sales History Report')) ?>,
    periodLabel: <?= json_encode(t('Period:')) ?>,
    printedByLabel: <?= json_encode(t('Printed by:')) ?>,
    printedByFooter: <?= json_encode(t('This document was')) ?>,
    printedWord: <?= json_encode(t('Printed')) ?>,
    poweredByFooter: <?= json_encode(t('Powered by BJP Technologies © %d, All Rights Reserved.')) ?>,
    voidSaleTitle: <?= json_encode(t('Void sale %s?')) ?>,
    voidReasonPlaceholder: <?= json_encode(t('Reason for voiding (required)')) ?>,
    voidWarningText: <?= json_encode(t('Stock and cash will be reversed. This cannot be undone.')) ?>,
    voidSaleButton: <?= json_encode(t('Void Sale')) ?>,
    reasonRequired: <?= json_encode(t('A reason is required')) ?>,
    voiding: <?= json_encode(t('Voiding…')) ?>,
    voided: <?= json_encode(t('Voided')) ?>,
    failed: <?= json_encode(t('Failed.')) ?>,
    serverError: <?= json_encode(t('Server error.')) ?>,
    noReturnableLines: <?= json_encode(t('No returnable lines.')) ?>,
    nothingSelected: <?= json_encode(t('Nothing selected')) ?>,
    enterReturnQty: <?= json_encode(t('Enter a return quantity for at least one line.')) ?>,
    processing: <?= json_encode(t('Processing...')) ?>,
    returnProcessed: <?= json_encode(t('Return processed')) ?>,
    saving: <?= json_encode(t('Saving...')) ?>,
    paymentReceived: <?= json_encode(t('Payment received')) ?>,
    selectPlaceholder: <?= json_encode(t('Select...')) ?>,
    noDamageShrinkage: <?= json_encode(t('No damage, expiry, or theft recorded this month.')) ?>,
    damagedLabel: <?= json_encode(t('Damaged')) ?>,
    expiredLabel: <?= json_encode(t('Expired')) ?>,
    theftLabel: <?= json_encode(t('Theft')) ?>,
    noSalesThisMonthCashiers: <?= json_encode(t('No completed sales this month.')) ?>,
    salesLabel: <?= json_encode(t('sales')) ?>,
    noTargetSet: <?= json_encode(t('No target set for this month.')) ?>,
    ofTargetLabel: <?= json_encode(t('of target')) ?>,
    targetSaved: <?= json_encode(t('Sales target saved')) ?>
};

const money = n => (parseFloat(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmt   = d => d ? new Date(d.replace(' ','T')).toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }) : '—';

function safeOutput(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}

function statusBadge(s) {
    const map = {
        completed: ['#0d6efd','#fff'], paid: ['#052c65','#fff'],
        partial: ['#cfe2ff','#084298'], pending: ['#e9ecef','#495057'],
        partially_refunded: ['#bfdbfe','#1e3a8a'], refunded: ['#6c757d','#fff'],
        voided: ['#dc3545','#fff'], cancelled: ['#6c757d','#fff']
    };
    const [bg, fg] = map[s] || ['#e9ecef','#495057'];
    return `<span class="badge" style="background:${bg};color:${fg};padding:4px 9px;border-radius:20px;font-size:.72rem;">${safeOutput(s)}</span>`;
}

// ══════════════════════ PERIOD FILTER ══════════════════════
function padZ(n) { return String(n).padStart(2, '0'); }
function fmtDate(d) { return d.getFullYear() + '-' + padZ(d.getMonth()+1) + '-' + padZ(d.getDate()); }

function getActivePeriod() {
    return $('.period-btn.btn-primary').data('period') || 'yearly';
}

function getDateRange() {
    const period = getActivePeriod();
    const now = new Date();
    const y = now.getFullYear(), m = now.getMonth();
    let start, end;

    if (period === 'daily') {
        const day = $('#fDay').val() || fmtDate(now);
        start = end = day;
    } else if (period === 'weekly') {
        const refDay = $('#fWeekDay').val() ? new Date($('#fWeekDay').val() + 'T00:00:00') : new Date();
        const dow = refDay.getDay();
        const mon = new Date(refDay); mon.setDate(refDay.getDate() - (dow === 0 ? 6 : dow - 1));
        const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
        start = fmtDate(mon); end = fmtDate(sun);
    } else if (period === 'monthly') {
        const mo = parseInt($('#fMonth').val()) || (m + 1);
        const my = parseInt($('#fMonthYear').val()) || y;
        start = my + '-' + padZ(mo) + '-01';
        end = fmtDate(new Date(my, mo, 0));
    } else if (period === 'quarterly') {
        const q = parseInt($('#fQuarter').val()) || Math.ceil((m + 1) / 3);
        const qy = parseInt($('#fQuarterYear').val()) || y;
        start = fmtDate(new Date(qy, (q - 1) * 3, 1));
        end = fmtDate(new Date(qy, q * 3, 0));
    } else {
        const fy = parseInt($('#fYear').val()) || y;
        start = fy + '-01-01';
        end = fy + '-12-31';
    }
    return { start, end };
}

function showFilterPanel(period) {
    $('.filter-panel').addClass('d-none');
    $('#fp-' + period).removeClass('d-none');
    if (period === 'weekly') updateWeekLabel();
}

function updateWeekLabel() {
    const refDay = $('#fWeekDay').val() ? new Date($('#fWeekDay').val() + 'T00:00:00') : new Date();
    const dow = refDay.getDay();
    const mon = new Date(refDay); mon.setDate(refDay.getDate() - (dow === 0 ? 6 : dow - 1));
    const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
    const opts = { day:'numeric', month:'short' };
    const label = mon.toLocaleDateString('en-GB', opts) + ' – ' + sun.toLocaleDateString('en-GB', { day:'numeric', month:'short', year:'numeric' });
    $('#weekRangeLabel').text('(' + label + ')');
}

function initFilterDefaults() {
    const now = new Date();
    const y = now.getFullYear(), m = now.getMonth() + 1, curQ = Math.ceil(m / 3);

    let yearOpts = '';
    for (let yr = y; yr >= y - 5; yr--) {
        yearOpts += `<option value="${yr}"${yr === y ? ' selected' : ''}>${yr}</option>`;
    }
    $('#fYear, #fMonthYear, #fQuarterYear').html(yearOpts);

    $('#fDay').val(fmtDate(now));
    $('#fWeekDay').val(fmtDate(now));
    $('#fMonth').val(m);
    $('#fQuarter').val(curQ);
}

// ══════════════════════ DATATABLE ══════════════════════
let table, dtRecent, dtLowStock;

function initDashboardTables() {
    dtLowStock = $('#lowStockTable').DataTable({
        responsive: false, pageLength: 10, dom: 'tip', order: [[2,'asc']],
        columns: [
            { data: null, orderable: false, className: 'text-center text-muted', render: (d,t,r,m) => m.row+1 },
            { data: 'name', render: d => safeOutput(d) },
            { data: 'current', className: 'text-end fw-bold',
              render: (d,t,r) => `<span class="${d<=0?'text-danger':'text-primary'}">${(+d).toLocaleString()}</span>` },
            { data: 'min', className: 'text-end text-muted', render: d => (+d).toLocaleString() }
        ],
        language: { emptyTable: T.allStockAboveMinimum, info: T.showingXofY, infoEmpty: '', paginate: { previous:'‹', next:'›' } }
    });

    dtRecent = $('#recentSalesTable').DataTable({
        responsive: false, pageLength: 8, dom: 'tip', order: [],
        columns: [
            { data: null, orderable: false, className: 'text-center text-muted', render: (d,t,r,m) => m.row+1 },
            { data: 'receipt_number', render: (d,t,r) => (r.is_return_sale ? '<i class="bi bi-arrow-return-left text-muted me-1"></i>' : '') + safeOutput(d) },
            { data: 'party', render: d => safeOutput(d) },
            { data: 'grand_total', className: 'text-end fw-bold', render: d => money(d) },
            { data: 'sale_status', render: d => statusBadge(d) }
        ],
        language: { emptyTable: T.noRecentSales, info: T.showingXofY, infoEmpty: '', paginate: { previous:'‹', next:'›' } }
    });
}

function payBadge(row) {
    if (row.is_return_sale) return '';
    let html = safeOutput(row.payment_method || '—');
    if (row.balance_due > 0.01) {
        html += ` <span class="badge" style="background:#cfe2ff;color:#084298;padding:3px 7px;border-radius:20px;font-size:.66rem;">${T.due} ${money(row.balance_due)}</span>`;
    } else if (row.payment_status === 'paid') {
        html += ` <span class="badge" style="background:#052c65;color:#fff;padding:3px 7px;border-radius:20px;font-size:.66rem;">${T.paid}</span>`;
    }
    return html;
}

function actionMenu(row) {
    let items = `<li><a class="dropdown-item py-2 rounded" href="${RECEIPT_URL}?id=${row.sale_id}" target="_blank"><i class="bi bi-receipt text-primary me-2"></i> ${T.viewReceipt}</a></li>`;
    if (CAN_EDIT   && row.can_receive) items += `<li><button class="dropdown-item py-2 rounded" onclick="openReceive(${row.sale_id})"><i class="bi bi-cash-coin text-primary me-2"></i> ${T.receivePayment}</button></li>`;
    if (CAN_CREATE && row.can_return)  items += `<li><button class="dropdown-item py-2 rounded" onclick="openReturn(${row.sale_id})"><i class="bi bi-arrow-return-left text-primary me-2"></i> ${T.returnRefund}</button></li>`;
    if (CAN_DELETE && row.can_void)    items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 rounded text-danger" onclick="voidSale(${row.sale_id},'${safeOutput(row.receipt_number)}')"><i class="bi bi-x-octagon text-danger me-2"></i> ${T.voidSaleLabel}</button></li>`;
    return `<div class="dropdown d-flex justify-content-end">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear-fill me-1"></i></button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul>
    </div>`;
}

function initHistoryTable() {
    table = $('#posSalesTable').DataTable({
        responsive: false, scrollX: true, pageLength: 25, order: [[2,'desc']],
        dom: 'rtipB',
        buttons: [
            {
                extend: 'copyHtml5',
                text: '<i class="bi bi-clipboard"></i> Copy',
                className: 'd-none',
                exportOptions: { columns: ':not(:last-child)' }
            },
            {
                extend: 'excelHtml5',
                className: 'd-none',
                exportOptions: { columns: ':not(:last-child)' }
            },
            {
                extend: 'print',
                className: 'd-none',
                title: T.salesHistoryPrintTitle + ' — ' + CO_NAME,
                exportOptions: { columns: ':not(:last-child)' },
                customize: function (win) {
                    $(win.document.body).css('font-family', 'Arial, sans-serif');
                    $(win.document.body).find('table').css('font-size', '9pt');
                    $(win.document.body).prepend(
                        `<div style="text-align:center;padding:16px 0 12px;border-bottom:3px solid #0d6efd;margin-bottom:16px;">
                            ${CO_LOGO ? `<img src="${CO_LOGO}" style="height:60px;margin-bottom:6px;display:block;margin:0 auto 6px;"><br>` : ''}
                            <h1 style="color:#0d6efd;font-weight:800;text-transform:uppercase;margin:0;font-size:18pt;">${CO_NAME}</h1>
                            <h2 style="color:#495057;font-weight:600;text-transform:uppercase;margin:4px 0;font-size:12pt;">${T.salesHistoryReportTitle}</h2>
                            <p style="color:#6c757d;margin:0;font-size:9pt;">${T.periodLabel} ${(function(){const r=getDateRange();return r.start+' to '+r.end;})()} &nbsp;|&nbsp; ${T.printedByLabel} ${PRINT_USER} on ${new Date().toLocaleString()}</p>
                        </div>`
                    );
                    $(win.document.body).append(
                        `<div style="position:fixed;bottom:0;left:0;right:0;width:100%;padding:1mm 10mm;border-top:1px solid #ddd;background:#fff;display:flex;flex-direction:column;align-items:center;text-align:center;">
                            <span style="font-size:8pt;color:#444;">${T.printedByFooter} <strong>${T.printedWord}</strong> by <strong>${PRINT_USER} - ${PRINT_ROLE}</strong> on ${new Date().toLocaleString()}</span>
                            <span style="font-size:7.5pt;font-weight:700;color:#0d6efd;">${T.poweredByFooter.replace('%d', PRINT_YEAR)}</span>
                        </div>`
                    );
                }
            }
        ],
        columns: [
            { data: null,             orderable: false, className: 'text-muted', render: (d,t,r,m) => m.row+1 },
            { data: 'receipt_number', render: (d,t,r) => (r.is_return_sale ? '<i class="bi bi-arrow-return-left text-muted me-1"></i>' : '') + safeOutput(d) },
            { data: 'sale_date',      render: d => fmt(d) },
            { data: 'party',          render: d => safeOutput(d) },
            { data: 'grand_total',    className: 'text-end fw-bold', render: d => money(d) },
            { data: 'payment_method', render: (d,t,r) => payBadge(r) },
            { data: 'sale_status',    render: d => statusBadge(d) },
            { data: null,             className: 'text-end', orderable: false, render: (d,t,r) => actionMenu(r) }
        ],
        language: { emptyTable: T.noSalesFoundPeriod, zeroRecords: T.noMatchingRecords },
        drawCallback: function () { renderCards(this.api().rows({ page:'current' }).data().toArray()); }
    });

    // Wire the page-length selector
    $('#pageLenSelect').on('change', function () { table.page.len($(this).val()).draw(); });
}

// ══════════════════════ TOOLBAR ACTIONS ══════════════════════
function copyTable()  { table.button('.buttons-copy').trigger(); }
function exportCSV()  { table.button('.buttons-excel').trigger(); }
function printTable() { table.button('.buttons-print').trigger(); }

// ══════════════════════ LOAD SALES ══════════════════════
function loadSales() {
    if (!table) return;
    const { start: from, end: to } = getDateRange();

    $('#stat-net,#stat-count,#stat-returns,#stat-voided').html('<span class="spinner-border spinner-border-sm text-primary"></span>');

    $.getJSON(GET_SALES, { start_date: from, end_date: to }, function (res) {
        if (!res.success) { Swal.fire({ icon:'error', title: T.error, text: res.message || T.loadFailed }); return; }
        const rows = res.data || [];
        table.clear().rows.add(rows).draw();
        applyView();

        let net = 0, count = 0, returns = 0, voided = 0;
        rows.forEach(r => {
            if (r.sale_status === 'voided') { voided++; return; }
            if (r.is_return_sale) { returns++; net -= (r.grand_total - r.tax_amount); return; }
            count++; net += (r.grand_total - r.tax_amount);
        });
        $('#stat-net').text(CURRENCY + ' ' + money(net));
        $('#stat-count').text(count.toLocaleString());
        $('#stat-returns').text(returns.toLocaleString());
        $('#stat-voided').text(voided.toLocaleString());
    }).fail(() => {
        Swal.fire({ icon:'error', title: T.error, text: T.serverErrorLoadingSales });
        $('#stat-net,#stat-count,#stat-returns,#stat-voided').text('—');
    });
}

// ══════════════════════ DASHBOARD ══════════════════════
let trendChart;
function loadDashboard() {
    $('#btnRefreshDash').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
    $('#topProducts').html('<div class="text-center py-3 text-muted"><span class="spinner-border spinner-border-sm me-1"></span> ' + T.loading + '</div>');
    $('#damageShrinkage,#topCashiers,#salesTarget').html('<div class="text-center py-3 text-muted"><span class="spinner-border spinner-border-sm me-1"></span> ' + T.loading + '</div>');
    $('#lowStockSpinner,#recentSalesSpinner').removeClass('d-none');
    $('#lowStockWrap,#recentSalesWrap').addClass('d-none');
    $('#stat-today-net,#stat-today-count,#stat-today-aov,#stat-today-items,#stat-month-net,#stat-low-stock').html('<span class="spinner-border spinner-border-sm text-primary"></span>');

    $.getJSON(DASH_URL, function (res) {
        $('#btnRefreshDash').prop('disabled', false).html('<i class="bi bi-arrow-clockwise me-1"></i> ' + T.refresh);
        if (!res.success) {
            const errMsg = safeOutput(res.message || T.dashboardFailed);
            $('#topProducts').html(`<div class="text-danger text-center py-3"><i class="bi bi-exclamation-triangle me-1"></i>${errMsg}</div>`);
            $('#lowStockSpinner').html(`<div class="text-danger text-center py-3"><i class="bi bi-exclamation-triangle me-1"></i>${errMsg}</div>`);
            $('#recentSalesSpinner').html(`<div class="text-danger text-center py-3"><i class="bi bi-exclamation-triangle me-1"></i>${errMsg}</div>`);
            $('#damageShrinkage,#topCashiers,#salesTarget').html(`<div class="text-danger text-center py-3"><i class="bi bi-exclamation-triangle me-1"></i>${errMsg}</div>`);
            $('#stat-today-net,#stat-today-count,#stat-today-aov,#stat-today-items,#stat-month-net,#stat-low-stock').text('—');
            return;
        }
        const d = res.data;

        // KPI tiles
        $('#stat-today-net').text(CURRENCY + ' ' + money(d.today.net));
        $('#stat-today-count').text((d.today.count || 0).toLocaleString());
        $('#stat-today-aov').text(CURRENCY + ' ' + money(d.today.aov));
        $('#stat-today-items').text((d.today.items_sold || 0).toLocaleString());
        $('#stat-month-net').text(CURRENCY + ' ' + money(d.month.net));
        $('#stat-low-stock').text((d.low_stock_count || 0).toLocaleString());

        // Trend chart
        const ctx = document.getElementById('trendChart').getContext('2d');
        if (trendChart) trendChart.destroy();
        trendChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: d.trend.map(t => t.label),
                datasets: [{ label: T.netSalesLabel + ' (' + CURRENCY + ')', data: d.trend.map(t => t.net), backgroundColor: '#0d6efd', borderRadius: 4 }]
            },
            options: {
                responsive: true, maintainAspectRatio: true,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => CURRENCY + ' ' + money(c.parsed.y) } } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => v >= 1e6 ? (v/1e6).toFixed(1)+'M' : (v >= 1e3 ? (v/1e3).toFixed(0)+'k' : v) } } }
            }
        });

        // Top products (plain table — no DT needed for 5 rows)
        if (!d.top_products.length) {
            $('#topProducts').html('<div class="text-muted text-center py-3">' + T.noSalesThisMonth + '</div>');
        } else {
            let html = '<table class="table table-sm mb-0"><thead><tr class="text-primary"><th class="text-primary text-center" style="width:35px">' + <?= json_encode(t('S/NO')) ?> + '</th><th class="text-primary">' + <?= json_encode(t('Product')) ?> + '</th><th class="text-primary text-end">' + <?= json_encode(t('Qty')) ?> + '</th><th class="text-primary text-end">' + <?= json_encode(t('Revenue')) ?> + '</th></tr></thead><tbody>';
            d.top_products.forEach((p, i) => {
                html += `<tr><td class="text-center text-muted">${i+1}</td><td>${safeOutput(p.name)}</td><td class="text-end fw-bold">${(+p.qty).toLocaleString()}</td><td class="text-end text-primary">${money(p.revenue)}</td></tr>`;
            });
            $('#topProducts').html(html + '</tbody></table>');
        }

        // Damage / Shrinkage tile (Phase 29)
        const ds = d.damage_shrinkage || { damaged: 0, expired: 0, theft: 0, total: 0 };
        if (!ds.total) {
            $('#damageShrinkage').html('<div class="text-muted text-center py-3">' + T.noDamageShrinkage + '</div>');
        } else {
            $('#damageShrinkage').html(`
                <div class="d-flex justify-content-around text-center py-2">
                    <div><div class="fw-bold text-danger fs-6">${(+ds.damaged).toLocaleString()}</div><div class="small text-muted">${T.damagedLabel}</div></div>
                    <div><div class="fw-bold text-warning fs-6">${(+ds.expired).toLocaleString()}</div><div class="small text-muted">${T.expiredLabel}</div></div>
                    <div><div class="fw-bold text-dark fs-6">${(+ds.theft).toLocaleString()}</div><div class="small text-muted">${T.theftLabel}</div></div>
                </div>`);
        }

        // Top Performing Cashiers tile (Phase 29)
        const cashiers = d.top_cashiers || [];
        if (!cashiers.length) {
            $('#topCashiers').html('<div class="text-muted text-center py-3">' + T.noSalesThisMonthCashiers + '</div>');
        } else {
            let cHtml = '<table class="table table-sm mb-0"><tbody>';
            cashiers.forEach((c, i) => {
                cHtml += `<tr><td class="text-center text-muted" style="width:28px">${i+1}</td><td>${safeOutput(c.name)}</td><td class="text-end text-muted small">${c.count} ${T.salesLabel}</td><td class="text-end fw-bold text-primary">${money(c.total)}</td></tr>`;
            });
            $('#topCashiers').html(cHtml + '</tbody></table>');
        }

        // Sales Target tile (Phase 29, pos_advanced only — element absent otherwise)
        if (d.sales_target && $('#salesTarget').length) {
            const st = d.sales_target;
            if (!st.has_target) {
                $('#salesTarget').html('<div class="text-muted text-center py-3">' + T.noTargetSet + '</div>');
            } else {
                const bandColor = { achieved: '#198754', on_track: '#0d6efd', needs_improvement: '#fd7e14', action_required: '#dc3545' }[st.band] || '#6c757d';
                $('#salesTarget').html(`
                    <div class="text-center py-2">
                        <div class="fs-5 fw-bold text-primary">${CURRENCY} ${money(st.actual)}</div>
                        <div class="small text-muted">${T.ofTargetLabel} ${CURRENCY} ${money(st.target_amount)}</div>
                        <span class="badge mt-2" style="background:${bandColor};color:#fff;padding:5px 12px;border-radius:20px;">${safeOutput(st.band_label)} (${st.pct}%)</span>
                    </div>`);
            }
        }

        // Low Stock DataTable
        $('#lowStockSpinner').addClass('d-none');
        $('#lowStockWrap').removeClass('d-none');
        dtLowStock.clear().rows.add(d.low_stock).draw();

        // Recent Sales DataTable
        $('#recentSalesSpinner').addClass('d-none');
        $('#recentSalesWrap').removeClass('d-none');
        dtRecent.clear().rows.add(d.recent).draw();

    }).fail(() => {
        $('#btnRefreshDash').prop('disabled', false).html('<i class="bi bi-arrow-clockwise me-1"></i> ' + T.refresh);
        const errHtml = '<div class="text-danger text-center py-3"><i class="bi bi-exclamation-triangle me-1"></i> ' + T.serverErrorRetry + '</div>';
        $('#topProducts').html(errHtml);
        $('#lowStockSpinner').html(errHtml).removeClass('d-none');
        $('#recentSalesSpinner').html(errHtml).removeClass('d-none');
        $('#damageShrinkage').html(errHtml);
        $('#topCashiers').html(errHtml);
        $('#salesTarget').html(errHtml);
        $('#stat-today-net,#stat-today-count,#stat-today-aov,#stat-today-items,#stat-month-net,#stat-low-stock').text('Err');
    });
}

// ══════════════════════ VIEW (mobile/desktop) ══════════════════════
function applyView() {
    if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
    else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
}

// ══════════════════════ ACTIONS ══════════════════════
function voidSale(saleId, receipt) {
    if (!CAN_DELETE) return;
    Swal.fire({
        title: T.voidSaleTitle.replace('%s', receipt),
        input: 'text', inputPlaceholder: T.voidReasonPlaceholder,
        text: T.voidWarningText, icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: T.voidSaleButton,
        inputValidator: v => (!v || !v.trim()) ? T.reasonRequired : undefined
    }).then(r => {
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('_csrf', CSRF_TOKEN); fd.append('sale_id', saleId); fd.append('reason', r.value.trim());
        Swal.fire({ title: T.voiding, allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: VOID_URL, type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) { loadSales(); if (!$('#paneDashboard').hasClass('d-none')) loadDashboard(); Swal.fire({ icon:'success', title: T.voided, text: res.message, timer:2000, showConfirmButton:false }); }
                else { Swal.fire({ icon:'error', title: T.error, text: res.message || T.failed }); }
            },
            error: function () { Swal.fire({ icon:'error', title: T.error, text: T.serverError }); }
        });
    });
}

function openReceive(saleId) {
    if (!CAN_EDIT) return;
    const row = table.rows().data().toArray().find(r => r.sale_id === saleId);
    if (!row) return;
    $('#rcv_sale_id').val(saleId);
    $('#rcv_receipt').text(row.receipt_number);
    $('#rcv_customer').text(row.party);
    $('#rcv_balance').text(money(row.balance_due));
    $('#rcv_amount').val((row.balance_due || 0).toFixed(2)).attr('max', row.balance_due);
    new bootstrap.Modal(document.getElementById('receiveModal')).show();
}

function openReturn(saleId) {
    if (!CAN_CREATE) return;
    $.getJSON(GET_ITEMS, { sale_id: saleId }, function (res) {
        if (!res.success) { Swal.fire({ icon:'error', title: T.error, text: res.message || T.failed }); return; }
        $('#ret_sale_id').val(res.sale.sale_id);
        $('#ret_receipt').text(res.sale.receipt_number);
        $('#ret_customer').text(res.sale.customer_name);
        let html = '';
        res.lines.forEach(l => {
            const dis = l.returnable <= 0 ? 'disabled' : '';
            html += `<tr data-iid="${l.sale_item_id}">
                <td>${safeOutput(l.product_name)}</td>
                <td class="text-end">${l.quantity}</td>
                <td class="text-end">${l.returnable}</td>
                <td><input type="number" class="form-control form-control-sm ret-qty" min="0" max="${l.returnable}" step="any" value="0" ${dis}></td>
            </tr>`;
        });
        $('#ret_lines').html(html || '<tr><td colspan="4" class="text-center text-muted py-3">' + T.noReturnableLines + '</td></tr>');
        new bootstrap.Modal(document.getElementById('returnModal')).show();
    });
}

// ══════════════════════ MOBILE CARDS ══════════════════════
function renderCards(rows) {
    if (!rows.length) { $('#cardView').html('<div class="col-12 text-center py-5 text-muted">' + T.noRecordsFound + '</div>'); return; }
    let html = '';
    rows.forEach((row, i) => {
        let actions = `<a class="btn btn-sm btn-outline-primary" href="${RECEIPT_URL}?id=${row.sale_id}" target="_blank" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-receipt"></i></a>`;
        if (CAN_EDIT   && row.can_receive) actions += `<button class="btn btn-sm btn-outline-primary" onclick="openReceive(${row.sale_id})" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-cash-coin"></i></button>`;
        if (CAN_CREATE && row.can_return)  actions += `<button class="btn btn-sm btn-outline-primary" onclick="openReturn(${row.sale_id})" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-arrow-return-left"></i></button>`;
        if (CAN_DELETE && row.can_void)    actions += `<button class="btn btn-sm btn-outline-danger" onclick="voidSale(${row.sale_id},'${safeOutput(row.receipt_number)}')" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-x-octagon"></i></button>`;
        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between">
                        <div class="fw-bold"><span class="text-muted me-1">${i+1}.</span>${(row.is_return_sale ? '<i class="bi bi-arrow-return-left text-muted me-1"></i>' : '') + safeOutput(row.receipt_number)}</div>
                        <div>${statusBadge(row.sale_status)}</div>
                    </div>
                    <small class="text-muted">${safeOutput(row.party)} &middot; ${fmt(row.sale_date)}</small>
                    <div class="fw-bold text-primary mt-1">${CURRENCY} ${money(row.grand_total)}</div>
                </div>
                <div class="card-footer bg-white border-top p-0">
                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">${actions}</div>
                </div>
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}

// ══════════════════════ MODAL BINDINGS ══════════════════════
<?php if ($can_create): ?>
$('#returnModal').on('shown.bs.modal', function () {
    $(this).find('.select2-static').each(function () {
        if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme:'bootstrap-5', dropdownParent:$('#returnModal'), placeholder: T.selectPlaceholder, width:'100%' });
    });
});
$('#returnModal').on('hidden.bs.modal', function () { $('#returnForm')[0].reset(); $('#ret_lines').empty(); $('#ret-message').html(''); });
$('#returnForm').on('submit', function (e) {
    e.preventDefault();
    const lines = [];
    $('#ret_lines tr').each(function () {
        const iid = $(this).data('iid'), qty = parseFloat($(this).find('.ret-qty').val()) || 0;
        if (iid && qty > 0) lines.push({ sale_item_id: iid, return_qty: qty });
    });
    if (!lines.length) { Swal.fire({ icon:'warning', title: T.nothingSelected, text: T.enterReturnQty }); return; }
    const btn = $(this).find('[type=submit]'), orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> ' + T.processing);
    const fd = new FormData(this); fd.append('items', JSON.stringify(lines));
    $.ajax({
        url: RETURN_URL, type:'POST', data:fd, contentType:false, processData:false, dataType:'json',
        success: function (res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('returnModal')).hide();
                loadSales(); if (!$('#paneDashboard').hasClass('d-none')) loadDashboard();
                Swal.fire({ icon:'success', title: T.returnProcessed, text: res.message, timer:2200, showConfirmButton:false });
            } else { Swal.fire({ icon:'error', title: T.error, text: res.message || T.failed }); }
        },
        error: function () { Swal.fire({ icon:'error', title: T.error, text: T.serverError }); },
        complete: function () { btn.prop('disabled', false).html(orig); }
    });
});
<?php endif; ?>

<?php if ($can_edit): ?>
$('#receiveModal').on('shown.bs.modal', function () {
    $(this).find('.select2-static').each(function () {
        if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme:'bootstrap-5', dropdownParent:$('#receiveModal'), placeholder: T.selectPlaceholder, width:'100%' });
    });
});
$('#receiveModal').on('hidden.bs.modal', function () { $('#receiveForm')[0].reset(); $('#rcv-message').html(''); });
$('#receiveForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type=submit]'), orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> ' + T.saving);
    $.ajax({
        url: RECEIVE_URL, type:'POST', data:new FormData(this), contentType:false, processData:false, dataType:'json',
        success: function (res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('receiveModal')).hide();
                loadSales();
                Swal.fire({ icon:'success', title: T.paymentReceived, text: res.message, timer:2200, showConfirmButton:false });
            } else { Swal.fire({ icon:'error', title: T.error, text: res.message || T.failed }); }
        },
        error: function () { Swal.fire({ icon:'error', title: T.error, text: T.serverError }); },
        complete: function () { btn.prop('disabled', false).html(orig); }
    });
});
<?php endif; ?>

<?php if ($can_view_targets && $can_edit_targets): ?>
// ══════════════════════ SALES TARGET (Phase 29) ══════════════════════
$('#btnSetTarget').on('click', function () {
    const now = new Date();
    $('#tgt_month').val(now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0'));
    $('#tgt_amount').val('');
    new bootstrap.Modal(document.getElementById('targetModal')).show();
});
$('#targetModal').on('hidden.bs.modal', function () { $('#targetForm')[0].reset(); $('#tgt-message').html(''); });
$('#targetForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type=submit]'), orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> ' + T.saving);
    $.ajax({
        url: TARGET_URL, type:'POST', data:new FormData(this), contentType:false, processData:false, dataType:'json',
        success: function (res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('targetModal')).hide();
                loadDashboard();
                Swal.fire({ icon:'success', title: T.targetSaved, text: res.message, timer:2200, showConfirmButton:false });
            } else { Swal.fire({ icon:'error', title: T.error, text: res.message || T.failed }); }
        },
        error: function () { Swal.fire({ icon:'error', title: T.error, text: T.serverError }); },
        complete: function () { btn.prop('disabled', false).html(orig); }
    });
});
<?php endif; ?>

// ══════════════════════ INIT ══════════════════════
$(document).ready(function () {
    initFilterDefaults();
    showFilterPanel('yearly');

    // Period buttons
    $('.period-btn').on('click', function () {
        $('.period-btn').removeClass('btn-primary').addClass('btn-outline-primary');
        $(this).removeClass('btn-outline-primary').addClass('btn-primary');
        showFilterPanel($(this).data('period'));
        if (table) loadSales();
    });

    // Deep-link from the main dashboard's "Today's POS Sales" card
    // (?period=daily): pre-select that tab so the unconditional loadSales()
    // call below shows exactly what the card showed, instead of this page's
    // own default (Yearly). #fDay already defaults to today via
    // initFilterDefaults() above, so no separate ?date= param is needed.
    const urlPeriod = new URLSearchParams(window.location.search).get('period');
    if (urlPeriod && $('.period-btn[data-period="' + urlPeriod + '"]').length) {
        $('.period-btn').removeClass('btn-primary').addClass('btn-outline-primary');
        $('.period-btn[data-period="' + urlPeriod + '"]').removeClass('btn-outline-primary').addClass('btn-primary');
        showFilterPanel(urlPeriod);
    }

    // Apply buttons (all panels)
    $(document).on('click', '.apply-btn', function () { loadSales(); });

    // Week label update on date change
    $('#fWeekDay').on('change', updateWeekLabel);

    // ── Sales Dashboard toggle ──
    $('#btnToggleDash').on('click', function () {
        const dashVisible = !$('#paneDashboard').hasClass('d-none');
        if (dashVisible) {
            // Hide dashboard, show history
            $('#paneDashboard').addClass('d-none');
            $('#paneHistory').removeClass('d-none');
            $(this).removeClass('btn-primary').addClass('btn-outline-primary');
        } else {
            // Show dashboard, hide history
            $('#paneHistory').addClass('d-none');
            $('#paneDashboard').removeClass('d-none');
            $(this).removeClass('btn-outline-primary').addClass('btn-primary');
            loadDashboard();
        }
    });

    // Resize
    $(window).on('resize', applyView);

    initHistoryTable();
    initDashboardTables();
    loadSales();
    applyView();
});
</script>

<?php require_once 'footer.php';
ob_end_flush();
?>
