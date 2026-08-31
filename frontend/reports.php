<?php
session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: login.html");
    exit();
}

include "../backend/db.php";
include "../backend/helpers.php";

refreshLoanStatuses($conn);
refreshLoanBalances($conn);

$reportTypes = [
    'loan_applications' => 'Loan Applications Report',
    'approved_loans' => 'Approved Loans Report',
    'rejected_loans' => 'Rejected Loans Report',
    'active_loans' => 'Active Loans Report',
    'completed_loans' => 'Completed Loans Report',
    'outstanding_loans' => 'Outstanding Loans Report',
    'repayments' => 'Repayment Report',
    'interest' => 'Interest Report',
    'overdue_loans' => 'Overdue Loans Report',
    'applicants' => 'Applicant Report',
    'disbursements' => 'Disbursement Report',
    'financial_summary' => 'Financial Summary Report',
];

$reportType = $_GET['report_type'] ?? 'repayments';
if (!array_key_exists($reportType, $reportTypes)) {
    $reportType = 'repayments';
}

$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? date('Y-m-d');

$from = DateTime::createFromFormat('Y-m-d', $fromDate) ?: new DateTime('first day of this month');
$to = DateTime::createFromFormat('Y-m-d', $toDate) ?: new DateTime('today');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$fromDate = $from->format('Y-m-d');
$toDate = $to->format('Y-m-d');
$issueDateCol = loanColumn($conn, 'issue_date');
$statusCol = loanColumn($conn, 'status');
$clientCol = loanColumn($conn, 'client_id');
$hasPlanColumn = tableHasColumn($conn, 'loans', 'plan_id');
$planJoin = $hasPlanColumn ? " LEFT JOIN loan_plans ON loans.plan_id = loan_plans.plan_id" : "";
$planSelect = $hasPlanColumn ? ", loan_plans.plan_name" : ", NULL AS plan_name";
$hasRepaymentAllocation = tableHasColumn($conn, 'repayments', 'interest_portion');

$summary = [
    'count' => 0,
    'total_disbursed' => 0,
    'total_repayments' => 0,
    'total_principal_paid' => 0,
    'total_interest_paid' => 0,
    'total_outstanding' => 0,
    'total_interest_accrued' => 0,
    'active_loans' => 0,
    'completed_loans' => 0,
    'overdue_loans' => 0,
];

$rows = [];
$reportTitle = $reportTypes[$reportType];

