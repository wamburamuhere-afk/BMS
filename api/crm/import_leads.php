<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/recalculate_lead_score.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canCreate('crm_import')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$valid_sources = ['website','referral','walk_in','phone_call','social_media','exhibition','cold_call','email_campaign','other'];
$stage_cache   = [];
$user_cache    = [];

function resolveStage(PDO $pdo, string $name, array &$cache): ?int {
    $key = strtolower(trim($name));
    if (isset($cache[$key])) return $cache[$key];
    $r = $pdo->prepare("SELECT stage_id FROM crm_pipeline_stages WHERE LOWER(stage_name) = ? AND status='active' LIMIT 1");
    $r->execute([$key]);
    $id = $r->fetchColumn() ?: null;
    $cache[$key] = $id;
    return $id;
}

function resolveUser(PDO $pdo, string $name, array &$cache): ?int {
    $key = strtolower(trim($name));
    if (isset($cache[$key])) return $cache[$key];
    $r = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(CONCAT_WS(' ', first_name, last_name)) = ? OR LOWER(username) = ? LIMIT 1");
    $r->execute([$key, $key]);
    $id = $r->fetchColumn() ?: null;
    $cache[$key] = $id;
    return $id;
}

function getDefaultStage(PDO $pdo): int {
    return (int)$pdo->query("SELECT stage_id FROM crm_pipeline_stages WHERE status='active' ORDER BY stage_order LIMIT 1")->fetchColumn();
}

// File upload
if (empty($_FILES['csv_file']['tmp_name'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']); exit;
}

$ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
if ($ext !== 'csv') { echo json_encode(['success' => false, 'message' => 'Only CSV files accepted']); exit; }
if ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) { echo json_encode(['success' => false, 'message' => 'File too large (max 5 MB)']); exit; }

$mode = trim($_POST['mode'] ?? 'preview'); // preview | import
$campaign_id = intval($_POST['campaign_id'] ?? 0) ?: null;
$assigned_to = intval($_POST['assigned_to'] ?? 0) ?: null;

// Column mapping from POST
$col_map = [
    'first_name'          => intval($_POST['col_first_name'] ?? -1),
    'last_name'           => intval($_POST['col_last_name'] ?? -1),
    'company_name'        => intval($_POST['col_company'] ?? -1),
    'email'               => intval($_POST['col_email'] ?? -1),
    'phone'               => intval($_POST['col_phone'] ?? -1),
    'lead_source'         => intval($_POST['col_source'] ?? -1),
    'lead_value'          => intval($_POST['col_value'] ?? -1),
    'expected_close_date' => intval($_POST['col_close_date'] ?? -1),
    'pipeline_stage'      => intval($_POST['col_stage'] ?? -1),
    'notes'               => intval($_POST['col_notes'] ?? -1),
];

$handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
$headers = fgetcsv($handle);
if (!$headers) { fclose($handle); echo json_encode(['success' => false, 'message' => 'Could not read CSV headers']); exit; }

// If no mapping provided yet, return headers for mapping step
if ($mode === 'headers') {
    fclose($handle);
    echo json_encode(['success' => true, 'headers' => $headers]);
    exit;
}

$defaultStage = getDefaultStage($pdo);
$results = ['imported' => 0, 'skipped' => 0, 'errors' => []];
$preview = [];
$row_num  = 1;

$getCol = function(array $row, int $idx, string $default = ''): string {
    return ($idx >= 0 && isset($row[$idx])) ? trim($row[$idx]) : $default;
};

while (($row = fgetcsv($handle)) !== false) {
    $row_num++;
    $first_name = $getCol($row, $col_map['first_name']);
    $company    = $getCol($row, $col_map['company_name']);

    if ($first_name === '' && $company === '') {
        $results['errors'][] = "Row $row_num: first_name or company_name is required — skipped";
        $results['skipped']++;
        continue;
    }

    $last_name  = $getCol($row, $col_map['last_name']);
    $email      = $getCol($row, $col_map['email']);
    $phone      = $getCol($row, $col_map['phone']);
    $raw_source = $getCol($row, $col_map['lead_source'], 'other');
    $source     = in_array(strtolower($raw_source), $valid_sources, true) ? strtolower($raw_source) : 'other';
    $value      = max(0, (float)str_replace([',', ' '], '', $getCol($row, $col_map['lead_value'], '0')));
    $close_date = $getCol($row, $col_map['expected_close_date']);
    if ($close_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $close_date)) {
        $ts = strtotime($close_date);
        $close_date = $ts ? date('Y-m-d', $ts) : null;
    }
    $close_date = $close_date ?: null;
    $notes      = $getCol($row, $col_map['notes']);

    // Resolve stage
    $stageRaw = $getCol($row, $col_map['pipeline_stage']);
    $stage_id = $stageRaw ? resolveStage($pdo, $stageRaw, $stage_cache) ?? $defaultStage : $defaultStage;

    // Duplicate detection: same email OR (first_name + company)
    $isDup = false;
    if ($email) {
        $d = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE email = ? AND status != 'deleted'");
        $d->execute([$email]);
        $isDup = (int)$d->fetchColumn() > 0;
    }
    if (!$isDup && $first_name && $company) {
        $d = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE first_name = ? AND company_name = ? AND status != 'deleted'");
        $d->execute([$first_name, $company]);
        $isDup = (int)$d->fetchColumn() > 0;
    }

    if ($isDup) {
        $results['skipped']++;
        if (count($preview) < 5) {
            $preview[] = ['row' => $row_num, 'name' => "$first_name $last_name", 'status' => 'duplicate'];
        }
        continue;
    }

    if ($mode === 'preview') {
        if (count($preview) < 10) {
            $preview[] = ['row' => $row_num, 'first_name' => $first_name, 'last_name' => $last_name,
                          'company' => $company, 'email' => $email, 'source' => $source,
                          'value' => $value, 'status' => 'will_import'];
        }
        $results['imported']++;
        continue;
    }

    // Import
    try {
        // Generate lead code
        require_once __DIR__ . '/../../core/code_generator.php';
        $lead_code = nextCode($pdo, 'LEAD');

        $pdo->prepare("
            INSERT INTO crm_leads (lead_code, first_name, last_name, company_name, email, phone,
                lead_source, pipeline_stage_id, assigned_to, lead_value, expected_close_date,
                notes, campaign_id, country, status, created_by, stage_entered, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Tanzania', 'active', ?, NOW(), NOW(), NOW())
        ")->execute([$lead_code, $first_name, $last_name ?: null, $company ?: null,
                     $email ?: null, $phone ?: null, $source, $stage_id,
                     $assigned_to, $value, $close_date, $notes ?: null,
                     $campaign_id, $_SESSION['user_id']]);

        $new_id = (int)$pdo->lastInsertId();
        $score  = computeLeadScore($pdo, $new_id);
        $pdo->prepare("UPDATE crm_leads SET lead_score = ? WHERE lead_id = ?")->execute([$score, $new_id]);
        $results['imported']++;
    } catch (PDOException $e) {
        $results['errors'][] = "Row $row_num: DB error — " . $e->getMessage();
        $results['skipped']++;
    }
}
fclose($handle);

if ($mode === 'import') {
    logActivity($pdo, $_SESSION['user_id'], "Imported {$results['imported']} CRM leads from CSV");
}

echo json_encode(['success' => true, 'mode' => $mode, 'results' => $results, 'preview' => $preview]);
