<?php
/**
 * Subscription — the company's own subscription with BJP. Company admins only, on its own subdomain.
 *
 *   My plan (front): the plan, what it includes, the running period (trial / paid / grace) with
 *   days left, and what needs the company's attention.
 *   Billing details: Plans (renew / change → a request BJP reviews), Invoices, Quotations,
 *   Payments, Contracts (sign), Requests. Invoices, quotations and payments are read-only;
 *   payments are recorded by BJP.
 */
require_once __DIR__ . '/../../../roots.php';
if (!isAdmin()) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
$tenantId = function_exists('bmsCurrentTenantId') ? bmsCurrentTenantId() : null;

$page_title = 'Subscription';
require_once __DIR__ . '/../../../header.php';
?>
<style>
    .sb-hero { border: 1px solid #dbe4f3; border-radius: 16px; overflow: hidden; background: #fff; }
    .sb-hero .sb-left { padding: 26px 28px; }
    .sb-hero .sb-right { padding: 26px 28px; background: #e7f0ff; }
    .sb-eyebrow { font-size: .75rem; font-weight: 800; letter-spacing: 1.1px; text-transform: uppercase; color: #475569; }
    .sb-name { font-size: 2rem; font-weight: 800; letter-spacing: -.5px; line-height: 1.1; }
    .sb-days { font-size: 3.6rem; font-weight: 800; letter-spacing: -2px; line-height: 1; color: #052c65; font-variant-numeric: tabular-nums; }
    .sb-days-lbl { font-size: 1.05rem; font-weight: 700; color: #334155; }
    .sb-bar { height: 10px; background: #fff; border-radius: 5px; overflow: hidden; }
    .sb-bar > div { height: 100%; background: #0d6efd; border-radius: 5px; transition: width .6s; }
    .sb-kv { display: flex; justify-content: space-between; gap: 12px; font-size: .9rem; padding: 4px 0; }
    .sb-kv span { color: #475569; } .sb-kv b { text-align: right; }
    .sb-limit b { font-size: 1.35rem; font-weight: 800; display: block; line-height: 1.2; }
    .sb-limit span { font-size: .8rem; color: #475569; }
    .sb-note { background: #fff; border-radius: 10px; padding: 10px 12px; font-size: .82rem; color: #334155; }
    .sb-tile { border: 1px solid #dbe4f3; border-radius: 14px; padding: 14px 18px; background: #fff; height: 100%; cursor: pointer; text-align: left; width: 100%; }
    .sb-tile:hover, .sb-tile:focus-visible { border-color: #0d6efd; }
    .sb-tile .n { font-size: 1.6rem; font-weight: 800; line-height: 1.2; }
    .sb-tile.hot { border-color: #b6ccfe; } .sb-tile.hot .n { color: #0d6efd; }
    .sb-banner { border-radius: 12px; padding: 12px 16px; display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; font-weight: 600; }
    .sb-banner.soon { background: #cfe2ff; color: #084298; }
    .sb-banner.over { background: #f8d7da; color: #842029; }
    .sb-badge { font-size: .72rem; font-weight: 800; padding: 4px 11px; border-radius: 20px; }
    .bl-plan { cursor: pointer; transition: border-color .15s, box-shadow .15s; }
    .bl-plan.sel { border-color: #0d6efd !important; box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
    .bl-term.sel { background: #0d6efd; color: #fff; border-color: #0d6efd; }
    .bl-new { background: #0d6efd; color: #fff; font-size: .65rem; }
    .bl-lines { font-size: .78rem; color: #6c757d; }
    .sb-tabs { flex-wrap: nowrap; overflow-x: auto; overflow-y: hidden; }
    .sb-tabs .nav-link { white-space: nowrap; }
    .sb-hide { display: none !important; }
    .sb-empty { text-align: center; color: #6c757d; padding: 28px 12px; border: 1px dashed #b6ccfe; border-radius: 12px; }
    @media (max-width: 991.98px) {
        .sb-hero .sb-left, .sb-hero .sb-right { padding: 20px; }
        .sb-name { font-size: 1.7rem; } .sb-days { font-size: 3.1rem; }
    }
</style>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2" style="position:sticky;top:0;z-index:1020;background:#fff;">
        <div>
            <button type="button" class="btn btn-link p-0 text-decoration-none small fw-semibold d-none" id="toPlan"><i class="bi bi-arrow-left me-1"></i><?= t('My plan') ?></button>
            <h4 class="mb-0"><i class="bi bi-credit-card-2-front text-primary me-2"></i><span id="pageTitle"><?= t('Subscription') ?></span></h4>
        </div>
<?php if ($tenantId !== null): ?>
        <button type="button" class="btn btn-outline-primary" id="toDetails"><i class="bi bi-receipt me-1"></i><?= t('Billing details') ?></button>
<?php endif; ?>
    </div>

<?php if ($tenantId === null): ?>
    <div class="alert alert-light border"><i class="bi bi-info-circle text-primary me-1"></i><?= t('Subscriptions are managed by the platform. Nothing to show on this installation.') ?></div>
<?php else: ?>
    <!-- ── My plan ─────────────────────────────────────────────────────── -->
    <div id="viewPlan">
        <div class="sb-banner d-none mb-3" id="sbBanner"><span id="sbBannerText"></span><button type="button" class="btn btn-primary btn-sm" data-go="plans" id="sbBannerBtn"></button></div>

        <div class="sb-hero mb-4">
            <div class="row g-0">
                <div class="col-lg-7 order-2 order-lg-1 sb-left d-flex flex-column gap-3">
                    <div class="sb-eyebrow" id="sbProduct">&nbsp;</div>
                    <div class="d-flex align-items-center gap-2 flex-wrap"><span class="sb-name" id="sbName"><?= t('Loading...') ?></span><span class="sb-badge" id="sbStatus"></span></div>
                    <p class="mb-0 text-body" id="sbDesc"></p>
                    <div class="row g-2 border-top pt-3" id="sbLimits"></div>
                    <div class="small text-muted d-none" id="sbModules"></div>
                    <div class="small d-none" id="sbItems"></div>
                    <div class="alert alert-light border small mb-0 d-none" id="sbScheduled"></div>
                    <div class="d-grid d-sm-flex gap-2 mt-1">
                        <button type="button" class="btn btn-primary px-4" data-go="plans" id="sbRenew"><i class="bi bi-arrow-repeat me-1"></i><?= t('Renew') ?></button>
                        <button type="button" class="btn btn-outline-primary px-4" data-go="plans"><?= t('Change plan') ?></button>
                    </div>
                </div>
                <div class="col-lg-5 order-1 order-lg-2 sb-right d-flex flex-column gap-2">
                    <div class="sb-eyebrow" style="color:#052c65" id="sbKind">&nbsp;</div>
                    <div class="d-flex align-items-baseline gap-2"><span class="sb-days" id="sbDays">—</span><span class="sb-days-lbl" id="sbDaysLbl"></span></div>
                    <div class="sb-bar d-none" id="sbBar"><div style="width:0"></div></div>
                    <div class="small text-muted" id="sbUsed"></div>
                    <div class="mt-1" id="sbKv"></div>
                    <div class="sb-note mt-1 d-none" id="sbRemind"><i class="bi bi-envelope text-primary me-1"></i><?= t('We email you 7, 3 and 1 day before your access ends.') ?></div>
                </div>
            </div>
        </div>

        <div class="small fw-bold text-muted mb-2" style="letter-spacing:1px"><?= mb_strtoupper(t('Needs your attention')) ?></div>
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4"><button type="button" class="sb-tile" data-go="invoices" id="tileInv"><div class="small text-muted"><?= t('Invoices to pay') ?></div><div class="n">—</div><div class="small text-muted sub"></div></button></div>
            <div class="col-12 col-md-4"><button type="button" class="sb-tile" data-go="contracts" id="tileCon"><div class="small text-muted"><?= t('Contracts to sign') ?></div><div class="n">—</div><div class="small text-muted sub"></div></button></div>
            <div class="col-12 col-md-4"><button type="button" class="sb-tile" data-go="requests" id="tileReq"><div class="small text-muted"><?= t('Requests with BJP') ?></div><div class="n">—</div><div class="small text-muted sub"></div></button></div>
        </div>
    </div>

    <!-- ── Billing details ─────────────────────────────────────────────── -->
    <div id="viewDetails" class="d-none">
        <ul class="nav nav-tabs sb-tabs mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPlans" type="button" data-tab="plans"><?= t('Plans') ?></button></li>
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabInvoices" type="button" data-tab="invoices"><?= t('Invoices') ?> <span class="badge rounded-pill bl-new d-none" id="newInv">0</span></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabQuotes" type="button" data-tab="quotations"><?= t('Quotations') ?> <span class="badge rounded-pill bl-new d-none" id="newQt">0</span></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPayments" type="button" data-tab="payments"><?= t('Payments') ?></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabContracts" type="button" data-tab="contracts"><?= t('Contracts') ?> <span class="badge rounded-pill bl-new d-none" id="contractsToSign">0</span></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabRequests" type="button" data-tab="requests"><?= t('Requests') ?> <span class="badge rounded-pill bl-new d-none" id="reqWaiting">0</span></button></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade" id="tabPlans">
                <p class="small text-muted mb-3"><?= t('Choose a plan and a period, add extras if you need them, and send the request. BJP reviews it and the quotation appears here once approved. Pay the invoice and the plan applies automatically — an upgrade at once, a smaller plan when your current period ends.') ?></p>
                <div id="catalog"><div class="text-muted small"><?= t('Loading...') ?></div></div>
            </div>
            <div class="tab-pane fade show active" id="tabInvoices">
                <div class="alert alert-light border small mb-3"><i class="bi bi-info-circle text-primary me-1"></i><?= t('Invoices come from BJP. Pay by bank or M-Pesa using the details on the invoice; BJP records the payment and your access extends automatically.') ?></div>
                <div class="sb-wrap" data-for="inv">
                    <div class="d-none d-md-block table-responsive"><table id="tblInvoices" class="table table-hover align-middle w-100" data-bms-cards="off">
                        <thead class="table-light"><tr><th>S/NO</th><th><?= t('Invoice') ?></th><th><?= t('For') ?></th><th><?= t('Date') ?></th><th><?= t('Due') ?></th><th class="text-end"><?= t('Total') ?></th><th class="text-end"><?= t('Balance') ?></th><th><?= t('Status') ?></th><th class="text-end"><?= t('Actions') ?></th></tr></thead>
                        <tbody></tbody></table></div>
                    <div class="d-md-none row g-2" id="cardsInvoices"></div>
                </div>
            </div>
            <div class="tab-pane fade" id="tabQuotes">
                <div class="sb-wrap" data-for="qt">
                    <div class="d-none d-md-block table-responsive"><table id="tblQuotes" class="table table-hover align-middle w-100" data-bms-cards="off">
                        <thead class="table-light"><tr><th>S/NO</th><th><?= t('Quotation') ?></th><th><?= t('For') ?></th><th><?= t('Date') ?></th><th><?= t('Valid until') ?></th><th class="text-end"><?= t('Total') ?></th><th><?= t('Status') ?></th><th class="text-end"><?= t('Actions') ?></th></tr></thead>
                        <tbody></tbody></table></div>
                    <div class="d-md-none row g-2" id="cardsQuotes"></div>
                </div>
            </div>
            <div class="tab-pane fade" id="tabPayments">
                <div class="sb-wrap" data-for="pay">
                    <div class="d-none d-md-block table-responsive"><table id="tblPayments" class="table table-hover align-middle w-100" data-bms-cards="off">
                        <thead class="table-light"><tr><th>S/NO</th><th><?= t('Recorded') ?></th><th class="text-end"><?= t('Amount (TZS)') ?></th><th><?= t('Months') ?></th><th><?= t('From') ?></th><th><?= t('To') ?></th><th><?= t('Reference') ?></th></tr></thead>
                        <tbody></tbody></table></div>
                    <div class="d-md-none row g-2" id="cardsPayments"></div>
                </div>
            </div>
            <div class="tab-pane fade" id="tabContracts">
                <div class="sb-wrap" data-for="con">
                    <div class="d-none d-md-block table-responsive"><table id="tblContracts" class="table table-hover align-middle w-100" data-bms-cards="off">
                        <thead class="table-light"><tr><th>S/NO</th><th><?= t('Contract') ?></th><th><?= t('Service') ?></th><th><?= t('Period') ?></th><th><?= t('From') ?></th><th><?= t('To') ?></th><th><?= t('Status') ?></th><th class="text-end"><?= t('Actions') ?></th></tr></thead>
                        <tbody></tbody></table></div>
                    <div class="d-md-none row g-2" id="cardsContracts"></div>
                </div>
            </div>
            <div class="tab-pane fade" id="tabRequests">
                <p class="small text-muted mb-3"><?= t('Requests you sent to BJP. Once BJP prepares the quotation it appears under Quotations.') ?></p>
                <div class="row g-2" id="waitList"></div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<?php if ($tenantId !== null): ?>
<script>
$(function () {
    const API = '<?= buildUrl('api/my_subscription.php') ?>', CSRF = <?= json_encode(csrf_token()) ?>;
    const LANG = <?= json_encode(function_exists('currentLanguage') && currentLanguage() === 'sw' ? 'sw-TZ' : 'en-GB') ?>;
    const L = <?= json_encode([
        'trial' => t('Trial'), 'active' => t('Active'), 'suspended' => t('Suspended'), 'noExpiry' => t('No expiry'),
        'confirmQ' => t('Send this request?'), 'confirmTxt' => t('BJP will review a quotation of %s for %p.'),
        'yes' => t('Yes, send'), 'cancel' => t('Cancel'), 'view' => t('View'), 'error' => t('Error'),
        'notLinked' => t('Your company is not yet linked to a customer account at BJP. You can still send a request — BJP sets up the link when preparing your quotation, then your quotations and invoices appear here.'),
        'unreachable' => t('BJP could not be reached right now. Please try again later.'),
        'paid' => t('Paid'), 'partial' => t('Partly paid'), 'unpaid' => t('Unpaid'), 'approved' => t('Approved'), 'accepted' => t('Accepted'), 'cancelled' => t('Replaced'),
        'waiting' => t('Waiting for BJP'), 'declined' => t('Declined by BJP'), 'new' => t('New'),
        'auto' => t('BJP invoice'), 'manual' => t('Recorded by BJP'), 'months' => t('months'),
        'noServices' => t('BJP has not published subscription periods yet.'),
        'sign' => t('Sign'), 'awaiting_customer' => t('Waiting for your signature'), 'signed' => t('Signed'),
        'awaiting_bjp' => t('Being prepared by BJP'), 'opening' => t('Opening the signing page...'),
        'users' => t('Users'), 'shops' => t('Shops'), 'storage' => t('Storage'), 'modulesLbl' => t('Modules'), 'unlimited' => t('Unlimited'), 'extra' => t('extra'),
        'scheduled' => t('Changes to %n on %d'), 'current' => t('Current'), 'popular' => t('Popular'),
        'period' => t('Period'), 'extras' => t('Extras'), 'perUnit' => t('each'), 'total' => t('Total'),
        'send' => t('Send request'), 'pickPeriod' => t('Choose a period first.'), 'noPlan' => t('No plan yet'),
        'modules' => t('%d modules'), 'alsoIncluded' => t('Also included'),
        'kTrial' => t('Free trial'), 'kPaid' => t('Paid subscription'), 'kGrace' => t('Access ended'), 'kNoEnd' => t('No end date'),
        'daysLeft' => t('days left'), 'dayLeft' => t('day left'), 'daysToRenew' => t('days to renew'),
        'used' => t('%u of %t days used'), 'started' => t('Period started'), 'trialStarted' => t('Trial started'),
        'until' => t('Access until'), 'trialEnds' => t('Trial ends'), 'lastPay' => t('Last payment'), 'graceUntil' => t('Data kept until'),
        'noEndTxt' => t('Your plan was set up by BJP without an end date.'),
        'stTrial' => t('Free trial'), 'stActive' => t('Active'), 'stSoon' => t('Ends soon'), 'stOver' => t('Access ended'),
        'bTrial' => t('Your free trial ends in %d days, on %e.'), 'bTrial1' => t('Your free trial ends tomorrow, %e.'), 'bTrial0' => t('Your free trial ends today.'),
        'bPaid' => t('Your plan ends in %d days, on %e.'), 'bPaid1' => t('Your plan ends tomorrow, %e.'), 'bPaid0' => t('Your plan ends today.'),
        'bOver' => t('Your access ended on %e. Renew by %g to keep using the system.'),
        'choosePlan' => t('Choose a plan'), 'renewNow' => t('Renew now'), 'renewX' => t('Renew %n'), 'renew' => t('Renew'),
        'allPaid' => t('All paid'), 'toPay' => t('%s TZS to pay'), 'nothingSign' => t('Nothing to sign'), 'signNow' => t('Open to sign'),
        'noReq' => t('No open requests'), 'sinceX' => t('%n · waiting since %d'),
        'eInv' => t('No invoices yet.'), 'eQt' => t('No quotations yet.'), 'ePay' => t('No payments recorded yet.'), 'eCon' => t('No contracts yet.'), 'eReq' => t('No requests yet. Choose a plan under Plans to send one.'),
        'subscription' => t('Subscription'), 'billing' => t('Billing details'), 'balance' => t('Balance'),
    ]) ?>;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = n => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    const dmy = s => s ? String(s).slice(0, 10).split('-').reverse().join('/') : '—';
    const longDate = s => { if (!s) return '—'; const d = new Date(String(s).slice(0, 10) + 'T00:00:00'); return isNaN(d) ? dmy(s) : d.toLocaleDateString(LANG, { day: 'numeric', month: 'short', year: 'numeric' }); };
    const pill = (txt, bg, fg) => `<span class="badge" style="background:${bg};color:${fg}">${esc(txt)}</span>`;
    const badge = st => {
        const map = { paid: ['#052c65', '#fff'], approved: ['#0d6efd', '#fff'], accepted: ['#052c65', '#fff'], partial: ['#cfe2ff', '#084298'],
                      unpaid: ['#e9ecef', '#495057'], cancelled: ['#6c757d', '#fff'], waiting: ['#bfdbfe', '#1e3a8a'], declined: ['#6c757d', '#fff'] };
        const [bg, fg] = map[st] || ['#e9ecef', '#495057'];
        return pill(L[st] || st, bg, fg);
    };
    const newChip = d => d.is_new ? ` <span class="badge rounded-pill bl-new">${L.new}</span>` : '';
    const lines = d => (d.lines || []).map(l => esc(l.name) + (Number(l.qty) !== 1 ? ` × ${Number(l.qty)}` : '') + (l.term ? ` <span class="text-primary">(${esc(l.term)})</span>` : '')).join('<br>');
    const gear = url => url ? `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill me-1"></i></button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2"><li><a class="dropdown-item py-2 rounded" href="${esc(url)}" target="_blank" rel="noopener"><i class="bi bi-eye text-primary me-2"></i>${L.view}</a></li></ul></div>` : '';
    // An empty list shows one short line instead of an empty table with paging.
    const dt = (id, cols, right) => $(id).DataTable({ responsive: false, scrollX: false, autoWidth: false, pageLength: 25, order: [], dom: 'rtip',
        columnDefs: [$.extend({ targets: 0 }, BMSSerial.column()), { targets: cols, orderable: false }, { targets: right, className: 'text-end' }] });
    const tInv = dt('#tblInvoices', [8], [5, 6, 8]), tQt = dt('#tblQuotes', [7], [5, 7]), tPay = dt('#tblPayments', [], [2]), tCon = dt('#tblContracts', [7], [7]);
    const fill = (key, table, rows, emptyTxt) => {
        const wrap = $(`.sb-wrap[data-for="${key}"]`);
        wrap.find('.sb-empty').remove();
        table.clear().rows.add(rows).draw();
        wrap.children('div').toggleClass('sb-hide', !rows.length);
        if (!rows.length) wrap.append(`<div class="sb-empty">${esc(emptyTxt)}</div>`);
        else table.columns.adjust();
    };
    const cards = (sel, list, fn) => $(sel).html(list.map(fn).join(''));
    const cardBtn = url => url ? `<div class="card-footer bg-white border-top p-0"><div style="display:flex;gap:4px;padding:6px;"><a class="btn btn-sm btn-outline-primary" style="flex:1;padding:3px 4px" href="${esc(url)}" target="_blank" rel="noopener"><i class="bi bi-eye"></i></a></div></div>` : '';
    const items = list => (list || []).map(i => `<div class="d-flex justify-content-between py-1"><span><i class="bi bi-check2 text-primary me-1"></i>${esc(i.label)}</span>${i.value ? `<span class="text-muted">${esc(i.value)}</span>` : ''}</div>`).join('');
    const storage = mb => mb === null || mb === undefined ? L.unlimited : (mb >= 1024 ? (Math.round(mb / 102.4) / 10) + ' GB' : mb + ' MB');
    let curPlanKey = null, curPlanName = '';

    // ── Views ──────────────────────────────────────────────────────────
    function showView(details, tab) {
        $('#viewPlan').toggleClass('d-none', details);
        $('#viewDetails').toggleClass('d-none', !details);
        $('#toDetails').toggleClass('d-none', details);
        $('#toPlan').toggleClass('d-none', !details);
        $('#pageTitle').text(details ? L.billing : L.subscription);
        if (details && tab) bootstrap.Tab.getOrCreateInstance($(`.sb-tabs [data-tab="${tab}"]`)[0]).show();
        if (details) setTimeout(() => [tInv, tQt, tPay, tCon].forEach(t => t.columns.adjust()), 200);
        history.replaceState(null, '', details ? '#billing' + (tab ? '-' + tab : '') : location.pathname + location.search);
        window.scrollTo(0, 0);
    }
    $('#toDetails').on('click', () => showView(true, null));
    $('#toPlan').on('click', () => showView(false));
    $(document).on('click', '[data-go]', function () { showView(true, $(this).data('go')); });
    $('.sb-tabs [data-tab]').on('shown.bs.tab', function () {
        history.replaceState(null, '', '#billing-' + $(this).data('tab'));
        [tInv, tQt, tPay, tCon].forEach(t => t.columns.adjust());
    });
    const hm = location.hash.match(/^#billing(?:-(\w+))?$/);
    if (hm) showView(true, hm[1] || null);

    // ── My plan ────────────────────────────────────────────────────────
    function renderPlan(r) {
        const st = r.tenant.status, per = r.period || { kind: 'no_end' }, d = r.days_left, p = r.plan;
        curPlanKey = p ? p.plan_key : null;
        curPlanName = p && p.name ? p.name : '';
        $('#sbProduct').text(p && p.product ? p.product : ' ');
        $('#sbName').text(curPlanName || L.noPlan);
        $('#sbDesc').text(p ? (p.tagline || p.description || '') : '').toggleClass('d-none', !(p && (p.tagline || p.description)));
        // Status pill: blue scale only; red once access has ended.
        const soon = d !== null && d >= 0 && d <= 7;
        const [stTxt, bg, fg] = per.kind === 'grace' ? [L.stOver, '#dc3545', '#fff'] : st === 'trial' ? [L.stTrial, '#cfe2ff', '#084298'] : soon ? [L.stSoon, '#bfdbfe', '#1e3a8a'] : [L.stActive, '#0d6efd', '#fff'];
        $('#sbStatus').text(stTxt).css({ background: bg, color: fg });
        $('#sbRenew').html(`<i class="bi bi-arrow-repeat me-1"></i>${esc(st === 'trial' ? L.choosePlan : (curPlanName ? L.renewX.replace('%n', curPlanName) : L.renew))}`);
        if (p) {
            const lim = (val, extra, lbl) => `<div class="col-6 col-sm-3 sb-limit"><b>${val}${extra > 0 ? ` <small class="text-primary fs-6">+${extra}</small>` : ''}</b><span>${esc(lbl)}</span></div>`;
            $('#sbLimits').html(lim(p.max_users === null ? L.unlimited : p.max_users, p.extra_users, L.users) + lim(p.max_shops === null ? L.unlimited : p.max_shops, p.extra_shops, L.shops)
                + lim(storage(p.max_storage_mb), 0, L.storage) + lim((p.modules || []).length, 0, L.modulesLbl)).removeClass('d-none');
            $('#sbModules').text((p.modules || []).length ? L.modulesLbl + ': ' + p.modules.join(' · ') : '').toggleClass('d-none', !(p.modules || []).length);
            $('#sbItems').html((p.items || []).length ? `<div class="fw-semibold mb-1">${esc(L.alsoIncluded)}</div>${items(p.items)}` : '').toggleClass('d-none', !(p.items || []).length);
            $('#sbScheduled').html(p.scheduled ? `<i class="bi bi-calendar-event text-primary me-1"></i>${esc(L.scheduled.replace('%n', p.scheduled.name).replace('%d', longDate(p.scheduled.at)))}` : '').toggleClass('d-none', !p.scheduled);
        } else {
            $('#sbLimits').addClass('d-none');
        }
        // Right panel: the running period.
        const kindTxt = { trial: L.kTrial, paid: L.kPaid, grace: L.kGrace, no_end: L.kNoEnd }[per.kind] || '';
        $('#sbKind').text(kindTxt);
        if (per.kind === 'no_end') {
            $('#sbDays').text('∞'); $('#sbDaysLbl').text(''); $('#sbUsed').text(L.noEndTxt);
        } else if (per.kind === 'grace') {
            const g = per.grace_until ? Math.max(0, Math.round((new Date(per.grace_until + 'T00:00:00') - new Date(new Date().toDateString())) / 86400000)) : 0;
            $('#sbDays').text(g); $('#sbDaysLbl').text(L.daysToRenew); $('#sbUsed').text('');
        } else {
            $('#sbDays').text(d); $('#sbDaysLbl').text(d === 1 ? L.dayLeft : L.daysLeft);
            $('#sbUsed').text(per.total_days ? L.used.replace('%u', per.used_days).replace('%t', per.total_days) : '');
        }
        const pct = per.total_days ? Math.min(100, Math.round(per.used_days * 100 / per.total_days)) : null;
        $('#sbBar').toggleClass('d-none', pct === null || per.kind === 'grace').find('div').css('width', (pct || 0) + '%');
        const kv = (k, v) => `<div class="sb-kv"><span>${esc(k)}</span><b>${esc(v)}</b></div>`;
        const lastPay = (r.payments || [])[0];
        let rows = '';
        if (per.starts_at) rows += kv(st === 'trial' ? L.trialStarted : L.started, longDate(per.starts_at));
        if (r.ends_at) rows += kv(st === 'trial' ? L.trialEnds : L.until, longDate(r.ends_at));
        if (per.kind === 'grace' && per.grace_until) rows += kv(L.graceUntil, longDate(per.grace_until));
        if (lastPay) rows += kv(L.lastPay, money(lastPay.amount_tzs) + ' TZS · ' + lastPay.duration_months + ' ' + L.months);
        $('#sbKv').html(rows);
        $('#sbRemind').toggleClass('d-none', per.kind === 'no_end' || per.kind === 'grace');
        // Banner: the last 7 days, and after the end date.
        let banner = '', over = false;
        if (per.kind === 'grace') { banner = L.bOver.replace('%e', longDate(r.ends_at)).replace('%g', longDate(per.grace_until)); over = true; }
        else if (soon) {
            const k = st === 'trial' ? 'bTrial' : 'bPaid';
            banner = (d === 0 ? L[k + '0'] : d === 1 ? L[k + '1'] : L[k].replace('%d', d)).replace('%e', longDate(r.ends_at));
        }
        $('#sbBanner').toggleClass('d-none', !banner).toggleClass('over', over).toggleClass('soon', !over);
        $('#sbBannerText').text(banner);
        $('#sbBannerBtn').text(st === 'trial' ? L.choosePlan : L.renewNow);
        markCurrent();
    }
    function tile(id, n, sub, hot) {
        $(id).toggleClass('hot', hot).find('.n').text(n);
        $(id).find('.sub').text(sub);
    }

    function loadStatus() {
        return $.getJSON(API, { action: 'status' }).done(r => {
            if (!r.success) return;
            renderPlan(r);
            const pays = r.payments || [];
            fill('pay', tPay, pays.map((p, i) => [i + 1, dmy(p.recorded_at), money(p.amount_tzs), p.duration_months, dmy(p.starts_at), dmy(p.ends_at),
                p.source === 'vendor_invoice' ? `${L.auto} ${esc(p.source_ref)}` : L.manual]), L.ePay);
            cards('#cardsPayments', pays, p => `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${money(p.amount_tzs)} TZS</span><small class="text-muted">${dmy(p.recorded_at)}</small></div>
                <div class="small">${p.duration_months} ${L.months} · ${dmy(p.starts_at)} → ${dmy(p.ends_at)}</div>
                <div class="small text-muted">${p.source === 'vendor_invoice' ? `${L.auto} ${esc(p.source_ref)}` : L.manual}</div></div></div></div>`);
        });
    }

    // ── Plans: renew / change ──────────────────────────────────────────
    let services = [];
    const sel = {};
    function markCurrent() {
        $('.bl-plan').each(function () { $(this).find('.bl-cur').toggleClass('d-none', $(this).data('plan') !== curPlanKey); });
    }
    function renderService(s) {
        const box = $(`#svc${s.product_id}`);
        if (!s.plans || !s.plans.length) {
            box.html(`<div class="row g-2">${s.terms.map(t => `<div class="col-6 col-md-4"><button type="button" class="btn btn-outline-primary w-100 bl-term ${sel[s.product_id].term === String(t.term_id) ? 'sel' : ''}"
                data-svc="${s.product_id}" data-term="${esc(String(t.term_id))}"><div class="fw-bold">${esc(t.term_label)}</div><div class="small">${money(t.price)} TZS</div></button></div>`).join('')}</div>${footer(s)}`);
            recalc(s.product_id);
            return;
        }
        const pk = sel[s.product_id].plan;
        const terms = s.terms.filter(t => t.plan_key === pk);
        const term = terms.find(t => String(t.term_id) === sel[s.product_id].term);
        const extras = term ? (s.extras || []).filter(e => e.plan_price_id === term.plan_price_id) : [];
        box.html(`
            <div class="row g-2 mb-3">${s.plans.map(p => `<div class="col-12 col-sm-6 col-lg-4"><div class="card h-100 border p-3 bl-plan ${p.plan_key === pk ? 'sel' : ''}" data-svc="${s.product_id}" data-plan="${esc(p.plan_key)}">
                <div class="d-flex justify-content-between align-items-start"><span class="fw-bold fs-5">${esc(p.name)}</span>
                    <span>${p.is_popular ? `<span class="badge" style="background:#0d6efd;color:#fff;font-size:.6rem">${L.popular}</span>` : ''}
                    <span class="badge bl-cur d-none" style="background:#052c65;color:#fff;font-size:.6rem">${L.current}</span></span></div>
                ${p.tagline ? `<div class="small fw-semibold text-primary">${esc(p.tagline)}</div>` : ''}
                <div class="small text-muted mt-1">${L.users}: ${p.max_users === null ? L.unlimited : p.max_users} · ${L.shops}: ${p.max_shops === null ? L.unlimited : p.max_shops}</div>
                ${(p.modules || []).length ? `<div class="small text-muted" title="${esc(p.modules.join(', '))}"><i class="bi bi-grid me-1 text-primary"></i>${esc(L.modules.replace('%d', p.modules.length))}</div>` : ''}
                <div class="small mt-1">${items((p.items || []).slice(0, 4))}</div></div></div>`).join('')}</div>
            ${pk ? `<div class="small fw-bold text-muted mb-1">${L.period}</div><div class="row g-2 mb-3">${terms.map(t => `<div class="col-6 col-md-3"><button type="button" class="btn btn-outline-primary w-100 bl-term ${String(t.term_id) === sel[s.product_id].term ? 'sel' : ''}"
                data-svc="${s.product_id}" data-term="${esc(String(t.term_id))}"><div class="fw-bold small">${esc(t.term_label.replace(/^.*?—\s*/, ''))}</div><div class="small">${money(t.price)} TZS</div></button></div>`).join('')}</div>` : ''}
            ${extras.length ? `<div class="small fw-bold text-muted mb-1">${L.extras}</div>${extras.map(e => `<div class="d-flex align-items-center gap-2 mb-2">
                <input type="number" min="0" max="99" step="1" value="0" class="form-control form-control-sm bl-extra" style="width:80px" data-svc="${s.product_id}" data-tok="${esc(e.term_id)}" data-price="${e.price}">
                <span class="small">${esc(e.name)} <span class="text-muted">— ${money(e.price)} TZS ${L.perUnit}</span></span></div>`).join('')}` : ''}
            ${footer(s)}`);
        markCurrent();
        recalc(s.product_id);
    }
    const footer = s => `<div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top"><span class="small">${L.total}: <span class="fw-bold text-primary" id="tot${s.product_id}">—</span></span>
        <button type="button" class="btn btn-primary bl-send" data-svc="${s.product_id}"><i class="bi bi-send me-1"></i>${L.send}</button></div>`;
    function recalc(pid) {
        const s = services.find(x => x.product_id === pid), t = s && s.terms.find(x => String(x.term_id) === sel[pid].term);
        let tot = t ? Number(t.price) : 0;
        $(`.bl-extra[data-svc="${pid}"]`).each(function () { tot += Math.max(0, parseInt(this.value, 10) || 0) * Number($(this).data('price')); });
        $(`#tot${pid}`).text(t ? money(tot) + ' TZS' : '—');
    }
    function loadCatalog() {
        $.getJSON(API, { action: 'catalog' }).done(r => {
            if (!r.success) { $('#catalog').html(`<div class="small text-muted">${esc(r.message || L.unreachable)}</div>`); return; }
            services = r.services || [];
            if (!services.length) { $('#catalog').html(`<div class="sb-empty">${L.noServices}</div>`); return; }
            $('#catalog').html(services.map(s => `<div class="mb-3">${services.length > 1 ? `<div class="fw-bold mb-2">${esc(s.name)}</div>` : ''}<div id="svc${s.product_id}"></div></div>`).join(''));
            services.forEach(s => {
                const def = s.terms.find(t => t.is_default) || s.terms[0];
                const onCur = (s.plans || []).some(p => p.plan_key === curPlanKey);
                const start = onCur ? curPlanKey : (def ? def.plan_key || null : null);
                // Renewing: the plan's default period is picked so the total shows at once.
                const t0 = s.terms.find(t => t.plan_key === start && t.is_default) || s.terms.find(t => t.plan_key === start) || (!(s.plans || []).length ? def : null);
                sel[s.product_id] = { plan: start, term: t0 ? String(t0.term_id) : null };
                renderService(s);
            });
        }).fail(x => $('#catalog').html(`<div class="small text-muted">${esc((x.responseJSON || {}).message || L.unreachable)}</div>`));
    }
    $(document).on('click', '.bl-plan', function () {
        const pid = Number($(this).data('svc'));
        sel[pid] = { plan: String($(this).data('plan')), term: null };
        renderService(services.find(s => s.product_id === pid));
    });
    $(document).on('click', '.bl-term', function () {
        const pid = Number($(this).data('svc'));
        sel[pid].term = String($(this).data('term'));
        renderService(services.find(s => s.product_id === pid));
    });
    $(document).on('input change', '.bl-extra', function () {
        const v = parseInt(this.value, 10);
        if (isNaN(v) || v < 0) this.value = 0; else if (v > 99) this.value = 99; else if (String(v) !== this.value) this.value = v;
        recalc(Number($(this).data('svc')));
    });
    $(document).on('click', '.bl-send', function () {
        const b = $(this), pid = Number(b.data('svc')), s = services.find(x => x.product_id === pid);
        const t = s.terms.find(x => String(x.term_id) === sel[pid].term);
        if (!t) { Swal.fire({ icon: 'info', title: L.pickPeriod, confirmButtonColor: '#0d6efd' }); return; }
        const extras = {};
        $(`.bl-extra[data-svc="${pid}"]`).each(function () { const q = parseInt(this.value, 10) || 0; if (q > 0) extras[$(this).data('tok')] = q; });
        Swal.fire({ title: L.confirmQ, text: L.confirmTxt.replace('%s', $(`#tot${pid}`).text()).replace('%p', t.term_label),
            icon: 'question', showCancelButton: true, confirmButtonText: L.yes, cancelButtonText: L.cancel, confirmButtonColor: '#0d6efd' })
        .then(x => {
            if (!x.isConfirmed) return;
            const orig = b.html();
            b.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
            $.ajax({ url: API, type: 'POST', dataType: 'json', data: { action: 'request', _csrf: CSRF, product_id: pid, term_id: t.term_id, extras: JSON.stringify(extras) } })
                .done(r => {
                    if (r.success) {
                        loadDocs();
                        Swal.fire({ icon: 'success', title: r.request_number || r.quotation_number, text: r.message, confirmButtonColor: '#0d6efd' });
                    } else Swal.fire({ icon: 'error', title: L.error, text: r.message });
                })
                .fail(x => Swal.fire({ icon: 'error', title: L.error, text: (x.responseJSON || {}).message || L.unreachable }))
                .always(() => b.prop('disabled', false).html(orig));
        });
    });

    // ── Documents ──────────────────────────────────────────────────────
    function loadDocs() {
        $.getJSON(API, { action: 'documents' }).done(r => {
            if (!r.success) return;
            $('#notLinked').remove();
            if (r.linked === false) $('#tabInvoices').prepend(`<div class="alert alert-light border small" id="notLinked">${L.notLinked}</div>`);
            const inv = r.invoices || [], qt = r.quotations || [], wait = r.requests || [];
            $('#waitList').html(wait.length ? wait.map(w => `<div class="col-12 col-md-6"><div class="border rounded-3 p-3 h-100 bg-white">
                <div class="d-flex justify-content-between"><span class="fw-bold">${esc(w.number)}</span>${badge(w.status === 'declined' ? 'declined' : 'waiting')}</div>
                <div class="small">${dmy(w.date)} · ${money(w.total)} TZS</div><div class="bl-lines">${lines(w)}</div>
                ${w.status === 'declined' && w.reason ? `<div class="small text-muted mt-1"><i class="bi bi-info-circle me-1"></i>${esc(w.reason)}</div>` : ''}</div></div>`).join('')
                : `<div class="col-12"><div class="sb-empty">${L.eReq}</div></div>`);
            const waiting = wait.filter(w => w.status !== 'declined');
            $('#reqWaiting').text(waiting.length).toggleClass('d-none', !waiting.length);
            tile('#tileReq', waiting.length, waiting.length ? L.sinceX.replace('%n', waiting[0].number).replace('%d', longDate(waiting[0].date)) : L.noReq, waiting.length > 0);
            const owing = inv.filter(d => d.status === 'unpaid' || d.status === 'partial');
            const due = owing.reduce((a, d) => a + Number(d.balance || 0), 0);
            tile('#tileInv', owing.length, owing.length ? L.toPay.replace('%s', money(due)) : L.allPaid, owing.length > 0);
            const nInv = inv.filter(d => d.is_new).length, nQt = qt.filter(d => d.is_new).length;
            $('#newInv').text(nInv).toggleClass('d-none', !nInv); $('#newQt').text(nQt).toggleClass('d-none', !nQt);
            $('.bms-billing-badge').addClass('d-none');
            fill('inv', tInv, inv.map((d, i) => [i + 1, esc(d.number) + newChip(d), `<div class="bl-lines">${lines(d)}</div>`, dmy(d.date), dmy(d.due_date), money(d.total), money(d.balance), badge(d.status), gear(d.view_url)]), L.eInv);
            fill('qt', tQt, qt.map((d, i) => [i + 1, esc(d.number) + newChip(d), `<div class="bl-lines">${lines(d)}</div>`, dmy(d.date), dmy(d.valid_until), money(d.total), badge(d.status), gear(d.view_url)]), L.eQt);
            cards('#cardsInvoices', inv, d => `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${esc(d.number)}${newChip(d)}</span>${badge(d.status)}</div>
                <div class="bl-lines">${lines(d)}</div>
                <div class="small">${dmy(d.date)} · ${money(d.total)} TZS</div><div class="small text-muted">${esc(L.balance)} ${money(d.balance)}</div></div>${cardBtn(d.view_url)}</div></div>`);
            cards('#cardsQuotes', qt, d => `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${esc(d.number)}${newChip(d)}</span>${badge(d.status)}</div>
                <div class="bl-lines">${lines(d)}</div>
                <div class="small">${dmy(d.date)} · ${money(d.total)} TZS</div></div>${cardBtn(d.view_url)}</div></div>`);
        });
    }

    function contractActions(c) {
        let it = '';
        if (c.view_url) it += `<li><a class="dropdown-item py-2 rounded" href="${esc(c.view_url)}" target="_blank" rel="noopener"><i class="bi bi-eye text-primary me-2"></i>${L.view}</a></li>`;
        if (c.state === 'awaiting_customer') it += `<li><button class="dropdown-item py-2 rounded" data-sign="${c.contract_id}"><i class="bi bi-pen text-primary me-2"></i>${L.sign}</button></li>`;
        return it ? `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill me-1"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${it}</ul></div>` : '';
    }
    const cBadge = st => { const m = { signed: ['#052c65', '#fff'], awaiting_customer: ['#0d6efd', '#fff'], awaiting_bjp: ['#e9ecef', '#495057'] }; const [b, f] = m[st] || ['#e9ecef', '#495057']; return pill(L[st] || st, b, f); };
    function loadContracts() {
        $.getJSON(API, { action: 'contracts' }).done(r => {
            if (!r.success) return;
            const list = r.contracts || [];
            const toSign = list.filter(c => c.state === 'awaiting_customer').length;
            $('#contractsToSign').text(toSign).toggleClass('d-none', !toSign);
            tile('#tileCon', toSign, toSign ? L.signNow : L.nothingSign, toSign > 0);
            fill('con', tCon, list.map((c, i) => [i + 1, esc(c.code), esc(c.service), esc(c.period), dmy(c.period_start), dmy(c.period_end), cBadge(c.state), contractActions(c)]), L.eCon);
            cards('#cardsContracts', list, c => `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${esc(c.code)}</span>${cBadge(c.state)}</div>
                <div class="small">${esc(c.service)} · ${esc(c.period)}</div><div class="small text-muted">${dmy(c.period_start)} → ${dmy(c.period_end)}</div></div>
                ${(c.view_url || c.state === 'awaiting_customer') ? `<div class="card-footer bg-white border-top p-0"><div style="display:flex;gap:4px;padding:6px;">
                    ${c.view_url ? `<a class="btn btn-sm btn-outline-primary" style="flex:1;padding:3px 4px" href="${esc(c.view_url)}" target="_blank" rel="noopener"><i class="bi bi-eye"></i></a>` : ''}
                    ${c.state === 'awaiting_customer' ? `<button class="btn btn-sm btn-primary" style="flex:1;padding:3px 4px" data-sign="${c.contract_id}"><i class="bi bi-pen"></i></button>` : ''}
                </div></div>` : ''}</div></div>`);
        }).fail(() => tile('#tileCon', '—', L.unreachable, false));
    }
    $(document).on('click', '[data-sign]', function () {
        const id = $(this).data('sign');
        // Open the window first (a popup opened after an AJAX call is blocked), then point it at the signing page.
        const w = window.open('', '_blank');
        if (w) w.document.write(`<p style="font-family:sans-serif;padding:20px">${L.opening}</p>`);
        $.ajax({ url: API, type: 'POST', dataType: 'json', data: { action: 'contract_sign', _csrf: CSRF, contract_id: id } })
            .done(r => { if (r.success && r.sign_url) { if (w) w.location.href = r.sign_url; else window.location.href = r.sign_url; }
                         else { if (w) w.close(); Swal.fire({ icon: 'error', title: L.error, text: r.message }); } })
            .fail(x => { if (w) w.close(); Swal.fire({ icon: 'error', title: L.error, text: (x.responseJSON || {}).message || L.unreachable }); });
    });

    // The catalog opens on the plan the company is on, so it waits for status.
    loadStatus().always(loadCatalog); loadDocs(); loadContracts();
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../../footer.php'; ?>
