<?php

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableHasColumn(PDO $conn, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache) && $cache[$key]) {
        return $cache[$key];
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    $cache[$key] = (bool)$stmt->fetchColumn();

    return $cache[$key];
}

function ensurePaymentVerificationColumns(PDO $conn) {
    $columns = [
        'payment_reference' => 'VARCHAR(100) NULL',
        'verification_status' => "VARCHAR(20) NOT NULL DEFAULT 'Verified'",
        'verified_by' => 'INT NULL',
        'verified_at' => 'DATETIME NULL',
        'rejection_reason' => 'VARCHAR(500) NULL',
    ];

    foreach ($columns as $column => $definition) {
        if (!tableHasColumn($conn, 'repayments', $column)) {
            $conn->exec("ALTER TABLE repayments ADD COLUMN $column $definition");
        }
    }
}

function ensureGatewayPaymentColumns(PDO $conn) {
    $columns = [
        'gateway' => 'VARCHAR(30) NULL',
        'checkout_request_id' => 'VARCHAR(100) NULL',
        'merchant_request_id' => 'VARCHAR(100) NULL',
        'gateway_result_code' => 'VARCHAR(20) NULL',
    ];

    foreach ($columns as $column => $definition) {
        if (!tableHasColumn($conn, 'repayments', $column)) {
            $conn->exec("ALTER TABLE repayments ADD COLUMN $column $definition");
        }
    }
}

