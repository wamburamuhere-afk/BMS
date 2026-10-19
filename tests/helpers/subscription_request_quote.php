<?php
/**
 * tests/helpers/subscription_request_quote.php — the BJP staff step of a company's
 * subscription request: save the quotation the way quotation_create.php?request=ID
 * does (same prefilled lines, same hidden subscription_request_id), through the real
 * api/account/save_quotation.php. Included by the subscription tests. CLI-only.
 *
 *   srTestQuote($api, $pdo, 'SR-0007', $customerId, $link = false)
 *     $api: the test's own fn(string $file, array $post): array (runs an endpoint as a BJP admin, platform root)
 *   → ['response' => save_quotation.php JSON, 'quotation' => quotations row or [], 'request' => srGet() row]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__, 2) . '/core/subscription_requests.php';

if (!function_exists('srTestRequest')) {
    function srTestRequest(PDO $pdo, string $number): ?array
    {
        $st = $pdo->prepare("SELECT id FROM subscription_requests WHERE request_number = ?");
        $st->execute([$number]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? srGet($pdo, $id) : null;
    }
}

if (!function_exists('srTestQuote')) {
    function srTestQuote(callable $api, PDO $pdo, string $number, int $customerId, bool $link = false): array
    {
        $req = srTestRequest($pdo, $number);
        if (!$req) return ['response' => ['success' => false, 'message' => "no request $number"], 'quotation' => [], 'request' => null];
        // What collectItems() posts for the lines the form filled in from the request.
        $items = array_map(fn($l) => [
            'product_id' => $l['product_id'], 'product_name' => $l['name'], 'sku' => '', 'quantity' => $l['qty'], 'unit' => $l['unit'],
            'unit_price' => $l['unit_price'], 'tax_rate' => $l['tax_rate'], 'discount_percent' => 0, 'description' => $l['description'],
            'price_term_id' => $l['token'],
        ], $req['lines']);
        $r = $api('api/account/save_quotation.php', [
            'customer_id' => (string)$customerId, 'order_date' => date('Y-m-d'), 'currency' => 'TZS', 'payment_terms' => 'cash',
            'reference' => 'Request ' . $req['request_number'] . ' (' . $req['subdomain'] . ')', 'notes' => '', 'terms_conditions' => '',
            'valid_until' => date('Y-m-d', strtotime('+14 days')), 'status' => 'pending', 'is_service_order' => 'on',
            'subscription_request_id' => (string)$req['id'], 'link_tenant' => $link ? '1' : '0', 'items' => json_encode($items),
        ]);
        $q = [];
        if (!empty($r['quotation_id'])) {
            $q = $pdo->query("SELECT * FROM quotations WHERE sales_order_id = " . (int)$r['quotation_id'])->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        return ['response' => $r, 'quotation' => $q, 'request' => srGet($pdo, $req['id'])];
    }
}
