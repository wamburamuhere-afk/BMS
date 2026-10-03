<?php
// POST visit_id — soft delete. Owner or admin only.
require_once __DIR__ . '/_common.php';
frRequirePost();

if (!canDelete('field_visits')) frJson(['success' => false, 'message' => t('Permission denied')], 403);
$id = (int)($_POST['visit_id'] ?? 0);
$v = $id > 0 ? frGetVisit($pdo, $id) : null;
if (!$v) frJson(['success' => false, 'message' => t('Visit not found')], 404);
if (!frCanTouch($v)) frJson(['success' => false, 'message' => t('Permission denied')], 403);

try {
    $me = (int)$_SESSION['user_id'];
    $pdo->prepare("UPDATE field_visits SET status = 'deleted', updated_by = ? WHERE visit_id = ?")->execute([$me, $id]);
    frMarkChanged($pdo, (int)$v['user_id'], $v['visit_date']);
    logActivity($pdo, $me, 'Delete field visit', "Deleted field visit #$id ({$v['client_name']}, {$v['location']})");
    frJson(['success' => true, 'message' => t('Visit deleted.')]);
} catch (PDOException $e) {
    error_log('field_reports/delete: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
