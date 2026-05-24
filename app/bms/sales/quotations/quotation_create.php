<?php
// File: app/bms/sales/quotations/quotation_create.php
// Entry point for creating a NEW quotation.
// The form body lives in quotation_form.php (quotation module only — not shared
// with sales orders). With no ?id= present, the form runs in "create" mode.
require_once __DIR__ . '/../../../../roots.php';

// Phase 5a — defense-in-depth: gate the entry point. quotation_form.php gates again.
autoEnforcePermission('sales_orders');

require_once __DIR__ . '/quotation_form.php';
