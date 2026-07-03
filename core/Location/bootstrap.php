<?php
/**
 * One-line entry point for the location engine.
 * Usage anywhere in the app:
 *
 *   require_once __DIR__ . '/../core/Location/bootstrap.php';   // adjust depth
 *   $locations = new LocationService(new LocationRepository($pdo));
 */
require_once __DIR__ . '/LocationRepository.php';
require_once __DIR__ . '/LocationService.php';
require_once __DIR__ . '/LocationSyncService.php';
require_once __DIR__ . '/Providers/LocationProviderInterface.php';
require_once __DIR__ . '/Providers/MtaaCsvProvider.php';
