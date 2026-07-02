<?php
require 'includes/config.php';
require 'helpers.php'; // For save_setting

$data = [
    'company_name' => 'BEJUNDAS FINANCIAL SERVICES LTD',
    'company_address' => 'P.O Box 7276, Msakuzi - Mbezi Ubungo – Dar es Salaam',
    'company_phone' => '+255 785 77 88 33/ +255 764 76 40 11',
    'company_email' => 'financialservices@bejundas.co.tz',
    'company_website' => 'https://bejundas.co.tz',
    'company_type' => 'microfinance',
    'currency' => 'TZS',
    'timezone' => 'Africa/Nairobi',
    'date_format' => 'Y-m-d',
    'default_interest_rate' => '3.5',
    'late_payment_fee' => '3.5',
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => '587',
    'from_email' => 'financialservices@bejundas.co.tz',
    'from_name' => 'BEJUNDAS FINANCIAL SERVICES LTD',
    'collection_target_monthly' => '50000.00',
    'overdue_reminder_days' => '3',
    'grace_period_days' => '7',
    'max_overdue_days' => '90',
    'items_per_page' => '10'
];

foreach ($data as $key => $value) {
    save_setting($key, $value);
}

echo "Settings updated successfully.";
