<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_networks');
includeHeader();
logActivity($pdo, $_SESSION['user_id'], 'View Networks', 'Viewed Mobile Money Networks');
?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-broadcast text-primary fs-4"></i>
        <h4 class="mb-0 fw-bold"><?= t('Networks') ?></h4>
        <span class="badge bg-primary ms-2"><?= t('Coming in Phase 1') ?></span>
    </div>
    <div class="alert alert-primary">
        <i class="bi bi-info-circle me-2"></i>
        <?= t('This section will be built in Phase 1 of the Mobile Money module.') ?>
    </div>
</div>
<?php includeFooter(); ?>
