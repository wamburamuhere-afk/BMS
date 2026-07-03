<?php
// API: Manage candidate interviews (Tier 4, Phase 4.5). schedule / record / cancel.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('recruitment')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$action = trim($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'schedule': {
            $cand = intval($_POST['candidate_id'] ?? 0);
            $date = trim($_POST['interview_date'] ?? '');
            $time = trim($_POST['interview_time'] ?? '');
            $interviewers = trim($_POST['interviewers'] ?? '');
            if (!$cand) throw new Exception('Candidate is required');
            if (!strtotime($date)) throw new Exception('A valid interview date is required');
            $chk = $pdo->prepare("SELECT candidate_id FROM candidates WHERE candidate_id=? AND status='active'");
            $chk->execute([$cand]);
            if (!$chk->fetch()) throw new Exception('Candidate not found');
            $pdo->prepare("INSERT INTO candidate_interviews (candidate_id, interview_date, interview_time, interviewers, status, created_by) VALUES (?, ?, ?, ?, 'scheduled', ?)")
                ->execute([$cand, $date, ($time!==''?$time:null), ($interviewers!==''?$interviewers:null), $_SESSION['user_id']]);
            $interview_id = (int)$pdo->lastInsertId();   // capture BEFORE logActivity (which inserts into activity_logs)
            logActivity($pdo, $_SESSION['user_id'], 'Schedule interview', "interview for candidate #$cand");
            echo json_encode(['success' => true, 'message' => 'Interview scheduled', 'interview_id' => $interview_id]);
            break;
        }
        case 'record': {
            $iid = intval($_POST['interview_id'] ?? 0);
            $rating = ($_POST['rating'] ?? '') !== '' ? (int)$_POST['rating'] : null;
            $feedback = trim($_POST['feedback'] ?? '');
            if (!$iid) throw new Exception('Interview id is required');
            if ($rating !== null && ($rating < 1 || $rating > 5)) throw new Exception('Rating must be 1–5');
            $pdo->prepare("UPDATE candidate_interviews SET rating=?, feedback=?, status='done' WHERE interview_id=? AND status!='deleted'")
                ->execute([$rating, ($feedback!==''?$feedback:null), $iid]);
            logActivity($pdo, $_SESSION['user_id'], 'Record interview', "interview #$iid recorded");
            echo json_encode(['success' => true, 'message' => 'Interview feedback saved']);
            break;
        }
        case 'cancel': {
            $iid = intval($_POST['interview_id'] ?? 0);
            if (!$iid) throw new Exception('Interview id is required');
            $pdo->prepare("UPDATE candidate_interviews SET status='cancelled' WHERE interview_id=?")->execute([$iid]);
            echo json_encode(['success' => true, 'message' => 'Interview cancelled']);
            break;
        }
        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
