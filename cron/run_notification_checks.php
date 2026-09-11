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
        $sum = ['invoice_overdue' => 0, 'quotation_expiring' => 0, 'tender_deadline' => 0, 'product_batch_expiring' => 0, 'product_expiring' => 0];

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

        // ── Product batch/lot expiring (Phase 17, pos_upgrade_plan.md §8) ───
        // Milestone-based (30/14/7/1 days), exact pattern as
        // cron/check_document_expiry.php's document_expiry_reminders —
        // fires once per milestone per batch, not once per day, to avoid
        // reminder fatigue over a month-long window. scope_aware on
        // warehouse_id (see core/notify.php's resolveRecipients()).
        try {
            $milestones = [30, 14, 7, 1];
            $batches = $pdo->query("
                SELECT pb.batch_id, pb.product_id, pb.warehouse_id, pb.batch_number, pb.expiry_date,
                       pb.quantity_remaining, p.product_name,
                       DATEDIFF(pb.expiry_date, CURDATE()) AS days_remaining
                FROM product_batches pb
                JOIN products p ON p.product_id = pb.product_id
                WHERE pb.expiry_date IS NOT NULL
                  AND pb.quantity_remaining > 0
                  AND DATEDIFF(pb.expiry_date, CURDATE()) <= 30
            ")->fetchAll(PDO::FETCH_ASSOC);

            $doneStmt   = $pdo->prepare("SELECT milestone FROM product_batch_expiry_reminders WHERE batch_id = ?");
            $recordStmt = $pdo->prepare("INSERT IGNORE INTO product_batch_expiry_reminders (batch_id, milestone) VALUES (?, ?)");

            foreach ($batches as $b) {
                $days = (int)$b['days_remaining'];
                $reached = array_filter($milestones, fn($m) => $days <= $m);
                if (empty($reached)) continue;

                $doneStmt->execute([$b['batch_id']]);
                $done = $doneStmt->fetchAll(PDO::FETCH_COLUMN);
                $newMilestones = array_diff($reached, $done);
                if (empty($newMilestones)) continue; // nothing new since the last run

                foreach ($newMilestones as $m) { $recordStmt->execute([$b['batch_id'], $m]); }

                $label = $b['batch_number'] ? ($b['product_name'] . ' (batch ' . $b['batch_number'] . ')') : $b['product_name'];
                $expOn = date('d M Y', strtotime($b['expiry_date']));
                $title = $days <= 0 ? 'Product batch expired' : ($days === 1 ? 'Product batch expires tomorrow' : "Product batch expiring in {$days} days");

                $res = dispatchEvent($pdo, 'product.batch_expiring', [
                    'entity_type'   => 'product_batch',
                    'entity_id'     => (int)$b['batch_id'],
                    'warehouse_id'  => (int)$b['warehouse_id'],
                    'title'         => $title . ': ' . $label,
                    'message'       => "{$label} expires on {$expOn} ({$days} day(s) remaining), "
                                     . number_format((float)$b['quantity_remaining'], 2) . ' unit(s) remaining.',
                    'action_url'    => 'product_view?id=' . (int)$b['product_id'],
                    'severity'      => $days <= 7 ? 'high' : 'medium',
                    'dedupe_suffix' => 'm' . min($newMilestones), // one send per milestone, not per day
                ]);
                if (!empty($res['dispatched'])) $sum['product_batch_expiring'] += (int)$res['created'] + (int)$res['emailed'];
            }
            if ($isCli) echo "  product.batch_expiring: scanned " . count($batches) . " expiring batch(es).\n";
        } catch (Throwable $e) {
            error_log('run_notification_checks product.batch_expiring: ' . $e->getMessage());
        }

        // ── Plain (non-batch-tracked) product expiry ───────────────────────
        // Gap closed 2026-09-11: the block above only ever scans
        // `product_batches`. A product that just has `products.expiry_date`
        // set (no batch tracking turned on for it) previously showed up on
        // the dashboard's "expiring" widget when someone looked, but never
        // triggered an automatic alert. Reuses the SAME `product.batch_
        // expiring` notification_events row (zero new settings UI — any
        // recipient/email rule already configured for it covers this too),
        // and only fires for a product where the tenant has explicitly
        // opted in via the pre-existing `email_alerts` checkbox
        // (products.email_alerts — previously captured by
        // api/update_product_alerts.php but never read by anything).
        // Explicitly excludes anything with its own product_batches rows,
        // so a batch-tracked product is never double-alerted by both blocks.
        try {
            $milestones = [30, 14, 7, 1];
            $plain = $pdo->query("
                SELECT p.product_id, p.product_name, p.expiry_date, ps.warehouse_id, ps.stock_quantity,
                       DATEDIFF(p.expiry_date, CURDATE()) AS days_remaining
                FROM products p
                JOIN product_stocks ps ON ps.product_id = p.product_id AND ps.stock_quantity > 0
                WHERE p.expiry_date IS NOT NULL
                  AND p.email_alerts = 1
                  AND p.status = 'active'
                  AND DATEDIFF(p.expiry_date, CURDATE()) <= 30
                  AND NOT EXISTS (SELECT 1 FROM product_batches pb WHERE pb.product_id = p.product_id)
            ")->fetchAll(PDO::FETCH_ASSOC);

            $doneStmt2   = $pdo->prepare("SELECT milestone FROM product_expiry_reminders WHERE product_id = ? AND warehouse_id = ?");
            $recordStmt2 = $pdo->prepare("INSERT IGNORE INTO product_expiry_reminders (product_id, warehouse_id, milestone) VALUES (?, ?, ?)");

            foreach ($plain as $p) {
                $days = (int)$p['days_remaining'];
                $reached = array_filter($milestones, fn($m) => $days <= $m);
                if (empty($reached)) continue;

                $doneStmt2->execute([$p['product_id'], $p['warehouse_id']]);
                $done = $doneStmt2->fetchAll(PDO::FETCH_COLUMN);
                $newMilestones = array_diff($reached, $done);
                if (empty($newMilestones)) continue; // nothing new since the last run

                foreach ($newMilestones as $m) { $recordStmt2->execute([$p['product_id'], $p['warehouse_id'], $m]); }

                $expOn = date('d M Y', strtotime($p['expiry_date']));
                $title = $days <= 0 ? 'Product expired' : ($days === 1 ? 'Product expires tomorrow' : "Product expiring in {$days} days");

                $res = dispatchEvent($pdo, 'product.batch_expiring', [
                    'entity_type'   => 'product',
                    'entity_id'     => (int)$p['product_id'],
                    'warehouse_id'  => (int)$p['warehouse_id'],
                    'title'         => $title . ': ' . $p['product_name'],
                    'message'       => "{$p['product_name']} expires on {$expOn} ({$days} day(s) remaining), "
                                     . number_format((float)$p['stock_quantity'], 2) . ' unit(s) remaining.',
                    'action_url'    => 'product_view?id=' . (int)$p['product_id'],
                    'severity'      => $days <= 7 ? 'high' : 'medium',
                    'dedupe_suffix' => 'pm' . min($newMilestones), // 'pm' = plain-product milestone, distinct from batch dedupe
                ]);
                if (!empty($res['dispatched'])) $sum['product_expiring'] += (int)$res['created'] + (int)$res['emailed'];
            }
            if ($isCli) echo "  product.expiring (plain products): scanned " . count($plain) . " expiring product(s).\n";
        } catch (Throwable $e) {
            error_log('run_notification_checks product.expiring: ' . $e->getMessage());
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
