<?php
/**
 * cron/run_online_shop_checks.php — the "every few minutes" online shop alerts
 * (core/retail_online_notify.php::rsnRunFrequentChecks): orders not accepted, accepted orders
 * not paid / collected, and waiting orders that expire after 24 hours.
 *
 * Also runs opportunistically from api/run_background_jobs.php (≈ every 2 minutes while anyone
 * uses the system). For a quiet shop add a server cron:
 *   *\/2 * * * *  php cron/run_online_shop_checks.php
 * Each alert fires once per order (dedupe), so running it often is safe.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/retail_online_notify.php';
global $pdo;
if (!rsnModuleOn($pdo)) { echo "Online shop off — nothing to do.\n"; exit(0); }
echo json_encode(rsnRunFrequentChecks($pdo)) . "\n";
