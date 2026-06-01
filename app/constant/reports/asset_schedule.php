<?php
/**
 * app/constant/reports/asset_schedule.php
 *
 * Asset / PPE Schedule report (Asset Register & PPE Schedule, Phase 7.5):
 * the grouped Cost → Depreciation → Net Book Value movement for a financial
 * year, categories across columns and movement lines down rows, with a TOTAL
 * column and a Book / Tax area switch. Print + Excel export.
 */
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/asset_ppe_schedule_service.php';
require_once __DIR__ . '/../../../core/asset_depreciation_run.php';
require_once __DIR__ . '/../../../core/asset_settings.php';

autoEnforcePermission('assets');

$settings = getAssetSettings($pdo);
$defaultFy = (int)date('Y', strtotime($settings['financial_year_start']));
$fy   = isset($_GET['fy']) && $_GET['fy'] !== '' ? (int)$_GET['fy'] : $defaultFy;
$area = (isset($_GET['area']) && $_GET['area'] === 'tax') ? 'tax' : 'book';

[$ps, $pe] = fyBoundsForYear($settings, $fy);
$schedule  = buildPpeSchedule($pdo, $ps, $pe, $area);
$rows   = $schedule['rows'];
$totals = $schedule['totals'];

if (function_exists('logActivity')) {
    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Viewed PPE Schedule', "FY $fy, area $area");
}

includeHeader();

// Movement lines (row label, key, section, indent).
$costLines = [
    ['Opening',   'cost_opening'],
    ['Additions', 'cost_additions'],
    ['Disposals', 'cost_disposals'],
    ['Closing',   'cost_closing'],
];
$depLines = [
    ['Opening',          'dep_opening'],
    ['Charge for year',  'dep_charge'],
    ['Less on disposal', 'dep_disposal'],
    ['Closing',          'dep_closing'],
];
function ncell($v) { return number_format((float)$v, 0); }
?>

<div class="container-fluid py-4">
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('assets') ?>">Assets</a></li>
            <li class="breadcrumb-item active">PPE Schedule</li>
        </ol>
    </nav>

    <div class="row mb-3 align-items-center">
        <div class="col-md-7">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-table me-2"></i> Asset / PPE Schedule</h2>
            <p class="text-muted small mb-0">Property, Plant &amp; Equipment movement — Cost → Depreciation → Net Book Value.</p>
        </div>
        <div class="col-md-5">
            <form class="row g-2 justify-content-end d-print-none" method="get" action="<?= getUrl('asset_schedule') ?>">
                <div class="col-auto">
                    <select name="area" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="book" <?= $area==='book'?'selected':'' ?>>Book (financial statements)</option>
                        <option value="tax"  <?= $area==='tax' ?'selected':'' ?>>Tax (capital allowances)</option>
                    </select>
                </div>
                <div class="col-auto">
                    <input type="number" name="fy" class="form-select form-select-sm" style="width:110px" value="<?= $fy ?>" min="2000" max="2100" placeholder="FY">
                </div>
                <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat"></i></button></div>
                <div class="col-auto"><button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></div>
                <div class="col-auto"><a class="btn btn-outline-success btn-sm" href="<?= buildUrl('api/assets/export_ppe_schedule.php') ?>?fy=<?= $fy ?>&area=<?= $area ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a></div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><?= strtoupper($area) ?> SCHEDULE — FY <?= $fy ?> <span class="text-muted fw-normal">(<?= safe_output($ps) ?> to <?= safe_output($pe) ?>)</span></h6>
                <span class="badge bg-<?= $area==='book'?'primary':'success' ?>-subtle text-<?= $area==='book'?'primary':'success' ?>-emphasis border"><?= $area==='book'?'Book area':'Tax area' ?></span>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (!$rows): ?>
                <div class="p-4 text-muted text-center">No assets to report for this period.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-sm mb-0 align-middle" style="min-width:680px">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="min-width:200px">TZS</th>
                            <?php foreach ($rows as $r): ?>
                                <th class="text-end"><?= safe_output($r['category']) ?><?= $r['is_depreciable'] ? '' : ' <span class="badge bg-info-subtle text-info-emphasis border">Land</span>' ?></th>
                            <?php endforeach; ?>
                            <th class="text-end bg-light">TOTAL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-secondary"><td colspan="<?= count($rows)+2 ?>" class="fw-bold ps-3">COST</td></tr>
                        <?php foreach ($costLines as [$label,$key]): ?>
                        <tr<?= $key==='cost_closing' ? ' class="fw-bold border-top"' : '' ?>>
                            <td class="ps-4"><?= $label ?></td>
                            <?php foreach ($rows as $r): ?><td class="text-end"><?= ncell($r[$key]) ?></td><?php endforeach; ?>
                            <td class="text-end bg-light fw-bold"><?= ncell($totals[$key]) ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <tr class="table-secondary"><td colspan="<?= count($rows)+2 ?>" class="fw-bold ps-3">ACCUMULATED DEPRECIATION</td></tr>
                        <?php foreach ($depLines as [$label,$key]): ?>
                        <tr<?= $key==='dep_closing' ? ' class="fw-bold border-top"' : '' ?>>
                            <td class="ps-4"><?= $label ?></td>
                            <?php foreach ($rows as $r): ?><td class="text-end"><?= ncell($r[$key]) ?></td><?php endforeach; ?>
                            <td class="text-end bg-light fw-bold"><?= ncell($totals[$key]) ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <tr class="table-primary fw-bold">
                            <td class="ps-3">NET BOOK VALUE</td>
                            <?php foreach ($rows as $r): ?><td class="text-end"><?= ncell($r['nbv']) ?></td><?php endforeach; ?>
                            <td class="text-end fw-bold"><?= ncell($totals['nbv']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="p-3 small text-muted">
                <i class="bi bi-info-circle me-1"></i> Closing Cost − Closing Accumulated Depreciation = Net Book Value, per category and in total. Gains/losses on disposal are recognised in the P&amp;L and are not shown here. Depreciation figures come from posted runs — use <a href="<?= getUrl('assets') ?>">Run Depreciation</a> to post the period first.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php includeFooter(); ?>
