<?php
/**
 * actions/superadmin_broadcast.php — send or count a broadcast to a tenant audience.
 *
 * Actions:
 *   count  — return recipient count for the given audience (GET-style POST, no email sent)
 *   send   — send broadcast emails and log to broadcast_log
 *
 * Audience keys:
 *   all_active          — all active tenants
 *   all_trial           — all trial tenants
 *   trial_expiring_7d   — trial expiring in ≤7 days
 *   plan_<id>           — tenants on a specific plan
 *   industry_<name>     — tenants in a specific industry
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/mailer.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

assertSuperadminHost();
superadminSessionReady();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (currentSuperadmin() === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your session has ended. Please sign in again.']);
    exit;
}

csrf_check();

$action   = (string)($_POST['action'] ?? '');
$audience = (string)($_POST['audience'] ?? '');

if (!in_array($action, ['count', 'send'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}
if ($audience === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No audience specified.']);
    exit;
}

/**
 * Build WHERE clause + params for the given audience key.
 * Always appends unsubscribed_at IS NULL guard.
 */
function audienceWhere(string $audience): ?array
{
    $base = "status != 'deleted' AND unsubscribed_at IS NULL";
    if ($audience === 'all_active') {
        return ["$base AND status = 'active'", []];
    }
    if ($audience === 'all_trial') {
        return ["$base AND status = 'trial'", []];
    }
    if ($audience === 'trial_expiring_7d') {
        return ["$base AND status = 'trial' AND trial_ends_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)", []];
    }
    if (strncmp($audience, 'plan_', 5) === 0) {
        $planId = (int)substr($audience, 5);
        if ($planId < 1) return null;
        return ["$base AND status = 'active' AND plan = (SELECT plan_key FROM subscription_plans WHERE id = ? LIMIT 1)", [$planId]];
    }
    if (strncmp($audience, 'industry_', 9) === 0) {
        $ind = trim(substr($audience, 9));
        if ($ind === '') return null;
        return ["$base AND LOWER(industry) = LOWER(?)", [$ind]];
    }
    return null;
}

try {
    $ctrl    = getControlPdo();
    $awhere  = audienceWhere($audience);

    if ($awhere === null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unknown audience.']);
        exit;
    }

    [$whereSql, $whereParams] = $awhere;

    if ($action === 'count') {
        $stmt = $ctrl->prepare("SELECT COUNT(*) FROM tenants WHERE $whereSql");
        $stmt->execute($whereParams);
        $count = (int)$stmt->fetchColumn();
        echo json_encode(['success' => true, 'count' => $count]);
        exit;
    }

    // action === 'send'
    $subject = trim((string)($_POST['subject'] ?? ''));
    $body    = trim((string)($_POST['body']    ?? ''));
    if ($subject === '' || $body === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Subject and body are required.']);
        exit;
    }
    if (mb_strlen($subject) > 200) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Subject must be ≤200 characters.']);
        exit;
    }

    $stmt = $ctrl->prepare(
        "SELECT id, owner_email, owner_first_name, owner_last_name, company_name, subdomain
           FROM tenants WHERE $whereSql"
    );
    $stmt->execute($whereParams);
    $recipients = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $sent   = 0;
    $failed = 0;

    $htmlBody = '<p>' . implode("</p>\n<p>", array_map('htmlspecialchars',
        array_filter(explode("\n\n", $body), fn($s) => trim($s) !== '')
    )) . '</p>';

    foreach ($recipients as $t) {
        if (empty($t['owner_email'])) { $failed++; continue; }
        $name = trim(($t['owner_first_name'] ?? '') . ' ' . ($t['owner_last_name'] ?? ''));
        $greet = $name !== '' ? $name : $t['owner_email'];
        $personalised = "<p>Hi {$greet},</p>\n" . $htmlBody;
        $ok = sendEmail(
            $t['owner_email'],
            $subject,
            $personalised,
            ['wrap_brand' => 'BJP Technologies / BMS']
        );
        if ($ok) $sent++; else $failed++;
    }

    // Log to broadcast_log
    $sa = currentSuperadmin();
    $ctrl->prepare(
        "INSERT INTO broadcast_log (subject, body, audience_definition, recipients_count, sent_by, sent_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    )->execute([$subject, $body, $audience, $sent, $sa['id']]);

    echo json_encode([
        'success' => true,
        'sent'    => $sent,
        'failed'  => $failed,
        'message' => "Broadcast sent to {$sent} tenant" . ($sent !== 1 ? 's' : '') . '.',
    ]);

} catch (\Throwable $e) {
    error_log('superadmin_broadcast.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
}
