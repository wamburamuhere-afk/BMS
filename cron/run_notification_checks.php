<?php
/**
 * cron/run_notification_checks.php
 * ---------------------------------------------------------------------------
 * Phase 6 — time-based notification checks. Scans for conditions that aren't
 * tied to a single user action (overdue, expiring, due) and emits them through
 * the same dispatchEvent() pipeline (RBAC + scope + rules + channels).
 *
 * Runs at most once per day:
 *   - Server cron:   php cron/run_notification_checks.php
 *   - Opportunistic: included (throttled to once/day) from header.php
 *
 * Each check dedupes per record per day, so an overdue invoice reminds at most
 * once a day, not on every page load. Self-contained + fail-silent.
 *
 * Add more checks the same way (one block per condition) — quotation.expiring,
 * tender.deadline, payroll.due, etc. — each just calls dispatchEvent().
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/notify.php';
global $pdo;

if (!function_exists('run_notification_checks')) {
    function run_notification_checks(PDO $pdo): array
    {
        $isCli = (php_sapi_name() === 'cli');
        $sum = ['invoice_overdue' => 0, 'quotation_expiring' => 0, 'tender_deadline' => 0];

        // ── Invoice overdue ────────────────────────────────────────────────
        try {
            $rows = $pdo->query("
                SELECT invoice_id, invoice_number, due_date, project_id, customer_id, balance_due
                FROM invoices
                WHERE due_date < CURDATE()
                  AND COALESCE(balance_due, 0) > 0
                  AND status NOT IN ('paid', 'void', 'deleted', 'cancelled')
            ")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $inv) {
                $res = dispatchEvent($pdo, 'invoice.overdue', [
                    'entity_type'   => 'invoice',
                    'entity_id'     => (int)$inv['invoice_id'],
                    'project_id'    => $inv['project_id']  !== null ? (int)$inv['project_id']  : null,
                    'customer_id'   => $inv['customer_id'] !== null ? (int)$inv['customer_id'] : null,
                    'title'         => 'Invoice overdue: ' . $inv['invoice_number'],
                    'message'       => 'Invoice ' . $inv['invoice_number'] . ' is overdue (due '
                                     . date('d M Y', strtotime($inv['due_date'])) . ', outstanding '
                                     . number_format((float)$inv['balance_due'], 2) . ').',
                    'action_url'    => 'invoice_view?id=' . (int)$inv['invoice_id'],
                    'severity'      => 'high',
                    'dedupe_suffix' => date('Y-m-d'),   // at most once/day per invoice
                ]);
                if (!empty($res['dispatched'])) {
                    $sum['invoice_overdue'] += (int)$res['created'] + (int)$res['emailed'];
                }
            }
            if ($isCli) echo "  invoice.overdue: scanned " . count($rows) . " overdue invoice(s).\n";
        } catch (Throwable $e) {
            error_log('run_notification_checks invoice.overdue: ' . $e->getMessage());
        }

        // ── Quotation expiring (within 7 days, still open) ──────────────────
        try {
            $rows = $pdo->query("
                SELECT sales_order_id, order_number, quote_valid_until, project_id
                FROM quotations
                WHERE quote_valid_until IS NOT NULL
                  AND quote_valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                  AND status NOT IN ('approved','rejected','expired','cancelled','deleted','converted')
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $q) {
                $res = dispatchEvent($pdo, 'quotation.expiring', [
                    'entity_type'   => 'quotation',
                    'entity_id'     => (int)$q['sales_order_id'],
                    'project_id'    => $q['project_id'] !== null ? (int)$q['project_id'] : null,
                    'title'         => 'Quotation expiring: ' . $q['order_number'],
                    'message'       => 'Quotation ' . $q['order_number'] . ' is valid only until '
                                     . date('d M Y', strtotime($q['quote_valid_until'])) . '.',
                    'action_url'    => 'quotation_view?id=' . (int)$q['sales_order_id'],
                    'dedupe_suffix' => date('Y-m-d'),
                ]);
                if (!empty($res['dispatched'])) $sum['quotation_expiring'] += (int)$res['created'] + (int)$res['emailed'];
            }
            if ($isCli) echo "  quotation.expiring: scanned " . count($rows) . " quote(s).\n";
        } catch (Throwable $e) {
            error_log('run_notification_checks quotation.expiring: ' . $e->getMessage());
        }

        // ── Tender submission deadline approaching (within 7 days) ──────────
        try {
            $rows = $pdo->query("
                SELECT tender_id, tender_no, tender_description, submission_deadline
                FROM tenders
                WHERE submission_deadline IS NOT NULL
                  AND submission_deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                  AND status NOT IN ('AWARDED','EVALUATION','APPROVED','CANCELLED','LOST','DELETED')
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $t) {
                $ref = $t['tender_no'] ?: ('Tender #' . $t['tender_id']);
                $res = dispatchEvent($pdo, 'tender.deadline', [
                    'entity_type'   => 'tender',
                    'entity_id'     => (int)$t['tender_id'],
                    'title'         => 'Tender deadline approaching: ' . $ref,
                    'message'       => 'Tender ' . $ref . ' submission deadline is '
                                     . date('d M Y', strtotime($t['submission_deadline'])) . '.',
                    'action_url'    => 'tenders',
                    'dedupe_suffix' => date('Y-m-d'),
                ]);
                if (!empty($res['dispatched'])) $sum['tender_deadline'] += (int)$res['created'] + (int)$res['emailed'];
            }
            if ($isCli) echo "  tender.deadline: scanned " . count($rows) . " tender(s).\n";
        } catch (Throwable $e) {
            error_log('run_notification_checks tender.deadline: ' . $e->getMessage());
        }

        return $sum;
    }
}

// ── Run ────────────────────────────────────────────────────────────────────
try {
    if (function_exists('save_setting')) {
        save_setting('notif_checks_last_run', date('Y-m-d'));
    }
    $res = run_notification_checks($pdo);
    if (php_sapi_name() === 'cli') {
        echo "Notification checks complete: " . json_encode($res) . "\n";
    }
} catch (Throwable $e) {
    error_log('run_notification_checks.php error: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo 'Notification checks FAILED: ' . $e->getMessage() . "\n";
        exit(1);
    }
}
