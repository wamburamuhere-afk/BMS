<?php
/**
 * General Ledger - Redirecting to the new Report
 */
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('ledger_report');

header('Location: ' . getUrl('ledger_report'));
exit();
?>
