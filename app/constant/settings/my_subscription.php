<?php
/**
 * My Subscription → now "Billing" (service_plans_plan.md D8). Old links and bookmarks land on the new page.
 */
require_once __DIR__ . '/../../../roots.php';
header('Location: ' . getUrl('subscription'), true, 301);
exit();