switch ($reportType) {
    case 'loan_applications':
        $stmt = $conn->prepare(
            "SELECT loan_applicants.*, loan_applicants.created_at AS record_date
             FROM loan_applicants
             WHERE DATE(created_at) BETWEEN ? AND ?
             ORDER BY created_at DESC"
        );
        $stmt->execute([$fromDate, $toDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary['count'] = count($rows);
        break;

    case 'approved_loans':
    case 'active_loans':
    case 'completed_loans':
    case 'outstanding_loans':
    case 'overdue_loans':
    case 'disbursements':
        $statusFilter = [
            'approved_loans' => ['Approved', 'Active', 'Overdue', 'Completed'],
            'active_loans' => ['Active', 'Overdue'],
            'completed_loans' => ['Completed'],
            'outstanding_loans' => ['Active', 'Overdue'],
            'overdue_loans' => ['Overdue'],
            'disbursements' => ['Active', 'Overdue', 'Completed'],
        ];
        $statusList = $statusFilter[$reportType];
        $placeholders = implode(',', array_fill(0, count($statusList), '?'));
        $stmt = $conn->prepare(
            "SELECT " . loanSelectAliases($conn) . ", loan_applicants.full_name$planSelect
             FROM loans
             LEFT JOIN loan_applicants ON loans.`$clientCol` = loan_applicants.applicant_id
             $planJoin
             WHERE DATE(loans.`$issueDateCol`) BETWEEN ? AND ?
             AND loans.`$statusCol` IN ($placeholders)
             ORDER BY loans.loan_id DESC"
        );
        $stmt->execute(array_merge([$fromDate, $toDate], $statusList));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary['count'] = count($rows);
        $summary['total_disbursed'] = array_sum(array_column($rows, 'loan_amount'));
        $summary['total_outstanding'] = array_sum(array_column($rows, 'outstanding_balance'));
        $summary['active_loans'] = count(array_filter($rows, fn($row) => in_array($row['loan_status'], ['Active', 'Overdue'], true)));
        $summary['completed_loans'] = count(array_filter($rows, fn($row) => $row['loan_status'] === 'Completed'));
        $summary['overdue_loans'] = count(array_filter($rows, fn($row) => $row['loan_status'] === 'Overdue'));
        break;

    case 'rejected_loans':
        $stmt = $conn->prepare(
            "SELECT * FROM loan_applicants
             WHERE application_status = 'Rejected'
             AND DATE(COALESCE(created_at)) BETWEEN ? AND ?
             ORDER BY  created_at DESC"
        );
        $stmt->execute([$fromDate, $toDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary['count'] = count($rows);
        break;

    case 'applicants':
        $stmt = $conn->prepare(
            "SELECT * FROM loan_applicants
             WHERE DATE(created_at) BETWEEN ? AND ?
             ORDER BY created_at DESC"
        );
        $stmt->execute([$fromDate, $toDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary['count'] = count($rows);
        break;

    case 'repayments':
    case 'interest':
        $interestPortion = $hasRepaymentAllocation ? 'repayments.interest_portion' : '0';
        $principalPortion = $hasRepaymentAllocation ? 'repayments.principal_portion' : '0';
        $remainingPrincipal = $hasRepaymentAllocation ? 'repayments.remaining_principal' : 'loans.outstanding_principal';
        $remainingInterest = $hasRepaymentAllocation ? 'repayments.remaining_interest' : 'loans.accrued_interest';
        $stmt = $conn->prepare(
            "SELECT repayments.*, loan_applicants.full_name, loans.`$clientCol` AS applicant_id,
                    COALESCE($interestPortion, 0) AS interest_paid,
                    COALESCE($principalPortion, 0) AS principal_paid,
                    COALESCE($remainingPrincipal, loans.outstanding_principal) AS outstanding_principal,
                    COALESCE($remainingInterest, loans.accrued_interest) AS accrued_interest,
                    repayments.remaining_balance AS outstanding_balance
             FROM repayments
             JOIN loans ON repayments.loan_id = loans.loan_id
             LEFT JOIN loan_applicants ON loans.`$clientCol` = loan_applicants.applicant_id
             WHERE DATE(repayments.payment_date) BETWEEN ? AND ?
             AND " . repaymentVerificationFilter($conn, 'repayments') . "
             ORDER BY repayments.repayment_id DESC"
        );
        $stmt->execute([$fromDate, $toDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary['count'] = count($rows);
        $summary['total_repayments'] = array_sum(array_column($rows, 'payment_amount'));
        $summary['total_interest_paid'] = array_sum(array_column($rows, 'interest_paid'));
        $summary['total_principal_paid'] = array_sum(array_column($rows, 'principal_paid'));
        break;

    case 'financial_summary':
    default:
        $summary['count'] = (int)$conn->query("SELECT COUNT(*) FROM loans")->fetchColumn();
        $summary['total_disbursed'] = (float)$conn->query("SELECT COALESCE(SUM(loan_amount),0) FROM loans")->fetchColumn();
        $summary['total_repayments'] = (float)$conn->query("SELECT COALESCE(SUM(payment_amount),0) FROM repayments WHERE " . repaymentVerificationFilter($conn))->fetchColumn();
        $summary['total_interest_paid'] = (float)$conn->query("SELECT COALESCE(SUM(interest_paid),0) FROM loans")->fetchColumn();
        $summary['total_principal_paid'] = (float)$conn->query("SELECT COALESCE(SUM(principal_paid),0) FROM loans")->fetchColumn();
        $summary['total_outstanding'] = (float)$conn->query("SELECT COALESCE(SUM(" . getOutstandingExpression($conn, 'loans') . "),0) FROM loans")->fetchColumn();
        $summary['total_interest_accrued'] = (float)$conn->query("SELECT COALESCE(SUM(accrued_interest),0) FROM loans")->fetchColumn();
        $summary['active_loans'] = (int)$conn->query("SELECT COUNT(*) FROM loans WHERE `$statusCol` IN ('Active','Overdue')")->fetchColumn();
        $summary['completed_loans'] = (int)$conn->query("SELECT COUNT(*) FROM loans WHERE `$statusCol`='Completed'")->fetchColumn();
        $summary['overdue_loans'] = (int)$conn->query("SELECT COUNT(*) FROM loans WHERE `$statusCol`='Overdue'")->fetchColumn();
        break;
}

// Chart data always respects the selected report date range.  It is kept
// separate from the report rows so every report type has useful live graphs.
$loanActivity = $conn->prepare(
    "SELECT DATE(`$issueDateCol`) AS activity_date, COALESCE(SUM(loan_amount), 0) AS amount
     FROM loans
     WHERE DATE(`$issueDateCol`) BETWEEN ? AND ?
     GROUP BY DATE(`$issueDateCol`)"
);
$loanActivity->execute([$fromDate, $toDate]);

$interestPortionSql = $hasRepaymentAllocation ? 'interest_portion' : '0';
$principalPortionSql = $hasRepaymentAllocation ? 'principal_portion' : '0';
$paymentActivity = $conn->prepare(
    "SELECT DATE(payment_date) AS activity_date,
            COALESCE(SUM(payment_amount), 0) AS amount,
            COALESCE(SUM($principalPortionSql), 0) AS principal,
            COALESCE(SUM($interestPortionSql), 0) AS interest
     FROM repayments
    WHERE DATE(payment_date) BETWEEN ? AND ?
    AND " . repaymentVerificationFilter($conn) . "
     GROUP BY DATE(payment_date)"
);
$paymentActivity->execute([$fromDate, $toDate]);

$chartRangeDays = (new DateTimeImmutable($fromDate))->diff(new DateTimeImmutable($toDate))->days + 1;
$chartGranularity = $chartRangeDays <= 31 ? 'day' : ($chartRangeDays <= 180 ? 'week' : 'month');
$chartGranularityLabel = ucfirst($chartGranularity) . ' view';
$chartTrend = [];
foreach ($loanActivity->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $date = new DateTimeImmutable($row['activity_date']);
    $key = $chartGranularity === 'day' ? $date->format('Y-m-d') : ($chartGranularity === 'week' ? $date->modify('monday this week')->format('Y-m-d') : $date->format('Y-m'));
    $chartTrend[$key]['loaned'] = ($chartTrend[$key]['loaned'] ?? 0) + (float)$row['amount'];
}
foreach ($paymentActivity->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $date = new DateTimeImmutable($row['activity_date']);
    $key = $chartGranularity === 'day' ? $date->format('Y-m-d') : ($chartGranularity === 'week' ? $date->modify('monday this week')->format('Y-m-d') : $date->format('Y-m'));
    $chartTrend[$key]['repaid'] = ($chartTrend[$key]['repaid'] ?? 0) + (float)$row['amount'];
    $chartTrend[$key]['principal'] = ($chartTrend[$key]['principal'] ?? 0) + (float)$row['principal'];
    $chartTrend[$key]['interest'] = ($chartTrend[$key]['interest'] ?? 0) + (float)$row['interest'];
}
ksort($chartTrend);
foreach ($chartTrend as &$point) {
    $point['loaned'] = $point['loaned'] ?? 0;
    $point['repaid'] = $point['repaid'] ?? 0;
    $point['principal'] = $point['principal'] ?? 0;
    $point['interest'] = $point['interest'] ?? 0;
}
unset($point);
$chartTrendLabels = [];
foreach (array_keys($chartTrend) as $key) {
    if ($chartGranularity === 'month') {
        $chartTrendLabels[] = date('M Y', strtotime($key . '-01'));
    } elseif ($chartGranularity === 'week') {
        $chartTrendLabels[] = 'Week of ' . date('M d', strtotime($key));
    } else {
        $chartTrendLabels[] = date('M d', strtotime($key));
    }
}

$statusChart = $conn->query(
    "SELECT `$statusCol` AS status_name, COUNT(*) AS total
     FROM loans
     GROUP BY `$statusCol`
     ORDER BY `$statusCol`"
)->fetchAll(PDO::FETCH_ASSOC);

function exportLink($params) {
    return '?' . http_build_query(array_merge($_GET, $params));
}

function csvValue($value) {
    $text = (string)$value;
    $text = str_replace('"', '""', $text);
    return '"' . $text . '"';
}

function pdfEscape($text) {
    $text = (string)$text;
    $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    $text = preg_replace('/[\r\n]+/', ' ', $text);
    return $text;
}

function pdfBuildLines(array $lines, $fontSize = 11, $pageWidth = 612, $pageHeight = 792, $margin = 36) {
    $lineHeight = $fontSize + 4;
    $usableWidth = $pageWidth - ($margin * 2);
    $charWidth = max($fontSize * 0.55, 4.5);
    $maxChars = max((int)floor($usableWidth / $charWidth), 20);

    $wrapped = [];
    foreach ($lines as $line) {
        $segments = explode("\n", (string)$line);
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                $wrapped[] = '';
                continue;
            }
            foreach (explode("\n", wordwrap($segment, $maxChars, "\n", true)) as $part) {
                $wrapped[] = $part;
            }
        }
    }

    $pages = [];
    $currentPage = [];
    $maxLinesPerPage = max((int)floor(($pageHeight - ($margin * 2)) / $lineHeight), 25);

    foreach ($wrapped as $line) {
        if (count($currentPage) >= $maxLinesPerPage) {
            $pages[] = $currentPage;
            $currentPage = [];
        }
        $currentPage[] = $line;
    }

    if (!empty($currentPage)) {
        $pages[] = $currentPage;
    }

    $objects = [];
    $pageObjects = [];
    $fontObjectId = 3 + (count($pages) * 2);

    $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj";

    $kids = [];
    $nextObjectId = 3;
    foreach ($pages as $pageIndex => $pageLines) {
        $contentObjectId = $nextObjectId;
        $pageObjectId = $nextObjectId + 1;
        $nextObjectId += 2;

        $stream = "BT\n/F1 {$fontSize} Tf\n{$margin} " . ($pageHeight - $margin - $fontSize) . " Td\n";
        $firstLine = true;
        foreach ($pageLines as $line) {
            if ($firstLine) {
                $stream .= '(' . pdfEscape($line) . ") Tj\n";
                $firstLine = false;
            } else {
                $stream .= "0 -{$lineHeight} Td\n(" . pdfEscape($line) . ") Tj\n";
            }
        }
        $stream .= "ET";

        $objects[] = $contentObjectId . " 0 obj << /Length " . strlen($stream) . " >> stream\n" . $stream . "\nendstream endobj";
        $objects[] = $pageObjectId . " 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 {$fontObjectId} 0 R >> >> /Contents {$contentObjectId} 0 R >> endobj";
        $kids[] = $pageObjectId . " 0 R";
    }

    $objects[] = "2 0 obj << /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($pages) . " >> endobj";
    $objects[] = $fontObjectId . " 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object . "\n";
    }

    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

    return $pdf;
}

function buildReportTableLines($reportType, array $rows, $issueDateCol) {
    $lines = [];

    if ($reportType === 'loan_applications') {
        $lines[] = 'Applicant ID | Name | Phone | National ID | Status | Date';
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '#%s | %s | %s | %s | %s | %s',
                $row['applicant_id'],
                $row['full_name'],
                $row['phone_number'],
                $row['national_id'],
                $row['application_status'] ?? 'Pending',
                $row['record_date']
            );
        }
    } elseif (in_array($reportType, ['approved_loans','active_loans','completed_loans','outstanding_loans','overdue_loans','disbursements'], true)) {
        $lines[] = 'Loan ID | Applicant | Plan | Amount | Rate | Principal | Interest | Outstanding | Status | Issue Date';
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '#%s | %s | %s | KES %s | %s%% | KES %s | KES %s | KES %s | %s | %s',
                $row['loan_id'],
                $row['full_name'] ?? 'Unknown',
                $row['plan_name'] ?? 'Custom',
                number_format($row['loan_amount']),
                $row['interest_rate'],
                number_format($row['outstanding_principal']),
                number_format($row['accrued_interest']),
                number_format($row['outstanding_balance']),
                $row['loan_status'],
                $row[$issueDateCol]
            );
        }
    } elseif ($reportType === 'rejected_loans') {
        $lines[] = 'Applicant ID | Name | Phone | National ID | Decision Date | Status';
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '#%s | %s | %s | %s | %s | %s',
                $row['applicant_id'],
                $row['full_name'],
                $row['phone_number'],
                $row['national_id'],
                $row['decided_at'] ?? $row['created_at'],
                $row['application_status']
            );
        }
    } elseif (in_array($reportType, ['repayments', 'interest'], true)) {
        $lines[] = 'Repayment ID | Applicant | Loan ID | Payment Date | Amount Paid | Interest Paid | Principal Paid | Remaining Principal | Remaining Interest | Outstanding Balance';
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '#%s | %s | #%s | %s | KES %s | KES %s | KES %s | KES %s | KES %s | KES %s',
                $row['repayment_id'],
                $row['full_name'] ?? 'Unknown',
                $row['loan_id'],
                $row['payment_date'],
                number_format($row['payment_amount']),
                number_format($row['interest_paid'] ?? 0),
                number_format($row['principal_paid'] ?? 0),
                number_format($row['outstanding_principal'] ?? 0),
                number_format($row['accrued_interest'] ?? 0),
                number_format($row['outstanding_balance'] ?? 0)
            );
        }
    } else {
        foreach ($rows as $row) {
            $lines[] = json_encode($row);
        }
    }

    return $lines;
}

