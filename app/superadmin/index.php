<?php
/**
 * app/superadmin/index.php — the platform panel's entry point.
 *
 * Phase 4 shipped a read-only overview here. Phase 6 replaced it with the full
 * lifecycle panel in tenants.php, so this is now just the front door: it applies
 * the guard, then forwards.
 *
 * Kept as a redirect rather than deleted because it is the stable landing
 * address — actions/superadmin_login.php points here, and it is the URL
 * operators were given in Phase 4.
 */
require_once __DIR__ . '/../../core/superadmin_auth.php';

requireSuperadmin();                 // 404 from a tenant host; redirect if signed out

header('Location: tenants.php');
exit;
