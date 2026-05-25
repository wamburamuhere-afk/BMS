<?php
// File: app/constant/accounts/payment_voucher_details.php
//
// Phase 9 — gate-and-redirect stub.
//
// This file existed as a 0-byte placeholder before Phase 5/9. It is
// reachable via roots.php as the 'payment_voucher_view' route. Until
// the on-screen detail view is built, we forward authorised users to
// the print page (which has the complete voucher rendering) and deny
// unauthorised users explicitly.
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('payment_vouchers');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id > 0) {
    header('Location: ' . getUrl('accounts/payment_voucher_print') . '?id=' . $id);
    exit;
}

header('Location: ' . getUrl('payment_vouchers'));
exit;
