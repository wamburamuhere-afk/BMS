<?php
/**
 * core/subscription_reminders.php — "your access ends soon" emails, for trials AND paid plans.
 *
 * Sent 7, 3 and 1 day before the end date and on the day itself, to the owner's real email
 * (owner_contact_email; owner_email often holds the login phone). Each step goes once per
 * period: the log row carries the end date, so a renewal starts a fresh set.
 * Run daily by api/cron/trial_reminders.php.
 */
require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/tenant_lifecycle_policy.php';
require_once __DIR__ . '/tenant_admin.php';
require_once __DIR__ . '/vendor_link.php';
require_once __DIR__ . '/mailer.php';

if (!function_exists('subscriptionReminderSteps')) {
    function subscriptionReminderSteps(): array { return [7, 3, 1, 0]; }
}

if (!function_exists('subscriptionPageUrl')) {
    /** The company's own Subscription page. */
    function subscriptionPageUrl(string $subdomain): string
    {
        $base   = tenantBaseDomain() ?? '';
        $scheme = preg_match('/\.(local|test|localhost)$/', $base) ? 'http' : 'https';
        return $scheme . '://' . $subdomain . ($base !== '' ? '.' . $base : '') . '/subscription';
    }
}

if (!function_exists('subscriptionReminderEmail')) {
    /** @return array{0:string,1:string} subject, html body */
    function subscriptionReminderEmail(array $t, int $daysLeft, string $endDate, ?string $planName): array
    {
        $h       = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $trial   = $t['status'] === 'trial';
        $what    = $trial ? 'free trial' : ($planName ? $planName . ' plan' : 'subscription');
        $when    = date('j M Y', strtotime($endDate));
        $url     = subscriptionPageUrl((string)$t['subdomain']);
        $name    = trim(($t['owner_first_name'] ?? '') . ' ' . ($t['owner_last_name'] ?? ''));
        $plural  = $daysLeft === 1 ? '' : 's';
        $subject = $daysLeft === 0 ? "Your {$what} ends today" : "Your {$what} ends in {$daysLeft} day{$plural}";
        $lead = $daysLeft === 0
            ? 'Your ' . $h($what) . ' for <strong>' . $h($t['company_name']) . '</strong> ends <strong>today</strong> (' . $h($when) . ').'
            : 'Your ' . $h($what) . ' for <strong>' . $h($t['company_name']) . "</strong> ends in <strong>{$daysLeft} day{$plural}</strong>, on " . $h($when) . '.';
        $ask = $trial
            ? 'Choose a plan and send your request from the Subscription page so you can keep working without a break.'
            : 'Renew from the Subscription page so you can keep working without a break.';
        $body = '<p>Hi ' . $h($name !== '' ? $name : $t['company_name']) . ',</p>'
              . '<p>' . $lead . '</p>'
              . '<p>' . $ask . ' Your data stays safe.</p>'
              . '<p><a href="' . $h($url) . '" style="display:inline-block;background:#0d6efd;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">Open Subscription</a></p>'
              . '<p style="color:#6c757d;font-size:13px">Or open: ' . $h($url) . '<br>Questions? Reply to this email or write to support@bjptechnologies.co.tz.</p>';
        return [$subject, $body];
    }
}

if (!function_exists('runSubscriptionReminders')) {
    /**
     * @param callable|null $send fn(string $to, string $subject, string $html): bool — tests pass their own
     * @param string|null $subdomainPrefix tests only: limit the run to their own companies
     * @return array{sent:int, skip:int, errors:int}
     */
    function runSubscriptionReminders(?callable $send = null, ?string $subdomainPrefix = null): array
    {
        $send = $send ?? fn($to, $s, $b) => sendEmail($to, $s, $b, ['wrap_brand' => 'BJP Technologies / BMS']);
        $c = getControlPdo();
        $cols = ['id', 'subdomain', 'company_name', 'status', 'plan', 'owner_email', 'owner_first_name', 'owner_last_name', 'trial_ends_at', 'subscription_ends_at'];
        foreach (['owner_contact_email', 'unsubscribed_at'] as $opt) if (controlColumnExists('tenants', $opt)) $cols[] = $opt;
        $q = $c->prepare("SELECT " . implode(', ', $cols) . " FROM tenants
                           WHERE ((status = 'trial'  AND trial_ends_at IS NOT NULL AND DATE(trial_ends_at) BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY)
                               OR (status = 'active' AND subscription_ends_at IS NOT NULL AND subscription_ends_at BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY))
                             AND subdomain LIKE ?");
        $q->execute([$subdomainPrefix === null ? '%' : addcslashes($subdomainPrefix, '%_\\') . '%']);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $done = $c->prepare("SELECT 1 FROM tenant_admin_log WHERE tenant_id = ? AND action = ? AND detail LIKE ? LIMIT 1");
        $planName = $c->prepare("SELECT name FROM plans WHERE plan_key = ?");
        $sent = $skip = $errors = 0;
        foreach ($rows as $t) {
            $days = tenantDaysRemaining($t);
            if ($days === null || !in_array($days, subscriptionReminderSteps(), true)) continue;
            if (!empty($t['unsubscribed_at'])) { $skip++; continue; }
            $end = substr((string)tenantAccessEndsAt($t), 0, 10);
            $action = 'expiry_reminder_' . $days . 'd';
            $done->execute([(int)$t['id'], $action, 'period_end=' . $end . ';%']);
            if ($done->fetchColumn()) { $skip++; continue; }
            $to = vlTenantContactEmail($t);
            $ok = false;
            try {
                if ($to !== '') {
                    $planName->execute([(string)$t['plan']]);
                    [$subject, $body] = subscriptionReminderEmail($t, $days, $end, $planName->fetchColumn() ?: null);
                    $ok = (bool)$send($to, $subject, $body);
                }
            } catch (Throwable $e) {
                error_log('subscription reminder tenant ' . $t['id'] . ': ' . $e->getMessage());
            }
            // A failed send is logged under another action so a second run the same day tries again.
            logTenantAdminAction((int)$t['id'], (string)$t['subdomain'], ($ok || $to === '') ? $action : 'expiry_reminder_failed',
                'period_end=' . $end . '; ' . ($t['status'] === 'trial' ? 'trial' : 'paid') . " reminder ({$days}d) — "
                . ($to === '' ? 'no owner email on file' : ($ok ? 'email delivered to ' . $to : 'email failed')));
            if ($ok) $sent++; elseif ($to === '') $skip++; else $errors++;
        }
        return ['sent' => $sent, 'skip' => $skip, 'errors' => $errors];
    }
}
