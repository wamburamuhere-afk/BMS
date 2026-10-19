<?php
/**
 * Billing — the company's own subscription with BJP (service_plans_plan.md Phase 5b, D8/D10;
 * replaces My Subscription). Top-level menu, company admins only, on the company's own subdomain.
 *
 *   - the plan it is on, what it includes, limits + extras bought, a change waiting for period end
 *   - renew / change plan: pick a plan, a period and extras → a request BJP reviews (D7)
 *   - requests still waiting for BJP, then the quotations / invoices BJP approved, payments, contracts
 */
require_once __DIR__ . '/../../../roots.php';
if (!isAdmin()) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
$tenantId = function_exists('bmsCurrentTenantId') ? bmsCurrentTenantId() : null;

$page_title = 'Subscription';
require_once __DIR__ . '/../../../header.php';
$statCard = 'background:#e7f0ff;border:1px solid #b6ccfe !important;';
?>
<style>
    .bl-plan { cursor:pointer; transition:border-color .15s, box-shadow .15s; }
    .bl-plan.sel { border-color:#0d6efd !important; box-shadow:0 0 0 .2rem rgba(13,110,253,.15); }
    .bl-term.sel { background:#0d6efd; color:#fff; border-color:#0d6efd; }
    .bl-new { background:#0d6efd; color:#fff; font-size:.65rem; }
    .bl-lines { font-size:.78rem; color:#6c757d; }
</style>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2" style="position:sticky;top:0;z-index:1020;background:#fff;">
        <h4 class="mb-0"><i class="bi bi-credit-card-2-front text-primary me-2"></i><?= t('Subscription') ?></h4>
    </div>

<?php if ($tenantId === null): ?>
    <div class="alert alert-light border"><i class="bi bi-info-circle text-primary me-1"></i><?= t('Subscriptions are managed by the platform. Nothing to show on this installation.') ?></div>
<?php else: ?>
    <div class="row g-3 mb-4" id="subStats">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="<?= $statCard ?>">
            <div class="fs-5 fw-bold text-primary" id="stStatus">—</div><div class="small text-muted"><?= t('Status') ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="<?= $statCard ?>">
            <div class="fs-5 fw-bold text-primary" id="stPlan">—</div><div class="small text-muted"><?= t('Plan') ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="<?= $statCard ?>">
            <div class="fs-5 fw-bold text-primary" id="stEnds">—</div><div class="small text-muted"><?= t('Access until') ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="<?= $statCard ?>">
            <div class="fs-5 fw-bold" id="stDays">—</div><div class="small text-muted"><?= t('Days left') ?></div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100" id="curPlan">
                <div class="card-header bg-white py-2"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-box-seam me-2"></i><?= t('Your plan') ?></h6></div>
                <div class="card-body small" id="curPlanBody"><span class="text-muted"><?= t('Loading...') ?></span></div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-arrow-repeat me-2"></i><?= t('Renew or change plan') ?></h6></div>
                <div class="card-body">
                    <p class="small text-muted mb-3"><?= t('Choose a plan and a period, add extras if you need them, and send the request. BJP reviews it and the quotation appears below once approved. Pay the invoice and the plan applies automatically — an upgrade at once, a smaller plan when your current period ends.') ?></p>
                    <div id="catalog"><div class="text-muted small"><?= t('Loading...') ?></div></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4 d-none" id="waitCard">
        <div class="card-header bg-white py-2"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-hourglass-split me-2"></i><?= t('Requests waiting for BJP') ?></h6></div>
        <div class="card-body p-2"><div class="row g-2" id="waitList"></div></div>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabInvoices" type="button"><?= t('Invoices') ?> <span class="badge rounded-pill bl-new d-none" id="newInv">0</span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabQuotes" type="button"><?= t('Quotations') ?> <span class="badge rounded-pill bl-new d-none" id="newQt">0</span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPayments" type="button"><?= t('Payments') ?></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabContracts" type="button"><?= t('Contracts') ?> <span class="badge rounded-pill d-none" id="contractsToSign" style="background:#0d6efd;color:#fff">0</span></button></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="tabInvoices">
            <div class="d-none d-md-block"><table id="tblInvoices" class="table table-hover align-middle w-100" data-bms-cards="off">
                <thead class="table-light"><tr><th>S/NO</th><th><?= t('Invoice') ?></th><th><?= t('For') ?></th><th><?= t('Date') ?></th><th><?= t('Due') ?></th><th class="text-end"><?= t('Total') ?></th><th class="text-end"><?= t('Balance') ?></th><th><?= t('Status') ?></th><th class="text-end"><?= t('Actions') ?></th></tr></thead>
                <tbody></tbody></table></div>
            <div class="d-md-none row g-2" id="cardsInvoices"></div>
        </div>
        <div class="tab-pane fade" id="tabQuotes">
            <div class="d-none d-md-block"><table id="tblQuotes" class="table table-hover align-middle w-100" data-bms-cards="off">
                <thead class="table-light"><tr><th>S/NO</th><th><?= t('Quotation') ?></th><th><?= t('For') ?></th><th><?= t('Date') ?></th><th><?= t('Valid until') ?></th><th class="text-end"><?= t('Total') ?></th><th><?= t('Status') ?></th><th class="text-end"><?= t('Actions') ?></th></tr></thead>
                <tbody></tbody></table></div>
            <div class="d-md-none row g-2" id="cardsQuotes"></div>
        </div>
        <div class="tab-pane fade" id="tabContracts">
            <div class="d-none d-md-block"><table id="tblContracts" class="table table-hover align-middle w-100" data-bms-cards="off">
                <thead class="table-light"><tr><th>S/NO</th><th><?= t('Contract') ?></th><th><?= t('Service') ?></th><th><?= t('Period') ?></th><th><?= t('From') ?></th><th><?= t('To') ?></th><th><?= t('Status') ?></th><th class="text-end"><?= t('Actions') ?></th></tr></thead>
                <tbody></tbody></table></div>
            <div class="d-md-none row g-2" id="cardsContracts"></div>
        </div>
        <div class="tab-pane fade" id="tabPayments">
            <div class="d-none d-md-block"><table id="tblPayments" class="table table-hover align-middle w-100" data-bms-cards="off">
                <thead class="table-light"><tr><th>S/NO</th><th><?= t('Recorded') ?></th><th class="text-end"><?= t('Amount (TZS)') ?></th><th><?= t('Months') ?></th><th><?= t('From') ?></th><th><?= t('To') ?></th><th><?= t('Reference') ?></th></tr></thead>
                <tbody></tbody></table></div>
            <div class="d-md-none row g-2" id="cardsPayments"></div>
        </div>
    </div>
<?php endif; ?>
</div>

<?php if ($tenantId !== null): ?>
<script>
$(function () {
    const API = '<?= buildUrl('api/my_subscription.php') ?>', CSRF = <?= json_encode(csrf_token()) ?>;
    const L = <?= json_encode([
        'trial' => t('Trial'), 'active' => t('Active'), 'suspended' => t('Suspended'), 'noExpiry' => t('No expiry'),
        'choose' => t('Choose'), 'confirmQ' => t('Send this request?'), 'confirmTxt' => t('BJP will review a quotation of %s for %p.'),
        'yes' => t('Yes, send'), 'cancel' => t('Cancel'), 'view' => t('View'), 'error' => t('Error'), 'none' => t('Nothing yet.'),
        'notLinked' => t('Your company is not yet linked to a customer account at BJP. You can still send a request — BJP sets up the link when preparing your quotation, then your quotations and invoices appear here.'),
        'unreachable' => t('BJP could not be reached right now. Please try again later.'),
        'paid' => t('Paid'), 'partial' => t('Partly paid'), 'unpaid' => t('Unpaid'), 'approved' => t('Approved'), 'accepted' => t('Accepted'), 'cancelled' => t('Replaced'),
        'waiting' => t('Waiting for BJP'), 'declined' => t('Declined by BJP'), 'new' => t('New'),
        'auto' => t('BJP invoice'), 'manual' => t('Recorded by BJP'), 'months' => t('months'),
        'noServices' => t('BJP has not published subscription periods yet.'),
        'sign' => t('Sign'), 'awaiting_customer' => t('Waiting for your signature'), 'signed' => t('Signed'),
        'awaiting_bjp' => t('Being prepared by BJP'), 'opening' => t('Opening the signing page...'),
        'users' => t('Users'), 'shops' => t('Shops'), 'unlimited' => t('Unlimited'), 'extra' => t('extra'),
        'scheduled' => t('Changes to %n on %d'), 'current' => t('Current'), 'popular' => t('Popular'),
        'period' => t('Period'), 'extras' => t('Extras'), 'perUnit' => t('each'), 'total' => t('Total'),
        'send' => t('Send request'), 'pickPeriod' => t('Choose a period first.'), 'noPlan' => t('No plan yet'),
        'modules' => t('%d modules'),
    ]) ?>;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = n => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    const dmy = s => s ? String(s).slice(0, 10).split('-').reverse().join('/') : '—';
    const badge = (st) => {
        const map = { paid: ['#052c65', '#fff'], approved: ['#0d6efd', '#fff'], accepted: ['#052c65', '#fff'], partial: ['#cfe2ff', '#084298'],
                      unpaid: ['#e9ecef', '#495057'], cancelled: ['#6c757d', '#fff'], waiting: ['#bfdbfe', '#1e3a8a'], declined: ['#6c757d', '#fff'] };
        const [bg, fg] = map[st] || ['#e9ecef', '#495057'];
        return `<span class="badge" style="background:${bg};color:${fg}">${esc(L[st] || st)}</span>`;
    };
    const newChip = d => d.is_new ? ` <span class="badge rounded-pill bl-new">${L.new}</span>` : '';
    const lines = d => (d.lines || []).map(l => esc(l.name) + (Number(l.qty) !== 1 ? ` × ${Number(l.qty)}` : '') + (l.term ? ` <span class="text-primary">(${esc(l.term)})</span>` : '')).join('<br>');
    const gear = url => url ? `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill me-1"></i></button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2"><li><a class="dropdown-item py-2 rounded" href="${esc(url)}" target="_blank" rel="noopener"><i class="bi bi-eye text-primary me-2"></i>${L.view}</a></li></ul></div>` : '';
    const dt = (id, cols) => $(id).DataTable({ responsive: false, scrollX: true, pageLength: 25, order: [], dom: 'rtip',
        language: { emptyTable: L.none, zeroRecords: L.none }, columnDefs: [{ targets: cols, orderable: false }] });
    const tInv = dt('#tblInvoices', [0, 8]), tQt = dt('#tblQuotes', [0, 7]), tPay = dt('#tblPayments', [0]), tCon = dt('#tblContracts', [0, 7]);
    const cards = (sel, list, fn) => $(sel).html(list.length ? list.map(fn).join('') : `<div class="col-12 text-center py-4 text-muted">${L.none}</div>`);
    const cardBtn = url => url ? `<div class="card-footer bg-white border-top p-0"><div style="display:flex;gap:4px;padding:6px;"><a class="btn btn-sm btn-outline-primary" style="flex:1;padding:3px 4px" href="${esc(url)}" target="_blank" rel="noopener"><i class="bi bi-eye"></i></a></div></div>` : '';
    const limit = (max, extra, label) => `<div class="d-flex justify-content-between border-bottom py-1"><span>${label}</span><span class="fw-semibold">${max === null ? L.unlimited : max}${extra > 0 ? ` <span class="text-primary">(+${extra} ${L.extra})</span>` : ''}</span></div>`;
    const items = list => (list || []).map(i => `<div class="d-flex justify-content-between py-1"><span><i class="bi bi-check2 text-primary me-1"></i>${esc(i.label)}</span>${i.value ? `<span class="text-muted">${esc(i.value)}</span>` : ''}</div>`).join('');
    let curPlanKey = null;

    function loadStatus() {
        return $.getJSON(API, { action: 'status' }).done(r => {
            if (!r.success) return;
            const st = r.tenant.status;
            $('#stStatus').html(esc(L[st] || st) + (st === 'trial' ? ' <span class="badge" style="background:#cfe2ff;color:#084298;font-size:.6rem">' + L.trial + '</span>' : ''));
            $('#stEnds').text(r.ends_at ? dmy(r.ends_at) : L.noExpiry);
            const d = r.days_left;
            $('#stDays').text(d === null ? '—' : d).toggleClass('text-danger', d !== null && d <= 7).toggleClass('text-primary', d === null || d > 7);
            const p = r.plan;
            curPlanKey = p ? p.plan_key : null;
            $('#stPlan').text(p && p.name ? p.name : L.noPlan);
            $('#curPlanBody').html(p ? `
                <div class="fs-5 fw-bold mb-2">${esc(p.name || L.noPlan)}</div>
                ${limit(p.max_users, p.extra_users, L.users)}${limit(p.max_shops, p.extra_shops, L.shops)}
                <div class="mt-2">${items(p.items)}</div>
                ${p.scheduled ? `<div class="alert alert-light border small mt-2 mb-0"><i class="bi bi-calendar-event text-primary me-1"></i>${esc(L.scheduled.replace('%n', p.scheduled.name).replace('%d', dmy(p.scheduled.at)))}</div>` : ''}`
                : `<span class="text-muted">${L.noPlan}</span>`);
            markCurrent();
            const pays = r.payments || [];
            tPay.clear().rows.add(pays.map((p, i) => [i + 1, dmy(p.recorded_at), money(p.amount_tzs), p.duration_months, dmy(p.starts_at), dmy(p.ends_at),
                p.source === 'vendor_invoice' ? `${L.auto} ${esc(p.source_ref)}` : L.manual])).draw();
            cards('#cardsPayments', pays, p => `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${money(p.amount_tzs)} TZS</span><small class="text-muted">${dmy(p.recorded_at)}</small></div>
                <div class="small">${p.duration_months} ${L.months} · ${dmy(p.starts_at)} → ${dmy(p.ends_at)}</div>
                <div class="small text-muted">${p.source === 'vendor_invoice' ? `${L.auto} ${esc(p.source_ref)}` : L.manual}</div></div></div></div>`);
        });
    }

    // ── Renew / change plan ────────────────────────────────────────────
    let services = [];
    const sel = {};   // product_id → { plan, term }
    function markCurrent() {
        $('.bl-plan').each(function () { $(this).find('.bl-cur').toggleClass('d-none', $(this).data('plan') !== curPlanKey); });
    }
    function renderService(s) {
        const box = $(`#svc${s.product_id}`);
        if (!s.plans || !s.plans.length) {
            // A service sold by period only (no plans).
            box.html(`<div class="row g-2">${s.terms.map(t => `<div class="col-6 col-md-4"><button type="button" class="btn btn-outline-primary w-100 bl-term ${sel[s.product_id].term === String(t.term_id) ? 'sel' : ''}"
                data-svc="${s.product_id}" data-term="${esc(String(t.term_id))}"><div class="fw-bold">${esc(t.term_label)}</div><div class="small">${money(t.price)} TZS</div></button></div>`).join('')}</div>${footer(s)}`);
            return;
        }
        const pk = sel[s.product_id].plan;
        const terms = s.terms.filter(t => t.plan_key === pk);
        const term = terms.find(t => String(t.term_id) === sel[s.product_id].term);
        const extras = term ? (s.extras || []).filter(e => e.plan_price_id === term.plan_price_id) : [];
        box.html(`
            <div class="row g-2 mb-3">${s.plans.map(p => `<div class="col-6 col-md-4"><div class="card h-100 border p-2 bl-plan ${p.plan_key === pk ? 'sel' : ''}" data-svc="${s.product_id}" data-plan="${esc(p.plan_key)}">
                <div class="d-flex justify-content-between align-items-start"><span class="fw-bold">${esc(p.name)}</span>
                    <span>${p.is_popular ? `<span class="badge" style="background:#0d6efd;color:#fff;font-size:.6rem">${L.popular}</span>` : ''}
                    <span class="badge bl-cur d-none" style="background:#052c65;color:#fff;font-size:.6rem">${L.current}</span></span></div>
                ${p.tagline ? `<div class="small fw-semibold text-primary">${esc(p.tagline)}</div>` : ''}
                <div class="small text-muted">${L.users}: ${p.max_users === null ? L.unlimited : p.max_users} · ${L.shops}: ${p.max_shops === null ? L.unlimited : p.max_shops}</div>
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
        <button type="button" class="btn btn-primary btn-sm bl-send" data-svc="${s.product_id}"><i class="bi bi-send me-1"></i>${L.send}</button></div>`;
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
            if (!services.length) { $('#catalog').html(`<div class="small text-muted">${L.noServices}</div>`); return; }
            $('#catalog').html(services.map(s => `<div class="mb-3">${services.length > 1 ? `<div class="fw-bold mb-2">${esc(s.name)}</div>` : ''}<div id="svc${s.product_id}"></div></div>`).join(''));
            services.forEach(s => {
                // Start on the plan the company is on (if offered), else the default period's plan.
                const def = s.terms.find(t => t.is_default) || s.terms[0];
                const start = (s.plans || []).some(p => p.plan_key === curPlanKey) ? curPlanKey : (def ? def.plan_key || null : null);
                sel[s.product_id] = { plan: start, term: null };
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
            if (r.linked === false) { $('#tabInvoices').prepend(`<div class="alert alert-light border small" id="notLinked">${L.notLinked}</div>`); }
            const inv = r.invoices || [], qt = r.quotations || [], wait = r.requests || [];
            $('#waitCard').toggleClass('d-none', !wait.length);
            $('#waitList').html(wait.map(w => `<div class="col-12 col-md-6"><div class="border rounded p-2 h-100">
                <div class="d-flex justify-content-between"><span class="fw-bold">${esc(w.number)}</span>${badge(w.status === 'declined' ? 'declined' : 'waiting')}</div>
                <div class="small">${dmy(w.date)} · ${money(w.total)} TZS</div><div class="bl-lines">${lines(w)}</div>
                ${w.status === 'declined' && w.reason ? `<div class="small text-muted mt-1"><i class="bi bi-info-circle me-1"></i>${esc(w.reason)}</div>` : ''}</div></div>`).join(''));
            const nInv = inv.filter(d => d.is_new).length, nQt = qt.filter(d => d.is_new).length;
            $('#newInv').text(nInv).toggleClass('d-none', !nInv); $('#newQt').text(nQt).toggleClass('d-none', !nQt);
            $('.bms-billing-badge').addClass('d-none');   // seen now
            tInv.clear().rows.add(inv.map((d, i) => [i + 1, esc(d.number) + newChip(d), `<div class="bl-lines">${lines(d)}</div>`, dmy(d.date), dmy(d.due_date), money(d.total), money(d.balance), badge(d.status), gear(d.view_url)])).draw();
            tQt.clear().rows.add(qt.map((d, i) => [i + 1, esc(d.number) + newChip(d), `<div class="bl-lines">${lines(d)}</div>`, dmy(d.date), dmy(d.valid_until), money(d.total), badge(d.status), gear(d.view_url)])).draw();
            cards('#cardsInvoices', inv, d => `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${esc(d.number)}${newChip(d)}</span>${badge(d.status)}</div>
                <div class="bl-lines">${lines(d)}</div>
                <div class="small">${dmy(d.date)} · ${money(d.total)} TZS</div><div class="small text-muted">Balance ${money(d.balance)}</div></div>${cardBtn(d.view_url)}</div></div>`);
            cards('#cardsQuotes', qt, d => `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${esc(d.number)}${newChip(d)}</span>${badge(d.status)}</div>
                <div class="bl-lines">${lines(d)}</div>
                <div class="small">${dmy(d.date)} · ${money(d.total)} TZS</div></div>${cardBtn(d.view_url)}</div></div>`);
        });
    }

    function contractActions(c) {
        let items = '';
        if (c.view_url) items += `<li><a class="dropdown-item py-2 rounded" href="${esc(c.view_url)}" target="_blank" rel="noopener"><i class="bi bi-eye text-primary me-2"></i>${L.view}</a></li>`;
        if (c.state === 'awaiting_customer') items += `<li><button class="dropdown-item py-2 rounded" data-sign="${c.contract_id}"><i class="bi bi-pen text-primary me-2"></i>${L.sign}</button></li>`;
        return items ? `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill me-1"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul></div>` : '';
    }
    const cBadge = st => { const m = { signed: ['#052c65', '#fff'], awaiting_customer: ['#0d6efd', '#fff'], awaiting_bjp: ['#e9ecef', '#495057'] }; const [b, f] = m[st] || ['#e9ecef', '#495057'];
        return `<span class="badge" style="background:${b};color:${f}">${esc(L[st] || st)}</span>`; };
    function loadContracts() {
        $.getJSON(API, { action: 'contracts' }).done(r => {
            if (!r.success) return;
            const list = r.contracts || [];
            const toSign = list.filter(c => c.state === 'awaiting_customer').length;
            $('#contractsToSign').text(toSign).toggleClass('d-none', !toSign);
            tCon.clear().rows.add(list.map((c, i) => [i + 1, esc(c.code), esc(c.service), esc(c.period), dmy(c.period_start), dmy(c.period_end), cBadge(c.state), contractActions(c)])).draw();
            cards('#cardsContracts', list, c => `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${esc(c.code)}</span>${cBadge(c.state)}</div>
                <div class="small">${esc(c.service)} · ${esc(c.period)}</div><div class="small text-muted">${dmy(c.period_start)} → ${dmy(c.period_end)}</div></div>
                ${(c.view_url || c.state === 'awaiting_customer') ? `<div class="card-footer bg-white border-top p-0"><div style="display:flex;gap:4px;padding:6px;">
                    ${c.view_url ? `<a class="btn btn-sm btn-outline-primary" style="flex:1;padding:3px 4px" href="${esc(c.view_url)}" target="_blank" rel="noopener"><i class="bi bi-eye"></i></a>` : ''}
                    ${c.state === 'awaiting_customer' ? `<button class="btn btn-sm btn-primary" style="flex:1;padding:3px 4px" data-sign="${c.contract_id}"><i class="bi bi-pen"></i></button>` : ''}
                </div></div>` : ''}</div></div>`);
        });
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
