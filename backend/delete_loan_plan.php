<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../frontend/login.html");
    exit();
}

include "db.php";
include "helpers.php";

$planId = (int)($_GET['id'] ?? 0);

if (!$planId) {
    $_SESSION['error'] = "Loan plan ID missing.";
    header("Location: ../frontend/loan_plans.php");
    exit();
}

// If loans already reference this plan, keep it for history and just
// deactivate it so it can no longer be assigned to new applicants.
$inUse = $conn->prepare("SELECT COUNT(*) FROM loans WHERE plan_id = ?");
$inUse->execute([$planId]);

if ($inUse->fetchColumn() > 0) {
    $conn->prepare("UPDATE loan_plans SET is_active = 0 WHERE plan_id = ?")->execute([$planId]);
    $_SESSION['success'] = "This plan is already linked to issued loans, so it was deactivated instead of deleted.";
} else {
    $conn->prepare("DELETE FROM loan_plans WHERE plan_id = ?")->execute([$planId]);
    $_SESSION['success'] = "Loan plan deleted successfully.";
}

$log = $conn->prepare("INSERT INTO activity_logs (admin_id, action, description) VALUES (?, ?, ?)");
$log->execute([$_SESSION['admin_id'], "Loan Plan Removed", "Admin removed/deactivated loan plan #$planId"]);

header("Location: ../frontend/loan_plans.php");
exit();
