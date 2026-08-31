<?php
/**
 * ajax/check_subdomain_availability.php
 *
 * Live "is this subdomain free?" check for the public signup form.
 *
 * Deliberately does NOT boot roots.php: this is an unauthenticated public
 * endpoint, and there is no tenant context to load.
 *
 * It is an enumeration surface by nature — its whole job is to say whether a
 * name is taken. That is acceptable because the answer is already public (a
 * tenant's subdomain resolves in DNS), but it must not leak anything MORE, so it
 * returns only a boolean and a message: never the company name, owner email, or
 * status of an existing tenant.
 */
require_once __DIR__ . '/../core/tenant_provisioner.php';
require_once __DIR__ . '/../core/tenant_registration.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$sub = strtolower(trim((string)($_GET['subdomain'] ?? $_POST['subdomain'] ?? '')));

if ($sub === '') {
    echo json_encode(['success' => true, 'available' => false, 'message' => '']);
    exit;
}

// Format and reserved-word rules first — no database work for input that could
// never be valid, so this stays cheap under keystroke-rate traffic.
if ($err = tenantSubdomainError($sub)) {
    echo json_encode(['success' => true, 'available' => false, 'message' => $err]);
    exit;
}

if (!selfRegistrationOpen()) {
    echo json_encode(['success' => false, 'available' => false,
                      'message' => selfRegistrationClosedReason()]);
    exit;
}

try {
    $free = tenantSubdomainAvailable($sub);
} catch (Throwable $e) {
    error_log('check_subdomain_availability: ' . $e->getMessage());
    echo json_encode(['success' => false, 'available' => false,
                      'message' => 'Could not check availability. Please try again.']);
    exit;
}

$base = tenantBaseDomain();
echo json_encode([
    'success'   => true,
    'available' => $free,
    'message'   => $free
        ? ($sub . ($base ? '.' . $base : '') . ' is available')
        : 'That subdomain is already taken.',
]);
