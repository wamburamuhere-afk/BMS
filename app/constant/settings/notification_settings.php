<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../core/permissions.php';

// Phase 2 of security_implementation_plan.md — previously any logged-in
// user could open this page and change notification settings. Now only
// admin or roles explicitly granted 'notification_settings' can.
autoEnforcePermission('notification_settings');

require_once __DIR__ . '/../../../header.php';



// Handle form submissions
if ($_POST) {
    $success_messages = [];
    $error_messages = [];
    
    // Notification Settings
    if (isset($_POST['save_notification_settings'])) {
        try {
            $settings = [
                'enable_email_notifications' => $_POST['enable_email_notifications'] ?? 0,
                'enable_sms_notifications' => $_POST['enable_sms_notifications'] ?? 0,
                'enable_push_notifications' => $_POST['enable_push_notifications'] ?? 0,
                'enable_desktop_notifications' => $_POST['enable_desktop_notifications'] ?? 0,
                'notification_sound' => $_POST['notification_sound'] ?? 0,
                'auto_dismiss_notifications' => $_POST['auto_dismiss_notifications'] ?? 0,
                'notification_timeout' => $_POST['notification_timeout'] ?? 5000
            ];
            
            foreach ($settings as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = "Notification settings updated successfully";
        } catch (Exception $e) {
            $error_messages[] = "Error updating notification settings: " . $e->getMessage();
        }
    }
    
    // Email Templates
    if (isset($_POST['save_email_templates'])) {
        try {
            $templates = [
                'loan_approval_email_subject' => $_POST['loan_approval_email_subject'],
                'loan_approval_email_body' => $_POST['loan_approval_email_body'],
                'payment_reminder_email_subject' => $_POST['payment_reminder_email_subject'],
                'payment_reminder_email_body' => $_POST['payment_reminder_email_body'],
                'overdue_notice_email_subject' => $_POST['overdue_notice_email_subject'],
                'overdue_notice_email_body' => $_POST['overdue_notice_email_body'],
                'loan_disbursement_email_subject' => $_POST['loan_disbursement_email_subject'],
                'loan_disbursement_email_body' => $_POST['loan_disbursement_email_body']
            ];
            
            foreach ($templates as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = "Email templates updated successfully";
        } catch (Exception $e) {
            $error_messages[] = "Error updating email templates: " . $e->getMessage();
        }
    }
    
    // SMS Templates
    if (isset($_POST['save_sms_templates'])) {
        try {
            $templates = [
                'payment_reminder_sms' => $_POST['payment_reminder_sms'],
                'overdue_alert_sms' => $_POST['overdue_alert_sms'],
                'loan_approval_sms' => $_POST['loan_approval_sms'],
                'loan_disbursement_sms' => $_POST['loan_disbursement_sms'],
                'general_notification_sms' => $_POST['general_notification_sms']
            ];
            
            foreach ($templates as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = "SMS templates updated successfully";
        } catch (Exception $e) {
            $error_messages[] = "Error updating SMS templates: " . $e->getMessage();
        }
    }
    
    // Alert Rules
    if (isset($_POST['save_alert_rules'])) {
        try {
            $rules = [
                'send_payment_reminder_days' => $_POST['send_payment_reminder_days'],
                'send_overdue_alert_days' => $_POST['send_overdue_alert_days'],
                'send_loan_approval_notification' => $_POST['send_loan_approval_notification'] ?? 0,
                'send_loan_disbursement_notification' => $_POST['send_loan_disbursement_notification'] ?? 0,
                'send_daily_collection_report' => $_POST['send_daily_collection_report'] ?? 0,
                'send_weekly_performance_report' => $_POST['send_weekly_performance_report'] ?? 0,
                'send_monthly_portfolio_report' => $_POST['send_monthly_portfolio_report'] ?? 0,
                'high_risk_loan_alert' => $_POST['high_risk_loan_alert'] ?? 0,
                'large_loan_alert_threshold' => $_POST['large_loan_alert_threshold']
            ];
            
            foreach ($rules as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = "Alert rules updated successfully";
        } catch (Exception $e) {
            $error_messages[] = "Error updating alert rules: " . $e->getMessage();
        }
    }
}

// Settings are loaded via get_setting() from helpers.php (auto-cached)
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-bell"></i> Notification Settings</h2>
            <p class="text-muted">Configure system notifications, alerts, and communication templates</p>
        </div>
    </div>

    <!-- Messages -->
    <?php if (!empty($success_messages)): ?>
        <?php foreach ($success_messages as $message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($error_messages)): ?>
        <?php foreach ($error_messages as $message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Settings Tabs -->
    <div class="card shadow">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" id="notificationTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="general-tab" data-bs-toggle="tab" 
                            data-bs-target="#general" type="button" role="tab">
                        <i class="bi bi-gear"></i> General Settings
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="email-tab" data-bs-toggle="tab" 
                            data-bs-target="#email" type="button" role="tab">
                        <i class="bi bi-envelope"></i> Email Templates
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="sms-tab" data-bs-toggle="tab" 
                            data-bs-target="#sms" type="button" role="tab">
                        <i class="bi bi-chat-text"></i> SMS Templates
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="alerts-tab" data-bs-toggle="tab" 
                            data-bs-target="#alerts" type="button" role="tab">
                        <i class="bi bi-exclamation-triangle"></i> Alert Rules
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="test-tab" data-bs-toggle="tab" 
                            data-bs-target="#test" type="button" role="tab">
                        <i class="bi bi-play-circle"></i> Test Notifications
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-body">
            <div class="tab-content" id="notificationTabsContent">
                <!-- General Settings Tab -->
                <div class="tab-pane fade show active" id="general" role="tabpanel">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary">Notification Channels</h5>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="enable_email_notifications" 
                                               name="enable_email_notifications" value="1" 
                                               <?= get_setting('enable_email_notifications') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="enable_email_notifications">
                                            Enable Email Notifications
                                        </label>
                                    </div>
                                    <div class="form-text">Send notifications via email to customers and staff</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="enable_sms_notifications" 
                                               name="enable_sms_notifications" value="1" 
                                               <?= get_setting('enable_sms_notifications') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="enable_sms_notifications">
                                            Enable SMS Notifications
                                        </label>
                                    </div>
                                    <div class="form-text">Send SMS alerts for important events (requires SMS gateway)</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="enable_push_notifications" 
                                               name="enable_push_notifications" value="1" 
                                               <?= get_setting('enable_push_notifications') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="enable_push_notifications">
                                            Enable Push Notifications
                                        </label>
                                    </div>
                                    <div class="form-text">Show browser push notifications for system alerts</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="enable_desktop_notifications" 
                                               name="enable_desktop_notifications" value="1" 
                                               <?= get_setting('enable_desktop_notifications') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="enable_desktop_notifications">
                                            Enable Desktop Notifications
                                        </label>
                                    </div>
                                    <div class="form-text">Show desktop notifications for important system events</div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary">Notification Preferences</h5>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="notification_sound" 
                                               name="notification_sound" value="1" 
                                               <?= get_setting('notification_sound') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="notification_sound">
                                            Enable Notification Sound
                                        </label>
                                    </div>
                                    <div class="form-text">Play sound when new notifications arrive</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="auto_dismiss_notifications" 
                                               name="auto_dismiss_notifications" value="1" 
                                               <?= get_setting('auto_dismiss_notifications') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="auto_dismiss_notifications">
                                            Auto-dismiss Notifications
                                        </label>
                                    </div>
                                    <div class="form-text">Automatically dismiss notifications after timeout</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="notification_timeout" class="form-label">Notification Timeout (ms)</label>
                                    <input type="number" class="form-control" id="notification_timeout" 
                                           name="notification_timeout" value="<?= get_setting('notification_timeout', '5000') ?>">
                                    <div class="form-text">Time in milliseconds before notifications auto-dismiss</div>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> 
                                    <strong>Notification Types:</strong><br>
                                    • System alerts<br>
                                    • Payment reminders<br>
                                    • Loan status updates<br>
                                    • Overdue alerts<br>
                                    • Report notifications
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" name="save_notification_settings" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Save Notification Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Email Templates Tab -->
                <div class="tab-pane fade" id="email" role="tabpanel">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary">Loan Approval Email</h5>
                                
                                <div class="mb-3">
                                    <label for="loan_approval_email_subject" class="form-label">Subject Template</label>
                                    <input type="text" class="form-control" id="loan_approval_email_subject" 
                                           name="loan_approval_email_subject" 
                                           value="<?= get_setting('loan_approval_email_subject', 'Loan Application Approved - {company_name}') ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="loan_approval_email_body" class="form-label">Email Body Template</label>
                                    <textarea class="form-control" id="loan_approval_email_body" 
                                              name="loan_approval_email_body" rows="6"><?= get_setting('loan_approval_email_body', 'Dear {customer_name},

We are pleased to inform you that your loan application #{loan_id} has been approved.

Loan Details:
- Amount: {loan_amount}
- Interest Rate: {interest_rate}%
- Term: {loan_term} months
- Monthly Payment: {monthly_payment}

Please visit our office to complete the disbursement process.

Best regards,
{company_name}') ?></textarea>
                                </div>
                                
                                <h5 class="mb-3 text-primary mt-4">Payment Reminder Email</h5>
                                
                                <div class="mb-3">
                                    <label for="payment_reminder_email_subject" class="form-label">Subject Template</label>
                                    <input type="text" class="form-control" id="payment_reminder_email_subject" 
                                           name="payment_reminder_email_subject" 
                                           value="<?= get_setting('payment_reminder_email_subject', 'Payment Reminder - Loan #{loan_id}') ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="payment_reminder_email_body" class="form-label">Email Body Template</label>
                                    <textarea class="form-control" id="payment_reminder_email_body" 
                                              name="payment_reminder_email_body" rows="6"><?= get_setting('payment_reminder_email_body', 'Dear {customer_name},

This is a friendly reminder that your payment for loan #{loan_id} is due on {due_date}.

Payment Details:
- Due Amount: {due_amount}
- Due Date: {due_date}
- Loan Balance: {outstanding_balance}

Please make your payment before the due date to avoid late fees.

Thank you,
{company_name}') ?></textarea>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary">Overdue Notice Email</h5>
                                
                                <div class="mb-3">
                                    <label for="overdue_notice_email_subject" class="form-label">Subject Template</label>
                                    <input type="text" class="form-control" id="overdue_notice_email_subject" 
                                           name="overdue_notice_email_subject" 
                                           value="<?= get_setting('overdue_notice_email_subject', 'URGENT: Overdue Payment - Loan #{loan_id}') ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="overdue_notice_email_body" class="form-label">Email Body Template</label>
                                    <textarea class="form-control" id="overdue_notice_email_body" 
                                              name="overdue_notice_email_body" rows="6"><?= get_setting('overdue_notice_email_body', 'Dear {customer_name},

Your payment for loan #{loan_id} is overdue. Immediate action is required to avoid further penalties.

Overdue Details:
- Overdue Amount: {overdue_amount}
- Due Date: {due_date}
- Days Overdue: {overdue_days}
- Late Fee: {late_fee}

Please contact us immediately to arrange payment.

Sincerely,
{company_name}') ?></textarea>
                                </div>
                                
                                <h5 class="mb-3 text-primary mt-4">Loan Disbursement Email</h5>
                                
                                <div class="mb-3">
                                    <label for="loan_disbursement_email_subject" class="form-label">Subject Template</label>
                                    <input type="text" class="form-control" id="loan_disbursement_email_subject" 
                                           name="loan_disbursement_email_subject" 
                                           value="<?= get_setting('loan_disbursement_email_subject', 'Loan Funds Disbursed - #{loan_id}') ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="loan_disbursement_email_body" class="form-label">Email Body Template</label>
                                    <textarea class="form-control" id="loan_disbursement_email_body" 
                                              name="loan_disbursement_email_body" rows="6"><?= get_setting('loan_disbursement_email_body', 'Dear {customer_name},

We are pleased to inform you that your loan funds have been disbursed.

Disbursement Details:
- Loan Amount: {loan_amount}
- Disbursement Date: {disbursement_date}
- Disbursement Method: {disbursement_method}
- First Payment Due: {first_payment_date}

Your repayment schedule has been created. Please ensure timely payments.

Best regards,
{company_name}') ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 
                            <strong>Available Template Variables:</strong><br>
                            <code>{customer_name}, {loan_id}, {loan_amount}, {interest_rate}, {loan_term}, {monthly_payment}, {due_date}, {due_amount}, {outstanding_balance}, {overdue_days}, {late_fee}, {disbursement_date}, {disbursement_method}, {first_payment_date}, {company_name}, {company_phone}, {company_email}</code>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" name="save_email_templates" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Save Email Templates
                            </button>
                        </div>
                    </form>
                </div>

                <!-- SMS Templates Tab -->
                <div class="tab-pane fade" id="sms" role="tabpanel">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary">Payment Reminder SMS</h5>
                                <div class="mb-3">
                                    <label for="payment_reminder_sms" class="form-label">SMS Template</label>
                                    <textarea class="form-control" id="payment_reminder_sms" 
                                              name="payment_reminder_sms" rows="3" maxlength="160"><?= get_setting('payment_reminder_sms', 'Hi {customer_name}, your payment of {due_amount} for loan #{loan_id} is due on {due_date}. - {company_name}') ?></textarea>
                                    <div class="form-text">
                                        <span id="payment_reminder_counter">0</span>/160 characters
                                    </div>
                                </div>
                                
                                <h5 class="mb-3 text-primary mt-4">Overdue Alert SMS</h5>
                                <div class="mb-3">
                                    <label for="overdue_alert_sms" class="form-label">SMS Template</label>
                                    <textarea class="form-control" id="overdue_alert_sms" 
                                              name="overdue_alert_sms" rows="3" maxlength="160"><?= get_setting('overdue_alert_sms', 'URGENT: Loan #{loan_id} is {overdue_days} days overdue. Amount: {overdue_amount}. Call {company_phone}. - {company_name}') ?></textarea>
                                    <div class="form-text">
                                        <span id="overdue_alert_counter">0</span>/160 characters
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary">Loan Approval SMS</h5>
                                <div class="mb-3">
                                    <label for="loan_approval_sms" class="form-label">SMS Template</label>
                                    <textarea class="form-control" id="loan_approval_sms" 
                                              name="loan_approval_sms" rows="3" maxlength="160"><?= get_setting('loan_approval_sms', 'Good news! Your loan #{loan_id} for {loan_amount} is approved. Visit us to complete. - {company_name}') ?></textarea>
                                    <div class="form-text">
                                        <span id="loan_approval_counter">0</span>/160 characters
                                    </div>
                                </div>
                                
                                <h5 class="mb-3 text-primary mt-4">Loan Disbursement SMS</h5>
                                <div class="mb-3">
                                    <label for="loan_disbursement_sms" class="form-label">SMS Template</label>
                                    <textarea class="form-control" id="loan_disbursement_sms" 
                                              name="loan_disbursement_sms" rows="3" maxlength="160"><?= get_setting('loan_disbursement_sms', 'Your loan #{loan_id} funds have been disbursed. First payment due: {first_payment_date}. - {company_name}') ?></textarea>
                                    <div class="form-text">
                                        <span id="loan_disbursement_counter">0</span>/160 characters
                                    </div>
                                </div>
                                
                                <h5 class="mb-3 text-primary mt-4">General Notification SMS</h5>
                                <div class="mb-3">
                                    <label for="general_notification_sms" class="form-label">SMS Template</label>
                                    <textarea class="form-control" id="general_notification_sms" 
                                              name="general_notification_sms" rows="3" maxlength="160"><?= get_setting('general_notification_sms', 'Important update from {company_name}. Please check your email or contact us at {company_phone}.') ?></textarea>
                                    <div class="form-text">
                                        <span id="general_notification_counter">0</span>/160 characters
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> 
                            <strong>SMS Character Limit:</strong> Standard SMS messages are limited to 160 characters. 
                            Messages longer than this will be split into multiple messages.
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" name="save_sms_templates" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Save SMS Templates
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Alert Rules Tab -->
                <div class="tab-pane fade" id="alerts" role="tabpanel">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary">Payment Alerts</h5>
                                
                                <div class="mb-3">
                                    <label for="send_payment_reminder_days" class="form-label">Send Payment Reminder (days before due date)</label>
                                    <input type="number" class="form-control" id="send_payment_reminder_days" 
                                           name="send_payment_reminder_days" 
                                           value="<?= get_setting('send_payment_reminder_days', '3') ?>">
                                    <div class="form-text">Send reminder this many days before payment due date</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="send_overdue_alert_days" class="form-label">Send Overdue Alert (days after due date)</label>
                                    <input type="number" class="form-control" id="send_overdue_alert_days" 
                                           name="send_overdue_alert_days" 
                                           value="<?= get_setting('send_overdue_alert_days', '1') ?>">
                                    <div class="form-text">Send overdue alert this many days after due date</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="send_loan_approval_notification" 
                                               name="send_loan_approval_notification" value="1" 
                                               <?= get_setting('send_loan_approval_notification', '1') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="send_loan_approval_notification">
                                            Send Loan Approval Notifications
                                        </label>
                                    </div>
                                    <div class="form-text">Notify customer when loan is approved</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="send_loan_disbursement_notification" 
                                               name="send_loan_disbursement_notification" value="1" 
                                               <?= get_setting('send_loan_disbursement_notification', '1') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="send_loan_disbursement_notification">
                                            Send Loan Disbursement Notifications
                                        </label>
                                    </div>
                                    <div class="form-text">Notify customer when loan funds are disbursed</div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary">Report Alerts</h5>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="send_daily_collection_report" 
                                               name="send_daily_collection_report" value="1" 
                                               <?= get_setting('send_daily_collection_report') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="send_daily_collection_report">
                                            Send Daily Collection Report
                                        </label>
                                    </div>
                                    <div class="form-text">Email daily collection summary to managers</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="send_weekly_performance_report" 
                                               name="send_weekly_performance_report" value="1" 
                                               <?= get_setting('send_weekly_performance_report') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="send_weekly_performance_report">
                                            Send Weekly Performance Report
                                        </label>
                                    </div>
                                    <div class="form-text">Email weekly performance metrics to management</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="send_monthly_portfolio_report" 
                                               name="send_monthly_portfolio_report" value="1" 
                                               <?= get_setting('send_monthly_portfolio_report') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="send_monthly_portfolio_report">
                                            Send Monthly Portfolio Report
                                        </label>
                                    </div>
                                    <div class="form-text">Email comprehensive portfolio analysis monthly</div>
                                </div>
                                
                                <h5 class="mb-3 text-primary mt-4">Risk Alerts</h5>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="high_risk_loan_alert" 
                                               name="high_risk_loan_alert" value="1" 
                                               <?= get_setting('high_risk_loan_alert') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="high_risk_loan_alert">
                                            High-Risk Loan Alerts
                                        </label>
                                    </div>
                                    <div class="form-text">Alert managers when high-risk loans are applied for</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="large_loan_alert_threshold" class="form-label">Large Loan Alert Threshold</label>
                                    <input type="number" step="0.01" class="form-control" id="large_loan_alert_threshold" 
                                           name="large_loan_alert_threshold" 
                                           value="<?= get_setting('large_loan_alert_threshold', '5000.00') ?>">
                                    <div class="form-text">Alert managers for loans above this amount</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" name="save_alert_rules" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Save Alert Rules
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Test Notifications Tab -->
                <div class="tab-pane fade" id="test" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6">
                            <h5 class="mb-3 text-primary">Test Email Notifications</h5>
                            
                            <div class="card mb-4">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="test_email_address" class="form-label">Test Email Address</label>
                                        <input type="email" class="form-control" id="test_email_address" 
                                               placeholder="Enter email address to test">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="test_email_type" class="form-label">Email Type</label>
                                        <select class="form-control" id="test_email_type">
                                            <option value="loan_approval">Loan Approval</option>
                                            <option value="payment_reminder">Payment Reminder</option>
                                            <option value="overdue_notice">Overdue Notice</option>
                                            <option value="loan_disbursement">Loan Disbursement</option>
                                        </select>
                                    </div>
                                    
                                    <button type="button" class="btn btn-primary" id="testEmail">
                                        <i class="bi bi-envelope"></i> Send Test Email
                                    </button>
                                </div>
                            </div>
                            
                            <h5 class="mb-3 text-primary">Test SMS Notifications</h5>
                            
                            <div class="card">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="test_phone_number" class="form-label">Test Phone Number</label>
                                        <input type="text" class="form-control" id="test_phone_number" 
                                               placeholder="Enter phone number to test">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="test_sms_type" class="form-label">SMS Type</label>
                                        <select class="form-control" id="test_sms_type">
                                            <option value="payment_reminder">Payment Reminder</option>
                                            <option value="overdue_alert">Overdue Alert</option>
                                            <option value="loan_approval">Loan Approval</option>
                                            <option value="loan_disbursement">Loan Disbursement</option>
                                        </select>
                                    </div>
                                    
                                    <button type="button" class="btn btn-primary" id="testSMS">
                                        <i class="bi bi-chat-text"></i> Send Test SMS
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <h5 class="mb-3 text-primary">Test System Notifications</h5>
                            
                            <div class="card mb-4">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="test_notification_type" class="form-label">Notification Type</label>
                                        <select class="form-control" id="test_notification_type">
                                            <option value="success">Success Notification</option>
                                            <option value="warning">Warning Notification</option>
                                            <option value="error">Error Notification</option>
                                            <option value="info">Info Notification</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="test_notification_message" class="form-label">Message</label>
                                        <input type="text" class="form-control" id="test_notification_message" 
                                               value="This is a test notification from the system">
                                    </div>
                                    
                                    <button type="button" class="btn btn-primary" id="testSystemNotification">
                                        <i class="bi bi-bell"></i> Show Test Notification
                                    </button>
                                </div>
                            </div>
                            
                            <h5 class="mb-3 text-primary">Notification Log</h5>
                            
                            <div class="card">
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover" id="notificationLog">
                                            <thead>
                                                <tr>
                                                    <th>Type</th>
                                                    <th>Message</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">
                                                        No test notifications sent yet
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="clearLog">
                                        <i class="bi bi-trash"></i> Clear Log
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999">
    <!-- Toast notifications will be inserted here -->
</div>

<?php require_once __DIR__ . '/../../../footer.php'; ?>

<style>
.card {
    border: none;
    border-radius: 0.5rem;
}

.nav-tabs .nav-link {
    border: none;
    color: #6c757d;
    font-weight: 500;
}

.nav-tabs .nav-link.active {
    color: #0d6efd;
    border-bottom: 3px solid #0d6efd;
    background: transparent;
}

.form-switch .form-check-input {
    width: 3em;
    height: 1.5em;
}

.character-counter {
    font-size: 0.875rem;
}

.character-counter.warning {
    color: #ffc107;
}

.character-counter.danger {
    color: #dc3545;
}

#notificationLog {
    font-size: 0.875rem;
}
</style>

<script>
$(document).ready(function() {
    // Character counters for SMS templates
    function updateCharacterCounters() {
        const fields = [
            { id: 'payment_reminder_sms', counter: 'payment_reminder_counter' },
            { id: 'overdue_alert_sms', counter: 'overdue_alert_counter' },
            { id: 'loan_approval_sms', counter: 'loan_approval_counter' },
            { id: 'loan_disbursement_sms', counter: 'loan_disbursement_counter' },
            { id: 'general_notification_sms', counter: 'general_notification_counter' }
        ];
        
        fields.forEach(field => {
            const textarea = $('#' + field.id);
            const counter = $('#' + field.counter);
            const length = textarea.val().length;
            
            counter.text(length);
            
            if (length > 160) {
                counter.addClass('danger').removeClass('warning');
            } else if (length > 140) {
                counter.addClass('warning').removeClass('danger');
            } else {
                counter.removeClass('warning danger');
            }
        });
    }
    
    // Initialize counters
    updateCharacterCounters();
    
    // Update counters on input
    $('textarea[maxlength="160"]').on('input', updateCharacterCounters);
    
    // Test Email
    $('#testEmail').click(function() {
        const email = $('#test_email_address').val();
        const type = $('#test_email_type').val();
        
        if (!email) {
            showToast('error', 'Please enter a test email address');
            return;
        }
        
        const btn = $(this);
        const originalText = btn.html();
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Sending...');
        
        $.ajax({
            url: 'api/test_notification.php',
            type: 'POST',
            data: {
                type: 'email',
                email_type: type,
                recipient: email
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast('success', 'Test email sent successfully!');
                    addToNotificationLog('Email', `Test ${type} email sent to ${email}`, 'success');
                } else {
                    showToast('error', 'Failed to send test email: ' + (response.message || 'Unknown error'));
                    addToNotificationLog('Email', `Failed to send test ${type} email`, 'error');
                }
            },
            error: function() {
                showToast('error', 'Error sending test email');
                addToNotificationLog('Email', 'Error sending test email', 'error');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalText);
            }
        });
    });
    
    // Test SMS
    $('#testSMS').click(function() {
        const phone = $('#test_phone_number').val();
        const type = $('#test_sms_type').val();
        
        if (!phone) {
            showToast('error', 'Please enter a test phone number');
            return;
        }
        
        const btn = $(this);
        const originalText = btn.html();
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Sending...');
        
        $.ajax({
            url: 'api/test_notification.php',
            type: 'POST',
            data: {
                type: 'sms',
                sms_type: type,
                recipient: phone
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast('success', 'Test SMS sent successfully!');
                    addToNotificationLog('SMS', `Test ${type} SMS sent to ${phone}`, 'success');
                } else {
                    showToast('error', 'Failed to send test SMS: ' + (response.message || 'Unknown error'));
                    addToNotificationLog('SMS', `Failed to send test ${type} SMS`, 'error');
                }
            },
            error: function() {
                showToast('error', 'Error sending test SMS');
                addToNotificationLog('SMS', 'Error sending test SMS', 'error');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalText);
            }
        });
    });
    
    // Test System Notification
    $('#testSystemNotification').click(function() {
        const type = $('#test_notification_type').val();
        const message = $('#test_notification_message').val();
        
        showToast(type, message);
        addToNotificationLog('System', message, type);
    });
    
    // Clear Log
    $('#clearLog').click(function() {
        $('#notificationLog tbody').html(`
            <tr>
                <td colspan="4" class="text-center text-muted">
                    No test notifications sent yet
                </td>
            </tr>
        `);
        showToast('info', 'Notification log cleared');
    });
    
    // Add entry to notification log
    function addToNotificationLog(notificationType, message, status) {
        const now = new Date().toLocaleString();
        const statusBadge = status === 'success' ? 'badge bg-success' : 
                           status === 'error' ? 'badge bg-danger' : 
                           status === 'warning' ? 'badge bg-warning' : 'badge bg-info';
        
        let newRow = `
            <tr>
                <td>${notificationType}</td>
                <td>${message}</td>
                <td>${now}</td>
                <td><span class="${statusBadge}">${status}</span></td>
            </tr>
        `;
        
        const tbody = $('#notificationLog tbody');
        if (tbody.find('td.text-muted').length > 0) {
            tbody.html(newRow);
        } else {
            tbody.prepend(newRow);
        }
    }
    
    // Toast notification function
    function showToast(type, message) {
        var toast = '<div class="toast align-items-center text-white bg-' + type + ' border-0" role="alert" aria-live="assertive" aria-atomic="true">';
        toast += '<div class="d-flex">';
        toast += '<div class="toast-body">' + message + '</div>';
        toast += '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>';
        toast += '</div></div>';
        
        var $toast = $(toast);
        $('.toast-container').append($toast);
        var bsToast = new bootstrap.Toast($toast[0]);
        bsToast.show();
        
        $toast.on('hidden.bs.toast', function() {
            $(this).remove();
        });
    }
    
    // Template variable helper
    $('.template-help').click(function() {
        const variable = $(this).data('variable');
        const target = $(this).data('target');
        const textarea = $('#' + target);
        const current = textarea.val();
        const cursorPos = textarea[0].selectionStart;
        
        const newText = current.substring(0, cursorPos) + variable + current.substring(cursorPos);
        textarea.val(newText);
        textarea.focus();
    });
});
</script>
<?php ob_end_flush(); ?>