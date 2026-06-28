<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/code_generator.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canCreate('crm_convert')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
csrf_check();

$lead_id = intval($_POST['lead_id'] ?? 0);
if (!$lead_id) { echo json_encode(['success'=>false,'message'=>'lead_id required']); exit; }

try {
    $stmt = $pdo->prepare("SELECT * FROM crm_leads WHERE lead_id = ? AND status != 'deleted'");
    $stmt->execute([$lead_id]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lead) { echo json_encode(['success'=>false,'message'=>'Lead not found']); exit; }
    if ($lead['converted']) { echo json_encode(['success'=>false,'message'=>'Lead is already converted']); exit; }

    $pdo->beginTransaction();

    // Step A — create Customer (company-prefixed sequential code, BFS-CUST-0001).
    $cust_code = nextCode($pdo, 'CUST');
    $full_name  = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
    $cust_name  = $lead['company_name'] ?: ($full_name ?: 'Lead #' . $lead_id);

    $pdo->prepare("
        INSERT INTO customers
            (customer_code, customer_name, company_name, email, phone, mobile, address, city,
             country, currency, status, created_by, created_at, year, project_id, category_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'TZS', 'active', ?, NOW(), ?, ?, 0)
    ")->execute([
        $cust_code, $cust_name,
        $lead['company_name'] ?? null,
        $lead['email']    ?? null,
        $lead['phone']    ?? null,
        $lead['mobile']   ?? null,
        $lead['address']  ?? null,
        $lead['city']     ?? null,
        $lead['country']  ?: 'Tanzania',
        $_SESSION['user_id'],
        date('Y'),
        $lead['project_id'] ?? null,
    ]);
    $customer_id = (int)$pdo->lastInsertId();

    // Step B — create Quotation (is_quote=1), company-prefixed sequential (BFS-QT-0001).
    $quote_code = nextCode($pdo, 'QT');

    $pdo->prepare("
        INSERT INTO quotations
            (order_number, customer_id, order_date, status, total_amount, grand_total,
             currency, notes, created_by, created_at, updated_by, is_quote, project_id, salesperson_id)
        VALUES (?, ?, CURDATE(), 'draft', ?, ?, 'TZS', ?, ?, NOW(), ?, 1, ?, ?)
    ")->execute([
        $quote_code, $customer_id,
        $lead['lead_value'] ?? 0,
        $lead['lead_value'] ?? 0,
        $lead['notes'] ?? null,
        $_SESSION['user_id'],
        $_SESSION['user_id'],
        $lead['project_id'] ?? null,
        $lead['assigned_to'] ?? $_SESSION['user_id'],
    ]);
    $quotation_id = (int)$pdo->lastInsertId();

    // Step C — mark lead converted
    $pdo->prepare("
        UPDATE crm_leads SET converted=1, customer_id=?, quotation_id=?, updated_by=?, updated_at=NOW()
        WHERE lead_id=?
    ")->execute([$customer_id, $quotation_id, $_SESSION['user_id'], $lead_id]);

    $pdo->commit();

    $full_name_log = trim($lead['first_name'] . ' ' . $lead['last_name']);
    logActivity($pdo, $_SESSION['user_id'],
        "Converted lead {$lead['lead_code']} ($full_name_log) → Customer $cust_code + Quotation $quote_code");

    echo json_encode([
        'success'      => true,
        'message'      => "Lead converted. Customer $cust_code and Quotation $quote_code created.",
        'customer_id'  => $customer_id,
        'customer_code'=> $cust_code,
        'quotation_id' => $quotation_id,
        'quote_code'   => $quote_code,
        'customer_url' => getUrl('customers?id=' . $customer_id),
        'quote_url'    => getUrl('quotations?id=' . $quotation_id),
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('convert_lead error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error.']);
}
