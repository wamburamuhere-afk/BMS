<?php
/**
 * api/ai/ask.php — "Ask BMS": answer a business question from the company's own
 * data, using ONLY the curated read-only insight functions (core/ai_insights.php).
 *
 * Provider-agnostic function calling: the model either replies with a JSON object
 * {"function":"name","args":{...}} to fetch a figure, or with a plain-language
 * answer. BMS runs the chosen insight and feeds the small result back. Max a few
 * hops per question. The model never sees raw rows and can never write anything.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/ai_service.php';
require_once __DIR__ . '/../../core/ai_insights.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('ai_assistant')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'You do not have access to the AI Assistant']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!aiConfigured()) { echo json_encode(['success' => false, 'message' => 'AI is not configured.']); exit; }

$question = trim($_POST['question'] ?? '');
if ($question === '') { echo json_encode(['success' => false, 'message' => 'Please type a question.']); exit; }
if (mb_strlen($question) > 500) $question = mb_substr($question, 0, 500);

$company  = getSetting('company_name', 'this company');
$currency = getSetting('currency', 'TZS');
$today    = date('Y-m-d');
$catalog  = json_encode(aiInsightCatalog(), JSON_PRETTY_PRINT);

$sys = "You are the business analyst assistant for {$company}. Today is {$today}. Currency is {$currency}.\n"
     . "Answer ONLY from the company's data, which you read through these functions:\n{$catalog}\n\n"
     . "RULES:\n"
     . "- To fetch a figure, reply with ONLY a JSON object: {\"function\":\"<name>\",\"args\":{...}} and nothing else.\n"
     . "- You may call functions one at a time; you'll receive each result, then you may call another or answer.\n"
     . "- When you have what you need, reply in clear plain language (1-4 sentences). Show amounts with the currency.\n"
     . "- Never invent numbers. If no function can answer, say you don't have that information yet.\n"
     . "- Do not output SQL or mention table/column names.";

$messages = [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $question]];
$usedFunctions = [];
$maxHops = 4;

for ($hop = 0; $hop < $maxHops; $hop++) {
    $res = aiComplete($messages, ['feature' => 'ask', 'max_tokens' => 700, 'temperature' => 0.2]);
    if (!$res['ok']) { echo json_encode(['success' => false, 'message' => $res['error'] ?: 'AI request failed.']); exit; }
    $reply = trim($res['text']);

    // Is this a function call? (a JSON object with "function")
    $call = _ai_extract_call($reply);
    if ($call === null) {
        // Final natural-language answer.
        logActivity($pdo, $_SESSION['user_id'] ?? 0, "Asked BMS AI: " . mb_substr($question, 0, 120));
        echo json_encode(['success' => true, 'answer' => $reply, 'used' => array_values(array_unique($usedFunctions))]);
        exit;
    }

    // Run the curated insight and feed the result back.
    $out = aiRunInsight($call['function'], is_array($call['args'] ?? null) ? $call['args'] : []);
    $usedFunctions[] = $call['function'];
    $messages[] = ['role' => 'assistant', 'content' => $reply];
    $messages[] = ['role' => 'user', 'content' => 'FUNCTION RESULT (' . $call['function'] . '): ' . json_encode($out['ok'] ? $out['data'] : ['error' => $out['error']])];
}

// Hop budget exhausted — make one final answer attempt without more calls.
$messages[] = ['role' => 'user', 'content' => 'Now answer in plain language using the results above. Do not call any more functions.'];
$res = aiComplete($messages, ['feature' => 'ask', 'max_tokens' => 500, 'temperature' => 0.2]);
if ($res['ok']) {
    echo json_encode(['success' => true, 'answer' => trim($res['text']), 'used' => array_values(array_unique($usedFunctions))]);
} else {
    echo json_encode(['success' => false, 'message' => 'Could not complete the answer.']);
}

/** Extract a {"function":...,"args":...} object from a model reply, or null. */
function _ai_extract_call(string $text): ?array
{
    $t = trim($text);
    // strip ```json fences if present
    $t = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t);
    if (strpos($t, '"function"') === false) return null;
    $start = strpos($t, '{'); $end = strrpos($t, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $json = substr($t, $start, $end - $start + 1);
    $obj = json_decode($json, true);
    if (is_array($obj) && !empty($obj['function']) && is_string($obj['function'])) return $obj;
    return null;
}
