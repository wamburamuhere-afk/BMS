<?php
// Shared start for every Field Reports API: login + module/permission gate.
// Row-level ownership (owner or admin) is enforced per endpoint via core/field_reports.php.
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/core/field_reports.php';
header('Content-Type: application/json');

function frJson(array $body, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if (!isAuthenticated()) frJson(['success' => false, 'message' => t('Unauthorized')], 401);

// APIs never include header.php, which is where the user's language is loaded —
// without this every label and message went out in English (customer_visits_ux_plan 1.1).
$_SESSION['user_lang'] = $_SESSION['user_lang'] ?? get_setting('user_language_' . (int)$_SESSION['user_id'], 'en');
loadLanguage($_SESSION['user_lang']);
if (!canView('field_visits')) frJson(['success' => false, 'message' => t('Permission denied')], 403);

function frRequirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') frJson(['success' => false, 'message' => t('Method not allowed')], 405);
    csrf_check();
}

/** A visit row as the page needs it (raw values; the page escapes on render). */
function frRowOut(array $r): array
{
    $interest = frInterestLabels();
    return [
        'visit_id'           => (int)$r['visit_id'],
        'user_id'            => (int)$r['user_id'],
        'staff_name'         => $r['staff_name'] ?? '',
        'visit_date'         => $r['visit_date'],
        'visit_time'         => $r['visit_time'] ? substr($r['visit_time'], 0, 5) : '',
        'location'           => $r['location'],
        'latitude'           => $r['latitude'] !== null ? (float)$r['latitude'] : null,
        'longitude'          => $r['longitude'] !== null ? (float)$r['longitude'] : null,
        'client_name'        => $r['client_name'],
        'client_phone'       => $r['client_phone'],
        'business_type'      => $r['business_type'],
        'business_other'     => $r['business_other'] ?? '',
        'business_label'     => frBusinessLabel($r),
        'gave_business_card' => (int)$r['gave_business_card'],
        'gave_trial_link'    => (int)$r['gave_trial_link'],
        'gave_training'      => (int)$r['gave_training'],
        'interest'           => $r['interest'] ?? '',
        'interest_label'     => $r['interest'] ? t($interest[$r['interest']] ?? '') : '',
        'notes'              => $r['notes'] ?? '',
        'joined'             => (int)$r['joined'],
        'joined_at'          => $r['joined_at'],
        'follow_up_date'     => $r['follow_up_date'] ?? null,
        'follow_up_done'     => !empty($r['follow_up_done_at']) ? 1 : 0,
        // GPS-confirmed: a position was captured with ±100 m or better (3.4).
        'gps_verified'       => frGpsVerified($r) ? 1 : 0,
        'gps_accuracy_m'     => $r['gps_accuracy_m'] !== null ? (int)$r['gps_accuracy_m'] : null,
        'can_edit'           => frCanTouch($r) && canEdit('field_visits'),
        'can_delete'         => frCanTouch($r) && canDelete('field_visits'),
    ];
}