function applyVerifiedRepayment(PDO $conn, $repaymentId, $verifiedBy = null, $allowAlreadyVerified = false) {
    ensurePaymentVerificationColumns($conn);

    $alreadyInTransaction = $conn->inTransaction();

    if (!$alreadyInTransaction) {
        $conn->beginTransaction();
    }

    try {
        $paymentStmt = $conn->prepare("SELECT * FROM repayments WHERE repayment_id = ? FOR UPDATE");
        $paymentStmt->execute([(int)$repaymentId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            throw new RuntimeException('Repayment not found.');
        }
        if ($payment['verification_status'] === 'Verified' && !$allowAlreadyVerified) {
            if (!$alreadyInTransaction && $conn->inTransaction()) {
                $conn->commit();
            }
            return false;
        }
        if ($payment['verification_status'] === 'Rejected') {
            throw new RuntimeException('Rejected repayments cannot be verified.');
        }

        $loan = getLoanFinancialSnapshot($conn, (int)$payment['loan_id'], date('Y-m-d'));
        if (!$loan || in_array($loan['loan_status'], ['Completed', 'Rejected'], true)) {
            throw new RuntimeException('The loan is not available for repayment.');
        }

        $amount = round((float)$payment['payment_amount'], 2);
        $accruedInterest = (float)$loan['accrued_interest'];
        $interestDate = null;

        if ($accruedInterest <= 0 && (float)$loan['outstanding_principal'] > 0) {
            $accruedInterest = round((float)$loan['outstanding_principal'] * ((float)$loan['interest_rate'] / 100), 2);
            $interestDate = date('Y-m-d');
        }

        $interestPortion = min($amount, $accruedInterest);
        $principalPortion = min($amount - $interestPortion, (float)$loan['outstanding_principal']);
        $remainingPrincipal = max((float)$loan['outstanding_principal'] - $principalPortion, 0);
        $remainingInterest = max($accruedInterest - $interestPortion, 0);
        $remainingBalance = round($remainingPrincipal + $remainingInterest, 2);

        $loanUpdateSql =
            "UPDATE loans
             SET outstanding_principal = ?, accrued_interest = ?, interest_paid = interest_paid + ?,
                 principal_paid = principal_paid + ?, total_payments = total_payments + ?,
                 outstanding_balance = ?";
        $loanUpdateValues = [
            $remainingPrincipal,
            $remainingInterest,
            $interestPortion,
            $principalPortion,
            $amount,
            $remainingBalance,
        ];

        if ($interestDate !== null && tableHasColumn($conn, 'loans', 'last_interest_calculated_at')) {
            $loanUpdateSql .= ', last_interest_calculated_at = ?';
            $loanUpdateValues[] = $interestDate;
        }

        $loanUpdateSql .= " WHERE loan_id = ?";
        $loanUpdateValues[] = $payment['loan_id'];
        $loanUpdate = $conn->prepare($loanUpdateSql);
        $loanUpdate->execute($loanUpdateValues);

        $paymentUpdate = $conn->prepare(
            "UPDATE repayments
             SET verification_status = 'Verified', verified_by = ?, verified_at = NOW(),
                 interest_portion = ?, principal_portion = ?, remaining_principal = ?,
                  remaining_interest = ?, remaining_balance = ?, balance_after_payment = ?
             WHERE repayment_id = ?"
        );
        $paymentUpdate->execute([
            $verifiedBy,
            $interestPortion,
            $principalPortion,
            $remainingPrincipal,
            $remainingInterest,
            $remainingBalance,
            $remainingBalance,
            $repaymentId,
        ]);

        if (!$alreadyInTransaction && $conn->inTransaction()) {
            $conn->commit();
        }
        return true;
    } catch (Throwable $e) {
        if (!$alreadyInTransaction && $conn->inTransaction()) {
            $conn->rollBack();
        } else if ($alreadyInTransaction) {
            // Let the caller manage the active transaction.
        }
        throw $e;
    }
}

function repaymentVerificationFilter(PDO $conn, $tableAlias = 'repayments') {
    return tableHasColumn($conn, 'repayments', 'verification_status')
        ? "$tableAlias.verification_status = 'Verified'"
        : '1=1';
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function isValidCsrfToken($token) {
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function firstExistingColumn(PDO $conn, $table, array $columns) {
    foreach ($columns as $column) {
        if (tableHasColumn($conn, $table, $column)) {
            return $column;
        }
    }

    return $columns[0];
}

function loanColumn(PDO $conn, $field) {
    $map = [
        'client_id' => ['client_id', 'applicant_id'],
        'duration' => ['loan_duration_months', 'loan_duration'],
        'total' => ['total_payment', 'total_repayment'],
        'issue_date' => ['issue_date', 'start_date'],
        'status' => ['status', 'loan_status'],
    ];

    return firstExistingColumn($conn, 'loans', $map[$field]);
}

function loanSelectAliases(PDO $conn) {
    $client = loanColumn($conn, 'client_id');
    $duration = loanColumn($conn, 'duration');
    $total = loanColumn($conn, 'total');
    $issueDate = loanColumn($conn, 'issue_date');
    $status = loanColumn($conn, 'status');

    return "loans.*, loans.`$client` AS applicant_id, loans.`$duration` AS loan_duration, loans.`$total` AS total_repayment, loans.`$issueDate` AS start_date, loans.`$status` AS loan_status";
}

function loanHasFinancialColumns(PDO $conn) {
    return tableHasColumn($conn, 'loans', 'outstanding_principal')
        && tableHasColumn($conn, 'loans', 'accrued_interest')
        && tableHasColumn($conn, 'loans', 'interest_paid')
        && tableHasColumn($conn, 'loans', 'principal_paid')
        && tableHasColumn($conn, 'loans', 'total_payments')
        && tableHasColumn($conn, 'loans', 'outstanding_balance');
}

function calculateElapsedWholeMonths($fromDate, $toDate) {
    $from = new DateTimeImmutable($fromDate);
    $to = new DateTimeImmutable($toDate);

    if ($to <= $from) {
        return 0;
    }

    $months = ((int)$to->format('Y') - (int)$from->format('Y')) * 12 + ((int)$to->format('n') - (int)$from->format('n'));

    if ((int)$to->format('j') < (int)$from->format('j')) {
        $months--;
    }

    return max($months, 0);
}

/**
 * Returns the end of the last fully elapsed interest period.  Keeping this
 * date on the original monthly anniversary prevents a payment made between
 * anniversaries from skipping or shifting an interest period.
 */
function lastCompletedInterestPeriodDate($fromDate, $toDate, $monthsElapsed) {
    $from = new DateTimeImmutable($fromDate);
    return $from->modify('+' . max((int)$monthsElapsed, 0) . ' months')->format('Y-m-d');
}

function refreshLoanBalances(PDO $conn, $loanId = null, $asOfDate = null) {
    if (!loanHasFinancialColumns($conn) || !tableHasColumn($conn, 'loans', 'last_interest_calculated_at')) {
        return;
    }

    $issueDateCol = loanColumn($conn, 'issue_date');
    $statusCol = loanColumn($conn, 'status');
    $asOf = new DateTimeImmutable($asOfDate ?? 'today');
    $where = $loanId ? 'WHERE loans.loan_id = ?' : '';

    $stmt = $conn->prepare(
        "SELECT loans.loan_id,
                loans.loan_amount,
                loans.interest_rate,
                loans.outstanding_principal,
                loans.accrued_interest,
                loans.outstanding_balance,
                loans.last_interest_calculated_at,
                loans.`$issueDateCol` AS issue_date,
                loans.`$statusCol` AS loan_status
         FROM loans
         $where
         ORDER BY loans.loan_id ASC"
    );
    $stmt->execute($loanId ? [$loanId] : []);

    $update = $conn->prepare(
        "UPDATE loans
         SET accrued_interest = ?,
             outstanding_balance = ?,
             last_interest_calculated_at = ?
         WHERE loan_id = ?"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $loan) {
        if (in_array($loan['loan_status'], ['Completed', 'Rejected'], true)) {
            continue;
        }

        $lastInterestDate = $loan['last_interest_calculated_at'] ?: $loan['issue_date'];
        $monthsElapsed = calculateElapsedWholeMonths($lastInterestDate, $asOf->format('Y-m-d'));

        if ($monthsElapsed <= 0) {
            continue;
        }

        $outstandingPrincipal = (float)$loan['outstanding_principal'];
        $interestRate = (float)$loan['interest_rate'];
        // Interest is deliberately calculated from principal only.  Accrued
        // interest is added afterwards and never becomes part of this base.
        $newInterest = round((float)$loan['accrued_interest'] + ($outstandingPrincipal * ($interestRate / 100) * $monthsElapsed), 2);
        $newBalance = round($outstandingPrincipal + $newInterest, 2);

        $update->execute([
            $newInterest,
            $newBalance,
            lastCompletedInterestPeriodDate($lastInterestDate, $asOf->format('Y-m-d'), $monthsElapsed),
            $loan['loan_id'],
        ]);
    }
}

function getLoanFinancialSnapshot(PDO $conn, $loanId, $asOfDate = null) {
    refreshLoanBalances($conn, $loanId, $asOfDate);

    $clientCol = loanColumn($conn, 'client_id');
    $statusCol = loanColumn($conn, 'status');

    $stmt = $conn->prepare(
        "SELECT loans.*, loans.`$clientCol` AS applicant_id, loans.`$statusCol` AS loan_status
         FROM loans
         WHERE loans.loan_id = ?"
    );
    $stmt->execute([$loanId]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$loan) {
        return null;
    }

    $paidStmt = $conn->prepare("SELECT COALESCE(SUM(payment_amount), 0) FROM repayments WHERE loan_id = ? AND " . repaymentVerificationFilter($conn));
    $paidStmt->execute([$loanId]);
    $totalPaid = (float)$paidStmt->fetchColumn();

    if (loanHasFinancialColumns($conn)) {
        $loan['outstanding_principal'] = (float)$loan['outstanding_principal'];
        $loan['accrued_interest'] = (float)$loan['accrued_interest'];
        $loan['interest_paid'] = (float)$loan['interest_paid'];
        $loan['principal_paid'] = (float)$loan['principal_paid'];
        $loan['total_payments'] = (float)$loan['total_payments'];
        $loan['outstanding_balance'] = (float)$loan['outstanding_balance'];
    } else {
        $loan['outstanding_principal'] = max((float)$loan['loan_amount'] - $totalPaid, 0);
        $loan['accrued_interest'] = max((float)$loan['total_repayment'] - (float)$loan['loan_amount'] - $totalPaid, 0);
        $loan['interest_paid'] = min($totalPaid, max((float)$loan['total_repayment'] - (float)$loan['loan_amount'], 0));
        $loan['principal_paid'] = max($totalPaid - $loan['interest_paid'], 0);
        $loan['total_payments'] = $totalPaid;
        $loan['outstanding_balance'] = max($loan['outstanding_principal'] + $loan['accrued_interest'], 0);
    }

    $loan['total_paid'] = $totalPaid;
    $loan['remaining_balance'] = $loan['outstanding_balance'];

    return $loan;
}

function normalizeOptional($value) {
    return isset($value) && trim((string)$value) !== '' ? trim((string)$value) : null;
}

function refreshLoanStatuses(PDO $conn) {
    refreshLoanBalances($conn);

    $totalCol = loanColumn($conn, 'total');
    $statusCol = loanColumn($conn, 'status');
    $outstandingExpression = getOutstandingExpression($conn, 'loans');

    $sqlCompleted = "
        UPDATE loans
        SET loans.`$statusCol` = 'Completed'
        WHERE $outstandingExpression <= 0
        AND loans.`$statusCol` <> 'Completed'
    ";
    $conn->exec($sqlCompleted);

    $sqlOverdue = "
        UPDATE loans
        SET loans.`$statusCol` = 'Overdue'
        WHERE loans.due_date < CURDATE()
        AND $outstandingExpression > 0
        AND loans.`$statusCol` IN ('Active', 'Overdue')
    ";
    $conn->exec($sqlOverdue);
}

function getOutstandingExpression(PDO $conn, $tableAlias = 'loans') {
    if (loanHasFinancialColumns($conn)) {
        return "GREATEST($tableAlias.outstanding_balance, 0)";
    }

    $totalCol = loanColumn($conn, 'total');
    return "GREATEST($tableAlias.`$totalCol` - COALESCE((SELECT SUM(payment_amount) FROM repayments WHERE repayments.loan_id = $tableAlias.loan_id AND " . repaymentVerificationFilter($conn) . "), 0), 0)";
}

function calculateLoanPlanInstallment($loanAmount, $interestRate, $months) {
    $months = max((int)$months, 1);

    if ($loanAmount <= 0) {
        return 0.00;
    }

    // Equal principal payments with interest calculated on the declining balance.
    $principalPayment = round($loanAmount / $months, 2);
    $balance = round($loanAmount, 2);
    $totalInterest = 0.00;
    $totalRepayment = 0.00;

    for ($month = 1; $month <= $months; $month++) {
        $interest = round($balance * ($interestRate / 100), 2);
        $principal = $month === $months
            ? round($balance, 2)
            : $principalPayment;
        $totalInterest += $interest;
        $totalRepayment += $principal + $interest;
        $balance = max(round($balance - $principal, 2), 0);
    }

    return round($totalRepayment / $months, 2);
}

function getActiveLoanPlans(PDO $conn) {
    if (!tableHasColumn($conn, 'loans', 'plan_id')) {
        return [];
    }
    return $conn->query(
        "SELECT * FROM loan_plans WHERE is_active = 1 ORDER BY loan_amount ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function getRejectedApplicantsCount(PDO $conn, $start = null, $end = null) {
    if (!tableHasColumn($conn, 'loan_applicants', 'application_status')) {
        return 0;
    }

    $decidedColExists = tableHasColumn($conn, 'loan_applicants', 'decided_at');

    if ($start && $end && $decidedColExists) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM loan_applicants
             WHERE application_status = 'Rejected'
             AND DATE(decided_at) BETWEEN ? AND ?"
        );
        $stmt->execute([$start, $end]);
        return (int)$stmt->fetchColumn();
    }

    if ($start && $end) {
        // decided_at not available (migration not run yet) - fall back to 0
        // rather than counting all-time rejections against a single month.
        return 0;
    }

    return (int)$conn->query(
        "SELECT COUNT(*) FROM loan_applicants WHERE application_status = 'Rejected'"
    )->fetchColumn();
}

function getLoanPlanById(PDO $conn, $planId) {
    if (!$planId) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM loan_plans WHERE plan_id = ?");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    return $plan ?: null;
}

function getApplicantsMonthlyBreakdown(PDO $conn, $year) {
    $stmt = $conn->prepare(
        "SELECT MONTH(created_at) AS month_number, COUNT(*) AS total
         FROM loan_applicants
         WHERE YEAR(created_at) = ? AND is_deleted = 0
         GROUP BY MONTH(created_at)"
    );
    $stmt->execute([$year]);
    $counts = array_fill(1, 12, 0);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[(int)$row['month_number']] = (int)$row['total'];
    }
    return $counts;
}

function getMonthlyReportData(PDO $conn, $year, $month) {
    refreshLoanStatuses($conn);

    $clientCol = loanColumn($conn, 'client_id');
    $issueDateCol = loanColumn($conn, 'issue_date');
    $statusCol = loanColumn($conn, 'status');
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $params = [$start, $end];

    $clients = $conn->prepare("SELECT COUNT(*) FROM loan_applicants WHERE DATE(created_at) BETWEEN ? AND ?");
    try {
        $clients->execute($params);
        $totalClients = (int)$clients->fetchColumn();
    } catch (PDOException $e) {
        $totalClients = 0;
    }

    $loanSummary = $conn->prepare(
        "SELECT COUNT(*) AS total_loans,
                COALESCE(SUM(loan_amount), 0) AS total_loaned,
                0 AS interest_earned
         FROM loans
         WHERE DATE(`$issueDateCol`) BETWEEN ? AND ?"
    );
    $loanSummary->execute($params);
    $loanTotals = $loanSummary->fetch(PDO::FETCH_ASSOC);

    $repayments = $conn->prepare("SELECT COALESCE(SUM(payment_amount), 0) FROM repayments WHERE DATE(payment_date) BETWEEN ? AND ?");
    $repayments->execute($params);
    $totalRepayments = (float)$repayments->fetchColumn();

    $interestReceived = 0.0;
    if (tableHasColumn($conn, 'repayments', 'interest_portion')) {
        $interestStmt = $conn->prepare("SELECT COALESCE(SUM(interest_portion), 0) FROM repayments WHERE DATE(payment_date) BETWEEN ? AND ?");
        $interestStmt->execute($params);
        $interestReceived = (float)$interestStmt->fetchColumn();
    }

    $statusCounts = $conn->query(
        "SELECT
            SUM(CASE WHEN `$statusCol`='Active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN `$statusCol`='Completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN `$statusCol`='Overdue' THEN 1 ELSE 0 END) AS overdue
         FROM loans"
    )->fetch(PDO::FETCH_ASSOC);

    $outstanding = $conn->query(
        "SELECT COALESCE(SUM(" . getOutstandingExpression($conn, 'loans') . "), 0)
         FROM loans
         WHERE `$statusCol` <> 'Completed'"
    )->fetchColumn();

    $rejected = getRejectedApplicantsCount($conn, $start, $end);

    $recentLoans = $conn->prepare(
        "SELECT " . loanSelectAliases($conn) . ", loan_applicants.full_name
         FROM loans
         LEFT JOIN loan_applicants ON loans.`$clientCol` = loan_applicants.applicant_id
         WHERE DATE(loans.`$issueDateCol`) BETWEEN ? AND ?
         ORDER BY loans.loan_id DESC
         LIMIT 10"
    );
    $recentLoans->execute($params);

    $recentRepayments = $conn->prepare(
        "SELECT repayments.*, loan_applicants.full_name
         FROM repayments
         JOIN loans ON repayments.loan_id = loans.loan_id
         LEFT JOIN loan_applicants ON loans.`$clientCol` = loan_applicants.applicant_id
         WHERE DATE(repayments.payment_date) BETWEEN ? AND ?
         ORDER BY repayments.repayment_id DESC
         LIMIT 10"
    );
    $recentRepayments->execute($params);

    $repaymentTrend = $conn->prepare(
        "SELECT DAY(payment_date) AS day, COALESCE(SUM(payment_amount), 0) AS total
         FROM repayments
         WHERE DATE(payment_date) BETWEEN ? AND ?
         GROUP BY DAY(payment_date)
         ORDER BY day"
    );
    $repaymentTrend->execute($params);

    return [
        'start' => $start,
        'end' => $end,
        'clients' => $totalClients,
        'loans' => (int)$loanTotals['total_loans'],
        'loaned' => (float)$loanTotals['total_loaned'],
        'repayments' => $totalRepayments,
        'interest' => $interestReceived,
        'active' => (int)$statusCounts['active'],
        'completed' => (int)$statusCounts['completed'],
        'overdue' => (int)$statusCounts['overdue'],
        'rejected' => $rejected,
        'outstanding' => (float)$outstanding,
        'recentLoans' => $recentLoans->fetchAll(PDO::FETCH_ASSOC),
        'recentRepayments' => $recentRepayments->fetchAll(PDO::FETCH_ASSOC),
        'repaymentTrend' => $repaymentTrend->fetchAll(PDO::FETCH_ASSOC),
    ];
}

?>
