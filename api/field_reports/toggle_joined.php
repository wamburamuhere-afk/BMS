<?php
// POST visit_id, joined (1|0) — "this client joined our system". Owner or admin only.
// Not counted as a change to a submitted report: joining is expected to happen later.
require_once __DIR__ . '/_common.php';
frRequirePost();

if (!canEdit('field_visits')) frJson(['success' => false, 'message' => t('Permission denied')], 403);
$id = (int)($_POST['visit_id'] ?? 0);
$v = $id > 0 ? frGetVisit($pdo, $id) : null;
if (!$v) frJson(['success' => false, 'message' => t('Visit not found')], 404);
if (!frCanTouch($v)) frJson(['success' => false, 'message' => t('Permission denied')], 403);

$joined = (string)($_POST['joined'] ?? '') === '1' ? 1 : 0;
try {
    $me = (int)$_SESSION['user_id'];
    $pdo->prepare("UPDATE field_visits SET joined = ?, joined_at = ?, joined_marked_by = ?, updated_by = ? WHERE visit_id = ?")
        ->execute([$joined, $joined ? date('Y-m-d') : null, $joined ? $me : null, $me, $id]);
    logActivity($pdo, $me, 'Field visit joined', ($joined ? 'Marked joined' : 'Unmarked joined') . " — field visit #$id ({$v['client_name']})");
    frJson(['success' => true, 'joined' => $joined, 'message' => $joined ? t('Marked as joined.') : t('Joined mark removed.')]);
} catch (PDOException $e) {
    error_log('field_reports/toggle_joined: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
