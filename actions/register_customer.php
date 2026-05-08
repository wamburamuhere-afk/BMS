<?php
// ajax/register_customer.php
session_start();
require_once '../includes/db.php';

$response = ['success' => false, 'message' => '', 'customer_id' => null];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form data
    $first_name = $_POST['first_name'];
    $middle_name = $_POST['middle_name'];
    $last_name = $_POST['last_name'];
    $date_of_birth = $_POST['date_of_birth'];
    $gender = $_POST['gender'];
    $marital_status = $_POST['marital_status'];
    $nationality = $_POST['nationality'];
    $id_type = $_POST['id_type'];
    $id_number = $_POST['id_number'];
    $address = $_POST['address'];
    $phone_number = $_POST['phone_number'];
    $email = $_POST['email'];
    $employment_status = $_POST['employment_status'];
    $monthly_income = $_POST['monthly_income'];

    // Insert customer into the database
    $stmt = $pdo->prepare("INSERT INTO customers (first_name, middle_name, last_name, date_of_birth, gender, marital_status, nationality, id_type, id_number, address, phone_number, email, employment_status, monthly_income) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$first_name, $middle_name, $last_name, $date_of_birth, $gender, $marital_status, $nationality, $id_type, $id_number, $address, $phone_number, $email, $employment_status, $monthly_income])) {
        $response['success'] = true;
        $response['message'] = 'Customer registered successfully!';
        $response['customer_id'] = $pdo->lastInsertId(); // Get the last inserted customer ID
    } else {
        $response['message'] = 'Failed to register customer.';
    }
}

echo json_encode($response);
?>