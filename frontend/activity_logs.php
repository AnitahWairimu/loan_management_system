<?php

session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: login.html");
    exit();
}

include "../backend/db.php";

$logs = $conn->query(
    "SELECT 
        activity_logs.*,
        admin.admin_name
    FROM activity_logs
    JOIN admin ON activity_logs.admin_id = admin.admin_id
    ORDER BY action_date DESC"
);

$totalLogs = $conn->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();

?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .logs-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .logs-header h1 {
            font-size: 28px;
            color: #1f2937;
            font-weight: 600;
        }

        .logs-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .log-stat-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        .log-stat-box .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #0f9d58;
        }

        .log-stat-box .stat-label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }

        .logs-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .action-type {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .action-type.create { background: #d1fae5; color: #065f46; }
        .action-type.update { background: #dbeafe; color: #0c4a6e; }
        .action-type.delete { background: #fee2e2; color: #7f1d1d; }
        .action-type.approve { background: #fef3c7; color: #92400e; }
        .action-type.other { background: #f3f4f6; color: #374151; }
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
            <li><a href="repayments.php">💳 Repayments</a></li>
            <li><a href="reports.php">📊 Reports</a></li>
            <li><a class="active" href="activity_logs.php">📜 Activity Logs</a></li>
            <li><a href="profile.php">👤 Profile</a></li>
            <li><a href="../backend/logout.php">🚪 Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <!-- Header -->
        <div class="logs-header">
            <h1>📜 Activity Logs</h1>
        </div>

        <!-- Stats -->
        <div class="logs-stats">
            <div class="log-stat-box">
                <div class="stat-value"><?php echo $totalLogs; ?></div>
                <div class="stat-label">Total Activities</div>
            </div>
        </div>

        <!-- Logs Table -->
        <div class="logs-table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Admin</th>
                        <th>Action Type</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $logs->fetch(PDO::FETCH_ASSOC)): 
                        $actionType = strtolower(str_replace(' ', '_', $row['action']));
                        $actionClass = 'other';
                        
                        if(strpos($row['action'], 'Created') !== false || strpos($row['action'], 'created') !== false) $actionClass = 'create';
                        elseif(strpos($row['action'], 'Updated') !== false || strpos($row['action'], 'updated') !== false) $actionClass = 'update';
                        elseif(strpos($row['action'], 'Deleted') !== false || strpos($row['action'], 'deleted') !== false) $actionClass = 'delete';
                        elseif(strpos($row['action'], 'Approved') !== false || strpos($row['action'], 'approved') !== false) $actionClass = 'approve';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo date('M d, Y', strtotime($row['action_date'])); ?></strong>
                            <br>
                            <small style="color: #9ca3af;"><?php echo date('H:i:s', strtotime($row['action_date'])); ?></small>
                        </td>
                        <td>
                            <strong><?php echo $row['admin_name']; ?></strong>
                        </td>
                        <td>
                            <span class="action-type <?php echo $actionClass; ?>">
                                <?php echo $row['action']; ?>
                            </span>
                        </td>
                        <td><?php echo $row['description']; ?></td>
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