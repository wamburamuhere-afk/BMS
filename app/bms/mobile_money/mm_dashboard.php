<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_dashboard');
includeHeader();
logActivity($pdo, $_SESSION['user_id'], 'View MM Dashboard', 'Viewed Mobile Money dashboard');
?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-phone-vibrate text-primary fs-4"></i>
        <h4 class="mb-0 fw-bold"><?= t('Mobile Money Dashboard') ?></h4>
        <span class="badge bg-primary ms-2"><?= t('Coming in Phase 7') ?></span>
    </div>
    <div class="alert alert-primary">
        <i class="bi bi-info-circle me-2"></i>
        <?= t('Mobile Money module is active. Dashboard content will be built in Phase 7.') ?>
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm" style="border:1px solid #b6ccfe!important;background:#e7f0ff;">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="bi bi-shop-window text-primary fs-3"></i>
                    <div>
                        <div class="small text-muted text-uppercase"><?= t('Agents / Outlets') ?></div>
                        <a href="<?= getUrl('mm_agents') ?>" class="btn btn-sm btn-primary mt-1"><?= t('Manage Outlets') ?></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm" style="border:1px solid #b6ccfe!important;background:#e7f0ff;">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="bi bi-arrow-left-right text-primary fs-3"></i>
                    <div>
                        <div class="small text-muted text-uppercase"><?= t('Transactions') ?></div>
                        <a href="<?= getUrl('mm_transactions') ?>" class="btn btn-sm btn-primary mt-1"><?= t('View Transactions') ?></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm" style="border:1px solid #b6ccfe!important;background:#e7f0ff;">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="bi bi-broadcast text-primary fs-3"></i>
                    <div>
                        <div class="small text-muted text-uppercase"><?= t('Networks') ?></div>
                        <a href="<?= getUrl('mm_networks') ?>" class="btn btn-sm btn-primary mt-1"><?= t('Configure Networks') ?></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php includeFooter(); ?>
