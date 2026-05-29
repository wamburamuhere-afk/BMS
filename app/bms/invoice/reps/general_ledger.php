<?php
// File: reps/general_ledger.php
// Phase 2.2 — General Ledger UI partial. Included by reports.php.
//
// Per-account audit trail backing the Trial Balance (Phase 1) and the
// formal financial statements. Renders the API output from Phase 2.1
// (api/account/get_general_ledger.php) using the standard GL format
// referenced by DualEntry, Microsoft Dynamics 365, and Financial-Cents:
//
//   Account header (name, code, type, normal_side)
//   Opening Balance card
//   Per-line table: Date / Description / Reference / Source / Dr / Cr / Running
//   Window totals row
//   Closing Balance card
//   Notes
//
// Account selector is server-side rendered (9 active accounts; AJAX is
// overkill — same rule as the COA admin). Project dropdown stays AJAX
// for consistency with the other reports.
//
// Source column shows plain "entity_type-entity_id" text per the Phase
// 2.1 agreement. Clickable drill-down is intentionally deferred until
// Phase 4 wires the entity-type-to-URL resolver.

require_once __DIR__ . '/../../../../roots.php';
if (!canView('reports')) {
    http_response_code(403);
    die("Access Denied");
}

global $pdo;

$account_id = isset($_GET['account_id']) && (int)$_GET['account_id'] > 0
    ? (int)$_GET['account_id'] : 0;
$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id'] : null;

