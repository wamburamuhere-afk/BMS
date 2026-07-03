<?php
// API: Employee Self-Service data (Tier 4, Phase 4.6 — D24).
// SECURITY LINCHPIN: the employee is resolved from the SESSION ONLY
// (users.employee_id of $_SESSION['user_id']). There is NO employee_id input
// parameter — a user can only ever see their own record. Unlinked users get 403.
// Read-only aggregation of data built across Tiers 1–4. ?section=<name> returns
// one section; omitted returns the profile + a summary.
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('my_hr')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

// Resolve the employee from the session — never from input (D24).
$eid = (int)($pdo->query("SELECT employee_id FROM users WHERE user_id = " . (int)$_SESSION['user_id'])->fetchColumn() ?: 0);
if (!$eid) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'not_linked', 'linked' => false]);
    exit;
}

$section = trim($_GET['section'] ?? 'profile');

try {
    switch ($section) {
        case 'profile': {
            $p = $pdo->prepare("SELECT e.employee_id, e.first_name, e.middle_name, e.last_name, e.employee_number,
                                       e.email, e.phone, e.photo, e.hire_date, e.employment_status,
                                       d.department_name, des.designation_name, pr.project_name
                                FROM employees e
                                LEFT JOIN departments d ON d.department_id = e.department_id
                                LEFT JOIN designations des ON des.designation_id = e.designation_id
                                LEFT JOIN projects pr ON pr.project_id = e.project_id
                                WHERE e.employee_id = ?");
            $p->execute([$eid]);
            echo json_encode(['success' => true, 'linked' => true, 'data' => $p->fetch(PDO::FETCH_ASSOC)]);
            break;
        }
        case 'payslips': {
            $rows = $pdo->prepare("SELECT payroll_id, payroll_period, payroll_date, net_salary, payment_status
                                   FROM payroll WHERE employee_id = ? ORDER BY payroll_period DESC, payroll_date DESC");
            $rows->execute([$eid]);
            echo json_encode(['success' => true, 'data' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }
        case 'leave': {
            $bal = $pdo->prepare("SELECT lb.*, lt.type_name FROM leave_balances lb LEFT JOIN leave_types lt ON lt.type_id = lb.leave_type_id WHERE lb.employee_id = ? AND lb.year = YEAR(CURDATE())");
            $bal->execute([$eid]);
            $hist = $pdo->prepare("SELECT leave_id, leave_type, start_date, end_date, total_days, status, reason FROM leaves WHERE employee_id = ? ORDER BY start_date DESC LIMIT 50");
            $hist->execute([$eid]);
            echo json_encode(['success' => true, 'balances' => $bal->fetchAll(PDO::FETCH_ASSOC), 'history' => $hist->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }
        case 'documents': {
            $docs = $pdo->prepare("SELECT ed.emp_doc_id, ed.document_name, ed.issue_date, ed.expire_date, dt.type_name,
                                          DATEDIFF(ed.expire_date, CURDATE()) AS days_to_expiry
                                   FROM employee_documents ed JOIN employee_document_types dt ON dt.doc_type_id = ed.doc_type_id
                                   WHERE ed.employee_id = ? AND ed.status = 'active' ORDER BY ed.created_at DESC");
            $docs->execute([$eid]);
            $con = $pdo->prepare("SELECT contract_id, contract_type, start_date, end_date, status, DATEDIFF(end_date, CURDATE()) AS days_to_expiry
                                  FROM employee_contracts WHERE employee_id = ? AND status != 'deleted' ORDER BY created_at DESC");
            $con->execute([$eid]);
            echo json_encode(['success' => true, 'documents' => $docs->fetchAll(PDO::FETCH_ASSOC), 'contracts' => $con->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }
        case 'performance': {
            $ap = $pdo->prepare("SELECT a.appraisal_id, a.overall_rating, a.appraisal_date, c.cycle_name FROM employee_appraisals a LEFT JOIN appraisal_cycles c ON c.cycle_id = a.cycle_id WHERE a.employee_id = ? AND a.status = 'approved' ORDER BY a.appraisal_date DESC");
            $ap->execute([$eid]);
            $gl = $pdo->prepare("SELECT goal_id, subject, progress, status, end_date FROM employee_goals WHERE employee_id = ? AND status != 'deleted' ORDER BY end_date DESC");
            $gl->execute([$eid]);
            $tr = $pdo->prepare("SELECT p.status AS part_status, p.certificate_path, p.participant_id, t.title, t.start_date, tt.type_name
                                 FROM training_participants p JOIN trainings t ON t.training_id = p.training_id AND t.status!='deleted'
                                 LEFT JOIN training_types tt ON tt.training_type_id = t.training_type_id WHERE p.employee_id = ? ORDER BY t.start_date DESC");
            $tr->execute([$eid]);
            echo json_encode(['success' => true, 'appraisals' => $ap->fetchAll(PDO::FETCH_ASSOC), 'goals' => $gl->fetchAll(PDO::FETCH_ASSOC), 'trainings' => $tr->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }
        case 'record': {
            $sr = $pdo->prepare("SELECT event_type, event_date, title, status FROM employee_lifecycle_events WHERE employee_id = ? AND status = 'approved' ORDER BY event_date DESC");
            $sr->execute([$eid]);
            $tp = $pdo->prepare("SELECT trip_id, destination, start_date, end_date, status FROM employee_trips WHERE employee_id = ? AND status != 'deleted' ORDER BY start_date DESC");
            $tp->execute([$eid]);
            $mt = $pdo->prepare("SELECT m.title, m.meeting_date, m.start_time FROM meeting_attendees ma JOIN meetings m ON m.meeting_id = ma.meeting_id WHERE ma.employee_id = ? AND m.status = 'scheduled' AND m.meeting_date >= CURDATE() ORDER BY m.meeting_date ASC");
            $mt->execute([$eid]);
            echo json_encode(['success' => true, 'service_record' => $sr->fetchAll(PDO::FETCH_ASSOC), 'trips' => $tp->fetchAll(PDO::FETCH_ASSOC), 'meetings' => $mt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }
        case 'leave_types': {
            $lt = $pdo->query("SELECT type_id, type_name FROM leave_types WHERE status='active' ORDER BY type_name");
            echo json_encode(['success' => true, 'data' => $lt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }
        default:
            throw new Exception('Unknown section');
    }
} catch (Exception $e) {
    error_log("my_hr_data error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
