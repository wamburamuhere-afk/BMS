<?php
require 'includes/config.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS councils (
        council_id INT AUTO_INCREMENT PRIMARY KEY,
        council_name VARCHAR(100) NOT NULL,
        district_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (district_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wards (
        ward_id INT AUTO_INCREMENT PRIMARY KEY,
        ward_name VARCHAR(100) NOT NULL,
        council_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (council_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tenders (
        tender_id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NULL,
        procuring_entity_name VARCHAR(255) NULL,
        acronym VARCHAR(50) NULL,
        region_id INT NULL,
        district_id INT NULL,
        council_id INT NULL,
        ward_id INT NULL,
        contact_number VARCHAR(20) NULL,
        physical_address TEXT NULL,
        postal_address VARCHAR(255) NULL,
        tender_description TEXT NULL,
        tender_no VARCHAR(100) NULL,
        tender_category VARCHAR(100) NULL,
        tender_category_specify VARCHAR(255) NULL,
        tender_sub_category VARCHAR(100) NULL,
        tender_sub_category_specify VARCHAR(255) NULL,
        tender_type VARCHAR(100) NULL,
        tender_type_specify VARCHAR(255) NULL,
        publication_date DATE NULL,
        submission_deadline DATETIME NULL,
        tender_document VARCHAR(255) NULL,
        status ENUM('pending', 'submitted', 'awarded', 'lost', 'cancelled') DEFAULT 'pending',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (customer_id),
        INDEX (region_id),
        INDEX (district_id),
        INDEX (council_id),
        INDEX (ward_id)
    )");

    echo "Tables created successfully!" . PHP_EOL;

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
