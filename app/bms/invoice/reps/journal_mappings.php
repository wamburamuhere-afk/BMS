<?php
// File: reps/journal_mappings.php
// Phase 4.2 — Admin page for the Journal Mappings table.
//   Lists all 8 canonical event rows seeded in Phase 4.1.
//   For each row admin can pick debit + credit account, toggle is_active,
//   and edit a free-text notes field. One Save button bulk-saves the whole
//   page via api/account/save_journal_mappings.php in a single transaction.
require_once __DIR__ . '/../../../../roots.php';
// First-level gate: parent reports.php already enforces canView('reports'),
// re-asserted here so this partial is safe under direct inclusion too.
if (!canView('reports') || !canEdit('chart_of_accounts')) {
    http_response_code(403);
    die('Access Denied — admin only');
}
global $pdo;

// Load every active account for the dropdowns. Sorted by category so the
// admin can scan them by section (asset / liability / equity / income / expense).
$accounts = $pdo->query("
    SELECT account_id, account_code, account_name, account_type
      FROM accounts
     WHERE status = 'active'
  ORDER BY account_type, account_code
")->fetchAll(PDO::FETCH_ASSOC);

// Group accounts by account_type so we can render <optgroup>s
$grouped_accounts = [];
foreach ($accounts as $a) {
    $grouped_accounts[$a['account_type']][] = $a;
}

// Load all journal mapping rows
$mappings = $pdo->query("
    SELECT id, event_type, description, debit_account_id, credit_account_id,
           is_active, notes
      FROM journal_mappings
  ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

// Helper: render the account <select> for one mapping field
if (!function_exists('jm_render_account_select')) {
    function jm_render_account_select(string $name, ?int $selected_id, array $grouped_accounts, int $row_id): string {
        $out = '<select name="' . htmlspecialchars($name) . '" class="form-select form-select-sm jm-account-select" data-row-id="' . (int)$row_id . '">';
        $out .= '<option value="">— select account —</option>';
        $type_labels = [
            'asset'     => 'Assets',
            'liability' => 'Liabilities',
            'equity'    => 'Equity',
            'income'    => 'Income',
            'expense'   => 'Expense',
        ];
        foreach ($grouped_accounts as $type => $accts) {
            $label = $type_labels[$type] ?? ucfirst($type);
            $out .= '<optgroup label="' . htmlspecialchars($label) . '">';
            foreach ($accts as $a) {
                $sel = ((int)$a['account_id'] === (int)$selected_id) ? ' selected' : '';
                $out .= '<option value="' . (int)$a['account_id'] . '"' . $sel . '>'
                     . htmlspecialchars($a['account_code'] . ' — ' . $a['account_name'])
                     . '</option>';
            }
            $out .= '</optgroup>';
        }
        $out .= '</select>';
        return $out;
    }
}

// How many mappings are currently active?
$active_count = count(array_filter($mappings, fn($m) => (int)$m['is_active'] === 1));
$total_count  = count($mappings);
?>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-primary">
            <i class="bi bi-diagram-3 me-2"></i> Journal Mappings — Auto-posting Configuration
        </h5>
        <span class="badge bg-<?= $active_count === 0 ? 'secondary' : ($active_count === $total_count ? 'success' : 'info') ?>">
            <?= $active_count ?> / <?= $total_count ?> events active
        </span>
    </div>

    <div class="card-body border-bottom bg-light">
        <p class="mb-1 small text-muted">
            <i class="bi bi-info-circle me-1"></i>
            Each row says <em>"when this operational event happens, post a journal entry
            <strong>debiting</strong> the left account and <strong>crediting</strong> the right one."</em>
            Activation is OFF by default — the auto-poster is a no-op for every event until you set both
            accounts and tick the <strong>Active</strong> checkbox.
        </p>
        <p class="mb-0 small text-muted">
            <i class="bi bi-shield-check me-1"></i>
            Mappings cannot be deleted or renamed from this page (event types come from the Phase 4
            migrations). You can update accounts, toggle Active, and edit notes.
        </p>
    </div>

    <form id="jm-form" onsubmit="return false;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-uppercase small fw-bold text-muted">
                        <tr>
                            <th class="ps-4" style="width:22%;">Event</th>
                            <th style="width:26%;">Debit (Dr)</th>
                            <th style="width:26%;">Credit (Cr)</th>
                            <th class="text-center" style="width:8%;">Active</th>
                            <th class="pe-4" style="width:18%;">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mappings as $m): ?>
                            <tr data-row-id="<?= (int)$m['id'] ?>">
                                <td class="ps-4">
                                    <strong class="d-block"><?= htmlspecialchars($m['event_type']) ?></strong>
                                    <small class="text-muted"><?= htmlspecialchars($m['description']) ?></small>
                                    <input type="hidden" name="mappings[<?= (int)$m['id'] ?>][id]" value="<?= (int)$m['id'] ?>">
                                </td>
                                <td>
                                    <?= jm_render_account_select(
                                        "mappings[{$m['id']}][debit_account_id]",
                                        $m['debit_account_id'] !== null ? (int)$m['debit_account_id'] : null,
                                        $grouped_accounts,
                                        (int)$m['id']
                                    ) ?>
                                </td>
                                <td>
                                    <?= jm_render_account_select(
                                        "mappings[{$m['id']}][credit_account_id]",
                                        $m['credit_account_id'] !== null ? (int)$m['credit_account_id'] : null,
                                        $grouped_accounts,
                                        (int)$m['id']
                                    ) ?>
                                </td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-flex justify-content-center">
                                        <input class="form-check-input jm-active-toggle"
                                               type="checkbox"
                                               name="mappings[<?= (int)$m['id'] ?>][is_active]"
                                               value="1"
                                               <?= (int)$m['is_active'] === 1 ? 'checked' : '' ?>>
                                    </div>
                                </td>
                                <td class="pe-4">
                                    <input type="text"
                                           class="form-control form-control-sm"
                                           name="mappings[<?= (int)$m['id'] ?>][notes]"
                                           value="<?= htmlspecialchars($m['notes'] ?? '') ?>"
                                           placeholder="optional">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer bg-white py-3 d-flex justify-content-between align-items-center">
            <small class="text-muted">
                <i class="bi bi-lightbulb me-1"></i>
                Tip: pick the <em>natural</em> side for each event. Sales invoice → Dr Receivables, Cr Revenue.
                Customer payment → Dr Cash, Cr Receivables. (Server validates the rest.)
            </small>
            <button type="button" class="btn btn-primary text-white" id="jm-save-btn">
                <i class="bi bi-save me-1"></i> Save All Mappings
            </button>
        </div>
    </form>

    <div id="jm-feedback" class="px-4 py-2"></div>
</div>

<script>
$(document).ready(function () {
    // Initialise Select2 on every account dropdown for search
    if (typeof $.fn.select2 === 'function') {
        $('.jm-account-select').select2({
            placeholder: '— select account —',
            allowClear: true,
            width: '100%'
        });
    }

    // Guard: if Active is toggled ON, require both Dr and Cr to be set
    $('.jm-active-toggle').on('change', function () {
        if (!this.checked) return;
        const $row = $(this).closest('tr');
        const dr = $row.find('select[name$="[debit_account_id]"]').val();
        const cr = $row.find('select[name$="[credit_account_id]"]').val();
        if (!dr || !cr) {
            alert('Set both Debit and Credit accounts before activating this mapping.');
            this.checked = false;
        }
    });

    // Bulk save handler
    $('#jm-save-btn').on('click', function () {
        const $btn = $(this);
        const original = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');

        const formData = $('#jm-form').serialize();

        $.ajax({
            url: '/bms/api/account/save_journal_mappings.php',
            method: 'POST',
            data: formData,
            dataType: 'json'
        }).done(function (resp) {
            const $fb = $('#jm-feedback');
            $fb.empty();
            if (resp.success) {
                $fb.html(
                    '<div class="alert alert-success py-2 mb-0">' +
                    '<i class="bi bi-check-circle me-1"></i>' + (resp.message || 'Saved.') +
                    '</div>'
                );
                if (typeof logReportAction === 'function') {
                    logReportAction('Updated Journal Mappings', resp.updated + ' row(s)');
                }
            } else {
                let html = '<div class="alert alert-danger py-2 mb-0"><strong>' +
                           (resp.message || 'Save failed') + '</strong>';
                if (Array.isArray(resp.errors) && resp.errors.length > 0) {
                    html += '<ul class="mb-0 mt-2 small">';
                    resp.errors.forEach(e => { html += '<li>' + $('<div>').text(e).html() + '</li>'; });
                    html += '</ul>';
                }
                html += '</div>';
                $fb.html(html);
            }
        }).fail(function (xhr) {
            $('#jm-feedback').html(
                '<div class="alert alert-danger py-2 mb-0">' +
                '<i class="bi bi-x-circle me-1"></i>Server error (HTTP ' + xhr.status + ')' +
                '</div>'
            );
        }).always(function () {
            $btn.prop('disabled', false).html(original);
        });
    });
});
</script>
