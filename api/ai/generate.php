<?php
/**
 * api/ai/generate.php — "Generate with AI" text drafting.
 * Input: instruction (what to write), field_type (context label), tone.
 * Returns drafted text. Permission-gated; works only when AI is enabled.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/ai_service.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('ai_assistant')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'You do not have access to the AI Assistant']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

if (!aiConfigured()) { echo json_encode(['success' => false, 'message' => 'AI is not configured.']); exit; }
if (aiRateLimited()) { echo json_encode(['success' => false, 'message' => 'You are sending requests too fast — please wait a moment.']); exit; }

$instruction = trim($_POST['instruction'] ?? '');
$fieldType   = trim($_POST['field_type'] ?? 'text');
$tone        = in_array($_POST['tone'] ?? '', ['professional', 'friendly', 'concise', 'formal'], true) ? $_POST['tone'] : 'professional';
$existing    = trim($_POST['existing'] ?? '');

if ($instruction === '' && $existing === '') {
    echo json_encode(['success' => false, 'message' => 'Tell the assistant what to write.']);
    exit;
}

$company = getSetting('company_name', 'our company');
$fieldLabels = [
    'invoice_description'   => 'a line-item / notes description for a customer invoice',
    'quotation_notes'       => 'notes/terms for a customer quotation',
    'expense_description'   => 'a description for a business expense record',
    'email'                 => 'a business email body',
    'sms'                   => 'a short SMS message (max ~300 chars)',
    'product_description'    => 'a product/service description',
    'document_letter'      => 'the body of a formal business letter/memo — salutation through closing, no letterhead or subject line (those are separate fields)',
    'text'                  => 'business text',
];
$what = $fieldLabels[$fieldType] ?? 'business text';

$sys = "You are a business writing assistant for {$company}, a company in Tanzania. "
     . "Write {$what} in a {$tone} tone. Output ONLY the text itself — no preamble, no quotes, no markdown headings. "
     . "Keep it accurate and business-appropriate; do not invent specific figures, dates, or names that were not provided.";

$user = $instruction !== '' ? "Write this: {$instruction}" : "Improve and polish the following:\n\n{$existing}";
if ($instruction !== '' && $existing !== '') {
    $user .= "\n\nCurrent draft to build on:\n{$existing}";
}

$res = aiComplete([
    ['role' => 'system', 'content' => $sys],
    ['role' => 'user',   'content' => $user],
], ['feature' => 'generate', 'max_tokens' => ($fieldType === 'sms' ? 200 : 600)]);

if (!$res['ok']) {
    echo json_encode(['success' => false, 'message' => $res['error'] ?: 'AI request failed.']);
    exit;
}

logActivity($pdo, $_SESSION['user_id'] ?? 0, "Used Generate-with-AI ($fieldType)");
echo json_encode(['success' => true, 'text' => trim($res['text'])]);
