<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_transactions');
includeHeader();
logActivity($pdo, $_SESSION['user_id'], 'View Transactions', 'Viewed Mobile Money Transactions');
?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-arrow-left-right text-primary fs-4"></i>
        <h4 class="mb-0 fw-bold"><?= t('Transactions') ?></h4>
        <span class="badge bg-primary ms-2"><?= t('Coming in Phase 2') ?></span>
    </div>
    <div class="alert alert-primary">
        <i class="bi bi-info-circle me-2"></i>
        <?= t('This section will be built in Phase 2 of the Mobile Money module.') ?>
    </div>
</div>
<?php includeFooter(); ?>
