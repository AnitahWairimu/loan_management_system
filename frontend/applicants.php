<?php

session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: login.html");
    exit();
}

include "../backend/db.php";
include "../backend/helpers.php";

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$regMonth = (int)($_GET['reg_month'] ?? 0);
$regYear = (int)($_GET['reg_year'] ?? 0);
$issueMonth = (int)($_GET['issue_month'] ?? 0);
$issueYear = (int)($_GET['issue_year'] ?? 0);

$clientCol = loanColumn($conn, 'client_id');
$issueDateCol = loanColumn($conn, 'issue_date');

$where = ["loan_applicants.is_deleted = 0"];
$params = [];
$joinLoans = false;

if ($search !== '') {
    $where[] = "(loan_applicants.full_name LIKE ? OR loan_applicants.national_id LIKE ? OR loan_applicants.phone_number LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($statusFilter !== '') {
    $where[] = "loan_applicants.application_status = ?";
    $params[] = $statusFilter;
}

if ($regMonth > 0) {
    $where[] = "MONTH(loan_applicants.created_at) = ?";
    $params[] = $regMonth;
}
if ($regYear > 0) {
    $where[] = "YEAR(loan_applicants.created_at) = ?";
    $params[] = $regYear;
}

if ($issueMonth > 0 || $issueYear > 0) {
    $joinLoans = true;
    if ($issueMonth > 0) {
        $where[] = "MONTH(loans.`$issueDateCol`) = ?";
        $params[] = $issueMonth;
    }
    if ($issueYear > 0) {
        $where[] = "YEAR(loans.`$issueDateCol`) = ?";
        $params[] = $issueYear;
    }
}

$sql = "SELECT DISTINCT loan_applicants.* FROM loan_applicants";
if ($joinLoans) {
    $sql .= " JOIN loans ON loans.`$clientCol` = loan_applicants.applicant_id";
}
$sql .= " WHERE " . implode(" AND ", $where) . " ORDER BY loan_applicants.applicant_id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$applicants = $stmt;

$totalApplicants = $conn->query("SELECT COUNT(*) FROM loan_applicants WHERE is_deleted=0")->fetchColumn();

$currentMonth = (int)date('n');
$currentYear = (int)date('Y');
$registeredThisMonth = getApplicantsMonthlyBreakdown($conn, $currentYear)[$currentMonth];
$rejectedTotal = getRejectedApplicantsCount($conn);

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];


