<?php

session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: login.html");
    exit();
}

include "../backend/db.php";
include "../backend/helpers.php";

refreshLoanStatuses($conn);
$clientCol = loanColumn($conn, 'client_id');
$statusCol = loanColumn($conn, 'status');
$issueDateCol = loanColumn($conn, 'issue_date');
$hasPlanColumn = tableHasColumn($conn, 'loans', 'plan_id');

// Get loans with optional filtering
$status = $_GET['status'] ?? null;
$search = trim($_GET['search'] ?? '');
$issueMonth = (int)($_GET['issue_month'] ?? 0);
$issueYear = (int)($_GET['issue_year'] ?? 0);

$planSelect = $hasPlanColumn ? ", loan_plans.plan_name" : ", NULL AS plan_name";
$planJoin = $hasPlanColumn ? " LEFT JOIN loan_plans ON loans.plan_id = loan_plans.plan_id" : "";

$where = [];
$params = [];

if ($status) {
    $where[] = "loans.`$statusCol` = ?";
    $params[] = $status;
}
if ($search !== '') {
    $where[] = "(loan_applicants.full_name LIKE ? OR loan_applicants.national_id LIKE ? OR loan_applicants.phone_number LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($issueMonth > 0) {
    $where[] = "MONTH(loans.`$issueDateCol`) = ?";
    $params[] = $issueMonth;
}
if ($issueYear > 0) {
    $where[] = "YEAR(loans.`$issueDateCol`) = ?";
    $params[] = $issueYear;
}

$sql = "SELECT " . loanSelectAliases($conn) . ", loan_applicants.full_name, loan_applicants.national_id, loan_applicants.phone_number$planSelect
        FROM loans
        LEFT JOIN loan_applicants ON loans.`$clientCol` = loan_applicants.applicant_id
        $planJoin";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY loans.loan_id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$loans = $stmt;
$pageTitle = $status ? "Loans - " . $status : "All Loans";

// Get loan stats
$totalLoans = $conn->query("SELECT COUNT(*) FROM loans")->fetchColumn();
$activeLoans = $conn->query("SELECT COUNT(*) FROM loans WHERE `$statusCol`='Active'")->fetchColumn();
$completedLoans = $conn->query("SELECT COUNT(*) FROM loans WHERE `$statusCol`='Completed'")->fetchColumn();
$overdueLoans = $conn->query("SELECT COUNT(*) FROM loans WHERE `$statusCol`='Overdue'")->fetchColumn();

