<?php

session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: ../frontend/login.html");
    exit();
}

include "db.php";
include "helpers.php";


// Get form data

$applicant_id = $_POST['applicant_id'];
$admin_id = $_SESSION['admin_id'];

$plan_id = (int)($_POST['plan_id'] ?? 0);
$plan = $plan_id ? getLoanPlanById($conn, $plan_id) : null;

if ($plan) {
    // A Loan Plan was selected: its terms are authoritative.
    $loan_amount = (float)$plan['loan_amount'];
    $interest_rate = (float)$plan['interest_rate'];
    $duration = (int)$plan['repayment_period_months'];
} else {
    $plan_id = null;
    $loan_amount = $_POST['loan_amount'];
    $interest_rate = $_POST['interest_rate'];
    $duration = (int)($_POST['loan_duration_months'] ?? $_POST['duration'] ?? 0);
}

if ($loan_amount <= 0 || $interest_rate <= 0 || $duration < 1) {
    $_SESSION['error'] = "Enter a valid loan amount, interest rate, and duration in months.";
    header("Location: ../frontend/approve_loan.php?id=" . urlencode($applicant_id));
    exit();
}

$monthly = calculateLoanPlanInstallment($loan_amount, $interest_rate, $duration);
// Legacy total-payment field is retained for schema/UI compatibility only.
// The live amount due is always outstanding_principal + accrued_interest.
$total = round($loan_amount, 2);


// =========================
// SECURITY CHECK 1
// Check applicant status
// =========================

$stmt = $conn->prepare(
"SELECT application_status
FROM loan_applicants
WHERE applicant_id=?"
);

$stmt->execute([$applicant_id]);

$applicant = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$applicant){

    die("Applicant not found.");

}

if($applicant['application_status'] == 'Approved'){

   $_SESSION['error'] =
"Applicant has already been approved.";

header("Location: ../frontend/applicants.php");
exit();
}


// =========================
// SECURITY CHECK 2
// Check active loans
// =========================

$stmt = $conn->prepare(
"SELECT COUNT(*)
FROM loans
WHERE `" . loanColumn($conn, 'client_id') . "`=?
AND `" . loanColumn($conn, 'status') . "` IN ('Active', 'Overdue')"
);

$stmt->execute([$applicant_id]);

$activeLoan = $stmt->fetchColumn();

if($activeLoan > 0){

    $_SESSION['error'] =
"Applicant already has an active loan. Complete repayment before applying for another loan.";

header("Location: ../frontend/applicants.php");
exit();
}


// =========================
// Create Loan
// =========================

$start_date = date("Y-m-d");

$due_date = date(
    "Y-m-d",
    strtotime("+$duration months")
);


$clientCol = loanColumn($conn, 'client_id');
$durationCol = loanColumn($conn, 'duration');
$totalCol = loanColumn($conn, 'total');
$issueDateCol = loanColumn($conn, 'issue_date');
$statusCol = loanColumn($conn, 'status');
$hasPlanColumn = tableHasColumn($conn, 'loans', 'plan_id');

$columns = [
    "`$clientCol`",
    "admin_id",
    "loan_amount",
    "interest_rate",
    "`$durationCol`",
    "monthly_payment",
    "`$totalCol`",
    "`$issueDateCol`",
    "due_date",
    "`$statusCol`",
    "outstanding_principal",
    "accrued_interest",
    "interest_paid",
    "principal_paid",
    "total_payments",
    "outstanding_balance",
    "last_interest_calculated_at"
];

$values = [
    $applicant_id,
    $admin_id,
    $loan_amount,
    $interest_rate,
    $duration,
    $monthly,
    $total,
    $start_date,
    $due_date,
    'Active',
    $loan_amount,
    0.00,
    0.00,
    0.00,
    0.00,
    $loan_amount,
    $start_date
];

if ($hasPlanColumn) {
    $columns[] = "plan_id";
    $values[] = $plan_id;
}

$sql = "INSERT INTO loans (" . implode(", ", $columns) . ") VALUES (" . implode(", ", array_fill(0, count($values), "?")) . ")";

$stmt = $conn->prepare($sql);
$stmt->execute($values);


// =========================
// Update Applicant Status
// =========================

$hasDecidedAt = tableHasColumn($conn, 'loan_applicants', 'decided_at');

$update = $conn->prepare(
    $hasDecidedAt
        ? "UPDATE loan_applicants SET application_status='Approved', decided_at=NOW() WHERE applicant_id=?"
        : "UPDATE loan_applicants SET application_status='Approved' WHERE applicant_id=?"
);

$update->execute([$applicant_id]);


// =========================
// Activity Log
// =========================

$action = "Loan Approved";

$description =
"Admin approved a loan of KES "
. number_format($loan_amount)
. " for applicant ID "
. $applicant_id
. ($plan ? " using plan \"" . $plan['plan_name'] . "\"" : " (custom terms)");


$log = $conn->prepare(

"INSERT INTO activity_logs

(
admin_id,
action,
description
)

VALUES
(?,?,?)"

);

$log->execute([

$admin_id,
$action,
$description

]);


// =========================
// Redirect
// =========================

$_SESSION['success'] =
"Loan approved successfully.";

header(
"Location: ../frontend/applicants.php"
);

exit();
?>
