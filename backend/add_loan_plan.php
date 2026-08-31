<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../frontend/login.html");
    exit();
}

include "db.php";
include "helpers.php";

$planName = normalizeOptional($_POST['plan_name'] ?? null);
$loanAmount = (float)($_POST['loan_amount'] ?? 0);
$interestRate = (float)($_POST['interest_rate'] ?? 0);
$duration = (int)($_POST['repayment_period_months'] ?? 0);
$description = normalizeOptional($_POST['description'] ?? null);

if (!$planName || $loanAmount <= 0 || $interestRate <= 0 || $duration < 1) {
    $_SESSION['error'] = "Please fill in a valid plan name, loan amount, interest rate, and repayment period.";
    header("Location: ../frontend/loan_plans.php");
    exit();
}

$monthlyInstallment = calculateLoanPlanInstallment($loanAmount, $interestRate, $duration);

$stmt = $conn->prepare(
    "INSERT INTO loan_plans
        (plan_name, loan_amount, interest_rate, repayment_period_months, monthly_installment, description, is_active)
     VALUES (?, ?, ?, ?, ?, ?, 1)"
);
$stmt->execute([$planName, $loanAmount, $interestRate, $duration, $monthlyInstallment, $description]);

$log = $conn->prepare("INSERT INTO activity_logs (admin_id, action, description) VALUES (?, ?, ?)");
$log->execute([
    $_SESSION['admin_id'],
    "Loan Plan Created",
    "Admin created loan plan \"$planName\" (KES " . number_format($loanAmount) . ", $interestRate% for $duration months)"
]);

$_SESSION['success'] = "Loan plan created successfully.";
header("Location: ../frontend/loan_plans.php");
exit();
