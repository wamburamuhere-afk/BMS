<?php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

/**
 * Compute lead score (0–100) based on activity, deal attributes, and pipeline position.
 * Called internally by move_lead_stage.php, add_activity.php, and edit_lead.php.
 */
function computeLeadScore(PDO $pdo, int $lead_id): int
{
    $lead = $pdo->prepare("
        SELECT cl.lead_value, cl.probability, cl.expected_close_date, cl.lead_source,
               cl.last_activity, cl.stage_entered, cl.assigned_to,
               ps.stage_order,
               (SELECT COUNT(*) FROM crm_lead_activities la
                WHERE la.lead_id = cl.lead_id AND la.status != 'deleted') AS act_count,
               (SELECT COUNT(*) FROM crm_lead_activities la
                WHERE la.lead_id = cl.lead_id AND la.status = 'overdue') AS overdue_count,
               (SELECT AVG(cl2.lead_value) FROM crm_leads cl2
                WHERE cl2.status != 'deleted' AND cl2.lead_value > 0) AS avg_value
        FROM crm_leads cl
        LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
        WHERE cl.lead_id = ?
    ");
    $lead->execute([$lead_id]);
    $r = $lead->fetch(PDO::FETCH_ASSOC);
    if (!$r) return 0;

    $score = 0;
    $avg   = max(1, (float)$r['avg_value']);

    // +20 if lead_value above company average
    if ((float)$r['lead_value'] > $avg) $score += 20;

    // +15 if probability >= 60%
    if ((int)$r['probability'] >= 60) $score += 15;

    // +15 if last_activity within 7 days
    if ($r['last_activity'] && strtotime($r['last_activity']) >= strtotime('-7 days')) $score += 15;

    // +10 if activity count >= 3
    if ((int)$r['act_count'] >= 3) $score += 10;

    // +10 if expected_close_date within 30 days (and not past)
    if ($r['expected_close_date']) {
        $closeTs = strtotime($r['expected_close_date']);
        if ($closeTs >= time() && $closeTs <= strtotime('+30 days')) $score += 10;
    }

    // +10 if high-intent source
    if (in_array($r['lead_source'], ['referral', 'walk_in'], true)) $score += 10;

    // +10 if stage_order >= 3 (Qualified or beyond)
    if ((int)$r['stage_order'] >= 3) $score += 10;

    // +10 if assigned_to is set
    if ($r['assigned_to']) $score += 10;

    // -5 if stalled (>14 days in same stage)
    if ($r['stage_entered'] && strtotime($r['stage_entered']) < strtotime('-14 days')) $score -= 5;

    // -10 if overdue activity exists
    if ((int)$r['overdue_count'] > 0) $score -= 10;

    return max(0, min(100, $score));
}

// Skip endpoint logic when loaded as a library (require_once from another script)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

// Allow direct call (admin only) to bulk-recalculate
if (isset($_GET['bulk']) && isAdmin()) {
    $ids = $pdo->query("SELECT lead_id FROM crm_leads WHERE status != 'deleted'")->fetchAll(PDO::FETCH_COLUMN);
    $upd = $pdo->prepare("UPDATE crm_leads SET lead_score = ? WHERE lead_id = ?");
    foreach ($ids as $lid) {
        $upd->execute([computeLeadScore($pdo, (int)$lid), $lid]);
    }
    logActivity($pdo, $_SESSION['user_id'], 'Bulk-recalculated lead scores for ' . count($ids) . ' leads');
    echo json_encode(['success' => true, 'message' => count($ids) . ' leads re-scored.']);
    exit;
}

// Single lead recalculation
if (!canEdit('crm_leads')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
$lead_id = intval($_GET['lead_id'] ?? $_POST['lead_id'] ?? 0);
if (!$lead_id) { echo json_encode(['success' => false, 'message' => 'lead_id required']); exit; }

$score = computeLeadScore($pdo, $lead_id);
$pdo->prepare("UPDATE crm_leads SET lead_score = ? WHERE lead_id = ?")->execute([$score, $lead_id]);
logActivity($pdo, $_SESSION['user_id'], "Recalculated lead score for lead #$lead_id → $score");
echo json_encode(['success' => true, 'score' => $score]);
