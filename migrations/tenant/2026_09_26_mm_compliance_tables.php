<?php
/**
 * migrations/tenant/2026_09_26_mm_compliance_tables.php
 *
 * Retired 2026-10-13: the Mobile Money module was removed from BMS (it moved to
 * the LMS product) and its tables are dropped by 2026_10_13_drop_mm_tables.
 * This setup step is kept only so the filename stays known to the migration
 * runners; it now does nothing. It must not fail: a failing tenant migration
 * stops every later migration for that tenant, which is what happened to newly
 * registered tenants once the tables this file used to touch were gone.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

echo "Skipped 2026_09_26_mm_compliance_tables: Mobile Money module was removed — nothing to do.
";
