<?php

session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: login.html");
    exit();
}

include "../backend/db.php";
include "../backend/helpers.php";

refreshLoanStatuses($conn);

$loan_id = $_GET['id'];
$loan = getLoanFinancialSnapshot($conn, $loan_id);

if (!$loan) {
    die("Loan not found.");
}

$hasPlanColumn = tableHasColumn($conn, 'loans', 'plan_id');
$planSelect = $hasPlanColumn ? ", loan_plans.plan_name, loan_plans.description AS plan_description" : ", NULL AS plan_name, NULL AS plan_description";
$planJoin = $hasPlanColumn ? " LEFT JOIN loan_plans ON loans.plan_id = loan_plans.plan_id" : "";

$stmt = $conn->prepare(
    "SELECT loan_applicants.full_name, loan_applicants.phone_number, loan_applicants.national_id$planSelect
    FROM loans
    JOIN loan_applicants ON loans.`" . loanColumn($conn, 'client_id') . "` = loan_applicants.applicant_id
    $planJoin
    WHERE loans.loan_id=?"
);

$stmt->execute([$loan_id]);
$loanMeta = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$loan = array_merge($loan, $loanMeta);

// Get repayment info
$total_paid = (float)($loan['total_paid'] ?? 0);
$balance = (float)($loan['outstanding_balance'] ?? 0);
$progressBase = max(((float)$loan['loan_amount'] + (float)$loan['accrued_interest']), 1);
$progress = min(($total_paid / $progressBase) * 100, 100);
$nextMonthlyInterest = round((float)$loan['outstanding_principal'] * ((float)$loan['interest_rate'] / 100), 2);

// Get payment history
$payments = $conn->prepare("SELECT * FROM repayments WHERE loan_id=? ORDER BY repayment_id DESC");
$payments->execute([$loan_id]);

