<?php

include "db.php";
include "helpers.php";

$fullName = normalizeOptional($_POST['full_name'] ?? null);
$phoneNumber = normalizeOptional($_POST['phone_number'] ?? null);
$nationalId = normalizeOptional($_POST['national_id'] ?? null);

if (!$fullName || !$phoneNumber || !$nationalId) {
    session_start();
    $_SESSION['error'] = "Full Name, Phone Number, and National ID are required.";
    header("Location: ../frontend/edit_applicant.php?id=" . urlencode($_POST['applicant_id'] ?? ''));
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

$sets = [];
$values = [];
foreach ($fields as $column => $value) {
    if (tableHasColumn($conn, 'loan_applicants', $column)) {
        $sets[] = "`$column`=?";
        $values[] = $value;
    }
}
$values[] = $_POST['applicant_id'];

$sql = "UPDATE loan_applicants SET " . implode(", ", $sets) . " WHERE applicant_id=?";

$stmt = $conn->prepare($sql);

$stmt->execute($values);

header("Location: ../frontend/applicants.php");
exit();

?>
