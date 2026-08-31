<?php
session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: login.html");
    exit();
}

include "../backend/db.php";
include "../backend/helpers.php";

$selectedMonth = (int)($_GET['month'] ?? date('n'));
$selectedYear = (int)($_GET['year'] ?? date('Y'));
$selectedMonth = min(max($selectedMonth, 1), 12);
$selectedYear = min(max($selectedYear, 2000), 2100);

$report = getMonthlyReportData($conn, $selectedYear, $selectedMonth);
$issueDateCol = loanColumn($conn, 'issue_date');
$totalCol = loanColumn($conn, 'total');
$statusCol = loanColumn($conn, 'status');

$performanceRows = $conn->prepare(
    "SELECT MONTH(`$issueDateCol`) AS month_number,
            DATE_FORMAT(`$issueDateCol`, '%b') AS month_name,
            COUNT(*) AS loans,
            COALESCE(SUM(loan_amount), 0) AS loaned
     FROM loans
     WHERE YEAR(`$issueDateCol`) = ?
     GROUP BY MONTH(`$issueDateCol`), DATE_FORMAT(`$issueDateCol`, '%b')
     ORDER BY MONTH(`$issueDateCol`)"
);
$performanceRows->execute([$selectedYear]);
$performance = $performanceRows->fetchAll(PDO::FETCH_ASSOC);
$performanceByMonth = array_fill(1, 12, ['loaned' => 0.0, 'loans' => 0]);
foreach ($performance as $row) {
    $performanceByMonth[(int)$row['month_number']] = [
        'loaned' => (float)$row['loaned'],
        'loans' => (int)$row['loans'],
    ];
}

$statusBreakdown = $conn->query(
    "SELECT `$statusCol` AS loan_status, COUNT(*) AS count
     FROM loans
     GROUP BY `$statusCol`"
)->fetchAll(PDO::FETCH_ASSOC);

$trendDays = array_fill(1, (int)date('t', strtotime($report['start'])), 0);
foreach ($report['repaymentTrend'] as $row) {
    $trendDays[(int)$row['day']] = (float)$row['total'];
}

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$cards = [
    ['New Clients Registered', $report['clients'], 'primary'],
    ['Loans Issued', $report['loans'], 'success'],
    ['Total Amount Loaned', 'KES ' . number_format($report['loaned']), 'primary'],
    ['Repayments Received', 'KES ' . number_format($report['repayments']), 'success'],
    ['Interest Earned', 'KES ' . number_format($report['interest']), 'warning'],
    ['Active Loans', $report['active'], 'primary'],
    ['Outstanding Loan Balance', 'KES ' . number_format($report['outstanding']), 'warning'],
    ['Overdue Loans', $report['overdue'], 'danger'],
    ['Rejected Applicants', $report['rejected'], 'danger'],
];

// Overall (all-time) totals for the top-level dashboard summary
$overallApplicants = $conn->query("SELECT COUNT(*) FROM loan_applicants WHERE is_deleted = 0")->fetchColumn();
$overallActiveLoans = $conn->query("SELECT COUNT(*) FROM loans WHERE `$statusCol` IN ('Active','Overdue')")->fetchColumn();
$overallLoanedAmount = $conn->query("SELECT COALESCE(SUM(loan_amount),0) FROM loans")->fetchColumn();

$applicantsByMonth = getApplicantsMonthlyBreakdown($conn, $selectedYear);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Loan Management System</title>
    <link rel="stylesheet" href="../css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
</head>
<body>

