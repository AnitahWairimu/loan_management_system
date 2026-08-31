<?php

session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: login.html");
    exit();
}

include "../backend/db.php";

$admin_id = $_SESSION['admin_id'];

// Fetch admin details
$stmt = $conn->prepare("SELECT * FROM admin WHERE admin_id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Get admin statistics
$totalLoans = $conn->query("SELECT COUNT(*) FROM loans")->fetchColumn();
$activeLoans = $conn->query("SELECT COUNT(*) FROM loans WHERE status='Active'")->fetchColumn();
$totalApplicants = $conn->query("SELECT COUNT(*) FROM loan_applicants WHERE is_deleted=0")->fetchColumn();
$recentActivity = $conn->query("SELECT COUNT(*) FROM activity_logs WHERE admin_id=$admin_id AND DATE(action_date) = CURDATE()")->fetchColumn();

// Handle password change
$passwordMessage = '';
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])){
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Verify current password
    if(password_verify($currentPassword, $admin['password'])){
        if($newPassword === $confirmPassword){
            if(strlen($newPassword) >= 6){
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $conn->prepare("UPDATE admin SET password = ? WHERE admin_id = ?");
                if($updateStmt->execute([$hashedPassword, $admin_id])){
                    $passwordMessage = '<div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;">✅ Password changed successfully!</div>';
                    // Refresh admin data
                    $stmt->execute([$admin_id]);
                    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            } else {
                $passwordMessage = '<div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #dc3545;">❌ Password must be at least 6 characters!</div>';
            }
        } else {
            $passwordMessage = '<div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #dc3545;">❌ Passwords do not match!</div>';
        }
    } else {
        $passwordMessage = '<div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #dc3545;">❌ Current password is incorrect!</div>';
    }
}

?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .profile-container {
            padding: 30px;
            background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
            min-height: 100vh;
        }

        .profile-header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }

        .profile-header h1 {
            color: #1f2937;
            margin-bottom: 5px;
            font-size: 28px;
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

        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .profile-sidebar {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #0f9d58, #087443);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 48px;
            color: white;
            box-shadow: 0 4px 12px rgba(15, 157, 88, 0.3);
        }

        .profile-name {
            color: #1f2937;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .profile-role {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .profile-email {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #0f9d58;
        }

        .profile-email-label {
            color: #6b7280;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .profile-email-value {
            color: #1f2937;
            font-size: 14px;
            word-break: break-all;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .stat-item {
            background: linear-gradient(135deg, #f0fdf4 0%, #f0fdf4 100%);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #10b981;
        }

        .stat-value {
            color: #0f9d58;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #6b7280;
            font-size: 12px;
            font-weight: 600;
        }

        .profile-main {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .card h2 {
            color: #0f9d58;
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
        }

        .info-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 20px;
        }

        .info-group {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            color: #6b7280;
            font-weight: 600;
            font-size: 12px;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            color: #1f2937;
            font-weight: 600;
            font-size: 15px;
            padding: 12px;
            background: #f9fafb;
            border-radius: 8px;
            border-left: 3px solid #0f9d58;
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

        .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #0f9d58;
            box-shadow: 0 0 0 3px rgba(15, 157, 88, 0.1);
        }

        .password-requirements {
            background: #f0fdf4;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            border-left: 4px solid #10b981;
            font-size: 13px;
            color: #059669;
        }

        .form-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 25px;
        }

        .btn-submit {
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

        .btn-reset {
            padding: 12px;
            background: #6b7280;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-reset:hover {
            background: #4b5563;
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }

            .info-row {
                grid-template-columns: 1fr;
            }

            .form-buttons {
                grid-template-columns: 1fr;
            }

            .profile-container {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<div class="profile-container">
    <a href="dashboard.php" class="back-link">← Back to Dashboard</a>

    <!-- Header -->
    <div class="profile-header">
        <h1>👤 Admin Profile</h1>
        <p style="color: #6b7280;">Manage your account settings and preferences</p>
    </div>

    <!-- Profile Grid -->
    <div class="profile-grid">
        <!-- Left Sidebar -->
        <div class="profile-sidebar">
            <div class="profile-avatar">👨‍💼</div>
            <div class="profile-name"><?php echo htmlspecialchars($admin['admin_name']); ?></div>
            <div class="profile-role">Administrator</div>
            
            <div class="profile-email">
                <div class="profile-email-label">Email Address</div>
                <div class="profile-email-value"><?php echo htmlspecialchars($admin['admin_email']); ?></div>
            </div>

            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $totalLoans; ?></div>
                    <div class="stat-label">Total Loans</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $activeLoans; ?></div>
                    <div class="stat-label">Active Loans</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $totalApplicants; ?></div>
                    <div class="stat-label">Applicants</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $recentActivity; ?></div>
                    <div class="stat-label">Today's Actions</div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="profile-main">
            <!-- Profile Information -->
            <div class="card">
                <h2>📋 Profile Information</h2>
                
                <div class="info-row">
                    <div class="info-group">
                        <span class="info-label">Admin Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($admin['admin_name']); ?></span>
                    </div>
                    <div class="info-group">
                        <span class="info-label">Email Address</span>
                        <span class="info-value"><?php echo htmlspecialchars($admin['admin_email']); ?></span>
                    </div>
                </div>

                <div class="info-row">
                    <div class="info-group">
                        <span class="info-label">Admin ID</span>
                        <span class="info-value">#<?php echo $admin['admin_id']; ?></span>
                    </div>
                    <div class="info-group">
                        <span class="info-label">Account Status</span>
                        <span class="info-value">
                            <span style="background: #d1fae5; color: #065f46; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                ✅ Active
                            </span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Change Password -->
            <div class="card">
                <h2>🔐 Change Password</h2>
                
                <?php echo $passwordMessage; ?>

                <form method="POST">
                    <input type="hidden" name="change_password" value="1">
                    
                    <div class="form-group">
                        <label>Current Password *</label>
                        <input type="password" name="current_password" placeholder="Enter your current password" required>
                    </div>

                    <div class="form-group">
                        <label>New Password *</label>
                        <input type="password" name="new_password" placeholder="Enter new password" required>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <input type="password" name="confirm_password" placeholder="Confirm your new password" required>
                    </div>

                    <div class="password-requirements">
                        ✓ Password must be at least 6 characters<br>
                        ✓ Use a mix of letters, numbers, and symbols for security<br>
                        ✓ Passwords are securely hashed and never stored in plain text
                    </div>

                    <div class="form-buttons">
                        <button type="submit" class="btn-submit">💾 Update Password</button>
                        <button type="reset" class="btn-reset">Clear Form</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

</body>

</html>