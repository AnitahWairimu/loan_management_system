<?php

session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: login.html");
    exit();
}

include "../backend/db.php";
include "../backend/helpers.php";

ensurePaymentVerificationColumns($conn);

refreshLoanStatuses($conn);

// Get all repayments with stats
$payments = $conn->query(
    "SELECT
        repayments.*,
        loan_applicants.full_name,
        loans.loan_amount
    FROM repayments
    JOIN loans ON repayments.loan_id = loans.loan_id
    JOIN loan_applicants ON loans.`" . loanColumn($conn, 'client_id') . "` = loan_applicants.applicant_id
    ORDER BY repayments.repayment_id DESC"
);

// Get repayment statistics
$totalRepayments = $conn->query("SELECT COUNT(*) FROM repayments")->fetchColumn();
$totalRepaid = $conn->query("SELECT COALESCE(SUM(payment_amount), 0) FROM repayments WHERE " . repaymentVerificationFilter($conn))->fetchColumn();

?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Repayments</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .review-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .review-actions form { display: inline-flex; align-items: center; gap: 6px; margin: 0; }
        .review-actions button { border: 0; border-radius: 4px; padding: 7px 10px; color: white; cursor: pointer; font-weight: 600; }
        .verify-button { background: #0f9d58; }
        .reject-button { background: #dc2626; }
        .review-inline-input { padding: 7px 8px; border: 1px solid #d1d5db; border-radius: 4px; min-width: 140px; }
        .repayments-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .repayments-header h1 {
            font-size: 28px;
            color: #1f2937;
            font-weight: 600;
        }

        .repayments-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .repayment-stat-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        .repayment-stat-box .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #0f9d58;
        }

        .repayment-stat-box .stat-label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }

        .repayments-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .payment-method {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            background: #f3f4f6;
            color: #374151;
        }
    </style>
</head>

<body>

<div class="container">
    <div class="sidebar">
        <h2 class="logo">🏦Smart Loans</h2>
        <ul>
            <li><a href="dashboard.php">🏠 Dashboard</a></li>
            <li><a href="applicants.php">👥 Applicants</a></li>
            <li><a href="loans.php">💰 Loans</a></li>
            <li><a href="loan_plans.php">🧾 Loan Plans</a></li>
            <li><a href="payment_calculator.php">🧮 Payment Calculator</a></li>
            <li><a class="active" href="repayments.php">💳 Repayments</a></li>
            <li><a href="reports.php">📊 Reports</a></li>
            <li><a href="activity_logs.php">📜 Activity Logs</a></li>
            <li><a href="profile.php">👤 Profile</a></li>
            <li><a href="../backend/logout.php">🚪 Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <!-- Header -->
        <div class="repayments-header">
            <h1>💳 Loan Repayments</h1>
        </div>

        <!-- Stats -->
        <div class="repayments-stats">
            <div class="repayment-stat-box">
                <div class="stat-value"><?php echo $totalRepayments; ?></div>
                <div class="stat-label">Total Payments</div>
            </div>
            <div class="repayment-stat-box">
                <div class="stat-value">KES <?php echo number_format($totalRepaid); ?></div>
                <div class="stat-label">Total Repaid</div>
            </div>
        </div>

        <!-- Repayments Table -->
        <div class="repayments-table-container">
            <table>
                <thead>
                    <tr>
                        <th>Payment ID</th>
                        <th>Applicant</th>
                        <th>Loan Amount</th>
                        <th>Payment Amount</th>
                        <th>Payment Method</th>
                        <th>Date</th>
                   </tr>
                </thead>
                <tbody>
                    <?php while($row = $payments->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><strong>#<?php echo $row['repayment_id']; ?></strong></td>
                        <td><?php echo $row['full_name']; ?></td>
                        <td>KES <?php echo number_format($row['loan_amount']); ?></td>
                        <td>KES <?php echo number_format($row['payment_amount']); ?></td>
                        <td>
                            <span class="payment-method">
                                <?php echo $row['payment_method']; ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($row['payment_date'])); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="../js/script.js"></script>

</body>

</html>