<div class="container">
    <div class="sidebar">
        <h2 class="logo">🏦Smart Loans</h2>
        <ul>
            <li><a class="active"href="dashboard.php">🏠 Dashboard</a></li>
            <li><a href="applicants.php">👥 Applicants</a></li>
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
        <div class="topbar">
            <button id="menuToggle" class="menu-btn">&#9776;</button>
            <h1>Monthly Dashboard</h1>
            <span>Welcome, <strong><?php echo h($_SESSION['admin_name']); ?></strong></span>
        </div>

        <div class="kpi-grid" style="margin-bottom:20px;">
            <div class="kpi-card success">
                <div class="kpi-label">Total Applicants (All Time)</div>
                <div class="kpi-value"><?php echo $overallApplicants; ?></div>
            </div>
            <div class="kpi-card primary">
                <div class="kpi-label">Total Active Loans</div>
                <div class="kpi-value"><?php echo $overallActiveLoans; ?></div>
            </div>
            <div class="kpi-card warning">
                <div class="kpi-label">Total Loan Amount Issued</div>
                <div class="kpi-value">KES <?php echo number_format($overallLoanedAmount); ?></div>
            </div>
        </div>

        <div class="action-buttons" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px;">
            <a href="reports.php?report_type=monthly" class="btn btn-primary" style="text-decoration:none;">📊 Monthly Report</a>
            <a href="reports.php?report_type=yearly" class="btn btn-primary" style="text-decoration:none;">📈 Yearly Report</a>
            <a href="loan_plans.php" class="btn btn-secondary" style="text-decoration:none;">🧾 Manage Loan Plans</a>
        </div>

        <form method="GET" class="filter-panel">
            <div>
                <label>Month</label>
                <select name="month">
                    <?php foreach($months as $number => $name): ?>
                        <option value="<?php echo $number; ?>" <?php echo $number === $selectedMonth ? 'selected' : ''; ?>>
                            <?php echo $name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Year</label>
                <input type="number" name="year" value="<?php echo $selectedYear; ?>" min="2000" max="2100">
            </div>
            <button type="submit" class="btn btn-primary">Apply Filter</button>
        </form>

        <div class="section-heading">
            <h2><?php echo $months[$selectedMonth] . ' ' . $selectedYear; ?> Overview</h2>
            <p><?php echo date('M d', strtotime($report['start'])); ?> to <?php echo date('M d, Y', strtotime($report['end'])); ?></p>
        </div>
        

        <div class="kpi-grid">
            <?php foreach($cards as $card): ?>
                <div class="kpi-card <?php echo $card[2]; ?>">
                    <div class="kpi-label"><?php echo h($card[0]); ?></div>
                    <div class="kpi-value"><?php echo h($card[1]); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="recent-section" style="margin-bottom:24px;">
            <h2>Applicants Registered by Month — <?php echo $selectedYear; ?></h2>
            <table>
                <thead>
                    <tr>
                        <?php foreach($months as $name): ?><th><?php echo substr($name, 0, 3); ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <?php foreach($applicantsByMonth as $count): ?><td><?php echo $count; ?></td><?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="charts-section dashboard-charts">
            <div class="chart-card">
                <div class="chart-title">Loan Activity (<?php echo $selectedYear; ?>)</div>
                <p class="chart-description">Amount issued and number of loans, month by month.</p>
                <div class="chart-container"><canvas id="loanPerformanceChart"></canvas></div>
            </div>
            <div class="chart-card">
                <div class="chart-title">Repayment Trend (<?php echo $months[$selectedMonth]; ?>)</div>
                <p class="chart-description">Hover over a point to see the exact repayment received that day.</p>
                <div class="chart-container"><canvas id="repaymentTrendChart"></canvas></div>
            </div>
            <div class="chart-card">
                <div class="chart-title">Current Loan Status</div>
                <p class="chart-description">Number of loans in each status.</p>
                <div class="chart-container"><canvas id="statusChart"></canvas></div>
            </div>
        </div>
        

        
        <div class="dashboard-tables">
            <div class="recent-section">
                <h2>Recent Loans</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Loan ID</th>
                            <th>Client</th>
                            <th>Amount</th>
                            <th>Starting Balance</th>
                            <th>Status</th>
                            <th>Issue Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($report['recentLoans'])): ?>
                            <tr><td colspan="6">No loans issued for this month.</td></tr>
                        <?php endif; ?>
                        <?php foreach($report['recentLoans'] as $loan): ?>
                            <tr>
                                <td>#<?php echo h($loan['loan_id']); ?></td>
                                <td><?php echo h($loan['full_name'] ?? 'Unknown'); ?></td>
                                <td>KES <?php echo number_format($loan['loan_amount']); ?></td>
                                <td>KES <?php echo number_format($loan['total_repayment']); ?></td>
                                <td><span class="status-badge status-<?php echo strtolower(h($loan['loan_status'])); ?>"><?php echo h($loan['loan_status']); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($loan['start_date'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="recent-section">
                <h2>Recent Repayments</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Payment ID</th>
                            <th>Client</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($report['recentRepayments'])): ?>
                            <tr><td colspan="5">No repayments received for this month.</td></tr>
                        <?php endif; ?>
                        <?php foreach($report['recentRepayments'] as $payment): ?>
                            <tr>
                                <td>#<?php echo h($payment['repayment_id']); ?></td>
                                <td><?php echo h($payment['full_name'] ?? 'Unknown'); ?></td>
                                <td>KES <?php echo number_format($payment['payment_amount']); ?></td>
                                <td><?php echo h($payment['payment_method']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const moneyTicks = value => 'KES ' + new Intl.NumberFormat('en-KE').format(value);
const moneyTooltip = context => moneyTicks(context.parsed.y);
const monthLabels = <?php echo json_encode(array_values($months)); ?>;
const monthlyLoaned = <?php echo json_encode(array_values(array_map(fn($item) => $item['loaned'], $performanceByMonth))); ?>;
const monthlyLoanCounts = <?php echo json_encode(array_values(array_map(fn($item) => $item['loans'], $performanceByMonth))); ?>;

new Chart(document.getElementById('loanPerformanceChart'), {
    type: 'bar',
    data: {
        labels: monthLabels,
        datasets: [
            {
                label: 'Amount Issued',
                data: monthlyLoaned,
                backgroundColor: '#3b82f6',
                borderRadius: 6,
                yAxisID: 'amount'
            },
            {
                type: 'line',
                label: 'Loans Issued',
                data: monthlyLoanCounts,
                borderColor: '#0f9d58',
                backgroundColor: '#0f9d58',
                pointRadius: 3,
                pointHoverRadius: 5,
                tension: .3,
                yAxisID: 'count'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { tooltip: { callbacks: { label: context => context.dataset.yAxisID === 'amount' ? `${context.dataset.label}: ${moneyTooltip(context)}` : `${context.dataset.label}: ${context.parsed.y}` } } },
        scales: {
            amount: { beginAtZero: true, position: 'left', ticks: { callback: moneyTicks } },
            count: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0 } }
        }
    }
});

new Chart(document.getElementById('repaymentTrendChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_keys($trendDays)); ?>,
        datasets: [{
            label: 'Repayments',
            data: <?php echo json_encode(array_values($trendDays)); ?>,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16,185,129,.12)',
            fill: true,
            tension: .35
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => `Repayments: ${moneyTooltip(context)}` } } },
        scales: {
            x: { ticks: { maxTicksLimit: 7, maxRotation: 0 } },
            y: { beginAtZero: true, ticks: { callback: moneyTicks } }
        }
    }
});

new Chart(document.getElementById('statusChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($statusBreakdown, 'loan_status')); ?>,
        datasets: [{
            label: 'Loans',
            data: <?php echo json_encode(array_map('intval', array_column($statusBreakdown, 'count'))); ?>,
            backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444'],
            borderRadius: 6
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => `Loans: ${context.parsed.x}` } } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } }
    }
});
</script>
<script src="../js/script.js"></script>
</body>
</html>
