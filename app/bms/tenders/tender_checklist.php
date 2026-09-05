<?php
// File: app/bms/tenders/tender_checklist.php
// scope-audit: skip — tender checklist view; tenders reference customers (no direct project_id); deferred to Phase G-2
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/tender_checklist.php';

autoEnforcePermission('tenders');
$can_edit = canEdit('tenders') || canCreate('tenders');

includeHeader();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $pdo->prepare("SELECT tender_id, tender_no, boq_grand_total FROM tenders WHERE tender_id = ?");
$stmt->execute([$id]);
$tender = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tender) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Tender not found.</div></div>";
    includeFooter();
    exit;
}

// Seed on first visit for any tender that predates this feature and slipped
// past the migration backfill for some reason — belt and braces, cheap check.
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM tender_checklist_items WHERE tender_id = ?");
$countStmt->execute([$id]);
if ((int)$countStmt->fetchColumn() === 0) {
    seedTenderChecklist($pdo, $id);
}

$itemsStmt = $pdo->prepare("SELECT * FROM tender_checklist_items WHERE tender_id = ? ORDER BY sort_order, item_id");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($items);
$ready = count(array_filter($items, fn($i) => (int)$i['is_ready'] === 1));

$materialsCountStmt = $pdo->prepare("SELECT COUNT(*) FROM tender_materials WHERE tender_id = ?");
$materialsCountStmt->execute([$id]);
$materialsCount = (int)$materialsCountStmt->fetchColumn();

// Items where we have real, concrete data elsewhere in the tender to point
// to — shown as a "View ->" hint next to the checklist row. Deliberately NOT
// auto-toggling the checkbox itself: a hint the user can check against is
// safe, silently flipping a box the user may have already unchecked is not.
$hints = [
    'Priced Bills of Quantities — signed & stamped' => ((float)$tender['boq_grand_total'] > 0)
        ? ['label' => 'BOQ priced at TZS ' . number_format((float)$tender['boq_grand_total'], 2), 'route' => 'tender_boq']
        : null,
    'Materials Schedule & delivery plan' => ($materialsCount > 0)
        ? ['label' => "$materialsCount material line(s) entered", 'route' => 'tender_materials']
        : null,
];

$tenderNavActive = 'checklist';
?>

<div class="container-fluid px-4 mt-4 mb-5">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
        <div class="mb-3 mb-md-0 text-center text-md-start">
            <h2 class="fw-bold text-primary"><i class="bi bi-check2-square me-2"></i>Compliance Checklist</h2>
            <p class="text-muted small mb-0">Tender No: <span class="fw-bold text-dark"><?= safe_output($tender['tender_no']) ?></span></p>
        </div>
        <a href="<?= getUrl('tenders') ?>" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-arrow-left"></i> Back to List</a>
    </div>

    <?php require __DIR__ . '/_tender_nav.php'; ?>

    <div id="checklist-message" class="mb-3"></div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="text-muted small mb-0">The standard East African / PPRA submission requirements. Tick each item once it is prepared, signed and stamped — a missing document is the most common cause of disqualification.</p>
        <span class="badge bg-primary fs-6" id="readyCounter"><?= $ready ?> / <?= $total ?> ready</span>
    </div>

    <div class="card border-0 shadow-sm">
        <ul class="list-group list-group-flush" id="checklistList">
            <?php foreach ($items as $item): $hint = $hints[$item['item_text']] ?? null; ?>
            <li class="list-group-item d-flex justify-content-between align-items-center" data-item-id="<?= $item['item_id'] ?>">
                <div class="form-check flex-grow-1">
                    <input class="form-check-input checklist-toggle" type="checkbox" id="chk<?= $item['item_id'] ?>"
                           <?= $item['is_ready'] ? 'checked' : '' ?> <?= $can_edit ? '' : 'disabled' ?>>
                    <label class="form-check-label <?= $item['is_ready'] ? 'text-decoration-line-through text-muted' : '' ?>" for="chk<?= $item['item_id'] ?>">
                        <?= safe_output($item['item_text']) ?>
                        <?php if ($item['is_custom']): ?><span class="badge bg-light text-dark border ms-1">custom</span><?php endif; ?>
                    </label>
                    <?php if ($hint): ?>
                        <br><a href="<?= getUrl($hint['route']) ?>?id=<?= $id ?>" class="small text-decoration-none"><i class="bi bi-info-circle"></i> <?= safe_output($hint['label']) ?> — View</a>
                    <?php endif; ?>
                </div>
                <?php if ($can_edit && $item['is_custom']): ?>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteChecklistItem(<?= $item['item_id'] ?>)"><i class="bi bi-x"></i></button>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if ($can_edit): ?>
    <div class="d-flex gap-2 mt-3">
        <input type="text" class="form-control" id="newItemText" placeholder="Add a custom requirement specific to this tender...">
        <button type="button" class="btn btn-outline-primary text-nowrap" onclick="addChecklistItem()"><i class="bi bi-plus-circle"></i> Add</button>
    </div>
    <?php endif; ?>
</div>

<script>
const CHECKLIST_API = '<?= buildUrl('api/tender_checklist.php') ?>';
const TENDER_ID = <?= (int)$id ?>;

function checklistMessage(type, text) {
    $('#checklist-message').html(`<div class="alert alert-${type} py-2">${text}</div>`);
}

function updateCounter(counts) {
    $('#readyCounter').text(`${counts.ready} / ${counts.total} ready`);
}

$(document).on('change', '.checklist-toggle', function () {
    const itemId = $(this).closest('li').data('item-id');
    const isReady = $(this).is(':checked');
    $(this).next('label').toggleClass('text-decoration-line-through text-muted', isReady);
    $.post(CHECKLIST_API, { action: 'TOGGLE_ITEM', tender_id: TENDER_ID, item_id: itemId, is_ready: isReady ? 1 : 0, _csrf: '<?= csrf_token() ?>' }, function (res) {
        if (res.success) { updateCounter(res.counts); } else { checklistMessage('danger', res.message); }
    }, 'json');
});

function addChecklistItem() {
    const text = $('#newItemText').val().trim();
    if (!text) return;
    $.post(CHECKLIST_API, { action: 'ADD_ITEM', tender_id: TENDER_ID, item_text: text, _csrf: '<?= csrf_token() ?>' }, function (res) {
        if (res.success) { location.reload(); } else { checklistMessage('danger', res.message); }
    }, 'json');
}

function deleteChecklistItem(itemId) {
    $.post(CHECKLIST_API, { action: 'DELETE_ITEM', tender_id: TENDER_ID, item_id: itemId, _csrf: '<?= csrf_token() ?>' }, function (res) {
        if (res.success) { location.reload(); } else { checklistMessage('danger', res.message); }
    }, 'json');
}
</script>

<?php includeFooter(); ?>