$export = $_GET['export'] ?? null;
if ($export === 'csv' || $export === 'excel') {
    $filename = $reportType . '_' . $fromDate . '_to_' . $toDate . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Loan Management System']);
    fputcsv($output, [$reportTitle]);
    fputcsv($output, ['From Date', $fromDate]);
    fputcsv($output, ['To Date', $toDate]);
    fputcsv($output, []);

    if ($reportType === 'loan_applications') {
        fputcsv($output, ['Applicant ID', 'Name', 'Phone', 'National ID', 'Status', 'Date']);
        foreach ($rows as $row) {
            fputcsv($output, [$row['applicant_id'], $row['full_name'], $row['phone_number'], $row['national_id'], $row['application_status'] ?? 'Pending', $row['record_date']]);
        }
    } elseif (in_array($reportType, ['approved_loans','active_loans','completed_loans','outstanding_loans','overdue_loans','disbursements'], true)) {
        fputcsv($output, ['Loan ID', 'Applicant', 'Plan', 'Amount', 'Interest Rate', 'Principal', 'Interest', 'Outstanding', 'Status', 'Issue Date']);
        foreach ($rows as $row) {
            fputcsv($output, [$row['loan_id'], $row['full_name'] ?? 'Unknown', $row['plan_name'] ?? 'Custom', $row['loan_amount'], $row['interest_rate'], $row['outstanding_principal'], $row['accrued_interest'], $row['outstanding_balance'], $row['loan_status'], $row[$issueDateCol]]);
        }
    } elseif ($reportType === 'rejected_loans') {
        fputcsv($output, ['Applicant ID', 'Name', 'Phone', 'National ID', 'Decision Date', 'Status']);
        foreach ($rows as $row) {
            fputcsv($output, [$row['applicant_id'], $row['full_name'], $row['phone_number'], $row['national_id'], $row['decided_at'] ?? $row['created_at'], $row['application_status']]);
        }
    } elseif (in_array($reportType, ['repayments', 'interest'], true)) {
        fputcsv($output, ['Repayment ID', 'Applicant', 'Loan ID', 'Payment Date', 'Amount Paid', 'Interest Paid', 'Principal Paid', 'Remaining Principal', 'Remaining Interest', 'Outstanding Balance']);
        foreach ($rows as $row) {
            fputcsv($output, [$row['repayment_id'], $row['full_name'] ?? 'Unknown', $row['loan_id'], $row['payment_date'], $row['payment_amount'], $row['interest_paid'] ?? 0, $row['principal_paid'] ?? 0, $row['outstanding_principal'] ?? 0, $row['accrued_interest'] ?? 0, $row['outstanding_balance'] ?? 0]);
        }
    } else {
        fputcsv($output, ['Label', 'Value']);
        foreach ($rows as $row) {
            fputcsv($output, [json_encode(array_keys($row)), json_encode(array_values($row))]);
        }
    }

    fclose($output);
    exit();
}

