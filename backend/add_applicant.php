<?php

session_start();

include "db.php";
include "helpers.php";

$fullName = normalizeOptional($_POST['full_name'] ?? null);
$phoneNumber = normalizeOptional($_POST['phone_number'] ?? null);
$nationalId = normalizeOptional($_POST['national_id'] ?? null);

if (!$fullName || !$phoneNumber || !$nationalId) {
    $_SESSION['error'] = "Full Name, Phone Number, and National ID are required.";
    header("Location: ../frontend/applicants.php");
    exit();
}

$fields = [
    'full_name' => $fullName,
    'email' => normalizeOptional($_POST['email'] ?? null),
    'phone_number' => $phoneNumber,
    'national_id' => $nationalId,
    'address' => normalizeOptional($_POST['address'] ?? null),
    'loan_purpose' => normalizeOptional($_POST['loan_purpose'] ?? null),
    'employment_status' => normalizeOptional($_POST['employment_status'] ?? null),
    'monthly_income' => normalizeOptional($_POST['monthly_income'] ?? null),
];

$columns = [];
$placeholders = [];
$values = [];

foreach ($fields as $column => $value) {
    if (tableHasColumn($conn, 'loan_applicants', $column)) {
        $columns[] = "`$column`";
        $placeholders[] = "?";
        $values[] = $value;
    }
}

$sql = "INSERT INTO loan_applicants (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $placeholders) . ")";
$stmt = $conn->prepare($sql);
$stmt->execute($values);

header("Location: ../frontend/applicants.php");
exit();
?>
