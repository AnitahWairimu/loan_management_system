<?php
session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: login.html");
    exit();
}

include "../backend/db.php";
include "../backend/helpers.php";

$plans = $conn->query("SELECT * FROM loan_plans ORDER BY is_active DESC, loan_amount ASC")->fetchAll(PDO::FETCH_ASSOC);

// How many loans currently use each plan (for the "in use" hint)
$usage = [];
if (tableHasColumn($conn, 'loans', 'plan_id')) {
    $usageRows = $conn->query("SELECT plan_id, COUNT(*) AS total FROM loans WHERE plan_id IS NOT NULL GROUP BY plan_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($usageRows as $row) {
        $usage[$row['plan_id']] = (int)$row['total'];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Plans</title>
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
        .header-section h1 { font-size: 28px; color: #1f2937; font-weight: 600; }
        .plans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
        }
        .plan-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 22px;
            border-top: 4px solid #0f9d58;
            position: relative;
        }
        .plan-card.inactive { border-top-color: #9ca3af; opacity: 0.7; }
        .plan-card h3 { font-size: 18px; color: #1f2937; margin-bottom: 12px; }
        .plan-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 14px;
            border-bottom: 1px solid #f3f4f6;
        }
        .plan-row span:first-child { color: #6b7280; }
        .plan-row span:last-child { font-weight: 600; color: #1f2937; }
        .plan-desc { font-size: 13px; color: #6b7280; margin: 10px 0; }
        .plan-actions { display: flex; gap: 8px; margin-top: 14px; }
        .plan-actions a, .plan-actions button {
            flex: 1;
            padding: 8px;
            font-size: 13px;
            border-radius: 6px;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            border: none;
        }
        .plan-actions .edit-btn { background: #3b82f6; color: white; }
        .plan-actions .delete-btn { background: #ef4444; color: white; }
        .inactive-tag {
            position: absolute;
            top: 16px;
            right: 16px;
            background: #f3f4f6;
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
        }
        .usage-tag { font-size: 12px; color: #6b7280; margin-top: 8px; }
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
            <li><a class="active" href="loan_plans.php">🧾 Loan Plans</a></li>
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
            <h1>Loan Plans</h1>
            <span>Welcome, <strong><?php echo h($_SESSION['admin_name']); ?></strong></span>
        </div>

        <?php if(isset($_SESSION['success'])): ?>
        <div class="success-message"><?php echo h($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if(isset($_SESSION['error'])): ?>
        <div class="error-message"><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <div class="header-section">
            <h1>🧾 Loan Plans</h1>
            <button id="addPlanBtn" class="btn">+ New Loan Plan</button>
        </div>

        <div class="plans-grid">
            <?php if(empty($plans)): ?>
                <p>No loan plans yet. Create one to start assigning plans to approved applicants.</p>
            <?php endif; ?>

            <?php foreach($plans as $plan): ?>
                <div class="plan-card <?php echo $plan['is_active'] ? '' : 'inactive'; ?>">
                    <?php if(!$plan['is_active']): ?><span class="inactive-tag">INACTIVE</span><?php endif; ?>
                    <h3><?php echo h($plan['plan_name']); ?></h3>
                    <div class="plan-row"><span>Loan Amount</span><span>KES <?php echo number_format($plan['loan_amount']); ?></span></div>
                    <div class="plan-row"><span>Interest Rate</span><span><?php echo h($plan['interest_rate']); ?>%</span></div>
                    <div class="plan-row"><span>Repayment Period</span><span><?php echo h($plan['repayment_period_months']); ?> months</span></div>
                    <div class="plan-row"><span>Average Monthly Payment</span><span>KES <?php echo number_format(calculateLoanPlanInstallment($plan['loan_amount'], $plan['interest_rate'], $plan['repayment_period_months']), 2); ?></span></div>
                    <?php if(!empty($plan['description'])): ?>
                        <p class="plan-desc"><?php echo h($plan['description']); ?></p>
                    <?php endif; ?>
                    <?php if(!empty($usage[$plan['plan_id']])): ?>
                        <div class="usage-tag">Used by <?php echo $usage[$plan['plan_id']]; ?> issued loan(s)</div>
                    <?php endif; ?>
                    <div class="plan-actions">
                        <a href="#" class="edit-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($plan), ENT_QUOTES); ?>); return false;">✏️ Edit</a>
                        <a href="../backend/delete_loan_plan.php?id=<?php echo $plan['plan_id']; ?>" class="delete-btn" onclick="return confirm('Delete or deactivate this loan plan?')">🗑️ Remove</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Add Plan Modal -->
        <div class="modal" id="addPlanModal">
            <div class="modal-content">
                <h2>➕ New Loan Plan</h2>
                <form action="../backend/add_loan_plan.php" method="POST">
                    <div class="form-group">
                        <label>Plan Name *</label>
                        <input type="text" name="plan_name" required placeholder="e.g., Standard - 6 Month">
                    </div>
                    <div class="form-group">
                        <label>Loan Amount (KES) *</label>
                        <input type="number" step="0.01" min="1" name="loan_amount" required>
                    </div>
                    <div class="form-group">
                        <label>Interest Rate (%) *</label>
                        <input type="number" step="0.01" min="0.1" name="interest_rate" required>
                    </div>
                    <div class="form-group">
                        <label>Repayment Period (months) *</label>
                        <input type="number" min="1" name="repayment_period_months" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="2" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;"></textarea>
                    </div>
                    <button type="submit" class="btn" style="width:100%;margin-top:10px;">💾 Save Plan</button>
                </form>
            </div>
        </div>

        <!-- Edit Plan Modal -->
        <div class="modal" id="editPlanModal">
            <div class="modal-content">
                <h2>✏️ Edit Loan Plan</h2>
                <form action="../backend/update_loan_plan.php" method="POST">
                    <input type="hidden" name="plan_id" id="edit_plan_id">
                    <div class="form-group">
                        <label>Plan Name *</label>
                        <input type="text" name="plan_name" id="edit_plan_name" required>
                    </div>
                    <div class="form-group">
                        <label>Loan Amount (KES) *</label>
                        <input type="number" step="0.01" min="1" name="loan_amount" id="edit_loan_amount" required>
                    </div>
                    <div class="form-group">
                        <label>Interest Rate (%) *</label>
                        <input type="number" step="0.01" min="0.1" name="interest_rate" id="edit_interest_rate" required>
                    </div>
                    <div class="form-group">
                        <label>Repayment Period (months) *</label>
                        <input type="number" min="1" name="repayment_period_months" id="edit_repayment_period_months" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="edit_description" rows="2" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;"></textarea>
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" name="is_active" id="edit_is_active" style="width:auto;"> Active (assignable to applicants)</label>
                    </div>
                    <button type="submit" class="btn" style="width:100%;margin-top:10px;">💾 Update Plan</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const addBtn = document.getElementById('addPlanBtn');
const addModal = document.getElementById('addPlanModal');
const editModal = document.getElementById('editPlanModal');

addBtn.onclick = () => { addModal.classList.add('show'); addModal.style.display = 'flex'; };

function openEditModal(plan) {
    document.getElementById('edit_plan_id').value = plan.plan_id;
    document.getElementById('edit_plan_name').value = plan.plan_name;
    document.getElementById('edit_loan_amount').value = plan.loan_amount;
    document.getElementById('edit_interest_rate').value = plan.interest_rate;
    document.getElementById('edit_repayment_period_months').value = plan.repayment_period_months;
    document.getElementById('edit_description').value = plan.description || '';
    document.getElementById('edit_is_active').checked = plan.is_active == 1;
    editModal.classList.add('show');
    editModal.style.display = 'flex';
}

window.addEventListener('click', function(event) {
    if (event.target === addModal) { addModal.classList.remove('show'); addModal.style.display = 'none'; }
    if (event.target === editModal) { editModal.classList.remove('show'); editModal.style.display = 'none'; }
});

const menuBtn = document.getElementById("menuToggle");
if (menuBtn) {
    const sidebar = document.querySelector(".sidebar");
    const main = document.querySelector(".main-content");
    menuBtn.addEventListener("click", function () {
        sidebar.classList.toggle("collapsed");
        main.classList.toggle("expanded");
    });
}
</script>
</body>
</html>
