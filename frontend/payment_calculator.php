<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.html');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Plan Calculator</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .calculator-page { max-width: 1180px; margin: 0 auto; }
        .calculator-intro { margin-bottom: 24px; }
        .calculator-intro h2 { color: #1f2937; margin-bottom: 6px; }
        .calculator-intro p { color: #6b7280; margin: 0; }
        .calculator-grid { display: grid; grid-template-columns: minmax(280px, 360px) 1fr; gap: 22px; align-items: start; }
        .calculator-panel, .summary-panel, .schedule-panel { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .calculator-panel { padding: 24px; }
        .calculator-panel h3, .schedule-panel h3 { color: #1f2937; margin: 0 0 18px; }
        .calculator-panel .form-group { margin-bottom: 16px; }
        .calculator-panel label { display: block; color: #374151; font-weight: 600; margin-bottom: 6px; }
        .calculator-panel input { width: 100%; box-sizing: border-box; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 15px; }
        .calculator-panel input:focus { outline: 2px solid rgba(15,157,88,.2); border-color: #0f9d58; }
        .calculator-actions { display: flex; gap: 10px; margin-top: 22px; }
        .calculator-actions button { flex: 1; border: 0; border-radius: 6px; padding: 11px 12px; cursor: pointer; font-weight: 700; }
        .calculate-btn { background: #0f9d58; color: #fff; }
        .clear-btn { background: #eef2f7; color: #374151; }
        .summary-panel { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; background: #dfe7e2; margin-bottom: 22px; overflow: hidden; }
        .summary-item { background: #fff; padding: 18px; }
        .summary-item span { display: block; color: #6b7280; font-size: 12px; margin-bottom: 6px; }
        .summary-item strong { color: #0f7a47; font-size: 20px; }
        .schedule-panel { padding: 24px; }
        .schedule-heading { display: flex; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 14px; }
        .schedule-heading button { border: 0; background: #e8f5ee; color: #0f7a47; border-radius: 6px; padding: 9px 12px; cursor: pointer; font-weight: 700; }
        .schedule-table { width: 100%; border-collapse: collapse; }
        .schedule-table th, .schedule-table td { text-align: left; padding: 11px 8px; border-bottom: 1px solid #edf0ee; }
        .schedule-table th { color: #6b7280; font-size: 12px; text-transform: uppercase; }
        .schedule-table td { color: #374151; }
        .empty-schedule { color: #6b7280; padding: 16px 0; }
        @media (max-width: 820px) {
            .calculator-grid { grid-template-columns: 1fr; }
            .summary-panel { grid-template-columns: repeat(2, 1fr); }
        }
        @media print {
            .sidebar, .topbar, .calculator-panel, .schedule-heading button, .menu-btn { display: none !important; }
            .main-content { margin: 0; padding: 0; }
            .calculator-page { max-width: none; }
            .summary-panel, .schedule-panel { box-shadow: none; border: 1px solid #d1d5db; }
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
            <li><a class="active" href="payment_calculator.php">🧮 Payment Calculator</a></li>
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
            <h1>Payment Plan Calculator</h1>
            <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></strong></span>
        </div>

        <main class="calculator-page">
            <div class="calculator-intro">
                <h2>Prepare a payment plan</h2>
                <p>Interest is calculated each month from the remaining principal, so payments reduce over time.</p>
            </div>

            <div class="calculator-grid">
                <section class="calculator-panel">
                    <h3>Loan terms</h3>
                    <div class="form-group">
                        <label for="clientName">Client name</label>
                        <input id="clientName" type="text" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label for="loanAmount">Loan amount (KES)</label>
                        <input id="loanAmount" type="number" min="1" step="0.01" value="10000">
                    </div>
                    <div class="form-group">
                        <label for="interestRate">Interest rate per month (%)</label>
                        <input id="interestRate" type="number" min="0" step="0.01" value="5">
                    </div>
                    <div class="form-group">
                        <label for="months">Repayment period (months)</label>
                        <input id="months" type="number" min="1" step="1" value="6">
                    </div>
                    <div class="calculator-actions">
                        <button class="calculate-btn" type="button" id="calculateBtn">Calculate plan</button>
                        <button class="clear-btn" type="button" id="clearBtn">Clear</button>
                    </div>
                </section>

                <section>
                    <div class="summary-panel" aria-live="polite">
                        <div class="summary-item"><span>Client</span><strong id="summaryClient">Client</strong></div>
                        <div class="summary-item"><span>Total interest</span><strong id="summaryInterest">KES 0.00</strong></div>
                        <div class="summary-item"><span>Total to repay</span><strong id="summaryTotal">KES 0.00</strong></div>
                        <div class="summary-item"><span>Average payment</span><strong id="summaryMonthly">KES 0.00</strong></div>
                    </div>
                    <div class="schedule-panel">
                        <div class="schedule-heading">
                            <h3>Suggested repayment schedule</h3>
                            <button type="button" id="printBtn">Print / Save PDF</button>
                        </div>
                        <div id="scheduleOutput" class="empty-schedule">Enter the terms to see the schedule.</div>
                    </div>
                </section>
            </div>
        </main>
    </div>
</div>
<script>
const amountInput = document.getElementById('loanAmount');
const rateInput = document.getElementById('interestRate');
const monthsInput = document.getElementById('months');
const clientInput = document.getElementById('clientName');
const money = value => 'KES ' + value.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function calculatePlan() {
    const amount = Math.round((Number(amountInput.value) || 0) * 100) / 100;
    const rate = Number(rateInput.value) || 0;
    const months = Math.floor(Number(monthsInput.value) || 0);
    const client = clientInput.value.trim() || 'Client';
    const output = document.getElementById('scheduleOutput');

    document.getElementById('summaryClient').textContent = client;
    if (amount <= 0 || rate < 0 || months < 1) {
        document.getElementById('summaryInterest').textContent = money(0);
        document.getElementById('summaryTotal').textContent = money(0);
        document.getElementById('summaryMonthly').textContent = money(0);
        output.textContent = 'Enter a valid amount, interest rate, and repayment period.';
        return;
    }

    const principalPayment = Math.round((amount / months) * 100) / 100;
    let totalInterest = 0;
    let totalRepayment = 0;
    let balance = amount;
    let rows = '';

    for (let month = 1; month <= months; month++) {
        const principal = month === months ? Math.round((amount - principalPayment * (months - 1)) * 100) / 100 : principalPayment;
        const interest = Math.round(balance * (rate / 100) * 100) / 100;
        const payment = Math.round((principal + interest) * 100) / 100;
        balance = Math.max(Math.round((balance - principal) * 100) / 100, 0);
        totalInterest = Math.round((totalInterest + interest) * 100) / 100;
        totalRepayment = Math.round((totalRepayment + payment) * 100) / 100;
        rows += `<tr><td>${month}</td><td>${money(interest)}</td><td>${money(principal)}</td><td>${money(payment)}</td><td>${money(balance)}</td></tr>`;
    }

    const averagePayment = Math.round((totalRepayment / months) * 100) / 100;
    document.getElementById('summaryInterest').textContent = money(totalInterest);
    document.getElementById('summaryTotal').textContent = money(totalRepayment);
    document.getElementById('summaryMonthly').textContent = money(averagePayment);

    output.innerHTML = `<table class="schedule-table"><thead><tr><th>Month</th><th>Interest</th><th>Principal</th><th>Payment</th><th>Principal remaining</th></tr></thead><tbody>${rows}</tbody></table>`;
}

document.getElementById('calculateBtn').addEventListener('click', calculatePlan);
[amountInput, rateInput, monthsInput, clientInput].forEach(input => input.addEventListener('input', calculatePlan));
document.getElementById('clearBtn').addEventListener('click', () => {
    clientInput.value = '';
    amountInput.value = '';
    rateInput.value = '';
    monthsInput.value = '';
    calculatePlan();
});
document.getElementById('printBtn').addEventListener('click', () => window.print());

const menuBtn = document.getElementById('menuToggle');
if (menuBtn) {
    const sidebar = document.querySelector('.sidebar');
    const main = document.querySelector('.main-content');
    menuBtn.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
        main.classList.toggle('expanded');
    });
}
calculatePlan();
</script>
</body>
</html>
