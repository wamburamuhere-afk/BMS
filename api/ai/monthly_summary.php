<?php
/**
 * api/ai/monthly_summary.php — plain-language "month in words" owner digest.
 * Gathers this month's KPIs via the curated insight registry, then asks the
 * model to phrase a short summary. Read-only; permission+CSRF gated.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/ai_service.php';
require_once __DIR__ . '/../../core/ai_insights.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('ai_assistant')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'No access to the AI Assistant']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!aiConfigured()) { echo json_encode(['success' => false, 'message' => 'AI is not configured.']); exit; }
if (aiRateLimited()) { echo json_encode(['success' => false, 'message' => 'Please wait a moment before generating another summary.']); exit; }

// Collect KPIs (each is a curated read-only insight; never raw rows).
$kpi = [
    'revenue_this_month'      => aiRunInsight('revenue', ['period' => 'this_month'])['data'] ?? null,
    'revenue_last_month'      => aiRunInsight('revenue', ['period' => 'last_month'])['data'] ?? null,
    'expenses_this_month'     => aiRunInsight('expenses_total', ['period' => 'this_month'])['data'] ?? null,
    'profit_this_month'       => aiRunInsight('profit', ['period' => 'this_month'])['data'] ?? null,
    'outstanding_receivables' => aiRunInsight('outstanding_receivables')['data'] ?? null,
    'cash_position'           => aiRunInsight('cash_position')['data'] ?? null,
    'top_customers'           => aiRunInsight('top_customers', ['limit' => 3, 'period' => 'this_month'])['data'] ?? null,
    'top_debtors'             => aiRunInsight('top_debtors', ['limit' => 3])['data'] ?? null,
    'low_stock'               => aiRunInsight('low_stock', ['limit' => 5])['data'] ?? null,
    'ar_aging'                => aiRunInsight('ar_aging_summary')['data'] ?? null,
];

$company  = getSetting('company_name', 'the company');
$currency = getSetting('currency', 'TZS');
$month    = date('F Y');

$sys = "You are a financial summariser for {$company}. Currency is {$currency}. "
     . "Write a concise business summary for {$month} (4-7 short sentences or bullet points) from the figures provided. "
     . "Mention revenue (and the month-on-month change if both months are present), expenses, profit, cash position, "
     . "outstanding receivables (flag anything in the 90+ bucket), a top customer, and any low-stock warning. "
     . "Use the currency on amounts. Do NOT invent figures beyond what is given. Be direct and useful to a busy owner.";

$res = aiComplete([
    ['role' => 'system', 'content' => $sys],
    ['role' => 'user',   'content' => "Figures (JSON):\n" . json_encode($kpi)],
], ['feature' => 'summary', 'max_tokens' => 600, 'temperature' => 0.3]);

if (!$res['ok']) { echo json_encode(['success' => false, 'message' => $res['error'] ?: 'Could not generate the summary.']); exit; }

logActivity($pdo, $_SESSION['user_id'] ?? 0, "Generated AI monthly summary ($month)");
echo json_encode(['success' => true, 'month' => $month, 'summary' => trim($res['text'])]);