?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Details - #<?php echo $loan_id; ?></title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .loan-view-container {
            padding: 30px;
            background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
            min-height: 100vh;
        }

        .loan-header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .loan-header h1 {
            font-size: 28px;
            color: #1f2937;
            margin: 0;
        }

        .loan-status {
            display: inline-block;
        }

        .loan-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .detail-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .detail-card h2 {
            color: #0f9d58;
            font-size: 16px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: #6b7280;
            font-weight: 600;
            font-size: 13px;
        }

        .detail-value {
            color: #1f2937;
            font-weight: 600;
        }

        .progress-container {
            margin-top: 15px;
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background: #e5e7eb;
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #0f9d58);
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .progress-text {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .action-buttons a {
            flex: 1;
            padding: 10px;
            text-align: center;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .action-buttons a:nth-child(1) {
            background: #3b82f6;
            color: white;
        }

        .action-buttons a:nth-child(1):hover {
            background: #2563eb;
        }

        .action-buttons a:nth-child(2) {
            background: #10b981;
            color: white;
        }

        .action-buttons a:nth-child(2):hover {
            background: #059669;
        }

        .payment-history {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .payment-history h2 {
            color: #1f2937;
            margin-bottom: 20px;
            font-size: 18px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #0f9d58;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .back-link:hover {
            transform: translateX(-5px);
        }
    </style>
</head>

<body>

<div class="loan-view-container">
    <a href="loans.php" class="back-link">← Back to Loans</a>

    <!-- Loan Header -->
    <div class="loan-header">
        <div>
            <h1>💰 Loan #<?php echo $loan['loan_id']; ?></h1>
            <p style="color: #6b7280; margin-top: 5px;">Applied by: <?php echo $loan['full_name']; ?></p>
        </div>
        <div class="loan-status">
            <span class="status-badge status-<?php echo strtolower($loan['loan_status']); ?>">
                <?php echo $loan['loan_status']; ?>
            </span>
        </div>
    </div>

    <!-- Details Grid -->
    <div class="loan-details-grid">
        <!-- Applicant Information -->
        <div class="detail-card">
            <h2>👤 Applicant Information</h2>
            <div class="detail-item">
                <span class="detail-label">Full Name</span>
                <span class="detail-value"><?php echo $loan['full_name']; ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Phone</span>
                <span class="detail-value"><?php echo $loan['phone_number']; ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">National ID</span>
                <span class="detail-value"><?php echo $loan['national_id']; ?></span>
            </div>
            
        </div>

        <!-- Loan Information -->
        <div class="detail-card">
            <h2>📋 Loan Information</h2>
            <div class="detail-item">
                <span class="detail-label">Loan Plan</span>
                <span class="detail-value"><?php echo $loan['plan_name'] ? h($loan['plan_name']) : 'Custom (no plan)'; ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Loan Amount</span>
                <span class="detail-value">KES <?php echo number_format($loan['loan_amount']); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Monthly Interest Rate</span>
                <span class="detail-value"><?php echo $loan['interest_rate']; ?>%</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Duration</span>
                <span class="detail-value"><?php echo $loan['loan_duration_months']; ?> Months</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Current Outstanding Balance</span>
                <span class="detail-value">KES <?php echo number_format((float)$loan['outstanding_balance']); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Average Monthly Payment</span>
                <span class="detail-value">KES <?php echo number_format(calculateLoanPlanInstallment($loan['loan_amount'], $loan['interest_rate'], $loan['loan_duration_months']), 2); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Outstanding Principal</span>
                <span class="detail-value">KES <?php echo number_format($loan['outstanding_principal']); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Accrued Interest</span>
                <span class="detail-value">KES <?php echo number_format($loan['accrued_interest']); ?></span>
            </div>
        </div>

        <!-- Interest Tracking -->
        <div class="detail-card">
            <h2>Interest Tracking</h2>
            <div class="detail-item">
                <span class="detail-label">Interest is calculated on</span>
                <span class="detail-value">Remaining principal</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Interest at next full month</span>
                <span class="detail-value">KES <?php echo number_format($nextMonthlyInterest, 2); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Last interest calculation</span>
                <span class="detail-value"><?php echo !empty($loan['last_interest_calculated_at']) ? date('M d, Y', strtotime($loan['last_interest_calculated_at'])) : 'Loan issue date'; ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Payment allocation</span>
                <span class="detail-value">Interest first, then principal</span>
            </div>
        </div>

        <!-- Repayment Progress -->
        <div class="detail-card">
            <h2>📊 Repayment Progress</h2>
            <div class="detail-item">
                <span class="detail-label">Total Paid</span>
                <span class="detail-value">KES <?php echo number_format($total_paid); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Remaining Balance</span>
                <span class="detail-value">KES <?php echo number_format($balance); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Interest Paid</span>
                <span class="detail-value">KES <?php echo number_format($loan['interest_paid']); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Principal Paid</span>
                <span class="detail-value">KES <?php echo number_format($loan['principal_paid']); ?></span>
            </div>
            <div class="progress-container">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min($progress, 100); ?>%"></div>
                </div>
                <div class="progress-text"><?php echo number_format($progress, 1); ?>% Completed</div>
            </div>
            <div class="action-buttons">
                <a href="loan_repayments.php?id=<?php echo $loan_id; ?>">💳 Record Payment</a>
                <a href="loans.php">📋 View All Loans</a>
            </div>
        </div>
    </div>

    <!-- Payment History -->
    <div class="payment-history">
        <h2>📜 Payment History</h2>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Interest Paid</th>
                    <th>Principal Paid</th>
                    <th>Balance After Payment</th>
                </tr>
            </thead>
            <tbody>
                <?php while($payment = $payments->fetch(PDO::FETCH_ASSOC)): ?>
                <tr>
                    <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                    <td>KES <?php echo number_format($payment['payment_amount']); ?></td>
                    <td>
                        <span style="background: #f3f4f6; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                            <?php echo $payment['payment_method']; ?>
                        </span>
                    </td>
                    <td>KES <?php echo number_format((float)($payment['interest_portion'] ?? 0)); ?></td>
                    <td>KES <?php echo number_format((float)($payment['principal_portion'] ?? 0)); ?></td>
                    <td>KES <?php echo number_format((float)($payment['remaining_balance'] ?? $payment['balance_after_payment'] ?? 0)); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

</body>

</html>
