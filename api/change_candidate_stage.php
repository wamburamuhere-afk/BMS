<?php
// API: Move a candidate through the pipeline (Tier 4, Phase 4.5 — D28a).
// Forward-only: applied → shortlisted → interview → offered → hired.
// 'rejected' is allowed from any non-terminal stage. Every move needs a note.
// 'hired' requires the opening still open; linking the created employee is done
// separately via action=link_employee after the Add-Employee modal succeeds.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('recruitment')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$order = ['applied' => 0, 'shortlisted' => 1, 'interview' => 2, 'offered' => 3, 'hired' => 4];

try {
    $id = intval($_POST['candidate_id'] ?? 0);
    $action = trim($_POST['action'] ?? 'stage');
    if (!$id) throw new Exception('Candidate id is required');

    $stmt = $pdo->prepare("SELECT * FROM candidates WHERE candidate_id=? AND status='active'");
    $stmt->execute([$id]);
    $cand = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cand) throw new Exception('Candidate not found');

    // Linking the hired employee (called after the Add Employee modal succeeds)
    if ($action === 'link_employee') {
        $emp = intval($_POST['employee_id'] ?? 0);
        if (!$emp) throw new Exception('Employee id is required');
        $pdo->prepare("UPDATE candidates SET hired_employee_id=?, updated_by=? WHERE candidate_id=?")->execute([$emp, $_SESSION['user_id'], $id]);
        logActivity($pdo, $_SESSION['user_id'], 'Link hired candidate', "candidate #$id → employee #$emp");
        echo json_encode(['success' => true, 'message' => 'Candidate linked to the new employee']);
        exit;
    }

    $new = trim($_POST['stage'] ?? '');
    $note = trim($_POST['note'] ?? '');
    if ($note === '') throw new Exception('A note is required for a stage move');
    $cur = $cand['stage'];
    if (in_array($cur, ['hired', 'rejected'], true)) throw new Exception("This candidate is already $cur");

    if ($new === 'rejected') {
        // allowed from any non-terminal stage
    } elseif (isset($order[$new]) && isset($order[$cur])) {
        if ($order[$new] <= $order[$cur]) throw new Exception("Cannot move backward or stay (from $cur to $new)");
        if ($order[$new] !== $order[$cur] + 1) throw new Exception('Stages cannot be skipped — advance one at a time');
    } else {
        throw new Exception('Invalid stage');
    }

    // hire requires the opening still open
    if ($new === 'hired') {
        $ostatus = $pdo->query("SELECT status FROM job_openings WHERE opening_id=" . (int)$cand['opening_id'])->fetchColumn();
        if ($ostatus !== 'open') throw new Exception('The opening is not open — reopen it before hiring');
    }

    $pdo->prepare("UPDATE candidates SET stage=?, stage_notes=?, updated_by=? WHERE candidate_id=?")->execute([$new, $note, $_SESSION['user_id'], $id]);
    logActivity($pdo, $_SESSION['user_id'], 'Move candidate stage', "candidate #$id: $cur → $new: $note");
    logAudit($pdo, $_SESSION['user_id'], 'stage_change', ['activity_type'=>'status_change','entity_type'=>'candidate','entity_id'=>$id,'description'=>"Candidate $cur → $new: $note",'old_values'=>['stage'=>$cur],'new_values'=>['stage'=>$new]]);

    echo json_encode(['success' => true, 'message' => "Moved to " . $new, 'stage' => $new]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