if ($export === 'pdf') {
    $pdfLines = [
        'Loan Management System',
        $reportTitle,
        'From Date: ' . $fromDate,
        'To Date: ' . $toDate,
        'Generated on: ' . date('d/m/Y H:i'),
        '',
        'Summary',
        'Records: ' . $summary['count'],
        'Disbursed: KES ' . number_format($summary['total_disbursed']),
        'Repayments: KES ' . number_format($summary['total_repayments']),
        'Principal Paid: KES ' . number_format($summary['total_principal_paid']),
        'Interest Paid: KES ' . number_format($summary['total_interest_paid']),
        'Outstanding: KES ' . number_format($summary['total_outstanding']),
        'Active Loans: ' . $summary['active_loans'],
        'Completed Loans: ' . $summary['completed_loans'],
        'Overdue Loans: ' . $summary['overdue_loans'],
        '',
        'Report Data',
    ];

    $pdfLines = array_merge($pdfLines, buildReportTableLines($reportType, $rows, $issueDateCol));

    $pdf = pdfBuildLines($pdfLines, 10);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $reportType . '_' . $fromDate . '_to_' . $toDate . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($reportTitle); ?></title>
    <link rel="stylesheet" href="../css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .report-shell { padding: 24px; background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 100%); min-height: 100vh; }
        .report-panel, .report-table-card { background: #fff; border-radius: 14px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); }
        .report-panel { padding: 20px; margin-bottom: 20px; }
        .report-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .report-card { padding: 16px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fbfdff; }
        .report-card .label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        .report-card .value { font-size: 22px; font-weight: 700; color: #0f9d58; margin-top: 6px; }
        .report-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
        .report-actions a, .report-actions button { text-decoration: none; border: 0; cursor: pointer; padding: 10px 14px; border-radius: 8px; font-weight: 600; }
        .btn-print { background: #111827; color: #fff; }
        .btn-csv { background: #2563eb; color: #fff; }
        .btn-pdf { background: #0f9d58; color: #fff; }
        .report-table-card { overflow: hidden; }
        .report-header { padding: 24px 24px 0; }
        .report-header h1 { margin: 0; color: #111827; }
        .report-header p { color: #6b7280; margin-top: 6px; }
        .table-wrap { overflow-x: auto; padding: 0 24px 24px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; }
        .filter-grid label { display: block; font-weight: 600; margin-bottom: 6px; }
        .filter-grid input, .filter-grid select { width: 100%; }
        .summary-note { font-size: 13px; color: #6b7280; }
        .report-charts { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; padding: 0 24px 24px; }
        .report-chart { border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; background: #fbfdff; }
        .report-chart h2 { margin: 0 0 6px; font-size: 16px; color: #1f2937; }
        .report-chart p { margin: 0 0 12px; font-size: 13px; color: #6b7280; }
        .report-chart-canvas { position: relative; height: 260px; }
        @media print {
            .no-print { display: none !important; }
            .report-shell { background: #fff; padding: 0; }
            .report-panel, .report-table-card { box-shadow: none; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="sidebar no-print">
        <h2 class="logo">🏦Smart Loans</h2>
        <ul>
            <li><a href="dashboard.php">🏠 Dashboard</a></li>
            <li><a href="applicants.php">👥 Applicants</a></li>
            <li><a href="loans.php">💰 Loans</a></li>
            <li><a href="loan_plans.php">🧾 Loan Plans</a></li>
            <li><a href="payment_calculator.php">🧮 Payment Calculator</a></li>
            <li><a href="repayments.php">💳 Repayments</a></li>
            <li><a class="active" href="reports.php">📊 Reports</a></li>
            <li><a href="activity_logs.php">📜 Activity Logs</a></li>
            <li><a href="profile.php">👤 Profile</a></li>
            <li><a href="../backend/logout.php">🚪 Logout</a></li>
        </ul>
    </div>

    <div class="main-content report-shell">
        <div class="topbar no-print">
            <button id="menuToggle" class="menu-btn">&#9776;</button>
            <h1>Reports</h1>
            <span><?php echo h($_SESSION['admin_name']); ?></span>
        </div>

        <form method="GET" class="report-panel no-print">
            <div class="filter-grid">
                <div>
                    <label>Report Type</label>
                    <select name="report_type">
                        <?php foreach ($reportTypes as $value => $label): ?>
                            <option value="<?php echo h($value); ?>" <?php echo $reportType === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>From Date</label>
                    <input type="date" name="from_date" value="<?php echo h($fromDate); ?>">
                </div>
                <div>
                    <label>To Date</label>
                    <input type="date" name="to_date" value="<?php echo h($toDate); ?>">
                </div>
            </div>
            <div class="report-actions">
                <button type="submit" class="btn-pdf">Generate Report</button>
                <button type="button" class="btn-print" onclick="window.print()">Print Report</button>
                <a class="btn-csv" href="<?php echo h(exportLink(['export' => 'csv'])); ?>">Export CSV</a>
                <a class="btn-csv" href="<?php echo h(exportLink(['export' => 'excel'])); ?>">Export Excel</a>
                <a class="btn-pdf" href="<?php echo h(exportLink(['export' => 'pdf'])); ?>">Export to PDF</a>
            </div>
            <div class="summary-note">Selected range: <?php echo h(date('d/m/Y', strtotime($fromDate))); ?> to <?php echo h(date('d/m/Y', strtotime($toDate))); ?></div>
        </form>

        <section class="report-table-card">
            <div class="report-header">
                <h1><?php echo h($reportTitle); ?></h1>
                <p>Loan Management System | Generated on <?php echo date('d/m/Y H:i'); ?></p>
            </div>
            <div class="report-grid">
                <div class="report-card"><div class="label">Records</div><div class="value"><?php echo h($summary['count']); ?></div></div>
                <div class="report-card"><div class="label">Disbursed</div><div class="value">KES <?php echo number_format($summary['total_disbursed']); ?></div></div>
                <div class="report-card"><div class="label">Repayments</div><div class="value">KES <?php echo number_format($summary['total_repayments']); ?></div></div>
                <div class="report-card"><div class="label">Principal Paid</div><div class="value">KES <?php echo number_format($summary['total_principal_paid']); ?></div></div>
                <div class="report-card"><div class="label">Interest Paid</div><div class="value">KES <?php echo number_format($summary['total_interest_paid']); ?></div></div>
                <div class="report-card"><div class="label">Outstanding</div><div class="value">KES <?php echo number_format($summary['total_outstanding']); ?></div></div>
                <div class="report-card"><div class="label">Active Loans</div><div class="value"><?php echo h($summary['active_loans']); ?></div></div>
                <div class="report-card"><div class="label">Completed Loans</div><div class="value"><?php echo h($summary['completed_loans']); ?></div></div>
                <div class="report-card"><div class="label">Overdue Loans</div><div class="value"><?php echo h($summary['overdue_loans']); ?></div></div>
            </div>

            <div class="report-charts">
                <div class="report-chart">
                    <h2>Loans Issued vs Repayments</h2>
                    <p><?php echo h($chartGranularityLabel); ?> for the selected date range.</p>
                    <div class="report-chart-canvas"><canvas id="reportCashFlowChart"></canvas></div>
                </div>
                <div class="report-chart">
                    <h2>Repayment Composition</h2>
                    <p>Principal and interest received in each <?php echo h(strtolower($chartGranularity)); ?>.</p>
                    <div class="report-chart-canvas"><canvas id="reportAllocationChart"></canvas></div>
                </div>
                <div class="report-chart">
                    <h2>Current Loan Status</h2>
                    <p>Live count of loans by status.</p>
                    <div class="report-chart-canvas"><canvas id="reportStatusChart"></canvas></div>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <?php if ($reportType === 'loan_applications'): ?>
                                <th>Applicant ID</th><th>Name</th><th>Phone</th><th>National ID</th><th>Status</th><th>Date</th>
                            <?php elseif (in_array($reportType, ['approved_loans','active_loans','completed_loans','outstanding_loans','overdue_loans','disbursements'], true)): ?>
                                <th>Loan ID</th><th>Applicant</th><th>Plan</th><th>Amount</th><th>Interest Rate</th><th>Principal</th><th>Interest</th><th>Outstanding</th><th>Status</th><th>Issue Date</th>
                            <?php elseif ($reportType === 'rejected_loans'): ?>
                                <th>Applicant ID</th><th>Name</th><th>Phone</th><th>National ID</th><th>Decision Date</th><th>Status</th>
                            <?php elseif (in_array($reportType, ['repayments','interest'], true)): ?>
                                <th>Repayment ID</th><th>Applicant</th><th>Loan ID</th><th>Payment Date</th><th>Amount Paid</th><th>Interest Paid</th><th>Principal Paid</th><th>Remaining Principal</th><th>Remaining Interest</th><th>Outstanding Balance</th>
                            <?php else: ?>
                                <th>Label</th><th>Value</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="10">No records found for the selected range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php if ($reportType === 'loan_applications'): ?>
                                        <td>#<?php echo h($row['applicant_id']); ?></td>
                                        <td><?php echo h($row['full_name']); ?></td>
                                        <td><?php echo h($row['phone_number']); ?></td>
                                        <td><?php echo h($row['national_id']); ?></td>
                                        <td><?php echo h($row['application_status'] ?? 'Pending'); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($row['record_date'])); ?></td>
                                    <?php elseif (in_array($reportType, ['approved_loans','active_loans','completed_loans','outstanding_loans','overdue_loans','disbursements'], true)): ?>
                                        <td>#<?php echo h($row['loan_id']); ?></td>
                                        <td><?php echo h($row['full_name'] ?? 'Unknown'); ?></td>
                                        <td><?php echo h($row['plan_name'] ?? 'Custom'); ?></td>
                                        <td>KES <?php echo number_format($row['loan_amount']); ?></td>
                                        <td><?php echo h($row['interest_rate']); ?>%</td>
                                        <td>KES <?php echo number_format($row['outstanding_principal']); ?></td>
                                        <td>KES <?php echo number_format($row['accrued_interest']); ?></td>
                                        <td>KES <?php echo number_format($row['outstanding_balance']); ?></td>
                                        <td><?php echo h($row['loan_status']); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($row[$issueDateCol])); ?></td>
                                    <?php elseif ($reportType === 'rejected_loans'): ?>
                                        <td>#<?php echo h($row['applicant_id']); ?></td>
                                        <td><?php echo h($row['full_name']); ?></td>
                                        <td><?php echo h($row['phone_number']); ?></td>
                                        <td><?php echo h($row['national_id']); ?></td>
                                        <td><?php echo h($row['decided_at'] ?? $row['created_at']); ?></td>
                                        <td><?php echo h($row['application_status']); ?></td>
                                    <?php elseif (in_array($reportType, ['repayments','interest'], true)): ?>
                                        <td>#<?php echo h($row['repayment_id']); ?></td>
                                        <td><?php echo h($row['full_name'] ?? 'Unknown'); ?></td>
                                        <td>#<?php echo h($row['loan_id']); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($row['payment_date'])); ?></td>
                                        <td>KES <?php echo number_format($row['payment_amount']); ?></td>
                                        <td>KES <?php echo number_format($row['interest_paid'] ?? 0); ?></td>
                                        <td>KES <?php echo number_format($row['principal_paid'] ?? 0); ?></td>
                                        <td>KES <?php echo number_format($row['outstanding_principal'] ?? 0); ?></td>
                                        <td>KES <?php echo number_format($row['accrued_interest'] ?? 0); ?></td>
                                        <td>KES <?php echo number_format($row['outstanding_balance'] ?? 0); ?></td>
                                    <?php else: ?>
                                        <td><?php echo h($reportTitle); ?></td>
                                        <td><?php echo h(json_encode($row)); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script>
const reportMoney = value => 'KES ' + new Intl.NumberFormat('en-KE').format(value);
const reportTrend = <?php echo json_encode($chartTrend, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const reportTrendLabels = <?php echo json_encode($chartTrendLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const reportStatus = <?php echo json_encode($statusChart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

if (window.Chart) {
    new Chart(document.getElementById('reportCashFlowChart'), {
        type: 'bar',
        data: {
            labels: reportTrendLabels,
            datasets: [
                { label: 'Loans Issued', data: Object.values(reportTrend).map(point => point.loaned), backgroundColor: '#2563eb', borderRadius: 5 },
                { label: 'Repayments', data: Object.values(reportTrend).map(point => point.repaid), backgroundColor: '#10b981', borderRadius: 5 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: context => `${context.dataset.label}: ${reportMoney(context.parsed.y)}`,
                        footer: items => {
                            const issued = items.find(item => item.dataset.label === 'Loans Issued')?.parsed.y ?? 0;
                            const repaid = items.find(item => item.dataset.label === 'Repayments')?.parsed.y ?? 0;
                            return `Net cash movement: ${reportMoney(repaid - issued)}`;
                        }
                    }
                }
            },
            scales: {
                x: { ticks: { autoSkip: true, maxTicksLimit: 12, maxRotation: 0 } },
                y: { beginAtZero: true, ticks: { callback: reportMoney } }
            }
        }
    });

    new Chart(document.getElementById('reportAllocationChart'), {
        type: 'bar',
        data: {
            labels: reportTrendLabels,
            datasets: [
                { label: 'Principal Received', data: Object.values(reportTrend).map(point => point.principal), backgroundColor: '#3b82f6', borderRadius: 4 },
                { label: 'Interest Received', data: Object.values(reportTrend).map(point => point.interest), backgroundColor: '#f59e0b', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: context => `${context.dataset.label}: ${reportMoney(context.parsed.y)}` } } },
            scales: {
                x: { stacked: true, ticks: { autoSkip: true, maxTicksLimit: 12, maxRotation: 0 } },
                y: { stacked: true, beginAtZero: true, ticks: { callback: reportMoney } }
            }
        }
    });

    new Chart(document.getElementById('reportStatusChart'), {
        type: 'bar',
        data: {
            labels: reportStatus.map(item => item.status_name),
            datasets: [{ label: 'Loans', data: reportStatus.map(item => item.total), backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444'], borderRadius: 6 }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => `Loans: ${context.parsed.x}` } } },
            scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } }
        }
    });
}
</script>
<script src="../js/script.js"></script>
</body>
</html>
