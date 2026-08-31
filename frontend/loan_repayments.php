<?php

session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: login.html");
    exit();
}

include "../backend/db.php";
include "../backend/helpers.php";

refreshLoanStatuses($conn);

if(!isset($_GET['id'])){
    die("Loan ID not provided.");
}

$loan_id = $_GET['id'];
$loan = getLoanFinancialSnapshot($conn, $loan_id);

if(!$loan){
    die("Loan not found.");
}

$stmt = $conn->prepare(
    "SELECT loan_applicants.full_name
    FROM loans
    LEFT JOIN loan_applicants ON loans.`" . loanColumn($conn, 'client_id') . "` = loan_applicants.applicant_id
    WHERE loans.loan_id = ?"
);

$stmt->execute([$loan_id]);
$loanMeta = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$loan = array_merge($loan, $loanMeta);

// Get payment statistics
$totalPaid = (float)($loan['total_paid'] ?? 0);
$remainingBalance = (float)($loan['outstanding_balance'] ?? 0);
$paymentProgress = max(min(($totalPaid / max(((float)$loan['loan_amount'] + (float)$loan['accrued_interest']), 1)) * 100, 100), 0);
$nextMonthlyInterest = round((float)$loan['outstanding_principal'] * ((float)$loan['interest_rate'] / 100), 2);

// Get payment history
$payments = $conn->prepare("SELECT * FROM repayments WHERE loan_id = ? ORDER BY repayment_id DESC");
$payments->execute([$loan_id]);


$loan_id = $_GET['id'];

