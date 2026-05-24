<?php
// File: app/bms/sales/quotations/quotation_edit.php
// Entry point for EDITING an existing quotation (requires ?id=).
// The form body lives in quotation_form.php (quotation module only — not shared
// with sales orders). With ?id= present, the form runs in "edit" mode.
require_once __DIR__ . '/../../../../roots.php';

// Phase 5a — defense-in-depth: gate the entry point. quotation_form.php gates again.
autoEnforcePermission('sales_orders');

require_once __DIR__ . '/quotation_form.php';
