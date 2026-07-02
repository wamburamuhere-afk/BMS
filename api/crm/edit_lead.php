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
if (!canEdit('crm_leads')) {
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

$lead_id = intval($_POST['lead_id'] ?? 0);
if (!$lead_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid lead ID']);
    exit;
}

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
    // Lead must exist, not be deleted, and be within the user's project scope
    $scope = scopeFilterSqlNullable('project', 'cl');
    $chk = $pdo->prepare("SELECT lead_code, converted FROM crm_leads cl WHERE cl.lead_id = ? AND cl.status != 'deleted' $scope");
    $chk->execute([$lead_id]);
    $lead = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$lead) {
        echo json_encode(['success' => false, 'message' => 'Lead not found']);
        exit;
    }

    // Validate the pipeline stage
    $stage_id = intval($_POST['pipeline_stage_id'] ?? 0);
    if ($stage_id) {
        $stg = $pdo->prepare("SELECT stage_id FROM crm_pipeline_stages WHERE stage_id = ? AND status = 'active'");
        $stg->execute([$stage_id]);
        if (!$stg->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Invalid pipeline stage']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Pipeline stage is required']);
        exit;
    }

    // Lead update + re-code + label replace commit together — a failure can't
    // leave the lead's labels deleted but not re-inserted.
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            UPDATE crm_leads SET
                first_name = ?, last_name = ?, company_name = ?, email = ?, phone = ?,
                mobile = ?, website = ?, address = ?, city = ?, country = ?,
                lead_source = ?, pipeline_stage_id = ?, assigned_to = ?, lead_value = ?,
                probability = ?, expected_close_date = ?, product_interest = ?, notes = ?,
                project_id = ?, updated_by = ?, updated_at = NOW()
            WHERE lead_id = ?
        ");
        $stmt->execute([
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
            $lead_id,
        ]);

        // Re-code on edit (leads are always editable): upgrade a legacy "LEAD-#####"
        // code to the company format. No-op if already converted or manually set.
        $newLeadCode = codeForEdit($pdo, 'LEAD', (string)$lead['lead_code'], 'LEAD-\\d+', 'crm_leads', $lead_id);
        if ($newLeadCode !== $lead['lead_code']) {
            $pdo->prepare("UPDATE crm_leads SET lead_code = ? WHERE lead_id = ?")->execute([$newLeadCode, $lead_id]);
            $lead['lead_code'] = $newLeadCode;
        }

        // Replace labels (join table only — no status column, so hard replace is correct)
        $pdo->prepare("DELETE FROM crm_lead_labels WHERE lead_id = ?")->execute([$lead_id]);
        if (!empty($_POST['labels']) && is_array($_POST['labels'])) {
            $lbl = $pdo->prepare("INSERT IGNORE INTO crm_lead_labels (lead_id, label_id) VALUES (?, ?)");
            foreach ($_POST['labels'] as $label_id) {
                $label_id = intval($label_id);
                if ($label_id) $lbl->execute([$lead_id, $label_id]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    logActivity($pdo, $_SESSION['user_id'], 'Edit lead', "User edited lead: {$lead['lead_code']} (ID $lead_id)");

    echo json_encode(['success' => true, 'message' => "Lead {$lead['lead_code']} updated successfully."]);

} catch (PDOException $e) {
    error_log("edit_lead error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
