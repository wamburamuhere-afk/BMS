<?php
/**
 * tests/test_customer_visit_followups_cli.php — Customer Visits: repeated follow-ups,
 * View details and follow-up reminders (customer_visit_followups_plan.md).
 *
 * Every endpoint runs in its own PHP process as a forged user
 * (tests/helpers/field_reports_request.php), so these are the real request paths:
 *   1. schema + seed       — table, event, the two default rules (seeded once, never re-added)
 *   2. backfill            — old one-date follow-ups copied once, done or not
 *   3. the form            — "Follow up on" adds / moves / cancels the open follow-up (D4)
 *   4. mark done           — how + what happened + next date; old visit_id/done calls; re-open rules
 *   5. set / move / cancel — followup_save.php, one open follow-up per visit (D2)
 *   6. new visit           — recorded from a follow-up closes it, only one the user may change (D5)
 *   7. View details        — the customer's timeline + visits; owner / admin-after-submit only
 *   8. To follow up        — the due list reads the new table; joined / done leave it
 *   9. delete              — a deleted visit's open follow-up is cancelled
 *  10. reminders           — 2/1/0/-1 days, once each, owner + admins, private (D7–D9)
 *  11. Notification Rules  — the "record owner" / "all admins" targets
 *
 * Fixtures: an admin and two non-admin users in DIFFERENT roles (staff A owns the visits,
 * staff B must never see or change them). Section 10 runs inside a transaction that is
 * rolled back, so no reminder email is ever left in the outbox to be sent. Every other row
 * this test writes is removed at the end.
 *
 *   php tests/test_customer_visit_followups_cli.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../roots.php';
require_once ROOT_DIR . '/core/field_reports_schema.php';
require_once ROOT_DIR . '/core/field_visit_followups.php';

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $m\n"; } else { $fail++; echo "  ✗ $m\n"; } }
function section($t) { echo "\n── $t\n"; }

/** Run an endpoint as a user → [http code, raw body, decoded json|null]. */
function call(string $endpoint, int $uid, bool $admin, string $method = 'GET', array $params = []): array
{
    $perms = ['view' => true, 'create' => true, 'edit' => true, 'delete' => true];
    $args = [PHP_BINARY, __DIR__ . '/helpers/field_reports_request.php', $endpoint, (string)$uid, $admin ? '1' : '0',
             json_encode($perms), $method, json_encode($params)];
    $proc = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_DIR);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $code = preg_match('/HTTP_CODE=(\d+)/', $err, $m) ? (int)$m[1] : 0;
    if (preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)/', $err . $out, $w)) echo "    ! PHP said '{$w[1]}' in $endpoint: " . substr(trim(strip_tags($err . ' ' . $out)), 0, 300) . "\n";
    return [$code, $out, json_decode($out, true)];
}
function fu(int $id): ?array { return frFuGet($GLOBALS['pdo'], $id); }
function fus(int $visitId): array
{
    $s = $GLOBALS['pdo']->prepare("SELECT * FROM field_visit_follow_ups WHERE visit_id = ? ORDER BY follow_up_id");
    $s->execute([$visitId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function mirror(int $visitId): array
{
    $s = $GLOBALS['pdo']->prepare("SELECT follow_up_date, follow_up_done_at, follow_up_done_by FROM field_visits WHERE visit_id = ?");
    $s->execute([$visitId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}
function pendingOf(int $visitId): ?array { return frFuPending($GLOBALS['pdo'], $visitId); }

// ── Fixtures ────────────────────────────────────────────────────────────
$adminId = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 AND u.is_active = 1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
$staff = $pdo->query("SELECT u.user_id, u.role_id FROM users u JOIN roles r ON r.role_id = u.role_id
                      WHERE r.is_admin = 0 AND COALESCE(u.is_admin, 0) = 0 AND u.role_id <> 1 AND u.is_active = 1 ORDER BY u.user_id")->fetchAll(PDO::FETCH_ASSOC);
$A = (int)($staff[0]['user_id'] ?? 0); $roleA = (int)($staff[0]['role_id'] ?? 0);
$B = 0; $roleB = 0;
foreach ($staff as $s) if ((int)$s['role_id'] !== $roleA) { $B = (int)$s['user_id']; $roleB = (int)$s['role_id']; break; }
if (!$adminId || !$A || !$B) { echo "Need one admin and two active non-admin users in different roles.\n"; exit(1); }
echo "Fixtures: admin #$adminId, staff A #$A, staff B #$B\n";

$today = date('Y-m-d');
$plus  = fn(int $n) => date('Y-m-d', strtotime("$today " . ($n >= 0 ? '+' : '') . "$n days"));
$day1  = $plus(-3);
$tag   = 'FUTEST-' . bin2hex(random_bytes(3));
$phone = '0711' . random_int(100000, 999999);
$daysBefore = $pdo->query("SELECT day_id FROM field_report_days")->fetchAll(PDO::FETCH_COLUMN);
$logStart = date('Y-m-d H:i:s', time() - 2);

function visit(array $over = []): array
{
    global $tag, $day1, $phone;
    return $over + [
        'visit_date' => $day1, 'visit_time' => '09:30', 'location' => "Mwenge $tag",
        'client_name' => "Client $tag", 'client_phone' => $phone, 'business_type' => 'retail',
        'interest' => 'thinking', 'notes' => "Note $tag",
        'latitude' => '-6.771200', 'longitude' => '39.239900', 'gps_accuracy_m' => '15',
    ];
}
function save(int $uid, bool $admin, array $data): array { return call('api/field_reports/save.php', $uid, $admin, 'POST', $data); }
function done(int $uid, array $data): array { return call('api/field_reports/followup_done.php', $uid, false, 'POST', $data); }
function fsave(int $uid, array $data): array { return call('api/field_reports/followup_save.php', $uid, false, 'POST', $data); }

try {
    // ═════════════════════════════════════════════════════════════════
    section('1. Schema + event + default rules');
    fieldReportsEnsureSchema($pdo);
    $cols = $pdo->query("SHOW COLUMNS FROM field_visit_follow_ups")->fetchAll(PDO::FETCH_COLUMN);
    ok(!array_diff(['follow_up_id', 'visit_id', 'due_date', 'purpose', 'status', 'method', 'outcome', 'done_at', 'done_by', 'result_visit_id', 'created_by'], $cols), 'field_visit_follow_ups has every column');
    $ev = $pdo->query("SELECT * FROM notification_events WHERE event_key = 'field_visit.follow_up_due'")->fetch(PDO::FETCH_ASSOC);
    ok($ev && $ev['page_key'] === 'field_visits' && (int)$ev['is_active'] === 1, "event 'field_visit.follow_up_due' registered on page key field_visits, active");
    $rules = fn() => $pdo->query("SELECT target_type, channel_email, channel_inapp FROM notification_rules WHERE event_key = 'field_visit.follow_up_due' AND is_active = 1 ORDER BY target_type")->fetchAll(PDO::FETCH_ASSOC);
    $r = $rules();
    ok(count($r) === 2 && $r[0]['target_type'] === 'admins' && $r[1]['target_type'] === 'record_owner'
        && (int)$r[0]['channel_email'] === 1 && (int)$r[1]['channel_email'] === 1 && (int)$r[0]['channel_inapp'] === 1 && (int)$r[1]['channel_inapp'] === 1,
        'default rules: the person who recorded it + all admins, both in-app + email');
    fieldReportsEnsureSchema($pdo); fieldReportsEnsureSchema($pdo);
    ok(count($rules()) === 2, 're-running the schema adds no duplicate rule');
    $adminsRule = $pdo->query("SELECT * FROM notification_rules WHERE event_key = 'field_visit.follow_up_due' AND target_type = 'admins'")->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("DELETE FROM notification_rules WHERE id = ?")->execute([$adminsRule['id']]);
    fieldReportsEnsureSchema($pdo);
    ok(count($rules()) === 1, 'a rule an admin removed is NOT put back by a re-run');
    $pdo->prepare("INSERT INTO notification_rules (id, event_key, target_type, target_id, channel_email, channel_inapp, digest, is_active, created_by, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$adminsRule['id'], $adminsRule['event_key'], $adminsRule['target_type'], $adminsRule['target_id'], $adminsRule['channel_email'],
                   $adminsRule['channel_inapp'], $adminsRule['digest'], $adminsRule['is_active'], $adminsRule['created_by'], $adminsRule['created_at']]);

    // ═════════════════════════════════════════════════════════════════
    section('2. Backfill of one-date follow-ups');
    $ins = $pdo->prepare("INSERT INTO field_visits (user_id, visit_date, location, client_name, client_phone, phone_normalized, business_type,
                              follow_up_date, follow_up_done_at, follow_up_done_by, created_by, created_at)
                          VALUES (?, ?, ?, ?, '', '', 'retail', ?, ?, ?, ?, NOW())");
    $ins->execute([$A, $day1, "Old $tag", "Old open $tag", $plus(3), null, null, $A]);
    $oldOpen = (int)$pdo->lastInsertId();
    $ins->execute([$A, $day1, "Old $tag", "Old done $tag", $plus(-1), date('Y-m-d H:i:s'), $A, $A]);
    $oldDone = (int)$pdo->lastInsertId();
    fieldReportsEnsureSchema($pdo);
    $o = fus($oldOpen); $d = fus($oldDone);
    ok(count($o) === 1 && $o[0]['status'] === 'pending' && $o[0]['due_date'] === $plus(3), 'an open one-date follow-up is copied as pending, same date');
    ok(count($d) === 1 && $d[0]['status'] === 'done' && $d[0]['due_date'] === $plus(-1) && (int)$d[0]['done_by'] === $A && $d[0]['done_at'] !== null, 'a done one is copied as done, with who and when');
    fieldReportsEnsureSchema($pdo);
    ok(count(fus($oldOpen)) === 1 && count(fus($oldDone)) === 1, 're-running copies nothing twice');

    // ═════════════════════════════════════════════════════════════════
    section('3. "Follow up on" in the form drives the open follow-up');
    [$c, , $j] = save($A, false, visit(['follow_up_date' => $plus(5)]));
    $v1 = (int)($j['visit_id'] ?? 0);
    $f = fus($v1);
    ok($c === 200 && count($f) === 1 && $f[0]['status'] === 'pending' && $f[0]['due_date'] === $plus(5) && (int)$f[0]['created_by'] === $A, 'a new visit with a date gets one pending follow-up, created by A');
    ok(mirror($v1)['follow_up_date'] === $plus(5) && mirror($v1)['follow_up_done_at'] === null, 'the visit row mirrors it (date, not done)');
    $firstId = (int)$f[0]['follow_up_id'];
    save($A, false, visit(['visit_id' => $v1, 'follow_up_date' => $plus(5), 'notes' => "edit $tag"]));
    ok(count(fus($v1)) === 1 && (int)fus($v1)[0]['follow_up_id'] === $firstId, 'editing other fields (same date) changes nothing');
    save($A, false, visit(['visit_id' => $v1, 'follow_up_date' => $plus(6)]));
    $f = fus($v1);
    ok(count($f) === 1 && (int)$f[0]['follow_up_id'] === $firstId && $f[0]['due_date'] === $plus(6), 'a new date MOVES the open follow-up (no second one)');
    save($A, false, visit(['visit_id' => $v1, 'follow_up_date' => '']));
    ok(fu($firstId)['status'] === 'cancelled' && mirror($v1)['follow_up_date'] === null, 'clearing the date cancels it; the visit shows no follow-up');
    save($A, false, visit(['visit_id' => $v1, 'follow_up_date' => $plus(7)]));
    $p = pendingOf($v1);
    ok($p && $p['due_date'] === $plus(7) && count(fus($v1)) === 2, 'setting a date again adds a new pending one (the cancelled one stays in history)');

    // ═════════════════════════════════════════════════════════════════
    section('4. Mark done — how, what happened, the next date');
    [$c] = done($B, ['follow_up_id' => $p['follow_up_id'], 'method' => 'call']);
    ok($c === 403, "B cannot mark A's follow-up done (got $c)");
    [$c, , $j] = done($A, ['follow_up_id' => $p['follow_up_id'], 'method' => 'fax']);
    ok($c === 422, 'an unknown method → 422');
    [$c, , $j] = done($A, ['follow_up_id' => $p['follow_up_id'], 'method' => 'call', 'next_date' => $plus(-1)]);
    ok($c === 422 && isset($j['errors']['next_date']), 'a next date in the past → 422');
    ok(fu((int)$p['follow_up_id'])['status'] === 'pending', '...and nothing was changed by the rejected calls');
    $pdo->prepare("UPDATE field_visits SET updated_at = '2026-01-01 10:00:00' WHERE visit_id = ?")->execute([$v1]);
    [$c, , $j] = done($A, ['follow_up_id' => $p['follow_up_id'], 'method' => 'call', 'outcome' => "Talked $tag", 'next_date' => $plus(10), 'next_purpose' => "Prices $tag"]);
    $was = fu((int)$p['follow_up_id']);
    $upd = $pdo->prepare("SELECT updated_at FROM field_visits WHERE visit_id = ?"); $upd->execute([$v1]);
    ok($upd->fetchColumn() === '2026-01-01 10:00:00', 'a follow-up does not count as an edit of the visit ("last changed" untouched)');
    ok($c === 200 && $was['status'] === 'done' && $was['method'] === 'call' && $was['outcome'] === "Talked $tag" && (int)$was['done_by'] === $A && $was['done_at'] !== null,
        'done: method, what happened, who and when are kept');
    $next = pendingOf($v1);
    ok($next && $next['due_date'] === $plus(10) && $next['purpose'] === "Prices $tag" && (int)($j['next_follow_up_id'] ?? 0) === (int)$next['follow_up_id'],
        'the next follow-up is set in the same step, with its reason');
    ok(mirror($v1)['follow_up_date'] === $plus(10) && mirror($v1)['follow_up_done_at'] === null, 'the visit row now shows the next one (not done)');
    [$c] = done($A, ['follow_up_id' => $p['follow_up_id'], 'method' => 'call']);
    ok($c === 422, 'marking the same follow-up done twice → 422');
    [$c] = done($A, ['visit_id' => $v1, 'done' => 1]);
    ok($c === 200 && fu((int)$next['follow_up_id'])['status'] === 'done' && mirror($v1)['follow_up_done_at'] !== null, 'the older call (visit_id + done=1) still completes the open one');
    [$c] = done($A, ['follow_up_id' => $p['follow_up_id'], 'done' => 0]);
    ok($c === 422, 'an older finished follow-up cannot be re-opened (only the last one)');
    [$c] = done($A, ['visit_id' => $v1, 'done' => 0]);
    ok($c === 200 && fu((int)$next['follow_up_id'])['status'] === 'pending' && fu((int)$next['follow_up_id'])['method'] === null, 'done=0 re-opens the last finished one (its outcome cleared)');
    [$c] = done($A, ['visit_id' => $v1, 'done' => 0]);
    ok($c === 422, 're-open while one is open → 422');
    [$c] = done($A, ['visit_id' => $oldDone + 999999, 'done' => 1]);
    ok($c === 404, 'unknown visit → 404');

    // ═════════════════════════════════════════════════════════════════
    section('5. Set / change date / cancel');
    $open = pendingOf($v1);
    [$c] = fsave($A, ['action' => 'add', 'visit_id' => $v1, 'due_date' => $plus(3)]);
    ok($c === 422 && count(array_filter(fus($v1), fn($r) => $r['status'] === 'pending')) === 1, 'a second open follow-up on one visit is refused (one at a time)');
    [$c] = fsave($B, ['action' => 'reschedule', 'follow_up_id' => $open['follow_up_id'], 'due_date' => $plus(4)]);
    ok($c === 403, "B cannot move A's follow-up (got $c)");
    [$c] = fsave($A, ['action' => 'reschedule', 'follow_up_id' => $open['follow_up_id'], 'due_date' => $plus(-2)]);
    ok($c === 422, 'moving it into the past → 422');
    [$c] = fsave($A, ['action' => 'reschedule', 'follow_up_id' => $open['follow_up_id'], 'due_date' => $plus(12), 'purpose' => "Moved $tag"]);
    ok($c === 200 && fu((int)$open['follow_up_id'])['due_date'] === $plus(12) && fu((int)$open['follow_up_id'])['purpose'] === "Moved $tag" && mirror($v1)['follow_up_date'] === $plus(12),
        'moved: new date + reason, mirrored on the visit');
    [$c] = fsave($A, ['action' => 'cancel', 'follow_up_id' => $open['follow_up_id']]);
    ok($c === 200 && fu((int)$open['follow_up_id'])['status'] === 'cancelled', 'cancelled');
    [$c] = fsave($A, ['action' => 'cancel', 'follow_up_id' => $open['follow_up_id']]);
    ok($c === 422, 'cancelling it again → 422 (no longer open)');
    // Last FINISHED one = the call (due +7): the +10 one was re-opened, moved and cancelled.
    ok(mirror($v1)['follow_up_date'] === $plus(7) && mirror($v1)['follow_up_done_at'] !== null, 'with none open, the visit shows its last finished one (✓)');
    [$c] = fsave($A, ['action' => 'add', 'visit_id' => $v1, 'due_date' => $plus(4), 'purpose' => "Again $tag"]);
    $open = pendingOf($v1);
    ok($c === 200 && $open && $open['due_date'] === $plus(4), 'a follow-up can be set again — the chain goes on');
    [$c] = fsave($A, ['action' => 'nonsense']);
    ok($c === 400, 'an unknown action → 400');

    // ═════════════════════════════════════════════════════════════════
    section('6. A new visit recorded from a follow-up closes it');
    [$c, , $j] = save($A, false, visit(['visit_date' => $today, 'visit_time' => '', 'from_follow_up_id' => $open['follow_up_id'], 'notes' => "Second $tag"]));
    $v2 = (int)($j['visit_id'] ?? 0);
    $closed = fu((int)$open['follow_up_id']);
    ok($c === 200 && !empty($j['follow_up_closed']) && $closed['status'] === 'done' && $closed['method'] === 'visit' && (int)$closed['result_visit_id'] === $v2,
        'the follow-up is done "by visit", linked to the new visit');
    ok(mirror($v1)['follow_up_done_at'] !== null, 'the old visit shows it done');
    fsave($A, ['action' => 'add', 'visit_id' => $v2, 'due_date' => $plus(2)]);
    $v2open = pendingOf($v2);
    [$c, , $j] = save($B, false, visit(['client_name' => "B client $tag", 'client_phone' => '', 'visit_date' => $today, 'visit_time' => '', 'from_follow_up_id' => $v2open['follow_up_id']]));
    $bVisit = (int)($j['visit_id'] ?? 0);
    ok($c === 200 && empty($j['follow_up_closed']) && fu((int)$v2open['follow_up_id'])['status'] === 'pending', "B's new visit is saved, but A's follow-up stays open");

    // ═════════════════════════════════════════════════════════════════
    section('7. View details');
    [$c, , $j] = call('api/field_reports/detail.php', $A, false, 'GET', ['visit_id' => $v1]);
    $ids = array_map(fn($x) => (int)$x['visit_id'], $j['visits'] ?? []);
    ok($c === 200 && in_array($v1, $ids, true) && in_array($v2, $ids, true) && !in_array($bVisit, $ids, true), "A: the customer's visits (same phone) — both of A's, not B's");
    $tl = $j['follow_ups'] ?? [];
    ok(count(array_filter($tl, fn($x) => $x['visit_id'] === $v1)) === count(fus($v1)) && count(array_filter($tl, fn($x) => $x['visit_id'] === $v2)) === 1,
        'the timeline has every follow-up of both visits (done, cancelled, open)');
    ok($tl && $tl[0]['status'] === 'pending', 'open follow-ups come first');
    $reopenable = array_values(array_filter($tl, fn($x) => !empty($x['can_reopen'])));
    ok(count($reopenable) === 1 && $reopenable[0]['follow_up_id'] === (int)$closed['follow_up_id'], "re-open is offered only on v1's last finished one (v2 has one open)");
    $doneRow = array_values(array_filter($tl, fn($x) => $x['follow_up_id'] === (int)$was['follow_up_id']))[0] ?? null;
    ok($doneRow && $doneRow['method_label'] !== '' && $doneRow['outcome'] === "Talked $tag" && $doneRow['done_by_name'] !== '', 'a done item says how, what happened and who');
    ok(($j['visit']['notes'] ?? '') === "Note $tag" && ($j['visit']['created_by_name'] ?? '') !== '' && array_key_exists('day_submitted', $j['visit'] ?? []), 'the visit carries every field + record info');
    [$c] = call('api/field_reports/detail.php', $B, false, 'GET', ['visit_id' => $v1]);
    ok($c === 403, "B cannot open A's visit (got $c)");
    [$c] = call('api/field_reports/detail.php', $adminId, true, 'GET', ['visit_id' => $v1]);
    ok($c === 403, "admin cannot open it while A's day is not submitted (got $c)");
    call('api/field_reports/submit_day.php', $A, false, 'POST', ['date' => $day1]);
    [$c, , $j] = call('api/field_reports/detail.php', $adminId, true, 'GET', ['visit_id' => $v1]);
    ok($c === 200 && ($j['visit']['day_submitted'] ?? false) === true, 'admin can once that day is submitted');
    [$c] = call('api/field_reports/detail.php', $A, false, 'GET', ['visit_id' => 999999999]);
    ok($c === 404, 'unknown visit → 404');
    [$c, $html] = call('app/bms/field_reports/field_visit_details.php', $A, false, 'GET', ['id' => $v1]);
    ok($c === 200 && strpos($html, 'id="fuDoneModal"') !== false && strpos($html, 'window.FrFollow') !== false && strpos($html, 'data-bms-cards="off"') !== false,
        'details page renders (A) with the follow-up dialogs; its own phone cards (no double cards)');
    [$c] = call('app/bms/field_reports/field_visit_details.php', $B, false, 'GET', ['id' => $v1]);
    ok($c === 403, "details page as B → 403 (got $c)");
    [$c] = call('app/bms/field_reports/field_visit_details.php', $A, false, 'GET', ['id' => 999999999]);
    ok($c === 404, "details page, unknown visit → 404 (got $c)");
    [$c, $page] = call('app/bms/field_reports/field_visits.php', $A, false, 'GET', []);
    $menu = substr($page, strpos($page, 'function actions(r)'), 600);
    ok($c === 200 && strpos($menu, 'DETAILS_URL') !== false && strpos($menu, 'DETAILS_URL') < strpos($menu, 'data-act="joined"'), 'visits page: View details is the FIRST item of the row menu');
    ok(strpos($page, 'name="from_follow_up_id"') !== false && strpos($page, 'FrFollow.done(') !== false, 'visits page: New visit carries the follow-up; Followed up opens the mark-done form');
    ok(strpos(file_get_contents(ROOT_DIR . '/roots.php'), "'field_reports/details'") !== false, "route 'field_reports/details' registered");

    // ═════════════════════════════════════════════════════════════════
    section('8. "To follow up" reads the new table');
    [$c, , $j] = save($A, false, visit(['client_name' => "Due $tag", 'client_phone' => '', 'visit_date' => $today, 'visit_time' => '', 'follow_up_date' => $today]));
    $v3 = (int)($j['visit_id'] ?? 0);
    $v3open = pendingOf($v3);
    $dueIds = fn($uid, $admin) => array_map(fn($x) => (int)$x['follow_up_id'], call('api/field_reports/list.php', $uid, $admin, 'GET', [])[2]['follow_ups'] ?? []);
    ok(in_array((int)$v3open['follow_up_id'], $dueIds($A, false), true), "due today → in A's list, with its follow-up id");
    ok(!in_array((int)$v3open['follow_up_id'], $dueIds($B, false), true), "not in B's list");
    done($A, ['follow_up_id' => $v3open['follow_up_id'], 'method' => 'whatsapp', 'next_date' => $today]);
    $v3next = pendingOf($v3);
    ok(!in_array((int)$v3open['follow_up_id'], $dueIds($A, false), true) && in_array((int)$v3next['follow_up_id'], $dueIds($A, false), true), 'done leaves the list; the next one (today) is in it');
    call('api/field_reports/toggle_joined.php', $A, false, 'POST', ['visit_id' => $v3, 'joined' => 1]);
    ok(!in_array((int)$v3next['follow_up_id'], $dueIds($A, false), true), 'a client who joined leaves the list');
    call('api/field_reports/toggle_joined.php', $A, false, 'POST', ['visit_id' => $v3, 'joined' => 0]);

    // ═════════════════════════════════════════════════════════════════
    section('9. Deleting a visit cancels its open follow-up');
    [$c] = call('api/field_reports/delete.php', $A, false, 'POST', ['visit_id' => $v3]);
    ok($c === 200 && fu((int)$v3next['follow_up_id'])['status'] === 'cancelled', 'deleted visit → its open follow-up is cancelled');
    ok(fu((int)$v3open['follow_up_id'])['status'] === 'done', '...its history stays as it was');

    // ═════════════════════════════════════════════════════════════════
    section('10. Reminders: 2 / 1 / 0 days before, once the day after (rolled back)');
    $pdo->beginTransaction();
    try {
        // A's and B's roles may view Customer Visits — so only the privacy rule can keep B out.
        $permId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'field_visits'")->fetchColumn();
        $grant = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE can_view = 1");
        $grant->execute([$roleA, $permId]); $grant->execute([$roleB, $permId]);
        $dbToday = $pdo->query("SELECT CURDATE()")->fetchColumn();
        $dd = fn(int $n) => date('Y-m-d', strtotime("$dbToday " . ($n >= 0 ? '+' : '') . "$n days"));
        $subDay = $dd(-30); $unsubDay = $dd(-31);
        $pdo->prepare("DELETE FROM field_report_days WHERE user_id = ? AND report_date IN (?, ?)")->execute([$A, $subDay, $unsubDay]);
        $pdo->prepare("INSERT INTO field_report_days (user_id, report_date, submitted_at, visit_count) VALUES (?, ?, NOW(), 1)")->execute([$A, $subDay]);
        $mkVisit = function (string $date, string $name, int $joined = 0, string $status = 'active') use ($pdo, $A, $tag) {
            $pdo->prepare("INSERT INTO field_visits (user_id, visit_date, location, client_name, client_phone, phone_normalized, business_type, joined, status, created_by, created_at)
                           VALUES (?, ?, ?, ?, '0755000111', '255755000111', 'retail', ?, ?, ?, NOW())")
                ->execute([$A, $date, "Sinza $tag", "$name $tag", $joined, $status, $A]);
            return (int)$pdo->lastInsertId();
        };
        $mkFu = function (int $visitId, string $due, ?string $purpose = null) use ($pdo, $A) {
            $pdo->prepare("INSERT INTO field_visit_follow_ups (visit_id, due_date, purpose, status, created_by, created_at) VALUES (?, ?, ?, 'pending', ?, NOW())")
                ->execute([$visitId, $due, $purpose, $A]);
            return (int)$pdo->lastInsertId();
        };
        $stage = [];
        foreach ([2, 1, 0, -1, 3, -2] as $n) $stage[$n] = $mkFu($mkVisit($subDay, "Stage$n"), $dd($n), $n === 0 ? "Bring prices $tag" : null);
        $unsub  = $mkFu($mkVisit($unsubDay, 'Unsubmitted'), $dd(0));
        $joined = $mkFu($mkVisit($subDay, 'Joined', 1), $dd(0));
        $deleted = $mkFu($mkVisit($subDay, 'Deleted', 0, 'deleted'), $dd(0));
        $doneFu = $mkFu($mkVisit($subDay, 'Done'), $dd(0));
        $pdo->prepare("UPDATE field_visit_follow_ups SET status = 'done', done_at = NOW() WHERE follow_up_id = ?")->execute([$doneFu]);

        // In-app rows carry no entity id; each test visit has a unique client name in the title.
        $notes = function (string $client, int $uid) use ($pdo) {
            $s = $pdo->prepare("SELECT * FROM notifications WHERE event_key = 'field_visit.follow_up_due' AND user_id = ? AND title LIKE ? ORDER BY notification_id");
            $s->execute([$uid, '%' . $client]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        };
        $logs = function (int $fuId, int $uid, string $channel = 'inapp') use ($pdo) {
            $s = $pdo->prepare("SELECT COUNT(*) FROM notification_log WHERE event_key = 'field_visit.follow_up_due' AND entity_type = 'field_visit_follow_up'
                                   AND entity_id = ? AND recipient_user_id = ? AND channel = ? AND status = 'created'");
            $s->execute([$fuId, $uid, $channel]);
            return (int)$s->fetchColumn();
        };
        $mail = function (int $fuId, int $uid) use ($pdo) {
            $s = $pdo->prepare("SELECT * FROM notification_outbox WHERE event_key = 'field_visit.follow_up_due' AND entity_type = 'field_visit_follow_up'
                                   AND entity_id = ? AND recipient_user_id = ?");
            $s->execute([$fuId, $uid]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        };

        $res = frRunFollowUpReminders($pdo);
        ok($res['scanned'] >= 5, "the check found the follow-ups due in the window (scanned {$res['scanned']})");
        foreach ([2 => 'Follow-up in 2 days: ', 1 => 'Follow-up tomorrow: ', 0 => 'Follow-up today: ', -1 => 'Follow-up missed: '] as $n => $title) {
            $an = $notes("Stage$n $tag", $A);
            ok($logs($stage[$n], $A) === 1 && $an && strpos($an[0]['title'], $title . "Stage$n $tag") === 0
                && $an[0]['action_url'] === 'field_reports/details?id=' . (int)fu($stage[$n])['visit_id'],
                "day $n: A (who recorded it) gets \"{$title}…\" linking to View details");
            ok($logs($stage[$n], $adminId) === 1, "day $n: admin gets it too (the day was submitted)");
            ok($logs($stage[$n], $B) === 0, "day $n: B (same access, not the owner) gets nothing");
            $am = $mail($stage[$n], $A);
            ok(count($am) === 1 && $am[0]['status'] === 'queued' && strpos($am[0]['body'], 'field_reports/details') !== false, "day $n: A's email is queued with the link");
            ok(count($mail($stage[$n], $adminId)) === 1, "day $n: admin's email is queued");
        }
        $msg = $notes("Stage0 $tag", $A)[0]['message'] ?? '';
        ok(strpos($msg, "Reason: Bring prices $tag") !== false && strpos($msg, 'is due today') !== false, 'the message says when, the reason and who recorded it');
        foreach ([3, -2] as $n) ok($logs($stage[$n], $A) === 0 && $logs($stage[$n], $adminId) === 0, "day $n: outside 2 / 1 / 0 / -1 — nothing sent");
        ok($logs($unsub, $A) === 1 && $logs($unsub, $adminId) === 0, 'day not submitted: A gets it, admin does not (could not open that visit yet)');
        ok($logs($joined, $A) === 0 && $logs($deleted, $A) === 0 && $logs($doneFu, $A) === 0, 'joined client / deleted visit / already done — nothing sent');

        frRunFollowUpReminders($pdo);
        ok($logs($stage[1], $A) === 1 && count($mail($stage[1], $A)) === 1, 'running again the same day sends nothing twice');
        $pdo->prepare("UPDATE field_visit_follow_ups SET due_date = ? WHERE follow_up_id = ?")->execute([$dd(1), $stage[2]]);
        frRunFollowUpReminders($pdo);
        ok($logs($stage[2], $A) === 2, 'a follow-up moved to another day is reminded again at its new stage');

        // A rule for "everyone with access" must not leak a private visit to B.
        $pdo->prepare("INSERT INTO notification_rules (event_key, target_type, target_id, channel_email, channel_inapp, is_active, created_at)
                       VALUES ('field_visit.follow_up_due', 'permission', NULL, 1, 1, 1, NOW())")->execute();
        $extra = $mkFu($mkVisit($subDay, 'Extra'), $dd(1));
        frRunFollowUpReminders($pdo);
        ok($logs($extra, $A) === 1 && $logs($extra, $adminId) === 1 && $logs($extra, $B) === 0, 'even with an "Everyone with access" rule, B never gets A\'s reminder');

        // Removing both default rules leaves the engine default — still private.
        $pdo->exec("UPDATE notification_rules SET is_active = 0 WHERE event_key = 'field_visit.follow_up_due'");
        $extra2 = $mkFu($mkVisit($subDay, 'NoRules'), $dd(1));
        frRunFollowUpReminders($pdo);
        ok($logs($extra2, $A) === 1 && $logs($extra2, $B) === 0, 'with no rules (engine default) it is still only A + admins');
    } finally {
        $pdo->rollBack();
    }
    $left = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE event_key = 'field_visit.follow_up_due' AND title LIKE ?");
    $left->execute(["%$tag%"]);
    $leftMail = $pdo->prepare("SELECT COUNT(*) FROM notification_outbox WHERE event_key = 'field_visit.follow_up_due' AND body LIKE ?");
    $leftMail->execute(["%$tag%"]);
    ok((int)$left->fetchColumn() === 0 && (int)$leftMail->fetchColumn() === 0, 'rolled back: no reminder or email of this test is left behind');

    // ═════════════════════════════════════════════════════════════════
    section('11. Notification Rules: "record owner" + "all admins" targets');
    [$c, , $j] = call('api/notifications/rules_api.php', $adminId, true, 'GET', ['action' => 'list']);
    $evRow = array_values(array_filter($j['events'] ?? [], fn($e) => $e['event_key'] === 'field_visit.follow_up_due'))[0] ?? null;
    $labels = array_column($evRow['rules'] ?? [], 'label');
    ok($evRow && in_array('The person who recorded it', $labels, true) && in_array('All admins', $labels, true), 'the event lists its two default targets');
    [$c, , $j] = call('api/notifications/rules_api.php', $adminId, true, 'GET', ['action' => 'preview', 'event_key' => 'field_visit.follow_up_due']);
    ok(($j['success'] ?? false) && ($j['record_owner'] ?? false) === true && in_array($adminId, array_column($j['recipients'] ?? [], 'user_id'), true),
        'preview: admins listed + "plus the person who recorded each record"');
    [$c, , $j] = call('api/notifications/rules_api.php', $adminId, true, 'POST', ['action' => 'save', 'event_key' => 'field_visit.follow_up_due', 'target_type' => 'record_owner', 'target_id' => 5, 'channel_inapp' => 1]);
    $newRule = $pdo->query("SELECT * FROM notification_rules WHERE event_key = 'field_visit.follow_up_due' AND target_type = 'record_owner' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok(($j['success'] ?? false) && $newRule && $newRule['target_id'] === null, 'saving a "record owner" target works; it stores no user id');
    if ($newRule && ($j['success'] ?? false)) $pdo->prepare("DELETE FROM notification_rules WHERE id = ?")->execute([$newRule['id']]);
    [$c, , $j] = call('api/notifications/rules_api.php', $adminId, true, 'POST', ['action' => 'save', 'event_key' => 'field_visit.follow_up_due', 'target_type' => 'bogus', 'channel_inapp' => 1]);
    ok(($j['success'] ?? true) === false && ($j['message'] ?? '') === 'Invalid target type', 'an unknown target type is refused');
    [$c, $html] = call('app/constant/settings/notification_rules.php', $adminId, true, 'GET', []);
    ok(strpos($html, 'value="record_owner"') !== false && strpos($html, 'value="admins"') !== false, 'the Notify list offers both new targets');
} catch (Throwable $e) {
    ok(false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    // ── Cleanup ─────────────────────────────────────────────────────────
    if ($pdo->inTransaction()) $pdo->rollBack();
    $vids = $pdo->prepare("SELECT visit_id FROM field_visits WHERE location LIKE ? OR client_name LIKE ?");
    $vids->execute(["%$tag%", "%$tag%"]);
    $ids = array_map('intval', $vids->fetchAll(PDO::FETCH_COLUMN));
    if ($ids) {
        $in = implode(',', $ids);
        $pdo->exec("DELETE FROM field_visit_follow_ups WHERE visit_id IN ($in)");
        $pdo->exec("DELETE FROM field_visit_services WHERE visit_id IN ($in)");
        $pdo->exec("DELETE FROM field_visits WHERE visit_id IN ($in)");
    }
    $newDays = array_diff($pdo->query("SELECT day_id FROM field_report_days")->fetchAll(PDO::FETCH_COLUMN), $daysBefore);
    if ($newDays) $pdo->exec("DELETE FROM field_report_days WHERE day_id IN (" . implode(',', array_map('intval', $newDays)) . ")");
    try {
        $pdo->prepare("DELETE FROM activity_logs WHERE user_id IN (?, ?, ?) AND created_at >= ? AND (description LIKE '%field visit%' OR description LIKE '%field report%' OR description LIKE '%Notification rule%')")
            ->execute([$A, $B, $adminId, $logStart]);
    } catch (PDOException $e) { /* no activity_logs table */ }
    $chk = $pdo->prepare("SELECT COUNT(*) FROM field_visits WHERE location LIKE ? OR client_name LIKE ?");
    $chk->execute(["%$tag%", "%$tag%"]);
    echo "\nCleanup: " . ((int)$chk->fetchColumn() === 0 ? 'all test rows removed' : 'SOME TEST ROWS REMAIN') . "\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail > 0 ? 1 : 0);
}
