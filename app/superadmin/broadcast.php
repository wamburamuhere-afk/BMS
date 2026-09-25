<?php
/**
 * app/superadmin/broadcast.php — compose and send broadcast emails to tenants.
 *
 * Audience options are evaluated at compose time; the actual send is handled
 * by actions/superadmin_broadcast.php after a confirmation step.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me    = currentSuperadmin();
$error = null;

// Load audience building data
$industries = $plans = [];
$broadcastHistory = [];
try {
    $ctrl = getControlPdo();

    $industries = $ctrl->query("
        SELECT DISTINCT industry FROM tenants
        WHERE industry IS NOT NULL AND industry != ''
        ORDER BY industry
    ")->fetchAll(\PDO::FETCH_COLUMN);

    // Plans (for "by plan" audience)
    if (function_exists('listPlans')) {
        $plans = array_values(array_filter(listPlans(), fn($p) => $p['plan_key'] !== 'blank'));
    }

    $broadcastHistory = $ctrl->query("
        SELECT bl.*, sa.email AS sender_email
          FROM broadcast_log bl
          LEFT JOIN superadmins sa ON sa.id = bl.sent_by
         ORDER BY bl.sent_at DESC
         LIMIT 50
    ")->fetchAll();
} catch (\Throwable $e) {
    error_log('broadcast.php: ' . $e->getMessage());
    $error = 'Could not load broadcast data.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Broadcast | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
.preview-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; min-height: 100px; padding: 1rem; white-space: pre-wrap; font-size: .9rem; }
</style>
</head>
<body>

<?php renderSuperadminHeader('broadcast', $me); ?>

<div class="container-fluid p-3" style="max-width:1200px">

    <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Compose column -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold py-3">
                    <i class="bi bi-megaphone text-primary me-2"></i>Compose Broadcast
                </div>
                <div class="card-body">
                    <form id="broadcastForm" autocomplete="off">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Audience <span class="text-danger">*</span></label>
                            <select name="audience" id="f-audience" class="form-select" required>
                                <option value="">— Choose audience —</option>
                                <option value="all_active">All active tenants</option>
                                <option value="all_trial">All trial tenants</option>
                                <option value="trial_expiring_7d">Trial expiring ≤7 days</option>
                                <?php foreach ($plans as $p): ?>
                                <option value="plan_<?= (int)$p['id'] ?>">Plan: <?= htmlspecialchars($p['name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                                <?php foreach ($industries as $ind): ?>
                                <option value="industry_<?= htmlspecialchars($ind, ENT_QUOTES) ?>">Industry: <?= htmlspecialchars(ucfirst($ind), ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Estimated recipients: <strong id="recipientCount">—</strong>
                                <span class="text-muted small" id="recipientNote">(excludes unsubscribed)</span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label>
                            <input type="text" name="subject" id="f-subject" class="form-control" maxlength="200" required
                                   placeholder="e.g. New feature: Expense approvals are here">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Message body <span class="text-danger">*</span></label>
                            <textarea name="body" id="f-body" class="form-control font-monospace" rows="8" required
                                      placeholder="Write your message here. Plain text. Use blank lines to separate paragraphs."></textarea>
                            <div class="form-text">Plain text. Each tenant's name and subdomain will be shown automatically in the email wrapper.</div>
                        </div>

                        <button type="submit" class="btn btn-primary" id="btnSend">
                            <i class="bi bi-send me-1"></i> Review &amp; Send
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Preview column -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold py-3">
                    <i class="bi bi-eye text-secondary me-2"></i>Live Preview
                </div>
                <div class="card-body">
                    <p class="text-muted small fw-semibold mb-1">Subject:</p>
                    <p id="prevSubject" class="fw-semibold mb-3 text-dark">—</p>
                    <p class="text-muted small fw-semibold mb-1">Body:</p>
                    <div id="prevBody" class="preview-box text-muted small">Start typing to see a preview…</div>
                </div>
            </div>
        </div>
    </div>

    <!-- History -->
    <?php if ($broadcastHistory): ?>
    <div class="mt-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-clock-history text-secondary me-2"></i>Broadcast History
            </div>
            <div class="table-responsive">
                <table id="historyTable" class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Subject</th>
                            <th>Audience</th>
                            <th>Recipients</th>
                            <th>Sent by</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($broadcastHistory as $b): ?>
                        <tr>
                            <td class="text-nowrap small"><?= date('d M Y H:i', strtotime((string)$b['sent_at'])) ?></td>
                            <td><?= safe_output($b['subject'], '') ?></td>
                            <td><span class="badge bg-secondary-subtle text-secondary"><?= safe_output($b['audience_definition'], '') ?></span></td>
                            <td><?= (int)$b['recipients_count'] ?></td>
                            <td class="small text-muted"><?= safe_output($b['sender_email'] ?? 'system', '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const SA_CSRF_TOKEN = '<?= csrf_token() ?>';

// Live preview
$('#f-subject').on('input', function () { $('#prevSubject').text(this.value || '—'); });
$('#f-body').on('input', function () { $('#prevBody').text(this.value || 'Start typing…'); });

// Recipient count — fetched when audience changes
$('#f-audience').on('change', function () {
    const val = this.value;
    $('#recipientCount').text('…');
    if (!val) { $('#recipientCount').text('—'); return; }
    $.post('/actions/superadmin_broadcast.php',
        { _csrf: SA_CSRF_TOKEN, action: 'count', audience: val },
        function (res) {
            if (res && res.success) {
                $('#recipientCount').text(res.count);
            } else {
                $('#recipientCount').text('?');
            }
        }, 'json').fail(function () { $('#recipientCount').text('?'); });
});

// Send with confirmation
$('#broadcastForm').on('submit', function (e) {
    e.preventDefault();
    const audience = $('#f-audience').val();
    const subject  = $('#f-subject').val().trim();
    const body     = $('#f-body').val().trim();
    const count    = parseInt($('#recipientCount').text(), 10) || 0;

    if (!audience || !subject || !body) {
        Swal.fire({ icon: 'warning', title: 'Incomplete', text: 'Please fill in audience, subject and body.' });
        return;
    }

    Swal.fire({
        title: 'Confirm Broadcast',
        html: '<p>You are about to email <strong>' + count + ' tenant' + (count !== 1 ? 's' : '') + '</strong>.</p>'
            + '<p class="text-muted small mb-0">This cannot be undone. Unsubscribed tenants will be skipped automatically.</p>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Send to ' + count + ' tenant' + (count !== 1 ? 's' : '')
    }).then(function (r) {
        if (!r.isConfirmed) return;
        const btn  = document.getElementById('btnSend');
        const orig = btn.innerHTML;
        btn.disabled  = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending…';
        $.ajax({
            url: '/actions/superadmin_broadcast.php',
            method: 'POST',
            dataType: 'json',
            data: { _csrf: SA_CSRF_TOKEN, action: 'send', audience: audience, subject: subject, body: body }
        }).done(function (res) {
            if (res && res.success) {
                Swal.fire({ icon: 'success', title: 'Broadcast sent!',
                    text: 'Sent to ' + res.sent + ' tenant' + (res.sent !== 1 ? 's' : '') + '.'
                          + (res.failed > 0 ? ' ' + res.failed + ' failed.' : ''),
                    timer: 3000, showConfirmButton: false })
                    .then(function () { window.location.reload(); });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not send.' });
            }
        }).fail(function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); })
          .always(function () { btn.disabled = false; btn.innerHTML = orig; });
    });
});

<?php if ($broadcastHistory): ?>
if ($.fn.DataTable) {
    $('#historyTable').DataTable({ pageLength: 10, order: [[0,'desc']], dom: 'rtip' });
}
<?php endif; ?>
</script>
</body>
</html>
