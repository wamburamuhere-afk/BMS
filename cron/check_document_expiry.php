<?php
/**
 * BMS — Document Expiry Notification Engine
 *
 * Scans documents that have an expire_date and creates in-app notifications
 * at 30, 14, 7 and 1 day(s) before the document expires.
 *
 * Recipients are RBAC-driven: all Admins, plus any user whose role has the
 * VIEW checkbox ticked on the 'document_expiry_alerts' permission
 * (Settings > User Roles & Permissions > Documents module).
 *
 * It runs in two ways:
 *   - Automatically once per day, included (throttled) from header.php.
 *   - Manually or via a server cron job:  php cron/check_document_expiry.php
 *
 * Notifications surface in the Notification Center and on the Dashboard.
 * Duplicate milestone alerts are prevented by the document_expiry_reminders table.
 */

require_once __DIR__ . '/../roots.php';

if (!function_exists('run_document_expiry_check')) {
    /**
     * Execute the expiry scan. Safe to call repeatedly — each milestone
     * fires only once per document thanks to document_expiry_reminders.
     *
     * @param  PDO   $pdo Active database connection.
     * @return array      Summary counts.
     */
    function run_document_expiry_check(PDO $pdo): array
    {
        $isCli      = (php_sapi_name() === 'cli');
        $milestones = [30, 14, 7, 1]; // days before expiry that trigger a reminder
        $summary    = ['documents_scanned' => 0, 'milestones_fired' => 0, 'notifications_created' => 0];

        // 1. Resolve recipients — admins + any role granted the permission.
        $recipients = $pdo->query("
            SELECT DISTINCT u.user_id
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            WHERE u.is_active = 1
              AND (
                  COALESCE(r.is_admin, 0) = 1
                  OR u.role_id = 1
                  OR u.role_id IN (
                      SELECT rp.role_id
                      FROM role_permissions rp
                      JOIN permissions p ON p.permission_id = rp.permission_id
                      WHERE p.page_key = 'document_expiry_alerts' AND rp.can_view = 1
                  )
              )
        ")->fetchAll(PDO::FETCH_COLUMN);

        if (empty($recipients)) {
            if ($isCli) echo "No recipients configured — nothing to do.\n";
            return $summary;
        }

        // 2. Documents expiring within the largest milestone window.
        $docs = $pdo->query("
            SELECT id, document_name, expire_date,
                   DATEDIFF(expire_date, CURDATE()) AS days_remaining
            FROM documents
            WHERE expire_date IS NOT NULL
              AND expire_date >= CURDATE()
              AND DATEDIFF(expire_date, CURDATE()) <= 30
        ")->fetchAll(PDO::FETCH_ASSOC);

        $summary['documents_scanned'] = count($docs);

        $docUrl = function_exists('getUrl') ? getUrl('document_library') : '/document_library';

        $doneStmt   = $pdo->prepare("SELECT milestone FROM document_expiry_reminders WHERE document_id = ?");
        $recordStmt = $pdo->prepare("INSERT IGNORE INTO document_expiry_reminders (document_id, milestone) VALUES (?, ?)");
        $notifyStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, priority, document_id, action_url, created_at)
            VALUES (?, ?, ?, 'alert', ?, ?, ?, NOW())
        ");

        foreach ($docs as $doc) {
            $days = (int) $doc['days_remaining'];

            // Milestones reached at the document's current days-remaining.
            $reached = array_filter($milestones, fn($m) => $days <= $m);
            if (empty($reached)) {
                continue;
            }

            // Milestones already handled for this document.
            $doneStmt->execute([$doc['id']]);
            $done = $doneStmt->fetchAll(PDO::FETCH_COLUMN);

            $newMilestones = array_diff($reached, $done);
            if (empty($newMilestones)) {
                continue; // nothing new since the last run
            }

            // Record every newly-reached milestone so none re-fire later.
            // (A document added with little time left records all passed
            //  milestones at once but still sends only one notification.)
            foreach ($newMilestones as $m) {
                $recordStmt->execute([$doc['id'], $m]);
            }
            $summary['milestones_fired'] += count($newMilestones);

            // One notification per recipient, describing the actual days left.
            $name  = $doc['document_name'];
            $expOn = date('d M Y', strtotime($doc['expire_date']));
            if ($days <= 0) {
                $title = 'Document expires today';
            } elseif ($days === 1) {
                $title = 'Document expires tomorrow';
            } else {
                $title = "Document expiring in {$days} days";
            }
            $message  = "'{$name}' expires on {$expOn} ({$days} day(s) remaining). Please review or renew it.";
            $priority = ($days <= 7) ? 'high' : 'medium';

            foreach ($recipients as $uid) {
                $notifyStmt->execute([$uid, $title, $message, $priority, $doc['id'], $docUrl]);
                $summary['notifications_created']++;
            }

            if ($isCli) {
                echo "  -> {$name}: {$days}d left — notified " . count($recipients) . " user(s).\n";
            }
        }

        // 3. Audit trail (only when something actually happened).
        if ($summary['notifications_created'] > 0) {
            logActivity($pdo, 0, "Document expiry check: {$summary['notifications_created']} notification(s) created for {$summary['milestones_fired']} milestone(s).");
            logAudit($pdo, 0, 'document_expiry_check', [
                'activity_type' => 'system',
                'description'   => "Document expiry scan created {$summary['notifications_created']} notification(s).",
                'entity_type'   => 'document',
            ]);
        }

        return $summary;
    }
}

// ── Run ────────────────────────────────────────────────────────────────
try {
    // Claim today's run immediately so concurrent web requests don't double-scan.
    if (function_exists('save_setting')) {
        save_setting('doc_expiry_last_run', date('Y-m-d'));
    }

    $result = run_document_expiry_check($pdo);

    if (php_sapi_name() === 'cli') {
        echo "Document expiry check complete: "
           . "{$result['documents_scanned']} document(s) scanned, "
           . "{$result['milestones_fired']} milestone(s) fired, "
           . "{$result['notifications_created']} notification(s) created.\n";
    }
} catch (Throwable $e) {
    error_log('check_document_expiry.php error: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo 'Document expiry check FAILED: ' . $e->getMessage() . "\n";
        exit(1);
    }
}
