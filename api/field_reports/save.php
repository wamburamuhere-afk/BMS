<?php
// POST: create (no visit_id) or update (visit_id). Owner or admin only for updates.
require_once __DIR__ . '/_common.php';
frRequirePost();

$me = (int)$_SESSION['user_id'];
$id = (int)($_POST['visit_id'] ?? 0);
$existing = null;

if ($id > 0) {
    if (!canEdit('field_visits')) frJson(['success' => false, 'message' => t('Permission denied')], 403);
    $existing = frGetVisit($pdo, $id);
    if (!$existing) frJson(['success' => false, 'message' => t('Visit not found')], 404);
    if (!frCanTouch($existing)) frJson(['success' => false, 'message' => t('Permission denied')], 403);
} elseif (!canCreate('field_visits')) {
    frJson(['success' => false, 'message' => t('Permission denied')], 403);
}

$date     = trim((string)($_POST['visit_date'] ?? ''));
$time     = trim((string)($_POST['visit_time'] ?? ''));
$location = trim((string)($_POST['location'] ?? ''));
$name     = trim((string)($_POST['client_name'] ?? ''));
$phone    = trim((string)($_POST['client_phone'] ?? ''));
$btype    = trim((string)($_POST['business_type'] ?? ''));
$bother   = trim((string)($_POST['business_other'] ?? ''));
$interest = trim((string)($_POST['interest'] ?? ''));
$notes    = trim((string)($_POST['notes'] ?? ''));
$followUp = trim((string)($_POST['follow_up_date'] ?? ''));   // optional (customer_visits_ux_plan 3.1)
$flag     = fn($k) => in_array((string)($_POST[$k] ?? '0'), ['1', 'on', 'true'], true) ? 1 : 0;

$errors = [];
if (!frValidDate($date))                         $errors['visit_date'] = t('Choose a valid date.');
elseif ($date > date('Y-m-d'))                   $errors['visit_date'] = t('The visit date cannot be in the future.');
if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $errors['visit_time'] = t('Enter the time as HH:MM.');
// One clock — the server's (EAT). Today's visit cannot be later than now (5 min grace
// for a slow phone); an empty time on today's visit means "now" (customer_visits_ux_plan 1.3).
elseif ($time !== '' && $date === date('Y-m-d') && $time > date('H:i', time() + 300)) $errors['visit_time'] = t('The time cannot be later than now.');
if ($time === '' && $date === date('Y-m-d') && !$existing) $time = date('H:i');
if ($location === '')                            $errors['location'] = t('Enter the place you visited.');
elseif (mb_strlen($location) > 255)              $errors['location'] = t('Place is too long.');
if ($name === '')                                $errors['client_name'] = t('Enter the client\'s name.');
elseif (mb_strlen($name) > 150)                  $errors['client_name'] = t('Name is too long.');
$digits = preg_replace('/\D+/', '', $phone);
// Optional (product owner, 2026-10-03): many shop owners will not give a number.
if ($phone !== '' && (strlen($digits) < 9 || strlen($digits) > 15 || mb_strlen($phone) > 30)) $errors['client_phone'] = t('Enter a valid phone number.');
if (!array_key_exists($btype, frBusinessTypes())) $errors['business_type'] = t('Choose the type of business.');
if ($btype === 'other' && $bother === '')        $errors['business_other'] = t('Describe the business.');
if (mb_strlen($bother) > 150)                    $errors['business_other'] = t('Business description is too long.');
if ($interest !== '' && !array_key_exists($interest, frInterestLabels())) $errors['interest'] = t('Choose a valid option.');
if (mb_strlen($notes) > 2000)                    $errors['notes'] = t('Notes are too long.');
if ($followUp !== '') {
    if (!frValidDate($followUp))                  $errors['follow_up_date'] = t('Choose a valid date.');
    elseif (frValidDate($date) && $followUp < $date) $errors['follow_up_date'] = t('The follow-up date cannot be before the visit.');
    elseif (frValidDate($date) && $followUp > date('Y-m-d', strtotime($date . ' +1 year'))) $errors['follow_up_date'] = t('Choose a follow-up date within a year.');
}

$lat = $_POST['latitude'] ?? ''; $lng = $_POST['longitude'] ?? ''; $acc = $_POST['gps_accuracy_m'] ?? '';
$lat = ($lat === '' || $lat === null) ? null : (is_numeric($lat) && $lat >= -90 && $lat <= 90 ? (float)$lat : false);
$lng = ($lng === '' || $lng === null) ? null : (is_numeric($lng) && $lng >= -180 && $lng <= 180 ? (float)$lng : false);
$acc = ($acc === '' || $acc === null) ? null : (is_numeric($acc) && $acc >= 0 ? (int)round((float)$acc) : null);
if ($lat === false || $lng === false || ($lat === null) !== ($lng === null)) $errors['location'] = t('The GPS position is not valid. Capture it again or clear it.');

if ($errors) frJson(['success' => false, 'message' => reset($errors), 'errors' => $errors], 422);

$vals = [
    $date, $time !== '' ? $time . ':00' : null, $location, $lat, $lng, $lat === null ? null : $acc,
    $name, $phone, frNormalizePhone($phone), $btype, $btype === 'other' ? $bother : null,
    $flag('gave_business_card'), $flag('gave_trial_link'), $flag('gave_training'),
    $interest !== '' ? $interest : null, $notes !== '' ? $notes : null, $followUp !== '' ? $followUp : null,
];

try {
    if ($existing) {
        $pdo->prepare("UPDATE field_visits SET visit_date = ?, visit_time = ?, location = ?, latitude = ?, longitude = ?, gps_accuracy_m = ?,
                client_name = ?, client_phone = ?, phone_normalized = ?, business_type = ?, business_other = ?,
                gave_business_card = ?, gave_trial_link = ?, gave_training = ?, interest = ?, notes = ?, follow_up_date = ?, follow_up_done_at = ?, updated_by = ?
            WHERE visit_id = ?")->execute(array_merge($vals, [
            // A changed follow-up date is a new follow-up: its "done" mark is cleared.
            ($existing['follow_up_date'] ?? null) === ($followUp !== '' ? $followUp : null) ? ($existing['follow_up_done_at'] ?? null) : null,
            $me, $id]));
        $owner = (int)$existing['user_id'];
        frMarkChanged($pdo, $owner, $date);
        if ($existing['visit_date'] !== $date) frMarkChanged($pdo, $owner, $existing['visit_date']);
        logActivity($pdo, $me, 'Update field visit', "Updated field visit #$id ($name, $location)");
        frJson(['success' => true, 'message' => t('Visit updated.'), 'visit_id' => $id]);
    }
    $pdo->prepare("INSERT INTO field_visits (visit_date, visit_time, location, latitude, longitude, gps_accuracy_m,
            client_name, client_phone, phone_normalized, business_type, business_other,
            gave_business_card, gave_trial_link, gave_training, interest, notes, follow_up_date, user_id, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")->execute(array_merge($vals, [$me, $me]));
    $newId = (int)$pdo->lastInsertId();
    frMarkChanged($pdo, $me, $date);
    logActivity($pdo, $me, 'Add field visit', "Recorded field visit #$newId ($name, $location)");
    frJson(['success' => true, 'message' => t('Visit saved.'), 'visit_id' => $newId]);
} catch (PDOException $e) {
    error_log('field_reports/save: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}