?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Applicants</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-section h1 {
            font-size: 28px;
            color: #1f2937;
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .applicants-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-box {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .stat-box .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #0f9d58;
        }

        .stat-box .stat-label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .action-cell {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-cell a, .action-cell button {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
            text-decoration: none;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }

        .action-cell .edit-btn {
            background: #3b82f6;
            color: white;
        }

        .action-cell .edit-btn:hover {
            background: #2563eb;
        }

        .action-cell .delete-btn {
            background: #ef4444;
            color: white;
        }

        .action-cell .delete-btn:hover {
            background: #dc2626;
        }

        .action-cell .approve-btn {
            background: #10b981;
            color: white;
        }

        .action-cell .approve-btn:hover {
            background: #059669;
        }

        .action-cell .approved-badge {
            background: #d1fae5;
            color: #065f46;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
    </style>
</head>

<body>

<div class="container">
    <div class="sidebar">
        <h2 class="logo">🏦Smart Loans</h2>
        <ul>
            <li><a href="dashboard.php">🏠 Dashboard</a></li>
            <li><a class="active" href="applicants.php">👥 Applicants</a></li>
            <li><a href="loans.php">💰 Loans</a></li>
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
        <!-- Messages -->
        <?php if(isset($_SESSION['success'])): ?>
        <div class="success-message">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
        <div class="error-message">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
        <?php endif; ?>

        <!-- Header Section -->
        <div class="header-section">
            <h1>👥 Loan Applicants</h1>
            <div class="action-buttons">
                <button id="openModal" class="btn">+ Add Applicant</button>
            </div>
        </div>

        <!-- Filter Panel -->
        <form method="GET" class="filter-panel">
            <div>
                <label>Search (Name, National ID, Phone)</label>
                <input type="text" name="search" placeholder="🔍 Search applicants" value="<?php echo h($search); ?>">
            </div>
            <div>
                <label>Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <?php foreach(['Pending', 'Approved', 'Rejected', 'Completed'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $statusFilter === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Registration Month</label>
                <select name="reg_month">
                    <option value="0">Any Month</option>
                    <?php foreach($months as $number => $name): ?>
                        <option value="<?php echo $number; ?>" <?php echo $regMonth === $number ? 'selected' : ''; ?>><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Registration Year</label>
                <input type="number" name="reg_year" value="<?php echo $regYear ?: ''; ?>" min="2000" max="2100" placeholder="Any year">
            </div>
            <div>
                <label>Loan Issue Month</label>
                <select name="issue_month">
                    <option value="0">Any Month</option>
                    <?php foreach($months as $number => $name): ?>
                        <option value="<?php echo $number; ?>" <?php echo $issueMonth === $number ? 'selected' : ''; ?>><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Loan Issue Year</label>
                <input type="number" name="issue_year" value="<?php echo $issueYear ?: ''; ?>" min="2000" max="2100" placeholder="Any year">
            </div>
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="applicants.php" class="btn btn-secondary" style="text-decoration:none;text-align:center;">Clear</a>
        </form>

        <!-- Stats -->
        <div class="applicants-stats">
            <div class="stat-box">
                <div class="stat-value"><?php echo $totalApplicants; ?></div>
                <div class="stat-label">Total Applicants</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?php echo $registeredThisMonth; ?></div>
                <div class="stat-label"><?php echo $months[$currentMonth]; ?> Registrations</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?php echo $rejectedTotal; ?></div>
                <div class="stat-label">Total Rejected</div>
            </div>
        </div>

        <!-- Modal -->
        <div class="modal" id="applicantModal">
            <div class="modal-content">
                <h2>➕ Add New Applicant</h2>

                <form action="../backend/add_applicant.php" method="POST">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" required>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email">
                    </div>

                    <div class="form-group">
                        <label>Phone Number *</label>
                        <input type="text" name="phone_number" required>
                    </div>

                    <div class="form-group">
                        <label>National ID *</label>
                        <input type="text" name="national_id" required>
                    </div>

                   

                    <div class="form-group">
                        <label>Monthly Income</label>
                        <input type="number" step="0.01" name="monthly_income">
                    </div>

                    <div class="form-group">
                        <label>Loan Purpose</label>
                        <input type="text" name="loan_purpose">
                    </div>

                    <button type="submit" class="btn" style="width: 100%; margin-top: 10px;">💾 Save Applicant</button>
                </form>
            </div>
        </div>

        <!-- Applicants Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Phone</th>
                        <th>National ID</th>
                        <th>Status</th>
                        <th>Monthly Income</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $applicants->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><strong>#<?php echo $row['applicant_id']; ?></strong></td>
                        <td><?php echo h($row['full_name']); ?></td>
                        <td><?php echo h($row['phone_number']); ?></td>
                        <td><?php echo h($row['national_id']); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo strtolower($row['application_status']); ?>">
                                <?php echo $row['application_status']; ?>
                            </span>
                        </td>
                        <td>KES <?php echo number_format($row['monthly_income'] ?? 0); ?></td>
                        <td>
                            <div class="action-cell">
                                <a href="edit_applicant.php?id=<?php echo $row['applicant_id']; ?>" class="edit-btn">✏️ Edit</a>
                                <a href="../backend/delete_applicant.php?id=<?php echo $row['applicant_id']; ?>" class="delete-btn" onclick="return confirm('Are you sure?')">🗑️ Delete</a>
                                <?php if($row['application_status'] == 'Approved'): ?>
                                    <span class="approved-badge">✅ Approved</span>
                                <?php elseif($row['application_status'] == 'Rejected'): ?>
                                    <span class="approved-badge" style="background:#fee2e2;color:#7f1d1d;">❌ Rejected</span>
                                <?php else: ?>
                                    <a href="approve_loan.php?id=<?php echo $row['applicant_id']; ?>" class="approve-btn">💰 Approve Loan</a>
                                    <a href="../backend/reject_applicant.php?id=<?php echo $row['applicant_id']; ?>" class="delete-btn" onclick="return confirm('Reject this application?')">❌ Reject</a>
                                <?php endif; ?>
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
