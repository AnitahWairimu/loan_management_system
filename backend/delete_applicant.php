<?php

session_start();

if(!isset($_SESSION['admin_id'])){

    header("Location: ../frontend/login.html");
    exit();

}

include "db.php";


if(!isset($_GET['id'])){

    $_SESSION['error'] = "Applicant ID missing.";

    header("Location: ../frontend/applicants.php");
    exit();

}


$applicant_id = $_GET['id'];


// Soft Delete

$stmt = $conn->prepare(

"UPDATE loan_applicants

SET is_deleted = 1

WHERE applicant_id=?"

);

$stmt->execute([$applicant_id]);



// Activity Log

$action = "Applicant Deleted";

$description =
"Admin soft deleted applicant ID "
. $applicant_id;


$log = $conn->prepare(

"INSERT INTO activity_logs

(
admin_id,
action,
description
)

VALUES
(?,?,?)"

);

$log->execute([

$_SESSION['admin_id'],
$action,
$description

]);



$_SESSION['success'] =
"Applicant deleted successfully.";


header(
"Location: ../frontend/applicants.php"
);

exit();

?>