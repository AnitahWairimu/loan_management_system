<?php

session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: login.html");
    exit();
}

include "../backend/db.php";
include "../backend/helpers.php";

$id = $_GET['id'];

$stmt = $conn->prepare("SELECT * FROM loan_applicants WHERE applicant_id=?");
$stmt->execute([$id]);
$applicant = $stmt->fetch(PDO::FETCH_ASSOC);

if($applicant['is_deleted'] == 1){
    die("This applicant has been deleted.");
}

if(!$applicant){
    die("Applicant not found.");
}

if($applicant['application_status'] == 'Approved'){
    die("This applicant has already been approved.");
}

$stmt = $conn->prepare("SELECT COUNT(*) FROM loans WHERE `" . loanColumn($conn, 'client_id') . "`=? AND `" . loanColumn($conn, 'status') . "` IN ('Active', 'Overdue')");
$stmt->execute([$id]);
$activeLoan = $stmt->fetchColumn();

if($activeLoan > 0){
    die("Applicant already has an active loan.");
}

$loanPlans = getActiveLoanPlans($conn);

?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Loan</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .approve-container {
            max-width: 700px;
            margin: 30px auto;
            padding: 20px;
        }

        .approve-card {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
        }

        .approve-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .approve-header h1 {
            color: #0f9d58;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .applicant-info {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .applicant-info .info-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .applicant-info .info-item:last-child {
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

        .form-section {
            margin-bottom: 25px;
        }

        .form-section-title {
            color: #0f9d58;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }

        .form-group {
            margin-bottom: 20px;
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

        .summary-section {
            background: linear-gradient(135deg, #f0fdf4 0%, #f0fdf4 100%);
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #10b981;
            margin-bottom: 25px;
        }

        .summary-title {
            color: #0f9d58;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            color: #1f2937;
        }

        .summary-item .label {
            color: #6b7280;
        }

        .summary-item .value {
            font-weight: 700;
            color: #0f9d58;
        }

        .form-buttons {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }

        .btn-approve {
            flex: 1;
            padding: 14px;
            background: #10b981;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-approve:hover {
            background: #059669;
            transform: translateY(-2px);
        }

        .btn-cancel {
            flex: 1;
            padding: 14px;
            background: #6b7280;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
        }

        .btn-cancel:hover {
            background: #4b5563;
            transform: translateY(-2px);
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

        .input-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        @media (max-width: 768px) {
            .input-group {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="approve-container">
    <a href="applicants.php" class="back-link">← Back to Applicants</a>

    <div class="approve-card">
        <div class="approve-header">
            <h1>💰 Approve Loan Application</h1>
            <p style="color: #6b7280;">Complete the loan details below</p>
        </div>

        <!-- Applicant Information -->
        <div class="applicant-info">
            <div class="info-item">
                <span class="info-label">👤 Applicant Name</span>
                    <span class="info-value"><?php echo h($applicant['full_name']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">📱 Phone</span>
                    <span class="info-value"><?php echo h($applicant['phone_number']); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">💼 Monthly Income</span>
                <span class="info-value">KES <?php echo number_format($applicant['monthly_income'] ?? 0); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">💡 Purpose</span>
                <span class="info-value"><?php echo $applicant['loan_purpose'] ?? 'Not specified'; ?></span>
            </div>
        </div>

        <!-- Loan Application Form -->
        <form action="../backend/create_loan.php" method="POST">
            <input type="hidden" name="applicant_id" value="<?php echo $applicant['applicant_id']; ?>">

            <div class="form-section">
                <div class="form-section-title">🧾 Loan Plan</div>
                <div class="form-group">
                    <label>Assign a Loan Plan (optional)</label>
                    <select id="plan_id" name="plan_id">
                        <option value="0">— Custom loan (enter details manually) —</option>
                        <?php foreach($loanPlans as $plan): ?>
                            <option value="<?php echo $plan['plan_id']; ?>"
                                data-amount="<?php echo h($plan['loan_amount']); ?>"
                                data-rate="<?php echo h($plan['interest_rate']); ?>"
                                data-duration="<?php echo h($plan['repayment_period_months']); ?>"
                                data-installment="<?php echo h($plan['monthly_installment']); ?>">
                                <?php echo h($plan['plan_name']); ?> — KES <?php echo number_format($plan['loan_amount']); ?>, <?php echo h($plan['interest_rate']); ?>% / <?php echo h($plan['repayment_period_months']); ?> mo
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if(empty($loanPlans)): ?>
                        <p style="font-size:12px;color:#6b7280;margin-top:6px;">No loan plans yet — <a href="loan_plans.php">create one</a> to assign it here, or enter custom terms below.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">📋 Loan Details</div>

                <div class="input-group">
                    <div class="form-group">
                        <label>Loan Amount (KES) *</label>
                        <input type="number" id="loan_amount" name="loan_amount" placeholder="e.g., 50000" required min="1000" max="10000000">
                    </div>

                    <div class="form-group">
                        <label>Interest Rate (%) *</label>
                        <input type="number" id="interest_rate" name="interest_rate" placeholder="e.g., 12" required min="0.1" max="100" step="0.1">
                    </div>
                </div>

                <div class="form-group">
                    <label>Loan Duration (Months) *</label>
                    <input type="number" id="duration" name="loan_duration_months" placeholder="e.g., 6" required min="1" max="36">
                </div>
            </div>

            <!-- Loan Summary -->
            <div class="summary-section">
                <div class="summary-title">📊 Loan Summary</div>
                <div class="summary-item">
                    <span class="label">Initial Monthly Interest:</span>
                    <span class="value">KES <span id="interest_amount">0</span></span>
                </div>
                <div class="summary-item">
                    <span class="label">Projected Total Repayment:</span>
                    <span class="value">KES <span id="total_repayment">0</span></span>
                </div>
                <div class="summary-item">
                    <span class="label">Recommended Monthly Payment:</span>
                    <span class="value">KES <span id="monthly_payment">0</span></span>
                </div>
            </div>

            <!-- Buttons -->
            <div class="form-buttons">
                <button type="submit" class="btn-approve">✅ Approve & Disburse Loan</button>
                <a href="applicants.php" class="btn-cancel">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
    // Loan calculation script
    const loanAmount = document.getElementById('loan_amount');
    const interestRate = document.getElementById('interest_rate');
    const duration = document.getElementById('duration');
    const interestAmountDisplay = document.getElementById('interest_amount');
    const totalRepaymentDisplay = document.getElementById('total_repayment');
    const monthlyPaymentDisplay = document.getElementById('monthly_payment');
    const planSelect = document.getElementById('plan_id');

    function calculateLoan() {
        const amount = parseFloat(loanAmount.value) || 0;
        const rate = parseFloat(interestRate.value) || 0;
        const months = parseFloat(duration.value) || 0;

        if (amount > 0 && rate >= 0 && months > 0) {
            const principalPayment = amount / months;
            let balance = amount;
            let totalInterest = 0;
            let totalRepayment = 0;

            for (let month = 1; month <= months; month++) {
                const interest = balance * rate / 100;
                const principal = month === months ? balance : principalPayment;
                totalInterest += interest;
                totalRepayment += principal + interest;
                balance -= principal;
            }

            const monthlyPayment = totalRepayment / months;

            interestAmountDisplay.textContent = new Intl.NumberFormat('en-KE').format(Math.round((amount * rate / 100) * 100) / 100);
            totalRepaymentDisplay.textContent = new Intl.NumberFormat('en-KE').format(Math.round(totalRepayment));
            monthlyPaymentDisplay.textContent = new Intl.NumberFormat('en-KE').format(Math.round(monthlyPayment));

        }
    }

    function applyPlanSelection() {
        const option = planSelect.options[planSelect.selectedIndex];
        const usingPlan = planSelect.value !== '0';

        if (usingPlan) {
            loanAmount.value = option.dataset.amount;
            interestRate.value = option.dataset.rate;
            duration.value = option.dataset.duration;
        }

        [loanAmount, interestRate, duration].forEach(field => {
            field.disabled = usingPlan;
            field.required = !usingPlan;
        });

        calculateLoan();
    }

    loanAmount.addEventListener('input', calculateLoan);
    interestRate.addEventListener('input', calculateLoan);
    duration.addEventListener('input', calculateLoan);
    planSelect.addEventListener('change', applyPlanSelection);
    applyPlanSelection();
</script>

</body>

</html>
