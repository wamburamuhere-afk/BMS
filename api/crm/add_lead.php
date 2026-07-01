<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/code_generator.php';

header('Content-Type: application/json');

// 1. Auth check
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Permission check
if (!canCreate('crm_leads')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

// 3. Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 4. CSRF + Input validation
csrf_check();

$first_name = trim($_POST['first_name'] ?? '');
if ($first_name === '') {
    echo json_encode(['success' => false, 'message' => 'First name is required']);
    exit;
}

$email = trim($_POST['email'] ?? '');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

$allowed_sources = ['website','referral','walk_in','phone_call','social_media','exhibition','cold_call','email_campaign','other'];
$lead_source = $_POST['lead_source'] ?? 'other';
if (!in_array($lead_source, $allowed_sources, true)) $lead_source = 'other';

$probability = max(0, min(100, intval($_POST['probability'] ?? 20)));
$lead_value  = floatval($_POST['lead_value'] ?? 0);
if ($lead_value < 0) $lead_value = 0;

$expected_close_date = trim($_POST['expected_close_date'] ?? '');
if ($expected_close_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected_close_date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid expected close date']);
    exit;
}

$assigned_to = intval($_POST['assigned_to'] ?? 0) ?: null;
$project_id  = (isset($_POST['project_id']) && $_POST['project_id'] !== '') ? intval($_POST['project_id']) : null;
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your assigned scope.']);
    exit;
}

try {
    // Pipeline stage: validate the chosen one, or default to the first stage
    $stage_id = intval($_POST['pipeline_stage_id'] ?? 0);
    if ($stage_id) {
        $chk = $pdo->prepare("SELECT stage_id FROM crm_pipeline_stages WHERE stage_id = ? AND status = 'active'");
        $chk->execute([$stage_id]);
        if (!$chk->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Invalid pipeline stage']);
            exit;
        }
    } else {
        $stage_id = (int) $pdo->query("SELECT stage_id FROM crm_pipeline_stages WHERE status = 'active' ORDER BY stage_order ASC LIMIT 1")->fetchColumn();
        if (!$stage_id) {
            echo json_encode(['success' => false, 'message' => 'No pipeline stages configured. Ask an administrator to set them up.']);
            exit;
        }
    }

    // Company-prefixed sequential Lead code, e.g. BFS-LEAD-0001 (gap-free).
    $lead_code = nextCode($pdo, 'LEAD');

    $stmt = $pdo->prepare("
        INSERT INTO crm_leads
            (lead_code, first_name, last_name, company_name, email, phone, mobile, website,
             address, city, country, lead_source, pipeline_stage_id, assigned_to,
             lead_value, probability, expected_close_date, product_interest, notes,
             project_id, status, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())
    ");
    $stmt->execute([
        $lead_code,
        $first_name,
        trim($_POST['last_name'] ?? '') ?: null,
        trim($_POST['company_name'] ?? '') ?: null,
        $email ?: null,
        trim($_POST['phone'] ?? '') ?: null,
        trim($_POST['mobile'] ?? '') ?: null,
        trim($_POST['website'] ?? '') ?: null,
        trim($_POST['address'] ?? '') ?: null,
        trim($_POST['city'] ?? '') ?: null,
        trim($_POST['country'] ?? '') ?: 'Tanzania',
        $lead_source,
        $stage_id,
        $assigned_to,
        $lead_value,
        $probability,
        $expected_close_date ?: null,
        trim($_POST['product_interest'] ?? '') ?: null,
        trim($_POST['notes'] ?? '') ?: null,
        $project_id,
        $_SESSION['user_id'],
    ]);
    $lead_id = (int) $pdo->lastInsertId();

    // Labels (optional multi-select)
    if (!empty($_POST['labels']) && is_array($_POST['labels'])) {
        $lbl = $pdo->prepare("INSERT IGNORE INTO crm_lead_labels (lead_id, label_id) VALUES (?, ?)");
        foreach ($_POST['labels'] as $label_id) {
            $label_id = intval($label_id);
            if ($label_id) $lbl->execute([$lead_id, $label_id]);
        }
    }

    $full_name = trim($first_name . ' ' . ($_POST['last_name'] ?? ''));
    logActivity($pdo, $_SESSION['user_id'], 'Create lead', "User created a new lead: $full_name ($lead_code)");

    echo json_encode(['success' => true, 'message' => "Lead $lead_code created successfully.", 'lead_id' => $lead_id, 'lead_code' => $lead_code]);

} catch (PDOException $e) {
    error_log("add_lead error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
