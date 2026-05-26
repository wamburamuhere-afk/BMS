<?php
// scope-audit: skip — tender workflow API; tenders reference customers, not project-scoped; deferred to Phase G-2
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('tenders') && !canCreate('tenders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to use tender workflow']);
    exit;
}

global $pdo;
$user_id = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? '';
$tender_id = $_REQUEST['tender_id'] ?? 0;

// Some actions don't require a tender_id (e.g., getting staff list or adding a new global employee)
$no_tender_actions = ['GET_STAFF_LIST', 'ADD_EMPLOYEE'];

if (!$tender_id && !in_array($action, $no_tender_actions)) {
    echo json_encode(['success' => false, 'message' => 'Invalid Tender ID']);
    exit;
}

try {
    switch ($action) {
        case 'UPDATE_STATUS':
            $new_status = $_POST['status'] ?? '';
            $stmt = $pdo->prepare("UPDATE tenders SET status = ?, updated_at = NOW() WHERE tender_id = ?");
            $stmt->execute([$new_status, $tender_id]);
            
            logActivity($pdo, $user_id, 'UPDATE', "[Tender Status Update] Changed tender #$tender_id status to $new_status");
            
            echo json_encode(['success' => true, 'message' => "Status updated to $new_status"]);
            break;

        case 'APPROVE_TENDER':
            $stmt = $pdo->prepare("UPDATE tenders SET status = 'APPROVED', updated_at = NOW() WHERE tender_id = ?");
            $stmt->execute([$tender_id]);
            logActivity($pdo, $user_id, 'UPDATE', "[Tender Approval] Approved tender #$tender_id. Moved to APPROVED status.");
            echo json_encode(['success' => true, 'message' => "Tender approved successfully!"]);
            break;

        case 'RECORD_FEE':
            $fee_amount = $_POST['fee_amount'] ?? 0;

            $stmt = $pdo->prepare("UPDATE tenders SET status = 'INVITATION', participation_fee_amount = ?, updated_at = NOW() WHERE tender_id = ?");
            $stmt->execute([$fee_amount, $tender_id]);

            logActivity($pdo, $user_id, 'UPDATE', "[Tender Budget Recorded] Recorded participation budget of $fee_amount for tender #$tender_id. Moved to INVITATION status.");
            echo json_encode(['success' => true, 'message' => "Budget recorded. Tender is now under INVITATION status."]);
            break;

        case 'SUBMISSION_PROCESS':
            $currency_choice = $_POST['sub_currency_choice'] ?? 'Tshs';
            $amount_tzs = ($currency_choice === 'Tshs' || $currency_choice === 'Both') ? ($_POST['sub_amount_tzs'] ?? 0) : null;
            $amount_usd = ($currency_choice === 'USD' || $currency_choice === 'Both') ? ($_POST['sub_amount_usd'] ?? 0) : null;
            
            $doc_tzs = null;
            $doc_usd = null;

            $upload_dir = ROOT_DIR . '/uploads/tenders/submissions/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            if (isset($_FILES['sub_doc_tzs']) && $_FILES['sub_doc_tzs']['error'] === UPLOAD_ERR_OK) {
                $fname = time() . '_tzs_' . $_FILES['sub_doc_tzs']['name'];
                move_uploaded_file($_FILES['sub_doc_tzs']['tmp_name'], $upload_dir . $fname);
                $doc_tzs = 'uploads/tenders/submissions/' . $fname;
                registerFileInLibrary($pdo, $doc_tzs, $_FILES['sub_doc_tzs']['name'], $_FILES['sub_doc_tzs']['size'], 'Tender Submission (TZS) - Tender #' . $tender_id, 'tender,submission,tzs', $user_id);
            }

            if (isset($_FILES['sub_doc_usd']) && $_FILES['sub_doc_usd']['error'] === UPLOAD_ERR_OK) {
                $fname = time() . '_usd_' . $_FILES['sub_doc_usd']['name'];
                move_uploaded_file($_FILES['sub_doc_usd']['tmp_name'], $upload_dir . $fname);
                $doc_usd = 'uploads/tenders/submissions/' . $fname;
                registerFileInLibrary($pdo, $doc_usd, $_FILES['sub_doc_usd']['name'], $_FILES['sub_doc_usd']['size'], 'Tender Submission (USD) - Tender #' . $tender_id, 'tender,submission,usd', $user_id);
            }

            $stmt = $pdo->prepare("UPDATE tenders SET 
                status = 'SUBMISSION', 
                currency = ?, 
                tender_amount_tzs = ?, 
                tender_amount_usd = ?, 
                submission_document_tzs = COALESCE(?, submission_document_tzs),
                submission_document_usd = COALESCE(?, submission_document_usd),
                updated_at = NOW() 
                WHERE tender_id = ?");
            
            $stmt->execute([
                $currency_choice === 'Both' ? 'Tshs & USD' : $currency_choice,
                $amount_tzs,
                $amount_usd,
                $doc_tzs,
                $doc_usd,
                $tender_id
            ]);

            // Track Assigned Staff (Technical Submission)
            if (!empty($_POST['staff_ids']) && is_array($_POST['staff_ids'])) {
                $pdo->prepare("DELETE FROM tender_staff WHERE tender_id = ?")->execute([$tender_id]);
                $staff_ids = $_POST['staff_ids'];
                $staff_roles = $_POST['staff_roles'] ?? [];
                
                $staff_stmt = $pdo->prepare("INSERT INTO tender_staff (tender_id, employee_id, role_position) VALUES (?, ?, ?)");
                foreach ($staff_ids as $idx => $emp_id) {
                    $role = $staff_roles[$idx] ?? 'Staff';
                    $staff_stmt->execute([$tender_id, $emp_id, $role]);
                }
            }

            logActivity($pdo, $user_id, 'UPDATE', "[Tender Submission] Recorded financial & technical submission for tender #$tender_id ($currency_choice).");
            echo json_encode(['success' => true, 'message' => "Financial & Technical submission recorded successfully! Status moved to SUBMISSION."]);
            break;

        case 'OPENING':
            // Data already stored during registration. Just move status to OPENING.
            $stmt = $pdo->prepare("UPDATE tenders SET status = 'OPENING', updated_at = NOW() WHERE tender_id = ?");
            $stmt->execute([$tender_id]);

            logActivity($pdo, $user_id, 'UPDATE', "[Tender Opening Confirmed] Confirmed opening for tender #$tender_id. Status moved to OPENING.");

            echo json_encode(['success' => true, 'message' => "Tender opening confirmed. Status updated to OPENING."]);
            break;

        case 'EVALUATION_PROCESS':
            // Data already stored during registration. Just move status to EVALUATION.
            $stmt = $pdo->prepare("UPDATE tenders SET status = 'EVALUATION', updated_at = NOW() WHERE tender_id = ?");
            $stmt->execute([$tender_id]);

            logActivity($pdo, $user_id, 'UPDATE', "[Tender Evaluation Confirmed] Confirmed evaluation for tender #$tender_id. Status moved to EVALUATION.");

            echo json_encode(['success' => true, 'message' => "Tender evaluation confirmed. Status updated to EVALUATION."]);
            break;

        case 'POST_QUALIFICATION_PROCESS':
            $file_path = null;
            if (isset($_FILES['post_qual_document']) && $_FILES['post_qual_document']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = ROOT_DIR . '/uploads/tenders/evaluation/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $file_name = time() . '_' . $_FILES['post_qual_document']['name'];
                move_uploaded_file($_FILES['post_qual_document']['tmp_name'], $upload_dir . $file_name);
                $file_path = 'uploads/tenders/evaluation/' . $file_name;
                registerFileInLibrary($pdo, $file_path, $_FILES['post_qual_document']['name'], $_FILES['post_qual_document']['size'], 'Post-Qualification Document - Tender #' . $tender_id, 'tender,post-qualification', $user_id);
            }

            // Ensure our column exists (safe guard)
            try {
                $pdo->exec("ALTER TABLE tenders ADD COLUMN post_qualification_document VARCHAR(500) DEFAULT NULL AFTER evaluation_document");
            } catch (Exception $e) { /* ignore if already exists */ }

            if ($file_path) {
                $stmt = $pdo->prepare("UPDATE tenders SET status = 'POST-QUALIFICATION', post_qualification_document = ?, updated_at = NOW() WHERE tender_id = ?");
                $stmt->execute([$file_path, $tender_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE tenders SET status = 'POST-QUALIFICATION', updated_at = NOW() WHERE tender_id = ?");
                $stmt->execute([$tender_id]);
            }

            logActivity($pdo, $user_id, 'UPDATE', "[Post-Qualification Confirmed] Tender #$tender_id moved to POST-QUALIFICATION." . ($file_path ? " Document uploaded." : ""));

            echo json_encode(['success' => true, 'message' => "Tender moved to POST-QUALIFICATION successfully."]);
            break;

        case 'NEGOTIATION_CONFIRM':
            $confirmed_sum = $_POST['confirmed_tender_sum'] ?? 0;
            $confirmed_currency = $_POST['confirmed_currency'] ?? 'Tshs';
            $notes = $_POST['negotiation_notes'] ?? '';

            $stmt = $pdo->prepare("UPDATE tenders SET status = 'NEGOTIATION', tender_sum = ?, currency = ?, negotiation_notes = ?, updated_at = NOW() WHERE tender_id = ?");
            $stmt->execute([$confirmed_sum, $confirmed_currency, $notes, $tender_id]);

            logActivity($pdo, $user_id, 'UPDATE', "[Tender Negotiation] Tender #$tender_id moved to NEGOTIATION. Confirmed Amount: $confirmed_currency $confirmed_sum");

            echo json_encode(['success' => true, 'message' => "Tender moved to NEGOTIATION stage successfully"]);
            break;

        case 'DECISION':
            $status = $_POST['status'] ?? '';
            $loss_reason = $_POST['loss_reason'] ?? null;
            $tender_sum = $_POST['tender_sum'] ?? null;
            $award_letter = null;

            if ($status === 'LOSS') {
                $status = 'LOSS';
                $stmt = $pdo->prepare("UPDATE tenders SET status = 'END TENDER', loss_reason = ?, updated_at = NOW() WHERE tender_id = ?");
                $stmt->execute([$loss_reason, $tender_id]);
            } else {
                // Optional Award Letter upload
                if (isset($_FILES['award_letter_document']) && $_FILES['award_letter_document']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/../uploads/tenders/awards/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                    
                    $file_ext = pathinfo($_FILES['award_letter_document']['name'], PATHINFO_EXTENSION);
                    $file_name = 'award_' . $tender_id . '_' . time() . '.' . $file_ext;
                    $award_letter = 'uploads/tenders/awards/' . $file_name;
                    
                    move_uploaded_file($_FILES['award_letter_document']['tmp_name'], $upload_dir . $file_name);
                    registerFileInLibrary($pdo, $award_letter, $_FILES['award_letter_document']['name'], $_FILES['award_letter_document']['size'], 'Award Letter - Tender #' . $tender_id, 'tender,award-letter', $user_id);
                }

                // Award the tender
                $status = 'AWARDED';
                $stmt = $pdo->prepare("UPDATE tenders SET status = 'AWARDED', tender_sum = ?, award_letter_document = ?, award_date = NOW(), updated_at = NOW() WHERE tender_id = ?");
                $stmt->execute([$tender_sum, $award_letter, $tender_id]);

                // AUTOMATICALLY Move to Projects
                $tender = $pdo->prepare("SELECT t.*, c.customer_name FROM tenders t LEFT JOIN customers c ON t.customer_id = c.customer_id WHERE t.tender_id = ?");
                $tender->execute([$tender_id]);
                $t = $tender->fetch(PDO::FETCH_ASSOC);

                if ($t) {
                    // Fallback for contract attachment
                    $contract_doc = $t['award_letter_document'] ?: ($t['submission_document_tzs'] ?: $t['submission_document_usd']);

                    $proj_stmt = $pdo->prepare("INSERT INTO projects (project_name, contract_number, contract_sum, client_name, customer_id, start_date, status, description, contract_attachment, duration, discipline, role_position, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $proj_name = $t['tender_description'] ?: ($t['tender_no'] . " Project");
                    $proj_stmt->execute([
                        $proj_name,
                        $t['tender_no'],
                        $t['tender_sum'],
                        $t['customer_name'] ?: $t['procuring_entity_name'],
                        $t['customer_id'],
                        date('Y-m-d'),
                        'planning',
                        $t['tender_description'],
                        $contract_doc,
                        $t['duration']    ?? null,
                        $t['discipline']  ?? null,
                        $t['tender_role'] ?? null
                    ]);
                }
            }

            logActivity($pdo, $user_id, 'UPDATE', "[Tender Decision] Decision made for tender #$tender_id: $status. Automatically moved to projects if awarded.");

            echo json_encode(['success' => true, 'message' => "Decision recorded and tender moved to Projects!"]);
            break;

        case 'AWARD_RECORDS':


        case 'DELETE':
            $stmt = $pdo->prepare("DELETE FROM tenders WHERE tender_id = ?");
            $stmt->execute([$tender_id]);

            logActivity($pdo, $user_id, 'DELETE', "[Tender Deletion] Deleted tender #$tender_id");

            echo json_encode(['success' => true, 'message' => "Tender deleted successfully"]);
            break;

        case 'GET_STAFF_LIST':
            $stmt = $pdo->query("SELECT e.employee_id, e.first_name, e.last_name, e.employee_number, d.designation_name 
                                 FROM employees e 
                                 LEFT JOIN designations d ON e.designation_id = d.designation_id 
                                 WHERE e.status = 'active' ORDER BY e.first_name ASC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            exit;

        case 'ADD_EMPLOYEE':
            $first_name = $_POST['first_name'] ?? '';
            $middle_name = $_POST['middle_name'] ?? '';
            $last_name = $_POST['last_name'] ?? '';
            $emp_no = $_POST['employee_number'] ?? '';
            $gender = $_POST['gender'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $hire_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : date('Y-m-d');
            $dep_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
            $des_id = !empty($_POST['designation_id']) ? $_POST['designation_id'] : null;
            $type_id = !empty($_POST['employment_type_id']) ? $_POST['employment_type_id'] : null;

            if (!$first_name || !$last_name || !$emp_no) {
                echo json_encode(['success' => false, 'message' => "Required fields missing (Name or Employee ID)"]);
                exit;
            }

            try {
                $stmt = $pdo->prepare("INSERT INTO employees (first_name, middle_name, last_name, employee_number, gender, email, phone, hire_date, department_id, designation_id, employment_type_id, employment_status, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 'active', NOW())");
                $stmt->execute([$first_name, $middle_name, $last_name, $emp_no, $gender, $email, $phone, $hire_date, $dep_id, $des_id, $type_id]);
                
                $new_id = $pdo->lastInsertId();
                logActivity($pdo, $user_id, 'CREATE', "[Employee Full Add] Created employee $first_name $last_name ($emp_no) during tender submission.");
                
                echo json_encode(['success' => true, 'message' => "Employee $first_name registered successfully!", 'employee_id' => $new_id]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => "Registration Failed: " . $e->getMessage()]);
            }
            exit;

        default:
            // Check if it's the specific decision form submit from my modal (which might use a different action name by default or I missed it)
            if (isset($_POST['status']) && !isset($action)) {
                 // handle as decision
            }
            echo json_encode(['success' => false, 'message' => "Unknown action: $action"]);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
