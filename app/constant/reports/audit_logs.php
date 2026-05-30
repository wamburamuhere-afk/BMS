<?php
// app/constant/reports/audit_logs.php
// Professional System Audit Trail — AJAX (get_audit_logs.php) merged view of
// activity_logs + audit_logs. Log viewer (no charts): aligned DataTable, S/No,
// white+blue, Select2 filters, print fixes.
// Standards: .claude/ui-constants.md, i_e_print.md, .claude/security.md.
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
autoEnforcePermission('audit_logs');

$page_title = 'System Audit Trail';
includeHeader();

$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$users = $pdo->query("SELECT user_id, username FROM users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid py-4">
    <div class="print-header d-none d-print-block text-center mb-2">
        <h2 style="color:#0d6efd;font-weight:700;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">SYSTEM AUDIT TRAIL REPORT</h2>
        <p style="color:#444;margin:4px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">History of system activities &amp; data changes</p>
        <p style="color:#444;margin:3px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">Generated: <?= date('d M Y, h:i A') ?></p>
        <div style="border-bottom:3px solid #0d6efd;margin:10px 0 16px;"></div>
    </div>

    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-shield-lock me-2"></i>System Audit Trail</h2>
            <p class="text-muted mb-0">Activity &amp; data-change history for compliance</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="window.print()"><i class="bi bi-printer me-2"></i> Print</button>
        </div>
    </div>

    <div class="card border shadow-sm mb-4 d-print-none" style="border-color:#b6ccfe!important;border-radius:12px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-2"><label class="form-label small fw-bold text-muted text-uppercase mb-1">From</label>
                    <input type="date" name="start_date" id="f-from" class="form-control" value="<?= htmlspecialchars($start_date) ?>"></div>
                <div class="col-md-2"><label class="form-label small fw-bold text-muted text-uppercase mb-1">To</label>
                    <input type="date" name="end_date" id="f-to" class="form-control" value="<?= htmlspecialchars($end_date) ?>"></div>
                <div class="col-md-3"><label class="form-label small fw-bold text-muted text-uppercase mb-1">User</label>
                    <select name="user_id" id="f-user" class="form-select" style="width:100%">
                        <option value="">All Staff</option>
                        <?php foreach ($users as $u): ?><option value="<?= (int)$u['user_id'] ?>"><?= safe_output($u['username']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-2"><label class="form-label small fw-bold text-muted text-uppercase mb-1">Log Type</label>
                    <select name="log_type" id="f-type" class="form-select" style="width:100%">
                        <option value="">Both Types</option>
                        <option value="activity">Activity</option>
                        <option value="audit">Audit (Data Changes)</option>
                    </select></div>
                <div class="col-md-2"><label class="form-label small fw-bold text-muted text-uppercase mb-1">Action</label>
                    <input type="text" name="action" id="f-action" class="form-control" placeholder="Login, Delete…"></div>
                <div class="col-md-1"><button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-filter"></i></button></div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4" id="summaryCards">
        <?php foreach ([['Total Entries','stat-total'],['Activity Logs','stat-activity'],['Audit Logs','stat-audit'],['Date Range','stat-range']] as $c): ?>
            <div class="col-6 col-md-3"><div class="card h-100" style="background:#e7f0ff;border:1px solid #b6ccfe;border-radius:12px;">
                <div class="card-body p-3 text-center"><p class="text-muted small text-uppercase fw-bold mb-1"><?= $c[0] ?></p>
                <h4 class="fw-bold mb-0" id="<?= $c[1] ?>" style="color:#0d6efd;font-size:<?= $c[1]==='stat-range'?'.95rem':'1.5rem' ?>;">—</h4></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="alert d-print-none" id="capNote" style="display:none;background:#cfe2ff;color:#084298;border:1px solid #b6ccfe;border-radius:10px;font-size:.85rem;">
        <i class="bi bi-info-circle me-1"></i> Showing the 2,000 most recent entries for this filter. Narrow the date range or filters to see older entries.
    </div>

    <div class="card border shadow-sm" style="border-color:#b6ccfe!important;border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white border-0"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-list-columns-reverse me-2"></i>Audit Trail</h6></div>
        <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover align-middle mb-0 w-100" id="auditTable">
                <thead class="table-light"><tr>
                    <th class="ps-3">S/No</th><th>Date &amp; Time</th><th>User</th>
                    <th class="text-center">Type</th><th>Action</th><th>Details</th><th class="pe-3">IP Address</th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div></div>
    </div>
</div>

<style>
    .card { border-radius: 12px; }
    #auditTable thead th { border-top: none; font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
    .badge-type { font-size: .66rem; padding: .3em .6em; border-radius: 6px; }
    #auditTable td { font-size: .82rem; }
    @media print {
        .d-print-none, .dataTables_filter, .dataTables_paginate, .dataTables_info, .dataTables_length { display: none !important; }
        .table-responsive { overflow: visible !important; }
        .dataTables_scroll, .dataTables_scrollHead, .dataTables_scrollBody { overflow: visible !important; }
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        #summaryCards .card { border: 1px solid #b6ccfe !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .card-header { background: #fff !important; }
        #auditTable { border: 1px solid #000 !important; }
        #auditTable th { background-color: #f1f5ff !important; border: 1px solid #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        #auditTable td { border: 1px solid #dee2e6 !important; }
        .badge-type { border: 1px solid #999 !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<script>
$(function () {
    const DATA_URL = '<?= buildUrl('api/account/get_audit_logs.php') ?>';
    const esc = t => $('<div>').text(t == null ? '' : t).html();
    function typeBadge(t) {
        const audit = (t === 'Audit');
        return `<span class="badge-type" style="background:${audit?'#052c65':'#cfe2ff'};color:${audit?'#fff':'#084298'};">${(t||'').toUpperCase()}</span>`;
    }

    $('#f-user, #f-type').select2({ theme: 'bootstrap-5', allowClear: true, width: '100%' });

    const table = $('#auditTable').DataTable({
        responsive: false, scrollX: false, pageLength: 50, order: [[0, 'asc']],
        dom: 'rtip', columnDefs: [{ targets: 3, className: 'text-center' }],
        language: { emptyTable: 'No log entries for this filter.', zeroRecords: 'No matching records.' }
    });

    function loadReport() {
        const params = {
            start_date: $('#f-from').val(), end_date: $('#f-to').val(),
            user_id: $('#f-user').val() || '', log_type: $('#f-type').val() || '',
            action: $('#f-action').val() || ''
        };
        $.getJSON(DATA_URL, params).done(function (res) {
            if (!res || !res.success) { Swal.fire({ icon:'error', title:'Error', text:(res&&res.message)||'Could not load the trail.' }); return; }
            const s = res.summary;
            $('#stat-total').text(Number(s.total).toLocaleString());
            $('#stat-activity').text(Number(s.activity).toLocaleString());
            $('#stat-audit').text(Number(s.audit).toLocaleString());
            $('#stat-range').text($('#f-from').val() + '  →  ' + $('#f-to').val());
            $('#capNote').toggle(!!s.capped);
            table.clear();
            res.rows.forEach((r, i) => table.row.add([
                i + 1,
                r.created_at ? new Date(r.created_at).toLocaleString() : '',
                esc(r.username),
                typeBadge(r.log_type),
                esc(r.action),
                esc(r.description) + (r.entity_type ? ` <span class="text-muted">(${esc(r.entity_type)})</span>` : ''),
                esc(r.ip_address || '—')
            ]));
            table.draw();
        }).fail(() => Swal.fire({ icon:'error', title:'Error', text:'Server error loading the trail.' }));
    }

    $('#filterForm').on('submit', e => { e.preventDefault(); loadReport(); });
    $('#f-user, #f-type').on('change', loadReport);
    loadReport();
    if (typeof logReportAction === 'function') logReportAction('Viewed Audit Trail', 'Loaded system audit trail report');
});
</script>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ?>