?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Repayment - #<?php echo $loan_id; ?></title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .repayment-container {
            padding: 30px;
            background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
            min-height: 100vh;
        }

        .repayment-header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }

        .repayment-header h1 {
            color: #1f2937;
            margin-bottom: 5px;
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

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .card h2 {
            color: #0f9d58;
            font-size: 16px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #6b7280;
            font-weight: 600;
        }

        .info-value {
            color: #1f2937;
            font-weight: 600;
        }

        .progress-section {
            background: linear-gradient(135deg, #f0fdf4 0%, #f0fdf4 100%);
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            border-left: 4px solid #10b981;
        }

        .progress-bar {
            width: 100%;
            height: 12px;
            background: #e5e7eb;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 10px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #0f9d58);
            border-radius: 6px;
            transition: width 0.5s ease;
        }

        .progress-text {
            font-size: 13px;
            color: #6b7280;
            text-align: center;
        }

        .form-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }

        .form-section h2 {
            color: #0f9d58;
            font-size: 18px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #1f2937;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #0f9d58;
            box-shadow: 0 0 0 3px rgba(15, 157, 88, 0.1);
        }

        .form-buttons {
            display: flex;
            gap: 12px;
        }

        .btn-submit {
            flex: 1;
            padding: 12px;
            background: #0f9d58;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-submit:hover {
            background: #087443;
            transform: translateY(-2px);
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

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #9ca3af;
        }

        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .repayment-container {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<div class="repayment-container">
    <a href="view_loan.php?id=<?php echo $loan_id; ?>" class="back-link">← Back to Loan Details</a>

    <!-- Header -->
    <div class="repayment-header">
        <h1>💳 Loan Repayment Management</h1>
        <p style="color: #6b7280;">Loan #<?php echo $loan['loan_id']; ?> - <?php echo $loan['full_name']; ?></p>
    </div>

    <!-- Content Grid -->
    <div class="content-grid">
        <!-- Loan Information -->
        <div class="card">
            <h2>📋 Loan Information</h2>
            <div class="info-item">
                <span class="info-label">Applicant</span>
                <span class="info-value"><?php echo $loan['full_name']; ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Loan Amount</span>
                <span class="info-value">KES <?php echo number_format($loan['loan_amount']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Monthly Interest Rate</span>
                <span class="info-value"><?php echo $loan['interest_rate']; ?>%</span>
            </div>
            <div class="info-item">
                <span class="info-label">Current Outstanding Balance</span>
                <span class="info-value">KES <?php echo number_format((float)$loan['outstanding_balance']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Average Monthly Payment</span>
                <span class="info-value">KES <?php echo number_format(calculateLoanPlanInstallment($loan['loan_amount'], $loan['interest_rate'], $loan['loan_duration_months']), 2); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Outstanding Principal</span>
                <span class="info-value">KES <?php echo number_format($loan['outstanding_principal']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Accrued Interest</span>
                <span class="info-value">KES <?php echo number_format($loan['accrued_interest']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Interest at next full month</span>
                <span class="info-value">KES <?php echo number_format($nextMonthlyInterest, 2); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Status</span>
                <span class="info-value">
                    <span class="status-badge status-<?php echo strtolower($loan['loan_status']); ?>">
                        <?php echo $loan['loan_status']; ?>
                    </span>
                </span>
            </div>
        </div>

        <!-- Payment Summary -->
        <div class="card">
            <h2>📊 Payment Summary</h2>
            <div class="info-item">
                <span class="info-label">Total Paid</span>
                <span class="info-value">KES <?php echo number_format($totalPaid); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Remaining Balance</span>
                <span class="info-value">KES <?php echo number_format($remainingBalance); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Interest Paid</span>
                <span class="info-value">KES <?php echo number_format($loan['interest_paid']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Principal Paid</span>
                <span class="info-value">KES <?php echo number_format($loan['principal_paid']); ?></span>
            </div>
            
            <div class="progress-section">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min($paymentProgress, 100); ?>%"></div>
                </div>
                <div class="progress-text"><?php echo number_format($paymentProgress, 1); ?>% Paid</div>
            </div>
        </div>
    </div>

    <!-- Record Payment Form -->
    <div class="form-section">
        <h2>➕ Record New Payment</h2>
        <form action="../backend/add_repayments.php" method="POST">
            <input type="hidden" name="loan_id" value="<?php echo $loan_id; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label>Payment Amount (KES) *</label>
                    <input type="number" name="payment_amount" placeholder="Enter amount" required min="100">
                </div>

                <div class="form-group">
                    <label>Payment Method *</label>
                    <select name="payment_method" required>
                        <option value="">-- Select Method --</option>
                        <option value="Cash">💵 Cash</option>
                        <option value="Bank">🏦 Bank Transfer</option>
                        <option value="Mobile Money">📱 Mobile Money</option>
                        <option value="Cheque">📄 Cheque</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Transaction Reference</label>
                <input type="text" name="payment_reference" placeholder="Optional reference or note">
            </div>

            <div class="form-buttons">
                <button type="submit" class="btn-submit">💾 Record Payment</button>
            </div>
        </form>

    </div>

    <!-- Payment History -->
    <div class="payment-history">
        <h2>📜 Payment History</h2>
        
        <?php 
        $paymentCount = 0;
        $tempPayments = $conn->prepare("SELECT COUNT(*) FROM repayments WHERE loan_id = ?");
        $tempPayments->execute([$loan_id]);
        $paymentCount = $tempPayments->fetchColumn();
        
        if($paymentCount == 0):
        ?>
            <div class="empty-state">
                <p>No payments recorded yet. Record the first payment above.</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Balance</th>
                        <th>Interest Paid</th>
                        <th>Principal Paid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $payments->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($row['payment_date'])); ?></td>
                        <td>KES <?php echo number_format($row['payment_amount']); ?></td>
                        <td>
                            <span style="background: #f3f4f6; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                <?php echo $row['payment_method']; ?>
                            </span>
                        </td>
                        <td>KES <?php echo number_format((float)($row['remaining_balance'] ?? $row['balance_after_payment'] ?? 0)); ?></td>
                        <td>KES <?php echo number_format((float)($row['interest_portion'] ?? 0)); ?></td>
                        <td>KES <?php echo number_format((float)($row['principal_portion'] ?? 0)); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

</body>

</html>




