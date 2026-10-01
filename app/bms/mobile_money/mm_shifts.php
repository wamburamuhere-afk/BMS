<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
ob_start();
$page_title = 'MM Shifts';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_shifts');
includeHeader();

$can_open  = canCreate('mm_shifts');
$can_close = canEdit('mm_shifts');

// Scope helpers: all open shifts on the user's granted tills.
$scopeShiftTill = mmScopeSql('s.till_id');
$scopeCloseTill = mmScopeSql('s.till_id', 'till', 'can_close_shift');

// All open shifts on the user's granted tills (admin → all; teller → their tills).
$myOpenShifts = $pdo->query("
    SELECT s.shift_id, s.shift_code, t.till_number, a.agent_name
    FROM mm_shifts s
    JOIN mm_tills t ON t.till_id = s.till_id
    JOIN mm_agents a ON a.agent_id = t.agent_id
    WHERE s.status = 'open' $scopeShiftTill
    ORDER BY s.opened_at
")->fetchAll(PDO::FETCH_ASSOC);

// All open shifts for "Close All" — admin sees all system-wide, non-admin sees own
if (isAdmin()) {
    $allOpenForClose = $pdo->query("
        SELECT s.shift_id, s.shift_code, s.opened_at,
               t.till_number, a.agent_name,
               CONCAT(u.first_name, ' ', u.last_name) AS teller_name
        FROM mm_shifts s
        JOIN mm_tills t ON t.till_id = s.till_id
        JOIN mm_agents a ON a.agent_id = t.agent_id
        LEFT JOIN users u ON u.user_id = s.teller_user_id
        WHERE s.status = 'open'
        ORDER BY s.opened_at
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Teller sees open shifts on tills where they hold can_close_shift.
    $allOpenForClose = $pdo->query("
        SELECT s.shift_id, s.shift_code, s.opened_at,
               t.till_number, a.agent_name,
               CONCAT(u.first_name, ' ', u.last_name) AS teller_name
        FROM mm_shifts s
        JOIN mm_tills t ON t.till_id = s.till_id
        JOIN mm_agents a ON a.agent_id = t.agent_id
        LEFT JOIN users u ON u.user_id = s.teller_user_id
        WHERE s.status = 'open' $scopeCloseTill
        ORDER BY s.opened_at
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Tills this user may open a shift on
if (isAdmin()) {
    $tillsForOpen = $pdo->query("
        SELECT t.till_id, t.till_number, a.agent_name, n.network_name, n.color_hex
        FROM mm_tills t
        JOIN mm_agents a ON a.agent_id = t.agent_id
        JOIN mm_networks n ON n.network_id = t.network_id
        WHERE t.status = 'active'
        ORDER BY a.agent_name, t.till_number
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Grant engine: can_open_shift on a live (active agent + active till) till only.
    $tillsForOpen = $pdo->query("
        SELECT t.till_id, t.till_number, a.agent_name, n.network_name, n.color_hex
        FROM mm_tills t
        JOIN mm_agents a ON a.agent_id = t.agent_id
        JOIN mm_networks n ON n.network_id = t.network_id
        WHERE t.status = 'active' " . mmScopeSql('t.till_id', 'till', 'can_open_shift') . "
        ORDER BY a.agent_name, t.till_number
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Currently busy tills (keyed by till_id) — only tills this user can see
$busyTillsRaw = $pdo->query("
    SELECT s.till_id, s.shift_code,
           CONCAT(u.first_name, ' ', u.last_name) AS teller_name,
           TIME_FORMAT(s.opened_at, '%H:%i') AS opened_time
    FROM mm_shifts s
    JOIN users u ON u.user_id = s.teller_user_id
    WHERE s.status = 'open' " . mmScopeSql('s.till_id') . "
")->fetchAll(PDO::FETCH_ASSOC);
$busyTills = [];
foreach ($busyTillsRaw as $bt) {
    $busyTills[(int)$bt['till_id']] = $bt;
}

// Free tills: assigned to this user but not yet open
$freeTillCount = count(array_filter($tillsForOpen, fn($t) => !isset($busyTills[(int)$t['till_id']])));

// Filters
$filterFrom   = $_GET['date_from'] ?? date('Y-m-01');
$filterTo     = $_GET['date_to']   ?? date('Y-m-d');
$filterStatus = $_GET['status']    ?? '';

$where  = ["s.opened_at >= :df", "s.opened_at <= :dt"];
$params = [':df' => $filterFrom . ' 00:00:00', ':dt' => $filterTo . ' 23:59:59'];
if ($filterStatus) { $where[] = 's.status = :status'; $params[':status'] = $filterStatus; }

$shifts = $pdo->prepare("
    SELECT s.*,
           t.till_number, a.agent_name, a.agent_code, n.network_name, n.color_hex,
           CONCAT(u.first_name, ' ', u.last_name) AS teller_name,
           CONCAT(uc.first_name, ' ', uc.last_name) AS closed_by_name,
           (SELECT COUNT(*) FROM mm_transactions mt WHERE mt.shift_id = s.shift_id AND mt.status = 'posted') AS txn_count,
           (SELECT SUM(mt.principal_amount) FROM mm_transactions mt WHERE mt.shift_id = s.shift_id AND mt.status = 'posted') AS txn_volume,
           (SELECT SUM(mt.commission_earned) FROM mm_transactions mt WHERE mt.shift_id = s.shift_id AND mt.status = 'posted') AS txn_commission
    FROM mm_shifts s
    JOIN mm_tills t    ON t.till_id  = s.till_id
    JOIN mm_agents a   ON a.agent_id = t.agent_id
    JOIN mm_networks n ON n.network_id = t.network_id
    LEFT JOIN users u  ON u.user_id = s.teller_user_id
    LEFT JOIN users uc ON uc.user_id = s.closed_by
    WHERE " . implode(' AND ', $where) . $scopeShiftTill . "
    ORDER BY s.opened_at DESC
    LIMIT 200
");
$shifts->execute($params);
$shifts = $shifts->fetchAll(PDO::FETCH_ASSOC);

$openCount   = count(array_filter($shifts, fn($s) => $s['status'] === 'open'));
$closedCount = count(array_filter($shifts, fn($s) => $s['status'] === 'closed'));

logActivity($pdo, $_SESSION['user_id'], 'View MM Shifts', 'Viewed shifts list');
?>

<div class="container-fluid mt-3 mb-5">
    <!-- Page header with dual Open / Close All buttons -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-clock-history text-primary me-2"></i><?= t('Teller Shifts') ?></h4>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($can_open && $freeTillCount > 0): ?>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#openShiftModal">
                <i class="bi bi-play-circle me-1"></i><?= t('Open Shift') ?>
                <?php if ($freeTillCount > 1): ?>
                <span class="badge bg-white text-primary ms-1"><?= $freeTillCount ?></span>
                <?php endif; ?>
            </button>
            <?php endif; ?>
            <?php if ($can_close && count($allOpenForClose) > 0): ?>
            <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#closeAllShiftsModal">
                <i class="bi bi-stop-circle me-1"></i><?= t('Close All Shifts') ?>
                <span class="badge bg-white text-danger ms-1"><?= count($allOpenForClose) ?></span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Active shift banner — lists all open shifts as chips -->
    <?php if (!empty($myOpenShifts)): ?>
    <div class="alert alert-success d-flex flex-wrap align-items-center gap-2 py-2 mb-3" style="border-radius:8px">
        <span><i class="bi bi-play-circle-fill me-1"></i><strong><?= t('Active Shifts:') ?></strong></span>
        <?php foreach ($myOpenShifts as $sh): ?>
        <span class="badge bg-white text-success border border-success" style="font-size:.82rem">
            <?= safe_output($sh['shift_code']) ?> · <?= safe_output($sh['agent_name'] . ' / ' . $sh['till_number']) ?>
        </span>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="alert alert-warning py-2 mb-3" style="border-radius:8px">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= t('No active shift.') ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-3">
        <?php if (isAdmin()): ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-success"><?= $openCount ?></div>
                <div class="small text-muted"><?= t('Open Shifts') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-primary"><?= $closedCount ?></div>
                <div class="small text-muted"><?= t('Closed Shifts') ?></div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-5 fw-bold text-info"><?= number_format(array_sum(array_column($shifts, 'txn_volume'))) ?></div>
                <div class="small text-muted"><?= t('Total Volume (TZS)') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-5 fw-bold text-warning"><?= number_format(array_sum(array_column($shifts, 'txn_commission'))) ?></div>
                <div class="small text-muted"><?= t('Commission (TZS)') ?></div>
            </div>
        </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="" class="row g-2 mb-3 align-items-end">
        <div class="col-md-2">
            <label class="form-label small mb-1"><?= t('From') ?></label>
            <input type="date" class="form-control form-control-sm" name="date_from" value="<?= htmlspecialchars($filterFrom) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1"><?= t('To') ?></label>
            <input type="date" class="form-control form-control-sm" name="date_to" value="<?= htmlspecialchars($filterTo) ?>">
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select form-select-sm">
                <option value=""><?= t('All Statuses') ?></option>
                <option value="open"         <?= $filterStatus==='open'?'selected':'' ?>><?= t('Open') ?></option>
                <option value="closed"       <?= $filterStatus==='closed'?'selected':'' ?>><?= t('Closed') ?></option>
                <option value="forced_close" <?= $filterStatus==='forced_close'?'selected':'' ?>><?= t('Force-closed') ?></option>
            </select>
        </div>
        <div class="col-md-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <a href="<?= getUrl('mm_shifts') ?>" class="btn btn-sm btn-outline-secondary"><?= t('Clear') ?></a>
        </div>
    </form>

<style>
.mm-stat-card{background:#d1e7dd!important;border-color:#badbcc!important;border-radius:12px;transition:transform .2s}
.mm-stat-card:hover{transform:translateY(-3px)}
.mm-stat-card .fw-bold,.mm-stat-card .fs-3,.mm-stat-card .fs-4,.mm-stat-card .fs-5{color:#0f5132!important}
.mm-stat-card .text-muted,.mm-stat-card .small{color:#0f5132!important;opacity:.85}
.mm-thead th{background:#fff!important;color:#212529;border-bottom:2px solid #dee2e6!important;text-align:center;font-weight:600;font-size:.8rem;padding:10px 8px}
.mm-sno{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#f0f2f5;color:#6b7280;font-size:.7rem;font-weight:700;flex-shrink:0}
.mm-kv{display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid #f3f4f6;font-size:.82rem}
.mm-kv:last-child{border:0}
.mm-kv .kv-lbl{color:#9ca3af}
.mm-kv .kv-val{font-weight:500;text-align:right;max-width:65%;word-break:break-word}
.mm-card-foot{display:flex;gap:6px;padding:8px 12px;border-top:1px solid #f3f4f6}
.mm-card-foot .btn{flex:1;font-size:.78rem;padding:3px 6px}
/* Close-all notes wrap */
.close-all-notes{resize:vertical;min-height:36px;white-space:pre-wrap;word-break:break-word}
</style>

    <!-- Table -->
    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="shiftsTable" class="table table-hover align-middle mb-0 w-100" style="font-size:.88rem">
                        <thead class="mm-thead">
                            <tr>
                                <th class="text-center" style="width:48px"><?= t('S/No') ?></th>
                                <th class="text-center"><?= t('Code') ?></th>
                                <th class="text-center"><?= t('Outlet / Till') ?></th>
                                <th class="text-center"><?= t('Teller') ?></th>
                                <th class="text-center"><?= t('Opened') ?></th>
                                <th class="text-center"><?= t('Closed') ?></th>
                                <th class="text-center"><?= t('Txns') ?></th>
                                <th class="text-center"><?= t('Volume (TZS)') ?></th>
                                <th class="text-center"><?= t('Cash Var.') ?></th>
                                <th class="text-center"><?= t('Float Var.') ?></th>
                                <th class="text-center"><?= t('Status') ?></th>
                                <th class="text-center"><?= t('Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sno = 1; foreach ($shifts as $sh): ?>
                            <tr data-id="<?= (int)$sh['shift_id'] ?>" data-code="<?= htmlspecialchars($sh['shift_code']) ?>" data-outlet="<?= htmlspecialchars($sh['agent_name'].' / '.$sh['till_number']) ?>" data-teller="<?= htmlspecialchars($sh['teller_name'] ?: '—') ?>" data-opened="<?= date('d M H:i', strtotime($sh['opened_at'])) ?>" data-volume="<?= $sh['txn_volume'] ? number_format((float)$sh['txn_volume']) : '—' ?>" data-status="<?= htmlspecialchars($sh['status']) ?>" data-can-close="<?= ($sh['status'] === 'open' && $can_close) ? '1' : '0' ?>" data-shift='<?= htmlspecialchars(json_encode(['id'=>$sh['shift_id'],'code'=>$sh['shift_code'],'till_number'=>$sh['till_number'],'agent'=>$sh['agent_name']]),ENT_QUOTES) ?>'>
                                <td class="text-center text-muted small"><?= $sno++ ?></td>
                                <td><code class="small"><?= safe_output($sh['shift_code']) ?></code></td>
                                <td class="small">
                                    <span class="d-inline-block me-1" style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($sh['color_hex'] ?: '#999') ?>"></span>
                                    <?= safe_output($sh['agent_name']) ?> / <?= safe_output($sh['till_number']) ?>
                                </td>
                                <td class="small"><?= safe_output($sh['teller_name'] ?: '—') ?></td>
                                <td class="small"><?= date('d M H:i', strtotime($sh['opened_at'])) ?></td>
                                <td class="small"><?= $sh['closed_at'] ? date('d M H:i', strtotime($sh['closed_at'])) : '—' ?></td>
                                <td class="text-end"><?= (int)$sh['txn_count'] ?></td>
                                <td class="text-end"><?= $sh['txn_volume'] ? number_format((float)$sh['txn_volume']) : '—' ?></td>
                                <td class="text-end <?= ($sh['cash_variance'] ?? 0) == 0 ? 'text-muted' : (($sh['cash_variance'] ?? 0) > 0 ? 'text-success' : 'text-danger') ?>">
                                    <?= $sh['cash_variance'] !== null ? number_format((float)$sh['cash_variance']) : '—' ?>
                                </td>
                                <td class="text-end <?= ($sh['float_variance'] ?? 0) == 0 ? 'text-muted' : (($sh['float_variance'] ?? 0) > 0 ? 'text-success' : 'text-danger') ?>">
                                    <?= $sh['float_variance'] !== null ? number_format((float)$sh['float_variance']) : '—' ?>
                                </td>
                                <td>
                                    <span class="badge <?= $sh['status']==='open'?'bg-success':($sh['status']==='forced_close'?'bg-danger':'bg-secondary') ?>">
                                        <?= ucfirst(str_replace('_', ' ', safe_output($sh['status']))) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear-fill"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:140px;font-size:.85rem">
                                            <li><a class="dropdown-item" href="<?= getUrl('mm_shift_report') ?>?id=<?= $sh['shift_id'] ?>"><i class="bi bi-printer me-2 text-secondary"></i><?= t('Report') ?></a></li>
                                            <?php if ($sh['status'] === 'open' && $can_close): ?>
                                            <li><hr class="dropdown-divider my-1"></li>
                                            <li><a class="dropdown-item text-danger" href="#" onclick='closeShift(<?= htmlspecialchars(json_encode(['id'=>$sh['shift_id'],'code'=>$sh['shift_code'],'till_number'=>$sh['till_number'],'agent'=>$sh['agent_name']]),ENT_QUOTES) ?>);return false'><i class="bi bi-stop-circle me-2"></i><?= t('Close') ?></a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- ══════════════════════════════════════════
     OPEN SHIFT MODAL — multi-till table
     ══════════════════════════════════════════ -->
<?php if ($can_open): ?>
<div class="modal fade" id="openShiftModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-play-circle me-1"></i><?= t('Open Shift') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="openShiftForm" autocomplete="off">
                <div class="modal-body p-0">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <?php if (empty($tillsForOpen)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-display fs-2 d-block mb-2"></i><?= t('No tills assigned to you.') ?>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" style="font-size:.88rem">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:44px" class="text-center">
                                        <input type="checkbox" id="selectAllTills" checked title="<?= t('Select all') ?>">
                                    </th>
                                    <th><?= t('Outlet / Till') ?></th>
                                    <th style="min-width:150px"><?= t('Opening Cash (TZS)') ?> <span class="text-danger">*</span></th>
                                    <th style="min-width:150px"><?= t('Opening Float (TZS)') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tillsForOpen as $t):
                                    $busy = $busyTills[(int)$t['till_id']] ?? null; ?>
                                <tr class="till-row <?= $busy ? 'table-secondary' : '' ?>" data-till-id="<?= (int)$t['till_id'] ?>">
                                    <td class="text-center">
                                        <?php if ($busy): ?>
                                        <i class="bi bi-lock-fill text-secondary" title="<?= t('In use by') ?> <?= htmlspecialchars($busy['teller_name']) ?>"></i>
                                        <?php else: ?>
                                        <input type="checkbox" class="till-checkbox" value="<?= (int)$t['till_id'] ?>" checked>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="d-inline-block me-1" style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($t['color_hex'] ?: '#999') ?>"></span>
                                        <strong><?= safe_output($t['agent_name'] . ' / ' . $t['till_number']) ?></strong>
                                        <small class="text-muted ms-1"><?= safe_output($t['network_name']) ?></small>
                                        <?php if ($busy): ?>
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.72rem">
                                            <?= t('Open') ?> · <?= safe_output($busy['teller_name']) ?> <?= t('since') ?> <?= $busy['opened_time'] ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="number"
                                               class="form-control form-control-sm open-cash"
                                               name="opening_cash_<?= (int)$t['till_id'] ?>"
                                               min="0" step="100"
                                               <?= $busy ? 'disabled' : '' ?>>
                                    </td>
                                    <td>
                                        <input type="number"
                                               class="form-control form-control-sm open-float"
                                               name="opening_float_<?= (int)$t['till_id'] ?>"
                                               data-till-id="<?= (int)$t['till_id'] ?>"
                                               min="0" step="100"
                                               <?= $busy ? 'disabled' : '' ?>
                                               placeholder="<?= $busy ? '—' : t('Loading…') ?>">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <?php if (!empty($tillsForOpen)): ?>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-play-circle me-1"></i><?= t('Open Selected Shifts') ?>
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════
     CLOSE ALL SHIFTS MODAL — multi-row table
     ══════════════════════════════════════════ -->
<?php if ($can_close && !empty($allOpenForClose)): ?>
<div class="modal fade" id="closeAllShiftsModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-stop-circle me-1"></i><?= t('Close All Open Shifts') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="closeAllShiftsForm" autocomplete="off">
                <div class="modal-body p-0">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <p class="text-muted small px-3 pt-3 mb-0"><?= t('Enter the physically counted amounts for each shift, then click Close All.') ?></p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" style="font-size:.88rem">
                            <thead class="table-light">
                                <tr>
                                    <th><?= t('Shift') ?></th>
                                    <th><?= t('Outlet / Till') ?></th>
                                    <th><?= t('Teller') ?></th>
                                    <th><?= t('Opened') ?></th>
                                    <th style="min-width:155px"><?= t('Counted Cash (TZS)') ?> <span class="text-danger">*</span></th>
                                    <th style="min-width:155px"><?= t('Counted Float (TZS)') ?> <span class="text-danger">*</span></th>
                                    <th style="min-width:180px"><?= t('Notes') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allOpenForClose as $sh): ?>
                                <tr data-shift-id="<?= (int)$sh['shift_id'] ?>">
                                    <td><code class="small"><?= safe_output($sh['shift_code']) ?></code></td>
                                    <td class="small"><?= safe_output($sh['agent_name'] . ' / ' . $sh['till_number']) ?></td>
                                    <td class="small"><?= safe_output($sh['teller_name'] ?: '—') ?></td>
                                    <td class="small"><?= date('d M H:i', strtotime($sh['opened_at'])) ?></td>
                                    <td>
                                        <input type="number"
                                               class="form-control form-control-sm close-all-cash"
                                               min="0" step="100" required>
                                    </td>
                                    <td>
                                        <input type="number"
                                               class="form-control form-control-sm close-all-float"
                                               min="0" step="100" required>
                                    </td>
                                    <td>
                                        <textarea class="form-control form-control-sm close-all-notes"
                                                  rows="2"
                                                  style="resize:vertical;min-height:36px;white-space:pre-wrap;word-break:break-word"
                                                  placeholder="<?= t('Issues or comments…') ?>"></textarea>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-danger btn-lg">
                        <i class="bi bi-stop-circle me-1"></i><?= t('Close All') ?> (<?= count($allOpenForClose) ?>)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════
     INDIVIDUAL CLOSE SHIFT MODAL
     ══════════════════════════════════════════ -->
<?php if ($can_close): ?>
<div class="modal fade" id="closeShiftModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-stop-circle me-1"></i><?= t('Close Shift') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="closeShiftForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="shift_id" id="close_shift_id">
                    <div id="close_shift_info" class="alert alert-light py-2 mb-3 small"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Counted Cash (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="closing_cash" id="close_cash" min="0" step="100" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Counted Float (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="closing_float" id="close_float" min="0" step="100" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Close Notes') ?></label>
                            <textarea class="form-control"
                                      name="close_notes"
                                      id="close_notes_field"
                                      rows="3"
                                      style="resize:vertical;white-space:pre-wrap;word-break:break-word"
                                      placeholder="<?= t('Any issues or comments…') ?>"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-stop-circle me-1"></i><?= t('Close Shift') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function safeOutput(s) { return s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]); }

$(document).ready(function () {
    // ── DataTable ──────────────────────────────────────────────────────────────
    if (!$.fn.DataTable.isDataTable('#shiftsTable')) {
        $('#shiftsTable').DataTable({
            responsive: false, scrollX: true, pageLength: 25, order: [[4, 'desc']],
            columnDefs: [{orderable: false, targets: 0}], dom: 'rtipB',
            language: { emptyTable: '<?= addslashes(t('No shifts found.')) ?>' },
            buttons: [{extend: 'excelHtml5', className: 'd-none', exportOptions: {columns: ':not(:last-child)'}}],
            drawCallback: function () { renderCards(this.api().rows({page: 'current'}).nodes()); applyView(); }
        });
    }
    function applyView() {
        if (window.innerWidth < 768) {
            $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none');
        } else {
            $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none');
        }
    }
    applyView(); $(window).on('resize', applyView);

    // ── Auto-open Open Shift modal from dashboard link ─────────────────────────
    <?php if (($_GET['action'] ?? '') === 'open' && $can_open && $freeTillCount > 0): ?>
    new bootstrap.Modal(document.getElementById('openShiftModal')).show();
    <?php endif; ?>
    // ── Auto-open Close Shift modal from dashboard link ────────────────────────
    <?php if (($_GET['action'] ?? '') === 'close' && $can_close && count($allOpenForClose) > 0): ?>
    new bootstrap.Modal(document.getElementById('closeAllShiftsModal')).show();
    <?php endif; ?>

    // ── Open Shift Modal: auto-fetch expected float per till ───────────────────
    $('#openShiftModal').on('shown.bs.modal', function () {
        $('.open-float:not([disabled])').each(function () {
            const $inp = $(this);
            const tillId = $inp.data('till-id');
            $inp.val('').attr('placeholder', '<?= t('Loading…') ?>');
            $.getJSON('<?= buildUrl('api/mobile_money/get_till_float.php') ?>', {till_id: tillId}, function (res) {
                if (res.success) {
                    $inp.val(res.expected_float).attr('placeholder', '');
                } else {
                    $inp.val('').attr('placeholder', '<?= t('Enter float') ?>');
                }
            }).fail(function () {
                $inp.attr('placeholder', '<?= t('Enter float') ?>');
            });
        });
    });

    // ── Select All tills checkbox ──────────────────────────────────────────────
    $('#selectAllTills').on('change', function () {
        $('.till-checkbox').prop('checked', this.checked);
    });
    $(document).on('change', '.till-checkbox', function () {
        const total   = $('.till-checkbox').length;
        const checked = $('.till-checkbox:checked').length;
        $('#selectAllTills').prop('indeterminate', checked > 0 && checked < total)
                            .prop('checked', checked === total);
    });

    // ── Open Shift Form Submit (batch) ─────────────────────────────────────────
    $('#openShiftForm').on('submit', function (e) {
        e.preventDefault();
        const selected = [];
        let valid = true;

        $('.till-checkbox:checked').each(function () {
            const $cb   = $(this);
            const tillId = $cb.val();
            const $row  = $cb.closest('tr');
            const cash  = $row.find('.open-cash').val();

            if (cash === '' || isNaN(parseFloat(cash)) || parseFloat(cash) < 0) {
                Swal.fire({icon: 'error', title: '<?= t('Error') ?>', text: '<?= t('Enter opening cash for every selected till.') ?>'});
                valid = false;
                return false;
            }
            selected.push({
                till_id:       tillId,
                opening_cash:  cash,
                opening_float: $row.find('.open-float').val() || 0
            });
        });

        if (!valid) return;
        if (!selected.length) {
            Swal.fire({icon: 'error', title: '<?= t('Error') ?>', text: '<?= t('Select at least one till.') ?>'});
            return;
        }

        const fd = new FormData();
        fd.append('_csrf', '<?= csrf_token() ?>');
        fd.append('tills', JSON.stringify(selected));

        const btn = $(this).find('[type=submit]'), orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>');

        $.ajax({
            url: '<?= buildUrl('api/mobile_money/batch_open_shifts.php') ?>',
            type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
            success: function (r) {
                btn.prop('disabled', false).html(orig);
                if (r.success) {
                    bootstrap.Modal.getInstance(document.getElementById('openShiftModal'))?.hide();
                    let html = `<p class="mb-2">${r.opened} <?= t('shift(s) opened.') ?></p>`;
                    r.results.forEach(res => {
                        if (res.success) html += `<div class="small text-success"><i class="bi bi-check-circle me-1"></i>${safeOutput(res.shift_code)} · Till ${safeOutput(res.till_number)}</div>`;
                        else             html += `<div class="small text-danger"><i class="bi bi-x-circle me-1"></i>Till ${safeOutput(res.till_number || '?')}: ${safeOutput(res.message)}</div>`;
                    });
                    Swal.fire({icon: 'success', title: '<?= t('Shifts Opened!') ?>', html: html, timer: 4000, timerProgressBar: true, showConfirmButton: true})
                        .then(() => location.reload());
                } else {
                    Swal.fire({icon: 'error', title: '<?= t('Error') ?>', text: r.message});
                }
            },
            error: (xhr) => {
                btn.prop('disabled', false).html(orig);
                Swal.fire({icon: 'error', title: '<?= t('Error') ?>', text: xhr.responseJSON?.message || '<?= t('Server error.') ?>'});
            }
        });
    });

    // ── Close All Shifts Form Submit (batch) ───────────────────────────────────
    $('#closeAllShiftsForm').on('submit', function (e) {
        e.preventDefault();
        const shiftsPayload = [];
        let valid = true;

        $(this).find('tr[data-shift-id]').each(function () {
            const $tr     = $(this);
            const shiftId = $tr.data('shift-id');
            const cash    = $tr.find('.close-all-cash').val();
            const float_  = $tr.find('.close-all-float').val();
            const notes   = $tr.find('.close-all-notes').val();

            if (cash === '' || isNaN(parseFloat(cash)) || parseFloat(cash) < 0 ||
                float_ === '' || isNaN(parseFloat(float_)) || parseFloat(float_) < 0) {
                Swal.fire({icon: 'error', title: '<?= t('Error') ?>', text: '<?= t('Fill in counted cash and float for every shift.') ?>'});
                valid = false;
                return false;
            }
            shiftsPayload.push({shift_id: shiftId, closing_cash: cash, closing_float: float_, close_notes: notes});
        });

        if (!valid) return;

        const fd = new FormData();
        fd.append('_csrf', '<?= csrf_token() ?>');
        fd.append('shifts', JSON.stringify(shiftsPayload));

        const btn = $(this).find('[type=submit]'), orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>');

        $.ajax({
            url: '<?= buildUrl('api/mobile_money/batch_close_shifts.php') ?>',
            type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
            success: function (r) {
                btn.prop('disabled', false).html(orig);
                if (r.success) {
                    bootstrap.Modal.getInstance(document.getElementById('closeAllShiftsModal'))?.hide();
                    let html = `<p class="mb-2">${r.closed} <?= t('shift(s) closed.') ?></p>`;
                    const anyVar = r.results.some(res => res.success && (parseFloat(res.cash_variance) !== 0 || parseFloat(res.float_variance) !== 0));
                    r.results.forEach(res => {
                        if (res.success) {
                            const cv  = parseFloat(res.cash_variance), fv = parseFloat(res.float_variance);
                            const cvC = cv  === 0 ? 'text-muted' : (cv  > 0 ? 'text-warning' : 'text-danger');
                            const fvC = fv  === 0 ? 'text-muted' : (fv  > 0 ? 'text-warning' : 'text-danger');
                            html += `<div class="small border-top pt-1 mt-1"><strong>${safeOutput(res.shift_code)}</strong> · Till ${safeOutput(res.till_number)}: `;
                            html += `Cash <span class="${cvC}">${cv >= 0 ? '+' : ''}${cv.toLocaleString()} TZS</span>, `;
                            html += `Float <span class="${fvC}">${fv >= 0 ? '+' : ''}${fv.toLocaleString()} TZS</span></div>`;
                        } else {
                            html += `<div class="small text-danger">${safeOutput(res.shift_code || 'Shift')}: ${safeOutput(res.message)}</div>`;
                        }
                    });
                    Swal.fire({icon: anyVar ? 'warning' : 'success', title: '<?= t('Shifts Closed!') ?>', html: html, showConfirmButton: true})
                        .then(() => location.reload());
                } else {
                    Swal.fire({icon: 'error', title: '<?= t('Error') ?>', text: r.message});
                }
            },
            error: (xhr) => {
                btn.prop('disabled', false).html(orig);
                Swal.fire({icon: 'error', title: '<?= t('Error') ?>', text: xhr.responseJSON?.message || '<?= t('Server error.') ?>'});
            }
        });
    });

    // ── Individual Close Shift Form Submit ─────────────────────────────────────
    $('#closeShiftForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]'), orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({
            url: '<?= buildUrl('api/mobile_money/close_shift.php') ?>',
            type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (r) {
                btn.prop('disabled', false).html(orig);
                if (r.success) {
                    bootstrap.Modal.getInstance(document.getElementById('closeShiftModal'))?.hide();
                    const cashVar  = parseFloat(r.cash_variance  ?? 0);
                    const floatVar = parseFloat(r.float_variance ?? 0);
                    const hasVar   = cashVar !== 0 || floatVar !== 0;
                    const cvC = cashVar  === 0 ? 'text-muted' : (cashVar  > 0 ? 'text-warning' : 'text-danger');
                    const fvC = floatVar === 0 ? 'text-muted' : (floatVar > 0 ? 'text-warning' : 'text-danger');
                    let html = '<p><?= t('Shift closed successfully.') ?></p>';
                    html += `<div class="d-flex justify-content-between border-top pt-2 mt-2"><span><?= t('Cash Variance:') ?></span><span class="fw-bold ${cvC}">${cashVar >= 0 ? '+' : ''}${cashVar.toLocaleString()} TZS</span></div>`;
                    html += `<div class="d-flex justify-content-between"><span><?= t('Float Variance:') ?></span><span class="fw-bold ${fvC}">${floatVar >= 0 ? '+' : ''}${floatVar.toLocaleString()} TZS</span></div>`;
                    Swal.fire({icon: hasVar ? 'warning' : 'success', title: '<?= t('Shift Closed!') ?>', html: html, showConfirmButton: true})
                        .then(() => location.reload());
                } else {
                    Swal.fire({icon: 'error', title: '<?= t('Error') ?>', text: r.message});
                }
            },
            error: (xhr) => {
                btn.prop('disabled', false).html(orig);
                Swal.fire({icon: 'error', title: '<?= t('Error') ?>', text: xhr.responseJSON?.message || '<?= t('Server error.') ?>'});
            }
        });
    });

    // ── Reset modals on close ──────────────────────────────────────────────────
    $('.modal').on('hidden.bs.modal', function () { $(this).find('form')[0]?.reset(); });
});

function closeShift(s) {
    $('#close_shift_id').val(s.id);
    $('#close_shift_info').html('<strong><?= t('Shift:') ?> ' + safeOutput(s.code) + '</strong> · ' + safeOutput(s.agent) + ' / ' + safeOutput(s.till_number));
    new bootstrap.Modal(document.getElementById('closeShiftModal')).show();
}

function renderCards(nodes) {
    if (!nodes.length) { $('#cardView').html('<div class="col-12 text-center py-5 text-muted"><?= t('No shifts') ?></div>'); return; }
    let html = '';
    $(nodes).each(function (idx) {
        const $tr = $(this);
        const id = $tr.data('id'), sno = idx + 1;
        const code = $tr.data('code'), outlet = $tr.data('outlet');
        const teller = $tr.data('teller'), opened = $tr.data('opened');
        const volume = $tr.data('volume'), status = $tr.data('status');
        const canClose = $tr.data('can-close') == 1, shiftData = $tr.data('shift');
        const sBadge = status === 'open' ? 'bg-success' : (status === 'forced_close' ? 'bg-danger' : 'bg-secondary');
        html += `<div class="col-12"><div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden">
          <div class="card-body p-3 pb-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="mm-sno">${sno}</span>
              <span class="badge ${sBadge}" style="font-size:.73rem">${safeOutput(status.replace('_',' ').replace(/\b\w/g,c=>c.toUpperCase()))}</span>
            </div>
            <div class="fw-semibold mb-2" style="font-size:.95rem"><code>${safeOutput(code)}</code></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Outlet / Till') ?></span><span class="kv-val">${safeOutput(outlet)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Teller') ?></span><span class="kv-val">${safeOutput(teller)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Opened') ?></span><span class="kv-val">${safeOutput(opened)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Volume (TZS)') ?></span><span class="kv-val fw-bold">${safeOutput(volume)}</span></div>
          </div>
          <div class="mm-card-foot">
            <a href="<?= getUrl('mm_shift_report') ?>?id=${id}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i><?= t('Report') ?></a>
            ${canClose ? `<button class="btn btn-sm btn-outline-danger" onclick='closeShift(${JSON.stringify(shiftData)})'><i class="bi bi-stop-circle me-1"></i><?= t('Close') ?></button>` : ''}
          </div>
        </div></div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php includeFooter(); ?>
