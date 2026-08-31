<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../frontend/login.html");
    exit();
}

include "db.php";
include "helpers.php";

$planId = (int)($_POST['plan_id'] ?? 0);
$planName = normalizeOptional($_POST['plan_name'] ?? null);
$loanAmount = (float)($_POST['loan_amount'] ?? 0);
$interestRate = (float)($_POST['interest_rate'] ?? 0);
$duration = (int)($_POST['repayment_period_months'] ?? 0);
$description = normalizeOptional($_POST['description'] ?? null);
$isActive = isset($_POST['is_active']) ? 1 : 0;

if (!$planId || !$planName || $loanAmount <= 0 || $interestRate <= 0 || $duration < 1) {
    $_SESSION['error'] = "Please fill in a valid plan name, loan amount, interest rate, and repayment period.";
    header("Location: ../frontend/loan_plans.php");
    exit();
}

$monthlyInstallment = calculateLoanPlanInstallment($loanAmount, $interestRate, $duration);

$stmt = $conn->prepare(
    "UPDATE loan_plans
     SET plan_name = ?, loan_amount = ?, interest_rate = ?, repayment_period_months = ?,
         monthly_installment = ?, description = ?, is_active = ?
     WHERE plan_id = ?"
);
$stmt->execute([$planName, $loanAmount, $interestRate, $duration, $monthlyInstallment, $description, $isActive, $planId]);

$log = $conn->prepare("INSERT INTO activity_logs (admin_id, action, description) VALUES (?, ?, ?)");
$log->execute([
    $_SESSION['admin_id'],
    "Loan Plan Updated",
    "Admin updated loan plan #$planId (\"$planName\")"
]);

$_SESSION['success'] = "Loan plan updated successfully.";
header("Location: ../frontend/loan_plans.php");
exit();
