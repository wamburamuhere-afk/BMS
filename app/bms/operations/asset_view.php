<?php
/**
 * app/bms/operations/asset_view.php
 *
 * Asset detail page (Asset Register & PPE Schedule, Phase 5.4): the full master
 * record, both depreciation areas (book + tax) with live values and the posted
 * period schedule, maintenance history, the immutable audit log, photo, and a
 * QR code generated from the asset code.
 */
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/asset_depreciation_service.php';
require_once __DIR__ . '/../../../core/asset_settings.php';

autoEnforcePermission('assets');

$asset_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── Load the asset with category / custodian / supplier names ────────────────
// scope-audit: skip — assets have no project_id; suppliers is joined below
// only to show the supplier name, not to expose project-scoped data.
$stmt = $pdo->prepare("
    SELECT a.*,
           c.category_name, c.is_depreciable, c.gl_asset_account, c.gl_accum_account, c.gl_expense_account,
           TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS custodian_name,
           u.username AS custodian_username,
           s.supplier_name
      FROM assets a
      LEFT JOIN asset_categories c ON c.category_id = a.category_id
      LEFT JOIN users u            ON u.user_id     = a.custodian_id
      LEFT JOIN suppliers s        ON s.supplier_id = a.supplier_id
     WHERE a.asset_id = ? AND a.status != 'deleted'
");
$stmt->execute([$asset_id]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);

includeHeader();

if (!$asset) {
    echo '<div class="container-fluid py-5"><div class="alert alert-warning">Asset not found. <a href="' . getUrl('assets') . '">Back to register</a></div></div>';
    includeFooter();
    ob_end_flush();
    return;
}

$cost    = (float)$asset['cost'];
$timing  = getAssetSettings($pdo)['depreciation_timing'];
$today   = date('Y-m-d');

// ── Depreciation areas + live values ─────────────────────────────────────────
$areaStmt = $pdo->prepare("SELECT * FROM asset_depreciation_areas WHERE asset_id = ?");
$areaStmt->execute([$asset_id]);
$areaRows = [];
foreach ($areaStmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $areaRows[$r['area']] = $r; }

$liveCalc = [];
foreach (['book', 'tax'] as $area) {
    if (isset($areaRows[$area])) {
        $liveCalc[$area] = calcAreaDepreciation($areaRows[$area], $cost, $today, $timing);
    }
}

// Posted depreciation entries per area.
$entStmt = $pdo->prepare("SELECT * FROM depreciation_entries WHERE asset_id = ? ORDER BY area, period_end");
$entStmt->execute([$asset_id]);
$entries = ['book' => [], 'tax' => []];
foreach ($entStmt->fetchAll(PDO::FETCH_ASSOC) as $e) { $entries[$e['area']][] = $e; }

// Maintenance history.
$mStmt = $pdo->prepare("SELECT * FROM asset_maintenance WHERE asset_id = ? ORDER BY maintenance_date DESC");
$mStmt->execute([$asset_id]);
$maintenance = $mStmt->fetchAll(PDO::FETCH_ASSOC);

// Audit log (with the user who made each change).
$aStmt = $pdo->prepare("
    SELECT l.*, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS changed_name, u.username
      FROM asset_audit_log l
      LEFT JOIN users u ON u.user_id = l.changed_by
     WHERE l.asset_id = ?
  ORDER BY l.changed_at DESC, l.id DESC
");
$aStmt->execute([$asset_id]);
$auditLog = $aStmt->fetchAll(PDO::FETCH_ASSOC);

// Disposal snapshot (if any).
$dStmt = $pdo->prepare("SELECT * FROM asset_disposals WHERE asset_id = ?");
$dStmt->execute([$asset_id]);
$disposal = $dStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$can_edit  = canEdit('assets');
$is_disposed = in_array($asset['status'], ['disposed','written_off'], true) || $disposal;

$statusColors = ['active'=>'success','maintenance'=>'warning','disposed'=>'danger','written_off'=>'danger'];
$condColors   = ['excellent'=>'success','good'=>'info','fair'=>'warning','poor'=>'danger','eol'=>'dark'];
function tzs($v) { return number_format((float)$v, 2) . ' TZS'; }
?>

<div class="container-fluid py-4">
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('assets') ?>">Assets</a></li>
            <li class="breadcrumb-item active"><?= safe_output($asset['asset_code']) ?></li>
        </ol>
    </nav>

    <!-- Header -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h3 class="fw-bold mb-1"><?= safe_output($asset['asset_name']) ?></h3>
                    <div class="text-muted">
                        <span class="badge bg-dark-subtle text-dark border me-2"><?= safe_output($asset['asset_code']) ?></span>
                        <?= safe_output($asset['category_name'] ?: $asset['category']) ?>
                        <span class="badge bg-<?= $statusColors[$asset['status']] ?? 'secondary' ?> ms-2"><?= ucfirst($asset['status']) ?></span>
                        <?php if ($asset['condition']): ?>
                        <span class="badge bg-<?= $condColors[$asset['condition']] ?? 'secondary' ?>-subtle text-<?= $condColors[$asset['condition']] ?? 'secondary' ?>-emphasis border ms-1"><?= ucfirst($asset['condition']) ?></span>
                        <?php endif; ?>
                        <span class="badge bg-light text-dark border ms-1"><?= $asset['acquisition_type'] === 'existing' ? 'Take-on' : 'New acquisition' ?></span>
                    </div>
                </div>
                <div class="text-end d-print-none">
                    <?php if ($can_edit): ?>
                        <?php if (!$is_disposed): ?>
                        <a href="<?= getUrl('assets') ?>?dep_asset=<?= $asset_id ?>" class="btn btn-outline-primary"><i class="bi bi-calculator me-1"></i> Run Depreciation</a>
                        <?php endif; ?>
                        <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#maintenanceModal"><i class="bi bi-tools me-1"></i> Log Maintenance</button>
                        <?php if (!$is_disposed): ?>
                        <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#disposeModal"><i class="bi bi-box-arrow-right me-1"></i> Dispose</button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <a href="<?= getUrl('assets') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back</a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($disposal): ?>
    <div class="card border-0 shadow-sm mb-4 border-start border-danger border-4">
        <div class="card-body">
            <h6 class="fw-bold text-danger mb-3"><i class="bi bi-box-arrow-right me-1"></i> Disposed (<?= ucfirst(str_replace('_',' ',$disposal['method'])) ?>) on <?= safe_output($disposal['disposal_date']) ?></h6>
            <div class="row g-3 text-center">
                <div class="col-6 col-md-2"><div class="small text-muted">Original Cost</div><div class="fw-bold"><?= tzs($disposal['original_cost']) ?></div></div>
                <div class="col-6 col-md-2"><div class="small text-muted">Accum Dep (Book)</div><div class="fw-bold"><?= tzs($disposal['accum_dep_book_at_disposal']) ?></div></div>
                <div class="col-6 col-md-2"><div class="small text-muted">NBV at Disposal</div><div class="fw-bold"><?= tzs($disposal['nbv_at_disposal']) ?></div></div>
                <div class="col-6 col-md-2"><div class="small text-muted">Proceeds</div><div class="fw-bold"><?= tzs($disposal['proceeds']) ?></div></div>
                <div class="col-6 col-md-2"><div class="small text-muted">Gain / (Loss)</div><div class="fw-bold <?= $disposal['gain_loss'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= tzs($disposal['gain_loss']) ?></div></div>
                <div class="col-6 col-md-2"><div class="small text-muted">Accum Dep (Tax)</div><div class="fw-bold"><?= tzs($disposal['accum_dep_tax_at_disposal']) ?></div></div>
            </div>
            <?php if ($disposal['notes']): ?><div class="small text-muted mt-2"><i class="bi bi-sticky me-1"></i><?= safe_output($disposal['notes']) ?></div><?php endif; ?>
            <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i> Gain/loss is recognised in the P&amp;L and is <strong>not</strong> part of the PPE movement schedule.</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Left: identification + assignment + photo/QR -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-bold"><i class="bi bi-info-circle me-1"></i> Details</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th class="text-muted">Serial No.</th><td><?= safe_output($asset['serial_number'], '—') ?></td></tr>
                        <tr><th class="text-muted">Location</th><td><?= safe_output($asset['location'], '—') ?></td></tr>
                        <tr><th class="text-muted">Custodian</th><td><?= safe_output(trim($asset['custodian_name']) ?: $asset['custodian_username'], '—') ?></td></tr>
                        <tr><th class="text-muted">Supplier</th><td><?= safe_output($asset['supplier_name'], '—') ?></td></tr>
                        <tr><th class="text-muted">Invoice Ref</th><td><?= safe_output($asset['invoice_ref'], '—') ?></td></tr>
                        <tr><th class="text-muted">Purchase Date</th><td><?= safe_output($asset['purchase_date'], '—') ?></td></tr>
                        <tr><th class="text-muted">Capitalization</th><td><?= safe_output($asset['capitalization_date'], '—') ?></td></tr>
                        <?php if ($asset['acquisition_type'] === 'existing'): ?>
                        <tr><th class="text-muted">Take-on Date</th><td><?= safe_output($asset['take_on_date'], '—') ?></td></tr>
                        <?php endif; ?>
                        <tr><th class="text-muted">Cost</th><td class="fw-bold"><?= tzs($cost) ?></td></tr>
                    </table>
                    <?php if ($asset['description']): ?>
                    <hr><div class="small text-muted"><?= nl2br(safe_output($asset['description'], '')) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-bold"><i class="bi bi-qr-code me-1"></i> Tag &amp; Photo</div>
                <div class="card-body text-center">
                    <div id="qrcode" class="d-inline-block mb-2"></div>
                    <div class="small text-muted mb-3"><?= safe_output($asset['asset_code']) ?></div>
                    <?php if ($asset['photo_path']): ?>
                        <img src="<?= getUrl($asset['photo_path']) ?>" alt="Asset photo" class="img-fluid rounded border">
                    <?php else: ?>
                        <div class="text-muted small"><i class="bi bi-image me-1"></i> No photo uploaded</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: depreciation areas + maintenance + audit -->
        <div class="col-lg-8">
            <div class="row g-4 mb-1">
                <?php foreach (['book' => 'Book Area (financial statements)', 'tax' => 'Tax Area (capital allowances)'] as $area => $title):
                    $a = $areaRows[$area] ?? null; $lc = $liveCalc[$area] ?? null; ?>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white fw-bold">
                            <i class="bi bi-<?= $area === 'book' ? 'journal-text text-primary' : 'bank text-success' ?> me-1"></i> <?= $title ?>
                        </div>
                        <div class="card-body">
                            <?php if (!$a): ?>
                                <div class="text-muted small">No <?= $area ?> area configured<?= $asset['is_depreciable'] === '0' ? ' (non-depreciable category).' : '.' ?></div>
                            <?php else: ?>
                                <table class="table table-sm mb-2">
                                    <tr><th class="text-muted">Method</th><td><?= $a['method'] === 'straight_line' ? 'Straight Line' : ($a['method'] === 'reducing_balance' ? 'Reducing Balance' : '—') ?></td></tr>
                                    <?php if ($a['method'] === 'straight_line'): ?>
                                    <tr><th class="text-muted">Useful Life</th><td><?= $a['useful_life'] ? (int)$a['useful_life'] . ' yrs' : '—' ?></td></tr>
                                    <?php else: ?>
                                    <tr><th class="text-muted">Rate</th><td><?= $a['rate'] !== null ? rtrim(rtrim((string)$a['rate'],'0'),'.') . '%' : '—' ?></td></tr>
                                    <?php endif; ?>
                                    <tr><th class="text-muted">Salvage</th><td><?= tzs($a['salvage_value']) ?></td></tr>
                                    <tr><th class="text-muted">Start</th><td><?= safe_output($a['start_date'], '—') ?></td></tr>
                                    <?php if ((float)$a['opening_accum_bf'] > 0): ?>
                                    <tr><th class="text-muted">Opening b/f</th><td><?= tzs($a['opening_accum_bf']) ?></td></tr>
                                    <?php endif; ?>
                                </table>
                                <div class="bg-light rounded p-2">
                                    <div class="d-flex justify-content-between small"><span class="text-muted">Accumulated (today):</span> <span class="fw-semibold"><?= tzs($lc['accumulated']) ?></span></div>
                                    <div class="d-flex justify-content-between"><span class="text-muted"><?= $area === 'book' ? 'Net Book Value' : 'Written-Down Value' ?>:</span> <span class="fw-bold text-<?= $area === 'book' ? 'primary' : 'success' ?>"><?= tzs($lc['nbv']) ?></span></div>
                                </div>
                                <?php if ($entries[$area]): ?>
                                <details class="mt-2">
                                    <summary class="small text-muted" style="cursor:pointer">Posted schedule (<?= count($entries[$area]) ?> period<?= count($entries[$area]) > 1 ? 's' : '' ?>)</summary>
                                    <table class="table table-sm table-bordered mt-2 mb-0 small">
                                        <thead class="table-light"><tr><th>Period End</th><th class="text-end">Charge</th><th class="text-end">Accum.</th><th class="text-end">Closing NBV</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($entries[$area] as $e): ?>
                                            <tr><td><?= safe_output($e['period_end']) ?></td><td class="text-end"><?= number_format($e['charge'],0) ?></td><td class="text-end"><?= number_format($e['accumulated'],0) ?></td><td class="text-end"><?= number_format($e['closing_nbv'],0) ?></td></tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </details>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Maintenance history -->
            <div class="card border-0 shadow-sm mb-4 mt-1">
                <div class="card-header bg-white fw-bold"><i class="bi bi-tools me-1"></i> Maintenance History</div>
                <div class="card-body p-0">
                    <?php if (!$maintenance): ?>
                        <div class="p-3 text-muted small">No maintenance recorded.</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th class="ps-3">Date</th><th>Description</th><th class="text-end">Cost</th><th>By</th><th>Next Due</th></tr></thead>
                            <tbody>
                            <?php foreach ($maintenance as $m): ?>
                                <tr>
                                    <td class="ps-3"><?= safe_output($m['maintenance_date']) ?></td>
                                    <td><?= safe_output($m['description'], '—') ?></td>
                                    <td class="text-end"><?= tzs($m['cost']) ?></td>
                                    <td><?= safe_output($m['performed_by'], '—') ?></td>
                                    <td><?= safe_output($m['next_due_date'], '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Audit log -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold"><i class="bi bi-clock-history me-1"></i> Change History</div>
                <div class="card-body p-0">
                    <?php if (!$auditLog): ?>
                        <div class="p-3 text-muted small">No changes logged.</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th class="ps-3">When</th><th>Action</th><th>Field</th><th>Old → New</th><th>By</th></tr></thead>
                            <tbody>
                            <?php foreach ($auditLog as $l): ?>
                                <tr>
                                    <td class="ps-3 small text-muted"><?= safe_output($l['changed_at']) ?></td>
                                    <td><span class="badge bg-secondary-subtle text-secondary-emphasis border"><?= safe_output($l['action']) ?></span></td>
                                    <td class="small"><?= safe_output($l['field_changed'], '—') ?></td>
                                    <td class="small"><?= $l['field_changed'] ? safe_output($l['old_value'], '—') . ' → ' . safe_output($l['new_value'], '—') : safe_output($l['new_value'], '') ?></td>
                                    <td class="small"><?= safe_output(trim($l['changed_name']) ?: $l['username'], '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($can_edit): ?>
<!-- Log Maintenance Modal -->
<div class="modal fade" id="maintenanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning"><h5 class="modal-title"><i class="bi bi-tools me-1"></i> Log Maintenance</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form id="maintenanceForm">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="asset_id" value="<?= (int)$asset_id ?>">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label fw-semibold">Date <span class="text-danger">*</span></label><input type="date" name="maintenance_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Cost (TZS)</label><input type="number" name="cost" class="form-control" step="0.01" min="0" value="0"></div>
                        <div class="col-12"><label class="form-label fw-semibold">Description</label><textarea name="description" class="form-control" rows="2" placeholder="What was done"></textarea></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Performed By</label><input type="text" name="performed_by" class="form-control" placeholder="Vendor / technician"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Next Due Date</label><input type="date" name="next_due_date" class="form-control"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning"><i class="bi bi-check-lg me-1"></i> Save</button></div>
            </form>
        </div>
    </div>
</div>

<?php if (!$is_disposed): ?>
<!-- Dispose Modal -->
<div class="modal fade" id="disposeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="bi bi-box-arrow-right me-1"></i> Dispose Asset</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form id="disposeForm">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="asset_id" value="<?= (int)$asset_id ?>">
                    <div class="alert alert-light small">Accumulated depreciation is snapshotted as at the disposal date; gain/(loss) = proceeds − NBV at disposal.</div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label fw-semibold">Disposal Date <span class="text-danger">*</span></label><input type="date" name="disposal_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Method <span class="text-danger">*</span></label>
                            <select name="method" class="form-select" required>
                                <option value="sold">Sold</option>
                                <option value="scrapped">Scrapped</option>
                                <option value="donated">Donated</option>
                                <option value="written_off">Written Off</option>
                            </select>
                        </div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Proceeds (TZS)</label><input type="number" name="proceeds" class="form-control" step="0.01" min="0" value="0"></div>
                        <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger"><i class="bi bi-box-arrow-right me-1"></i> Dispose</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
$(document).ready(function() {
    try {
        new QRCode(document.getElementById('qrcode'), {
            text: <?= json_encode($asset['qr_code'] ?: $asset['asset_code']) ?>,
            width: 140, height: 140
        });
    } catch (e) { $('#qrcode').html('<span class="text-muted small">QR unavailable offline</span>'); }

    function submitAsset(formSel, url, btnText) {
        $(formSel).on('submit', function(e) {
            e.preventDefault();
            const btn = $(this).find('[type="submit"]');
            const orig = btn.html();
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> ' + btnText);
            $.ajax({
                url: url, type: 'POST', dataType: 'json', data: $(this).serialize(),
                success: function(res) {
                    if (res.success) {
                        Swal.fire({ icon:'success', title:'Done', text:res.message, timer:1600, showConfirmButton:false })
                            .then(() => location.reload());
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text:res.message || 'Failed' });
                        btn.prop('disabled', false).html(orig);
                    }
                },
                error: function() { Swal.fire({ icon:'error', title:'Error', text:'Server error.' }); btn.prop('disabled', false).html(orig); }
            });
        });
    }
    submitAsset('#maintenanceForm', '<?= buildUrl('api/operations/save_maintenance.php') ?>', 'Saving…');
    submitAsset('#disposeForm', '<?= buildUrl('api/operations/dispose_asset.php') ?>', 'Disposing…');
});
</script>

<?php
includeFooter();
ob_end_flush();
?>
