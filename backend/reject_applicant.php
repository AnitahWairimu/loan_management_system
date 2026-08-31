<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../frontend/login.html");
    exit();
}

include "db.php";
include "helpers.php";

$applicantId = (int)($_GET['id'] ?? 0);

if (!$applicantId) {
    $_SESSION['error'] = "Applicant ID missing.";
    header("Location: ../frontend/applicants.php");
    exit();
}

$stmt = $conn->prepare("SELECT full_name, application_status FROM loan_applicants WHERE applicant_id = ?");
$stmt->execute([$applicantId]);
$applicant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$applicant) {
    $_SESSION['error'] = "Applicant not found.";
    header("Location: ../frontend/applicants.php");
    exit();
}

if ($applicant['application_status'] === 'Approved') {
    $_SESSION['error'] = "This applicant already has an approved loan and cannot be rejected.";
    header("Location: ../frontend/applicants.php");
    exit();
}

$hasDecidedAt = tableHasColumn($conn, 'loan_applicants', 'decided_at');

if ($hasDecidedAt) {
    $update = $conn->prepare("UPDATE loan_applicants SET application_status = 'Rejected', decided_at = NOW() WHERE applicant_id = ?");
} else {
    $update = $conn->prepare("UPDATE loan_applicants SET application_status = 'Rejected' WHERE applicant_id = ?");
}
$update->execute([$applicantId]);

$log = $conn->prepare("INSERT INTO activity_logs (admin_id, action, description) VALUES (?, ?, ?)");
$log->execute([
    $_SESSION['admin_id'],
    "Applicant Rejected",
    "Admin rejected the application of " . $applicant['full_name'] . " (ID $applicantId)"
]);

$_SESSION['success'] = "Applicant rejected.";
header("Location: ../frontend/applicants.php");
exit();