?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .loans-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .loans-header h1 {
            font-size: 28px;
            color: #1f2937;
            font-weight: 600;
        }

        .status-filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .status-filters a {
            padding: 10px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            background: white;
            color: #6b7280;
            border: 2px solid #e5e7eb;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .status-filters a:hover,
        .status-filters a.active {
            background: #0f9d58;
            color: white;
            border-color: #0f9d58;
        }

        .loans-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .loan-stat-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        .loan-stat-box .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #0f9d58;
        }

        .loan-stat-box .stat-label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }

        .loans-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .loan-action-cell {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .loan-action-cell a {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
            text-decoration: none;
            background: #3b82f6;
            color: white;
            transition: all 0.3s ease;
        }

        .loan-action-cell a:hover {
            background: #2563eb;
        }

        .loan-action-cell a:nth-child(2) {
            background: #10b981;
        }

        .loan-action-cell a:nth-child(2):hover {
            background: #059669;
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
            <li><a class="active" href="loans.php">💰 Loans</a></li>
            <li><a href="loan_plans.php">🧾 Loan Plans</a></li>
            <li><a href="payment_calculator.php">🧮 Payment Calculator</a></li>
            <li><a href="repayments.php">💳 Repayments</a></li>
            <li><a href="reports.php">📊 Reports</a></li>
            <li><a href="activity_logs.php">📜 Activity Logs</a></li>
            <li><a href="profile.php">👤 Profile</a></li>
            <li><a href="../backend/logout.php">🚪 Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <!-- Header -->
        <div class="loans-header">
            <h1>💰 Loans Management</h1>
        </div>

        <!-- Stats -->
        <div class="loans-stats">
            <div class="loan-stat-box">
                <div class="stat-value"><?php echo $totalLoans; ?></div>
                <div class="stat-label">Total Loans</div>
            </div>
            <div class="loan-stat-box">
                <div class="stat-value"><?php echo $activeLoans; ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="loan-stat-box">
                <div class="stat-value"><?php echo $completedLoans; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="loan-stat-box">
                <div class="stat-value"><?php echo $overdueLoans; ?></div>
                <div class="stat-label">Overdue</div>
            </div>
        </div>

        <!-- Status Filters -->
        <div class="status-filters">
            <a href="loans.php" <?php echo !$status ? 'class="active"' : ''; ?>>📋 All Loans</a>
            <a href="loans.php?status=Active" <?php echo $status === 'Active' ? 'class="active"' : ''; ?>>🟢 Active</a>
            <a href="loans.php?status=Completed" <?php echo $status === 'Completed' ? 'class="active"' : ''; ?>>✅ Completed</a>
            <a href="loans.php?status=Overdue" <?php echo $status === 'Overdue' ? 'class="active"' : ''; ?>>⚠️ Overdue</a>
        </div>

        <form method="GET" class="filter-panel">
            <?php if($status): ?><input type="hidden" name="status" value="<?php echo h($status); ?>"><?php endif; ?>
            <div>
                <label>Search (Name, National ID, Phone)</label>
                <input type="text" name="search" placeholder="🔍 Search loans" value="<?php echo h($search); ?>">
            </div>
            <div>
                <label>Loan Issue Month</label>
                <select name="issue_month">
                    <option value="0">Any Month</option>
                    <?php
                    $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
                    foreach($monthNames as $number => $name): ?>
                        <option value="<?php echo $number; ?>" <?php echo $issueMonth === $number ? 'selected' : ''; ?>><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Loan Issue Year</label>
                <input type="number" name="issue_year" value="<?php echo $issueYear ?: ''; ?>" min="2000" max="2100" placeholder="Any year">
            </div>
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="loans.php" class="btn btn-secondary" style="text-decoration:none;text-align:center;">Clear</a>
        </form>

        <!-- Loans Table -->
        <div class="loans-table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Applicant</th>
                        <th>Loan Plan</th>
                        <th>Amount</th>
                        <th>Interest Rate</th>
                        <th>Principal Due</th>
                        <th>Interest Due</th>
                        <th>Total Due</th>
                        <th>Suggested Monthly Payment</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $loans->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><strong>#<?php echo $row['loan_id']; ?></strong></td>
                        <td><?php echo h($row['full_name']); ?></td>
                        <td><?php echo $row['plan_name'] ? h($row['plan_name']) : '<span style="color:#9ca3af;">Custom</span>'; ?></td>
                        <td>KES <?php echo number_format($row['loan_amount']); ?></td>
                        <td><?php echo $row['interest_rate']; ?>%</td>
                        <td>KES <?php echo number_format((float)($row['outstanding_principal'] ?? $row['loan_amount'])); ?></td>
                        <td>KES <?php echo number_format((float)($row['accrued_interest'] ?? 0)); ?></td>
                        <td><strong>KES <?php echo number_format((float)($row['outstanding_balance'] ?? $row['total_repayment'])); ?></strong></td>
                        <td>KES <?php echo number_format(calculateLoanPlanInstallment($row['loan_amount'], $row['interest_rate'], $row['loan_duration']), 2); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo strtolower($row['loan_status']); ?>">
                                <?php echo $row['loan_status']; ?>
                            </span>
                        </td>
                        <td>
                            <div class="loan-action-cell">
                                <a href="view_loan.php?id=<?php echo $row['loan_id']; ?>">👁️ View</a>
                                <a href="loan_repayments.php?id=<?php echo $row['loan_id']; ?>">💳 Payments</a>
                            </div>
                        </td>
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
