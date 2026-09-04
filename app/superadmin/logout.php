<?php
/**
 * app/superadmin/logout.php — end a platform operator session.
 *
 * Clears only the superadmin key. It deliberately does not destroy the whole
 * session: in single-tenant/local mode the operator may also be signed in to the
 * application as an ordinary user in the same browser session, and signing out
 * of the platform panel should not sign them out of that too.
 */
require_once __DIR__ . '/../../core/superadmin_auth.php';

assertSuperadminHost();
superadminLogout();

header('Location: ' . saUrl('login'));
exit;
