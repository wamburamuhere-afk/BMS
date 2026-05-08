<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();
if (function_exists('autoEnforcePermission')) autoEnforcePermission('financial_reports');

// Redirect to audit_logs which has full implementation
header('Location: ' . getUrl('audit_logs'));
exit();
?>