// Load the account list for the dropdown (server-side rendered; small set).
$account_options = [];
try {
    $stmt = $pdo->query("
        SELECT a.account_id, a.account_code, a.account_name,
               COALESCE(at.statement, 'BS') AS statement,
               COALESCE(at.category, '?')    AS category
          FROM accounts a
     LEFT JOIN account_types at ON a.account_type_id = at.type_id
         WHERE a.status = 'active'
      ORDER BY
          CASE COALESCE(at.statement, 'BS') WHEN 'BS' THEN 1 ELSE 2 END,
          CASE COALESCE(at.category, 'zz')
              WHEN 'asset'     THEN 1
              WHEN 'liability' THEN 2
              WHEN 'equity'    THEN 3
              WHEN 'revenue'   THEN 4
              WHEN 'cogs'      THEN 5
              WHEN 'expense'   THEN 6
              ELSE 7
          END,
          a.account_code, a.account_id
    ");
    $account_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* page will degrade gracefully */ }

// Consume the GL API internally if an account is chosen.
$gl = null; $ok = false; $err = '';
if ($account_id > 0) {
    $saved_get = $_GET;
    $_GET = ['account_id' => $account_id, 'start_date' => $start_date, 'end_date' => $end_date];
    if ($project_id !== null) $_GET['project_id'] = (string)$project_id;
    ob_start();
    require __DIR__ . '/../../../../api/account/get_general_ledger.php';
    $raw = ob_get_clean();
    $_GET = $saved_get;
    $gl = json_decode($raw, true);
    $ok = $gl && !empty($gl['success']);
    $err = $ok ? '' : ($gl['message'] ?? 'Failed to load report');
}

// Projects dropdown (scoped endpoint, same pattern as TB/BS/CF).
$saved_get_p = $_GET; $_GET = [];
ob_start();
require_once __DIR__ . '/../../../../api/account/get_projects_for_filter.php';
$proj_raw = ob_get_clean();
$_GET = $saved_get_p;
$proj_resp = json_decode($proj_raw, true);
$projects_list = ($proj_resp && !empty($proj_resp['success'])) ? $proj_resp['projects'] : [];

if (!function_exists('gl_fmt')) {
    function gl_fmt(float $n): string { return number_format($n, 2); }
}
?>

<!-- Print-only header -->
<div class="d-none d-print-block text-center mb-4">
    <?php
    $c_name = getSetting('company_name', 'BMS');
    $c_logo = getSetting('company_logo', '');
    ?>
    <?php if (!empty($c_logo)): ?>
        <div class="mb-2"><img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 70px;"></div>
    <?php endif; ?>
    <h2 style="margin:0; font-size: 18pt;"><?= safe_output($c_name) ?></h2>
    <h3 style="margin-top: 10px; font-size: 13pt; text-transform: uppercase; letter-spacing: 2px;">General Ledger</h3>
    <?php if ($ok): ?>
        <p style="margin:0; font-size: 10pt;">Account: <?= safe_output($gl['data']['account']['account_name']) ?>
            <small>(<?= safe_output($gl['data']['account']['account_code']) ?>)</small></p>
        <p style="margin:0; font-size: 9pt;">
            Period <?= date('d M Y', strtotime($start_date)) ?> – <?= date('d M Y', strtotime($end_date)) ?>
        </p>
    <?php endif; ?>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center d-print-none">
        <div>
            <h5 class="mb-0 fw-bold text-info"><i class="bi bi-journal-album me-2"></i> General Ledger</h5>
            <small class="text-muted fst-italic">Per-account audit trail — the detail backing the Trial Balance and the formal financial statements.</small>
        </div>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>

    <div class="card-body border-bottom bg-light d-print-none">
        <form method="GET" action="<?= getUrl('reports') ?>" class="row g-3 align-items-end">
            <input type="hidden" name="report" value="general_ledger">
            <div class="col-md-4">
                <label class="form-label small fw-bold">Account <span class="text-danger">*</span></label>
                <select class="form-select form-select-sm" name="account_id" required>
                    <option value="">— Select an account —</option>
                    <?php
                    $cur_stmt = ''; $cur_cat = '';
                    foreach ($account_options as $opt):
                        if ($opt['statement'] !== $cur_stmt) {
                            if ($cur_stmt !== '') echo '</optgroup>';
                            $cur_stmt = $opt['statement']; $cur_cat = '';
                            $group_label = $cur_stmt === 'BS' ? 'Balance Sheet Accounts' : 'Income Statement Accounts';
                            echo '<optgroup label="' . htmlspecialchars($group_label) . '">';
                        }
                    ?>
                        <option value="<?= (int)$opt['account_id'] ?>" <?= $account_id === (int)$opt['account_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars(($opt['account_code'] ? $opt['account_code'] . ' — ' : '') . $opt['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if ($cur_stmt !== '') echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Start Date</label>
                <input type="date" class="form-control form-control-sm" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">End Date</label>
                <input type="date" class="form-control form-control-sm" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Project</label>
                <select class="form-select form-select-sm" name="project_id">
                    <option value=""><?= ($ok && empty($gl['data']['meta']['is_admin'])) ? 'All My Projects' : 'All Projects (Consolidated)' ?></option>
                    <?php foreach ($projects_list as $p): ?>
                        <option value="<?= (int)$p['project_id'] ?>" <?= $project_id === (int)$p['project_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-info btn-sm text-white"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>

    <?php if ($account_id === 0): ?>
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-arrow-up-circle fs-1 d-block mb-3"></i>
            <p class="fst-italic mb-0">Pick an account above to view its General Ledger.</p>
        </div>
    <?php elseif (!$ok): ?>
        <div class="alert alert-danger m-3"><?= htmlspecialchars($err) ?></div>
    <?php else:
        $meta    = $gl['data']['meta'];
        $account = $gl['data']['account'];
        $lines   = $gl['data']['lines'];
        $open    = (float)$gl['data']['opening_balance'];
        $close   = (float)$gl['data']['closing_balance'];
        $wdr     = (float)$gl['data']['window_debit_total'];
        $wcr     = (float)$gl['data']['window_credit_total'];
        $normal  = $account['normal_side'];
    ?>

    <?php if (!empty($meta['project_filter_active'])): ?>
        <div class="alert alert-info border-0 mx-3 mt-3 py-2 d-print-none" style="font-size: 0.85rem;">
            <i class="bi bi-info-circle me-2"></i>
            Project filter active. Only journal entries tagged to this project are included.
        </div>
    <?php endif; ?>
    <?php if (isset($meta['is_admin']) && $meta['is_admin'] === false): ?>
        <div class="alert alert-secondary border-0 mx-3 mt-3 py-2 d-print-none" style="font-size: 0.85rem;">
            <i class="bi bi-shield-lock me-2"></i>
            Showing your scoped view: <?= count($meta['scoped_project_ids'] ?? []) ?> assigned project(s) + untagged company-wide entries.
        </div>
    <?php endif; ?>

    <!-- Account header summary -->
    <div class="card-body pt-3 pb-2 border-bottom">
        <div class="row g-2 align-items-center">
            <div class="col-md-7">
                <div class="fw-bold fs-5 text-dark"><?= htmlspecialchars($account['account_name']) ?>
                    <small class="text-muted">(<?= htmlspecialchars($account['account_code']) ?>)</small>
                </div>
                <div class="small text-muted">
                    <?= strtoupper(htmlspecialchars($account['statement'])) ?> /
                    <?= htmlspecialchars(ucfirst($account['category'])) ?> /
                    <?= htmlspecialchars($normal === 'debit' ? 'Debit-natural' : 'Credit-natural') ?>
                </div>
            </div>
            <div class="col-md-5 text-md-end small">
                Period: <strong><?= date('d M Y', strtotime($start_date)) ?></strong>
                to <strong><?= date('d M Y', strtotime($end_date)) ?></strong>
            </div>
        </div>
    </div>

    <!-- Opening Balance card -->
    <div class="card-body py-3 border-bottom">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <small class="text-muted text-uppercase fw-bold">Opening Balance</small>
                <div class="small text-muted">as of <?= date('d M Y', strtotime($start_date)) ?></div>
            </div>
            <div class="text-end">
                <div class="fs-5 fw-bold font-monospace"><?= gl_fmt($open) ?> TZS</div>
                <small class="text-muted">(<?= $normal === 'debit' ? 'Dr' : 'Cr' ?> natural)</small>
            </div>
        </div>
    </div>

    <!-- Detail table -->
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0 gl-table">
                <thead class="bg-light text-uppercase small fw-bold text-muted">
                    <tr>
                        <th class="ps-4" style="width:11%">Date</th>
                        <th style="width:25%">Description</th>
                        <th style="width:13%">Reference</th>
                        <th style="width:14%">Source</th>
                        <th class="text-end" style="width:12%">Debit</th>
                        <th class="text-end" style="width:12%">Credit</th>
                        <th class="text-end pe-4" style="width:13%">Running</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lines)): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted fst-italic">
                            No posted journal entries for this account in the selected window.
                        </td></tr>
                    <?php else: foreach ($lines as $l):
                        $desc = $l['description'] ?? '';
                        if (!empty($l['item_description']) && $l['item_description'] !== $desc) {
                            $desc .= ' — ' . $l['item_description'];
                        }
                    ?>
                        <tr>
                            <td class="ps-4 font-monospace small"><?= htmlspecialchars($l['entry_date']) ?></td>
                            <td class="small"><?= htmlspecialchars($desc) ?></td>
                            <td class="font-monospace small text-muted"><?= htmlspecialchars($l['reference_number'] ?? '') ?></td>
                            <td class="small font-monospace"><?= htmlspecialchars($l['source']) ?></td>
                            <td class="text-end font-monospace small">
                                <?= (float)$l['debit'] > 0 ? gl_fmt((float)$l['debit']) : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td class="text-end font-monospace small">
                                <?= (float)$l['credit'] > 0 ? gl_fmt((float)$l['credit']) : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td class="text-end pe-4 font-monospace small fw-semibold">
                                <?= gl_fmt((float)$l['running_balance']) ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($lines)): ?>
                <tfoot>
                    <tr class="gl-window-total bg-light">
                        <td colspan="4" class="ps-4 text-end fst-italic small text-muted">Window totals</td>
                        <td class="text-end font-monospace fw-bold border-top"><?= gl_fmt($wdr) ?></td>
                        <td class="text-end font-monospace fw-bold border-top"><?= gl_fmt($wcr) ?></td>
                        <td class="pe-4"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- Closing Balance card -->
    <div class="card-body py-3 border-top">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <small class="text-muted text-uppercase fw-bold">Closing Balance</small>
                <div class="small text-muted">as of <?= date('d M Y', strtotime($end_date)) ?></div>
            </div>
            <div class="text-end">
                <div class="fs-4 fw-bold font-monospace text-info"><?= gl_fmt($close) ?> TZS</div>
                <small class="text-muted">(<?= $normal === 'debit' ? 'Dr' : 'Cr' ?> natural)</small>
            </div>
        </div>
    </div>

    <!-- Notes -->
    <div class="card-footer bg-white py-3">
        <div class="small text-muted">
            <strong>Notes:</strong>
            <ol class="mb-0 ps-3">
                <li>Opening balance includes <code>accounts.opening_balance</code> allocated by the account's natural side, plus the cumulative net of every posted journal entry on this account <strong>before</strong> the start date.</li>
                <li>Running balance accumulates per line: debit-natural accounts add Dr and subtract Cr; credit-natural accounts add Cr and subtract Dr.</li>
                <li><strong>Source</strong> shows <code>entity_type-entity_id</code> for entries auto-posted from operations, or <strong>Manual</strong> for entries posted directly to the ledger.</li>
                <li>Source links will become clickable once Phase 4 auto-posting begins routing operational events through the canonical ledger.</li>
            </ol>
        </div>
    </div>

    <?php endif; ?>
</div>

<style>
.gl-table tbody tr:hover { background: #f8fafd; }
.gl-table .gl-window-total td { font-size: 0.85rem; }
@media print {
    body { background: white !important; }
    .card { border: none !important; box-shadow: none !important; }
    .table { border: 1px solid #000 !important; font-size: 10pt; }
    .table th { background-color: #f8f9fa !important; }
}
</style>

<script>
$(document).ready(function () {
    if (typeof logReportAction === 'function') {
        logReportAction(
            'Viewed General Ledger',
            'account_id=<?= (int)$account_id ?>, period <?= $start_date ?> to <?= $end_date ?>'
            <?= $project_id !== null ? " + ', project ' + " . (int)$project_id : '' ?>
        );
    }
});
</script>
