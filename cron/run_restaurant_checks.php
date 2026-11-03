<?php
/**
 * cron/run_restaurant_checks.php — the "every few minutes" restaurant notifications
 * (core/restaurant_notify.php::rnRunFrequentChecks): online orders not accepted,
 * kitchen orders waiting long, reservations within the hour, table bills open long.
 *
 * Also runs opportunistically from api/run_background_jobs.php (≈ every 2 minutes
 * while anyone uses the system). For a quiet restaurant add a server cron:
 *   *\/2 * * * *  php cron/run_restaurant_checks.php
 * Each alert fires once per record (dedupe), so running it often is safe.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/restaurant_notify.php';
global $pdo;
if (!rnModuleOn($pdo)) { echo "Restaurant module off — nothing to do.\n"; exit(0); }
echo json_encode(rnRunFrequentChecks($pdo)) . "\n";
