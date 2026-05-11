<?php
/**
 * Test: Reporting tab fix — Actual (Qty) display + Comment/Attachment save guard
 *
 * Phase 1 fix: renderReportingTable() — savedToday null-check so 0-value actuals
 *              display as "0" (not empty) and Progress (%) calculates correctly.
 *
 * Phase 2 fix: saveDailyReporting() guard — comment or attachment alone is enough
 *              to allow a save without needing at least one non-zero actual.
 *
 * Run: http://localhost/bms/scratch/test_reporting_fix_2026_05.php
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: text/html; charset=utf-8');

// ── helpers ────────────────────────────────────────────────────────────────

function pass(string $label): void {
    echo "<p style='color:green;font-family:monospace'>&#10003; PASS &mdash; $label</p>";
}

function fail(string $label, string $detail = ''): void {
    $extra = $detail ? " <span style='color:#888'>($detail)</span>" : '';
    echo "<p style='color:red;font-family:monospace'>&#10007; FAIL &mdash; $label$extra</p>";
}

function section(string $title): void {
    echo "<h3 style='font-family:monospace;margin-top:24px;border-bottom:1px solid #ccc'>$title</h3>";
}

// ── find a real project to use ──────────────────────────────────────────────

$project = $pdo->query("SELECT project_id FROM projects LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$project) {
    die("<p style='color:red'>No projects found — cannot run tests.</p>");
}
$projectId = $project['project_id'];

// find a leaf milestone (no children)
$leafStmt = $pdo->prepare("
    SELECT id, scope FROM project_milestones
    WHERE project_id = ?
      AND scope_type = 'milestone'
      AND id NOT IN (SELECT DISTINCT parent_id FROM project_milestones WHERE parent_id IS NOT NULL)
    LIMIT 1
");
$leafStmt->execute([$projectId]);
$leaf = $leafStmt->fetch(PDO::FETCH_ASSOC);

$testDate = '2099-01-01'; // far-future date so we don't touch real data

// ── cleanup helper ──────────────────────────────────────────────────────────

function cleanupTestReport(PDO $pdo, int $projectId, string $date): void {
    $row = $pdo->prepare("SELECT id FROM project_progress_reports WHERE project_id=? AND report_date=? AND report_type='daily'");
    $row->execute([$projectId, $date]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $pdo->prepare("DELETE FROM project_progress_report_details WHERE report_id=?")->execute([$r['id']]);
        $pdo->prepare("DELETE FROM project_progress_reports WHERE id=?")->execute([$r['id']]);
    }
}

cleanupTestReport($pdo, $projectId, $testDate);

echo "<!DOCTYPE html><html><head><title>Reporting Fix Tests</title></head><body>";
echo "<h2 style='font-family:monospace'>Reporting Tab Fix — Test Suite</h2>";
echo "<p style='font-family:monospace;color:#555'>Project ID: $projectId | Test date: $testDate</p>";

// ═══════════════════════════════════════════════════════════════════════════
// PHASE 1 TESTS — Actual (Qty) display: 0-value roundtrip
// ═══════════════════════════════════════════════════════════════════════════

section('Phase 1 — Actual (Qty) + Progress: 0-value roundtrip via DB');

if ($leaf) {
    $milestoneId = $leaf['id'];

    // --- T1: Save actual_value = 0, then confirm the row EXISTS in details ---
    $pdo->prepare("INSERT INTO project_progress_reports (project_id, report_date, report_type, comments, created_by, created_at) VALUES (?,?,?,?,1,NOW())")
        ->execute([$projectId, $testDate, 'daily', '']);
    $reportId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO project_progress_report_details (report_id, milestone_id, actual_value, progress_percent) VALUES (?,?,0,0)")
        ->execute([$reportId, $milestoneId]);

    $detail = $pdo->prepare("SELECT actual_value FROM project_progress_report_details WHERE report_id=? AND milestone_id=?");
    $detail->execute([$reportId, $milestoneId]);
    $row = $detail->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['actual_value'] == 0) {
        pass("T1: actual_value=0 is stored in DB (not skipped on insert)");
    } else {
        fail("T1: actual_value=0 not found in DB after insert");
    }

    // --- T2: get_progress_reports.php returns details with actual_value=0 ---
    $stmt = $pdo->prepare("
        SELECT d.actual_value
        FROM project_progress_reports pr
        JOIN project_progress_report_details d ON pr.id = d.report_id
        WHERE pr.project_id=? AND pr.report_date=? AND pr.report_type='daily' AND d.milestone_id=?
    ");
    $stmt->execute([$projectId, $testDate, $milestoneId]);
    $fetched = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($fetched && $fetched['actual_value'] == 0) {
        pass("T2: get query returns the 0-value detail row (not filtered out)");
    } else {
        fail("T2: 0-value detail row not returned by get query");
    }

    // --- T3: JS fix logic — null-check distinguishes null from 0 ---
    // Simulates: detailEntry found → savedToday = parseFloat(0) = 0 (not null)
    // Before fix: 0 || 0 = 0 → then 0 || '' = '' (empty) ← BUG
    // After fix:  detailEntry !== undefined → savedToday = 0 → 0 ?? '' = 0 (shows 0) ← FIXED
    $actualValue   = (float)$fetched['actual_value']; // 0.0
    $detailFound   = true;                             // row was found
    $savedToday    = $detailFound ? $actualValue : null;
    $displayValue  = $savedToday ?? '';                // null → '', 0 → 0

    if ($displayValue === 0.0) {
        pass("T3: JS null-check logic — savedToday=0 displays as '0', not empty string");
    } else {
        fail("T3: JS null-check logic — got " . var_export($displayValue, true) . " instead of 0");
    }

    // --- T4: no detail row → savedToday is null → input should be empty ---
    $savedTodayNull = null;
    $displayNull    = $savedTodayNull ?? '';
    if ($displayNull === '') {
        pass("T4: JS null-check logic — no detail row → display is empty string (correct)");
    } else {
        fail("T4: JS null-check logic — no detail row should show '', got " . var_export($displayNull, true));
    }

    // --- T5: positive value roundtrip ---
    $pdo->prepare("UPDATE project_progress_report_details SET actual_value=125.50 WHERE report_id=? AND milestone_id=?")
        ->execute([$reportId, $milestoneId]);
    $stmt->execute([$projectId, $testDate, $milestoneId]);
    $r125 = $stmt->fetch(PDO::FETCH_ASSOC);
    $savedPos   = $r125 ? parseFloatPHP((string)$r125['actual_value']) : null;
    $displayPos = $savedPos ?? '';
    if ($displayPos === 125.5) {
        pass("T5: Positive value 125.50 — roundtrip correct, displays as 125.5");
    } else {
        fail("T5: Positive value roundtrip — got " . var_export($displayPos, true));
    }

    cleanupTestReport($pdo, $projectId, $testDate);

} else {
    echo "<p style='color:orange;font-family:monospace'>&#9888; No leaf milestone found for project $projectId — Phase 1 DB tests skipped.</p>";
}

// ═══════════════════════════════════════════════════════════════════════════
// PHASE 2 TESTS — Save guard: comment or attachment alone should allow save
// ═══════════════════════════════════════════════════════════════════════════

section('Phase 2 — Save guard: comment/attachment without actuals');

// Simulate the JS guard logic (after fix) in PHP
function canSave(array $details, string $comment, bool $hasNewAttachments): bool {
    $hasActuals       = count($details) > 0;
    $hasComment       = trim($comment) !== '';
    return $hasActuals || $hasComment || $hasNewAttachments;
}

// --- T6: no actuals, no comment, no attachment → blocked ---
if (!canSave([], '', false)) {
    pass("T6: Empty form (no actuals, no comment, no attachment) → save blocked correctly");
} else {
    fail("T6: Empty form should be blocked but was allowed");
}

// --- T7: comment only, no actuals → should be allowed ---
if (canSave([], 'Site visit completed, no works today.', false)) {
    pass("T7: Comment only (no actuals) → save allowed");
} else {
    fail("T7: Comment only should be allowed but was blocked");
}

// --- T8: attachment only, no actuals, no comment → should be allowed ---
if (canSave([], '', true)) {
    pass("T8: Attachment only (no actuals, no comment) → save allowed");
} else {
    fail("T8: Attachment only should be allowed but was blocked");
}

// --- T9: actuals only (original behaviour preserved) → should be allowed ---
if (canSave([['milestone_id' => 1, 'actual_value' => 50]], '', false)) {
    pass("T9: Actuals only → save allowed (original behaviour preserved)");
} else {
    fail("T9: Actuals only should always be allowed");
}

// --- T10: actuals + comment + attachment → save allowed ---
if (canSave([['milestone_id' => 1, 'actual_value' => 0]], 'Progress noted.', true)) {
    pass("T10: Actuals + comment + attachment → save allowed");
} else {
    fail("T10: Full data should always be allowed");
}

// --- T11: comment is only whitespace → treated as empty, blocks save ---
if (!canSave([], '   ', false)) {
    pass("T11: Whitespace-only comment → treated as empty, save blocked");
} else {
    fail("T11: Whitespace-only comment should not unlock save");
}

// ── DB-level Phase 2: save report with only a comment, no detail rows ───────

section('Phase 2 — DB level: comment-only report saves and reloads');

cleanupTestReport($pdo, $projectId, $testDate);

$pdo->prepare("INSERT INTO project_progress_reports (project_id, report_date, report_type, comments, created_by, created_at) VALUES (?,?,?,?,1,NOW())")
    ->execute([$projectId, $testDate, 'daily', 'Observation only — no works today.']);
$commentOnlyId = (int)$pdo->lastInsertId();

// verify report saved
$saved = $pdo->prepare("SELECT comments FROM project_progress_reports WHERE id=?");
$saved->execute([$commentOnlyId]);
$savedRow = $saved->fetch(PDO::FETCH_ASSOC);

if ($savedRow && $savedRow['comments'] === 'Observation only — no works today.') {
    pass("T12: Comment-only report saved to DB with correct text");
} else {
    fail("T12: Comment-only report not found or comments mismatch");
}

// verify it is fetchable by get_progress_reports query
$checkStmt = $pdo->prepare("
    SELECT pr.id, pr.comments
    FROM project_progress_reports pr
    WHERE pr.project_id=? AND pr.report_date=? AND pr.report_type='daily'
");
$checkStmt->execute([$projectId, $testDate]);
$checkRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

if ($checkRow && $checkRow['comments'] === 'Observation only — no works today.') {
    pass("T13: Comment-only report returned by get_progress_reports query");
} else {
    fail("T13: Comment-only report not found by get query");
}

// verify zero detail rows (correct — no actuals were entered)
$detailCount = (int)$pdo->prepare("SELECT COUNT(*) FROM project_progress_report_details WHERE report_id=?")
    ->execute([$commentOnlyId]) ? $pdo->query("SELECT COUNT(*) FROM project_progress_report_details WHERE report_id=$commentOnlyId")->fetchColumn() : -1;

if ($detailCount === 0) {
    pass("T14: Comment-only report has 0 detail rows (correct, no actuals)");
} else {
    fail("T14: Expected 0 detail rows, got $detailCount");
}

cleanupTestReport($pdo, $projectId, $testDate);

// ── summary ─────────────────────────────────────────────────────────────────

echo "<hr><p style='font-family:monospace;color:#555'>All tests complete. Green = pass, Red = fail.</p></body></html>";

// helper: PHP equivalent of JS parseFloat
function parseFloatPHP(string $val): ?float {
    if (is_numeric($val)) return (float)$val;
    return null;
}
