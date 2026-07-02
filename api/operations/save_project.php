<?php
// api/operations/save_project.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

if (!isAuthenticated()) {
    echo json_encode(["success" => false, "message" => "Unauthorized access"]);
    exit;
}

$project_id = $_POST['project_id'] ?? null;

if (!empty($project_id) ? !canEdit('projects') : !canCreate('projects')) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Access Denied: you do not have permission to " . (!empty($project_id) ? 'edit' : 'create') . " projects"]);
    exit;
}

// Phase B (scope) — when editing, block updates against projects not in user scope.
// Creates are allowed; the non-admin creator is auto-assigned in user_projects below.
if (!empty($project_id) && !userCan('project', (int)$project_id)) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Access denied: this project is not in your scope."]);
    exit;
}

$project_name = $_POST['project_name'] ?? null;
$customer_id = $_POST['customer_id'] ?? null;
$client_name = $_POST['client_name'] ?? '';
$discipline = $_POST['discipline'] ?? '';
$discipline_other = ($discipline === 'Other') ? ($_POST['discipline_other'] ?? '') : '';
$role_position = $_POST['role_position'] ?? '';
$role_position_other = ($role_position === 'Other') ? ($_POST['role_position_other'] ?? '') : '';
$project_manager = $_POST['project_manager'] ?? '';
$contract_number = $_POST['contract_number'] ?? '';
$contract_sum = $_POST['contract_sum'] ?? 0;
$priority = $_POST['priority'] ?? 'medium';
$start_date = $_POST['start_date'] ?? null;
$deadline = $_POST['deadline'] ?? null;
$status = $_POST['status'] ?? 'draft';
$description = $_POST['description'] ?? '';
$duration_days = $_POST['duration_days'] ?? 0;

if (!$project_name || !$start_date || (!$customer_id && !$client_name) || !$discipline || !$role_position) {
    echo json_encode(["success" => false, "message" => "Required fields are missing (Project Name, Client/Customer, Discipline, Position, Start Date)"]);
    exit;
}

// Handle File Upload
$contract_attachment = '';
if (isset($_FILES['contract_file']) && $_FILES['contract_file']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = ROOT_DIR . '/uploads/projects/contracts/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file_name = time() . '_' . basename($_FILES['contract_file']['name']);
    $target_file = $upload_dir . $file_name;

    // Check file size (5MB limit)
    if ($_FILES['contract_file']['size'] > 5000000) {
        echo json_encode(["success" => false, "message" => "File is too large. Max 5MB allowed."]);
        exit;
    }

    if (move_uploaded_file($_FILES['contract_file']['tmp_name'], $target_file)) {
        $contract_attachment = 'uploads/projects/contracts/' . $file_name;
    } else {
        echo json_encode(["success" => false, "message" => "Failed to move uploaded file."]);
        exit;
    }
} elseif (!$project_id) {
    // Mandatory for new projects
    echo json_encode(["success" => false, "message" => "Contract attachment is mandatory for new projects."]);
    exit;
}

try {
    if ($project_id) {
        // Build update query dynamically to handle attachment only if provided
        $update_sql = "UPDATE projects SET 
            project_name = ?, contract_number = ?, contract_sum = ?, customer_id = ?, client_name = ?, discipline = ?, discipline_other = ?, 
            role_position = ?, role_position_other = ?, project_manager = ?, 
            priority = ?, start_date = ?, deadline = ?, duration_days = ?, status = ?, description = ?, updated_at = NOW()";
        
        $params = [
            $project_name, $contract_number, $contract_sum, $customer_id ?: null, $client_name, $discipline, $discipline_other, 
            $role_position, $role_position_other, $project_manager, $priority, $start_date, $deadline, $duration_days, $status, $description
        ];
        
        if ($contract_attachment) {
            // Move file to project-specific folder
            $final_dir = ROOT_DIR . "/uploads/projects/$project_id/contracts/";
            if (!is_dir($final_dir)) mkdir($final_dir, 0777, true);
            $final_path = $final_dir . basename($contract_attachment);
            rename(ROOT_DIR . '/' . $contract_attachment, $final_path);
            $contract_attachment = "uploads/projects/$project_id/contracts/" . basename($contract_attachment);

            $update_sql .= ", contract_attachment = ?";
            $params[] = $contract_attachment;
        }
        
        $update_sql .= " WHERE project_id = ?";
        $params[] = $project_id;
        
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute($params);
        $msg = "Project updated successfully";
    } else {
        $stmt = $pdo->prepare("INSERT INTO projects (
            project_name, contract_number, contract_sum, customer_id, client_name, discipline, discipline_other, 
            role_position, role_position_other, 
            project_manager, priority, start_date, deadline, duration_days, status, description
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $project_name, $contract_number, $contract_sum, $customer_id ?: null, $client_name, $discipline, $discipline_other, 
            $role_position, $role_position_other, 
            $project_manager, $priority, $start_date, $deadline, $duration_days, $status, $description
        ]);
        $project_id = $pdo->lastInsertId();

        // Auto-scope: a non-admin who creates a project is immediately granted
        // access to it in user_projects (self-assigned). Everyone else still
        // waits for an admin to assign them via user_projects.php.
        if (!isAdmin()) {
            $pdo->prepare("INSERT IGNORE INTO user_projects (user_id, project_id, assigned_by) VALUES (?, ?, ?)")
                ->execute([$_SESSION['user_id'], $project_id, $_SESSION['user_id']]);
        }

        if ($contract_attachment) {
            // Move file to project-specific folder
            $final_dir = ROOT_DIR . "/uploads/projects/$project_id/contracts/";
            if (!is_dir($final_dir)) mkdir($final_dir, 0777, true);
            $final_path = $final_dir . basename($contract_attachment);
            rename(ROOT_DIR . '/' . $contract_attachment, $final_path);
            $contract_attachment = "uploads/projects/$project_id/contracts/" . basename($contract_attachment);

            $pdo->prepare("UPDATE projects SET contract_attachment = ? WHERE project_id = ?")->execute([$contract_attachment, $project_id]);
            registerFileInLibrary($pdo, $contract_attachment, basename($contract_attachment), filesize(ROOT_DIR . '/' . $contract_attachment), 'Contract - ' . ($project_name ?? 'Project #' . $project_id), 'project,contract', $_SESSION['user_id'] ?? 0);
        }
        $msg = "Project created successfully";
    }

    // Phase 3c — projects are the operational root; mutations are high-impact.
    logActivity(
        $pdo,
        $_SESSION['user_id'] ?? 0,
        isset($_POST['project_id']) && $_POST['project_id'] ? "Updated Project" : "Created Project",
        "Project ID: $project_id, name: " . ($project_name ?? '')
    );

    echo json_encode(["success" => true, "message" => $msg]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
