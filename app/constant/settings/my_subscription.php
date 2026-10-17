<?php
/**
 * My Subscription — a company's own BMS subscription with BJP (customer_subscription_plan.md Phase 5):
 * how long access runs, choose a period (BJP raises an approved quotation at once), and the
 * quotations / invoices BJP issued to this company. Admins only, on a company's own subdomain.
 */
require_once __DIR__ . '/../../../roots.php';
if (!isAdmin()) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
$tenantId = function_exists('bmsCurrentTenantId') ? bmsCurrentTenantId() : null;

$page_title = 'My Subscription';
require_once __DIR__ . '/../../../header.php';
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2" style="position:sticky;top:0;z-index:1020;background:#fff;">
        <h4 class="mb-0"><i class="bi bi-credit-card-2-front text-primary me-2"></i><?= t('My Subscription') ?></h4>
    </div>

<?php if ($tenantId === null): ?>
    <div class="alert alert-light border"><i class="bi bi-info-circle text-primary me-1"></i><?= t('Subscriptions are managed by the platform. Nothing to show on this installation.') ?></div>
<?php else: ?>
    <div class="row g-3 mb-4" id="subStats">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
            <div class="fs-5 fw-bold text-primary" id="stStatus">—</div><div class="small text-muted"><?= t('Status') ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
            <div class="fs-5 fw-bold text-primary" id="stEnds">—</div><div class="small text-muted"><?= t('Access until') ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
            <div class="fs-5 fw-bold" id="stDays">—</div><div class="small text-muted"><?= t('Days left') ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
            <div class="fs-5 fw-bold text-primary" id="stUnpaid">—</div><div class="small text-muted"><?= t('Unpaid invoices') ?></div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-2"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-arrow-repeat me-2"></i><?= t('Renew or extend') ?></h6></div>
        <div class="card-body">
            <p class="small text-muted mb-3"><?= t('Choose a period. BJP prepares a quotation straight away; pay it and your access is extended automatically — counted from the day your current access ends.') ?></p>
            <div class="row g-3" id="catalog"><div class="col-12 text-muted small"><?= t('Loading...') ?></div></div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabInvoices" type="button"><?= t('Invoices') ?></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabQuotes" type="button"><?= t('Quotations') ?></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPayments" type="button"><?= t('Payments') ?></button></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="tabInvoices">
            <div class="d-none d-md-block"><table id="tblInvoices" class="table table-hover align-middle w-100" data-bms-cards="off">
                <thead class="table-light"><tr><th>S/NO</th><th><?= t('Invoice') ?></th><th><?= t('Date') ?></th><th><?= t('Due') ?></th><th class="text-end"><?= t('Total') ?></th><th class="text-end"><?= t('Balance') ?></th><th><?= t('Status') ?></th><th class="text-end"><?= t('Actions') ?></th></tr></thead>
                <tbody></tbody></table></div>
            <div class="d-md-none row g-2" id="cardsInvoices"></div>
        </div>
        <div class="tab-pane fade" id="tabQuotes">
            <div class="d-none d-md-block"><table id="tblQuotes" class="table table-hover align-middle w-100" data-bms-cards="off">
                <thead class="table-light"><tr><th>S/NO</th><th><?= t('Quotation') ?></th><th><?= t('Date') ?></th><th><?= t('Valid until') ?></th><th class="text-end"><?= t('Total') ?></th><th><?= t('Status') ?></th><th class="text-end"><?= t('Actions') ?></th></tr></thead>
                <tbody></tbody></table></div>
            <div class="d-md-none row g-2" id="cardsQuotes"></div>
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
        'choose' => t('Choose'), 'confirmQ' => t('Request this period?'), 'confirmTxt' => t('BJP will prepare a quotation of %s for %p.'),
        'yes' => t('Yes, request'), 'cancel' => t('Cancel'), 'view' => t('View'), 'error' => t('Error'), 'none' => t('Nothing yet.'),
        'notLinked' => t('Your company is not yet linked to a customer account at BJP. Contact BJP to set it up — then your quotations and invoices appear here.'),
        'unreachable' => t('BJP could not be reached right now. Please try again later.'),
        'paid' => t('Paid'), 'partial' => t('Partly paid'), 'unpaid' => t('Unpaid'), 'approved' => t('Approved'), 'accepted' => t('Accepted'), 'cancelled' => t('Replaced'),
        'auto' => t('BJP invoice'), 'manual' => t('Recorded by BJP'), 'months' => t('months'),
        'noServices' => t('BJP has not published subscription periods yet.'),
    ]) ?>;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = n => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    const dmy = s => s ? String(s).slice(0, 10).split('-').reverse().join('/') : '—';
    const badge = (st) => {
        const map = { paid: ['#052c65', '#fff'], approved: ['#0d6efd', '#fff'], accepted: ['#052c65', '#fff'], partial: ['#cfe2ff', '#084298'],
                      unpaid: ['#e9ecef', '#495057'], cancelled: ['#6c757d', '#fff'] };
        const [bg, fg] = map[st] || ['#e9ecef', '#495057'];
        return `<span class="badge" style="background:${bg};color:${fg}">${esc(L[st] || st)}</span>`;
    };
    const gear = url => url ? `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill me-1"></i></button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2"><li><a class="dropdown-item py-2 rounded" href="${esc(url)}" target="_blank" rel="noopener"><i class="bi bi-eye text-primary me-2"></i>${L.view}</a></li></ul></div>` : '';
    const dt = (id, cols) => $(id).DataTable({ responsive: false, scrollX: true, pageLength: 25, order: [], dom: 'rtip',
        language: { emptyTable: L.none, zeroRecords: L.none }, columnDefs: [{ targets: cols, orderable: false }] });
    const tInv = dt('#tblInvoices', [0, 7]), tQt = dt('#tblQuotes', [0, 6]), tPay = dt('#tblPayments', [0]);
    const cards = (sel, list, fn) => $(sel).html(list.length ? list.map(fn).join('') : `<div class="col-12 text-center py-4 text-muted">${L.none}</div>`);
    const cardBtn = url => url ? `<div class="card-footer bg-white border-top p-0"><div style="display:flex;gap:4px;padding:6px;"><a class="btn btn-sm btn-outline-primary" style="flex:1;padding:3px 4px" href="${esc(url)}" target="_blank" rel="noopener"><i class="bi bi-eye"></i></a></div></div>` : '';

    function loadStatus() {
        $.getJSON(API, { action: 'status' }).done(r => {
            if (!r.success) return;
            const st = r.tenant.status;
            $('#stStatus').text(L[st] || st);
            $('#stEnds').text(r.ends_at ? dmy(r.ends_at) : L.noExpiry);
            const d = r.days_left;
            $('#stDays').text(d === null ? '—' : d).toggleClass('text-danger', d !== null && d <= 7).toggleClass('text-primary', d === null || d > 7);
            const pays = r.payments || [];
            tPay.clear().rows.add(pays.map((p, i) => [i + 1, dmy(p.recorded_at), money(p.amount_tzs), p.duration_months, dmy(p.starts_at), dmy(p.ends_at),
                p.source === 'vendor_invoice' ? `${L.auto} ${esc(p.source_ref)}` : L.manual])).draw();
            cards('#cardsPayments', pays, p => `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${money(p.amount_tzs)} TZS</span><small class="text-muted">${dmy(p.recorded_at)}</small></div>
                <div class="small">${p.duration_months} ${L.months} · ${dmy(p.starts_at)} → ${dmy(p.ends_at)}</div>
                <div class="small text-muted">${p.source === 'vendor_invoice' ? `${L.auto} ${esc(p.source_ref)}` : L.manual}</div></div></div></div>`);
        });
    }

    function loadCatalog() {
        $.getJSON(API, { action: 'catalog' }).done(r => {
            if (!r.success) { $('#catalog').html(`<div class="col-12 small text-muted">${esc(r.message || L.unreachable)}</div>`); return; }
            if (!(r.services || []).length) { $('#catalog').html(`<div class="col-12 small text-muted">${L.noServices}</div>`); return; }
            $('#catalog').html(r.services.map(s => s.terms.map(t => `
                <div class="col-6 col-md-3"><div class="card h-100 border ${t.is_default ? 'border-primary' : ''} text-center p-3">
                    <div class="small text-muted">${esc(s.name)}</div>
                    <div class="fw-bold">${esc(t.term_label)}</div>
                    <div class="fs-5 fw-bold text-primary my-1">${money(t.price)} <small class="fs-6 text-muted">TZS</small></div>
                    ${t.description ? `<div class="small text-muted mb-2">${esc(t.description)}</div>` : ''}
                    <button type="button" class="btn btn-sm btn-primary mt-auto" data-req="${s.product_id}" data-term="${t.term_id}"
                        data-label="${esc(t.term_label)}" data-price="${t.price}">${L.choose}</button>
                </div></div>`).join('')).join(''));
        }).fail(x => $('#catalog').html(`<div class="col-12 small text-muted">${esc((x.responseJSON || {}).message || L.unreachable)}</div>`));
    }

    function loadDocs() {
        $.getJSON(API, { action: 'documents' }).done(r => {
            if (!r.success) return;
            if (r.linked === false) { $('#tabInvoices').prepend(`<div class="alert alert-light border small">${L.notLinked}</div>`); }
            const inv = r.invoices || [], qt = r.quotations || [];
            $('#stUnpaid').text(inv.filter(i => i.status !== 'paid').length);
            tInv.clear().rows.add(inv.map((d, i) => [i + 1, esc(d.number), dmy(d.date), dmy(d.due_date), money(d.total), money(d.balance), badge(d.status), gear(d.view_url)])).draw();
            tQt.clear().rows.add(qt.map((d, i) => [i + 1, esc(d.number), dmy(d.date), dmy(d.valid_until), money(d.total), badge(d.status), gear(d.view_url)])).draw();
            cards('#cardsInvoices', inv, d => `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${esc(d.number)}</span>${badge(d.status)}</div>
                <div class="small">${dmy(d.date)} · ${money(d.total)} TZS</div><div class="small text-muted">Balance ${money(d.balance)}</div></div>${cardBtn(d.view_url)}</div></div>`);
            cards('#cardsQuotes', qt, d => `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${esc(d.number)}</span>${badge(d.status)}</div>
                <div class="small">${dmy(d.date)} · ${money(d.total)} TZS</div></div>${cardBtn(d.view_url)}</div></div>`);
        });
    }

    $(document).on('click', '[data-req]', function () {
        const b = $(this);
        Swal.fire({ title: L.confirmQ, text: L.confirmTxt.replace('%s', money(b.data('price')) + ' TZS').replace('%p', b.data('label')),
            icon: 'question', showCancelButton: true, confirmButtonText: L.yes, cancelButtonText: L.cancel, confirmButtonColor: '#0d6efd' })
        .then(x => {
            if (!x.isConfirmed) return;
            const orig = b.html();
            b.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
            $.ajax({ url: API, type: 'POST', dataType: 'json', data: { action: 'request', _csrf: CSRF, product_id: b.data('req'), term_id: b.data('term') } })
                .done(r => {
                    if (r.success) {
                        loadDocs();
                        Swal.fire({ icon: 'success', title: r.quotation_number, text: r.message, confirmButtonColor: '#0d6efd',
                            showCancelButton: !!r.view_url, cancelButtonText: L.view })
                            .then(z => { if (z.dismiss === Swal.DismissReason.cancel && r.view_url) window.open(r.view_url, '_blank', 'noopener'); });
                    } else Swal.fire({ icon: 'error', title: L.error, text: r.message });
                })
                .fail(x => Swal.fire({ icon: 'error', title: L.error, text: (x.responseJSON || {}).message || L.unreachable }))
                .always(() => b.prop('disabled', false).html(orig));
        });
    });

    loadStatus(); loadCatalog(); loadDocs();
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../../footer.php'; ?>
