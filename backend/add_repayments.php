<?php

session_start();

include "db.php";
include "helpers.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../frontend/login.html");
    exit();
}

$loanId = (int)($_POST['loan_id'] ?? 0);
$amount = round((float)($_POST['payment_amount'] ?? 0), 2);
$method = trim($_POST['payment_method'] ?? '');
$reference = strtoupper(trim($_POST['payment_reference'] ?? ''));
$reference = $reference !== '' ? $reference : 'MANUAL-' . date('YmdHis') . '-' . $loanId;
$adminId = (int)$_SESSION['admin_id'];

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['error'] = 'Your payment form expired. Please try again.';
    header("Location: ../frontend/loan_repayments.php?id=" . urlencode((string)$loanId));
    exit();
}

if ($loanId <= 0 || $amount <= 0 || $method === '') {
    $_SESSION['error'] = 'Enter a valid payment amount and method.';
    header("Location: ../frontend/loan_repayments.php?id=" . urlencode((string)$loanId));
    exit();
}

$requiredLoanColumns = [
    'outstanding_principal' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'accrued_interest' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'interest_paid' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'principal_paid' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'total_payments' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'outstanding_balance' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'last_interest_calculated_at' => 'DATE NULL',
];
foreach ($requiredLoanColumns as $column => $definition) {
    if (!tableHasColumn($conn, 'loans', $column)) {
        $conn->exec("ALTER TABLE loans ADD COLUMN $column $definition");
    }
}

$repaymentColumns = [
    'interest_portion' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'principal_portion' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'remaining_principal' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'remaining_interest' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
];
foreach ($repaymentColumns as $column => $definition) {
    if (!tableHasColumn($conn, 'repayments', $column)) {
        $conn->exec("ALTER TABLE repayments ADD COLUMN $column $definition");
    }
}
ensurePaymentVerificationColumns($conn);

$loan = getLoanFinancialSnapshot($conn, $loanId, date('Y-m-d'));
if (!$loan) {
    $_SESSION['error'] = 'Loan not found.';
    header('Location: ../frontend/repayments.php');
    exit();
}

if (in_array($loan['loan_status'], ['Completed', 'Rejected'], true)) {
    $_SESSION['error'] = 'This loan is not active.';
    header("Location: ../frontend/loan_repayments.php?id=" . urlencode((string)$loanId));
    exit();
}

$duplicate = $conn->prepare('SELECT repayment_id FROM repayments WHERE payment_reference = ? LIMIT 1');
$duplicate->execute([$reference]);
if ($duplicate->fetchColumn()) {
    $_SESSION['error'] = 'This transaction reference has already been recorded.';
    header("Location: ../frontend/loan_repayments.php?id=" . urlencode((string)$loanId));
    exit();
}

if (round($amount, 2) > (float)$loan['outstanding_balance'] + 0.009) {
    $_SESSION['error'] = 'This payment exceeds the current outstanding balance.';
    header("Location: ../frontend/loan_repayments.php?id=" . urlencode((string)$loanId));
    exit();
}

$conn->beginTransaction();

try {
    $insert = $conn->prepare(
        "INSERT INTO repayments
        (loan_id, payment_amount, payment_method, payment_reference, recorded_by)
        VALUES (?,?,?,?,?)"
    );
    $insert->execute([
        $loanId,
        $amount,
        $method,
        $reference,
        $adminId,
    ]);

    $repaymentId = (int)$conn->lastInsertId();
    applyVerifiedRepayment($conn, $repaymentId, $adminId, true);

    $log = $conn->prepare("INSERT INTO activity_logs (admin_id, action, description) VALUES (?,?,?)");
    $log->execute([
        $adminId,
        'Payment Recorded',
        sprintf('Payment reference %s for KES %s via %s was recorded and applied to loan ID %d.', $reference, number_format($amount, 2), $method, $loanId),
    ]);

    $conn->commit();
    $_SESSION['success'] = 'Payment recorded successfully and applied to the loan balance.';
    header('Location: ../frontend/repayments.php');
    exit();
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    throw $e;
}
