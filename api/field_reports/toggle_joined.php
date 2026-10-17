<?php
// POST visit_id, joined (1|0) — "this client joined our system". Owner or admin only.
// Not counted as a change to a submitted report: joining is expected to happen later.
// On the platform root (BJP selling the system) "joined" can also carry the client's
// subdomain, linking the visit to their company on the platform (customer_subscription_plan.md Phase 3).
require_once __DIR__ . '/_common.php';
require_once ROOT_DIR . '/core/vendor_context.php';
require_once ROOT_DIR . '/core/vendor_link.php';
frRequirePost();

if (!canEdit('field_visits')) frJson(['success' => false, 'message' => t('Permission denied')], 403);
$id = (int)($_POST['visit_id'] ?? 0);
$v = $id > 0 ? frGetVisit($pdo, $id) : null;
if (!$v) frJson(['success' => false, 'message' => t('Visit not found')], 404);
if (!frCanTouch($v)) frJson(['success' => false, 'message' => t('Permission denied')], 403);

$joined = (string)($_POST['joined'] ?? '') === '1' ? 1 : 0;
$hasLinkCols = array_key_exists('tenant_id', $v);

// Optional subdomain (vendor only). Blank = joined without naming the company yet.
$tenant = null;
$rawSub = trim((string)($_POST['subdomain'] ?? ''));
if ($joined && $rawSub !== '' && isPlatformVendorContext() && $hasLinkCols) {
    $sub = vlNormalizeSubdomain($rawSub);
    $tenant = $sub !== '' ? vlFindTenant($sub) : null;
    if (!$tenant) frJson(['success' => false, 'message' => t('No company with that web address is registered on the platform. Check the spelling.')], 422);
}

try {
    $me = (int)$_SESSION['user_id'];
    $pdo->prepare("UPDATE field_visits SET joined = ?, joined_at = ?, joined_marked_by = ?, updated_by = ? WHERE visit_id = ?")
        ->execute([$joined, $joined ? ($v['joined_at'] ?: date('Y-m-d')) : null, $joined ? $me : null, $me, $id]);

    $note = '';
    if ($hasLinkCols) {
        if ($tenant) {
            $pdo->prepare("UPDATE field_visits SET tenant_id = ?, tenant_subdomain = ? WHERE visit_id = ?")
                ->execute([(int)$tenant['id'], $tenant['subdomain'], $id]);
            $note = ' — ' . $tenant['subdomain'];
        } elseif (!$joined && empty($v['customer_id'])) {
            // Un-joining forgets the company, unless the visit already became a real customer.
            $pdo->prepare("UPDATE field_visits SET tenant_id = NULL, tenant_subdomain = NULL WHERE visit_id = ?")->execute([$id]);
        }
    }
    logActivity($pdo, $me, 'Field visit joined', ($joined ? 'Marked joined' : 'Unmarked joined') . " — field visit #$id ({$v['client_name']})$note");
    frJson(['success' => true, 'joined' => $joined, 'tenant_subdomain' => $tenant['subdomain'] ?? null,
            'message' => $joined ? t('Marked as joined.') : t('Joined mark removed.')]);
} catch (PDOException $e) {
    error_log('field_reports/toggle_joined: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
