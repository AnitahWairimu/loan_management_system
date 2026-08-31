<?php

include "db.php";


$admin_name = "System Administrator";
$email = "admin@loan.com";
$password = "admin123";
$phone = "0700000000";


// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);



$sql = "INSERT INTO admin
(admin_name, admin_email, password, phone_number)

VALUES (?, ?, ?, ?)";


$stmt = $conn->prepare($sql);


$stmt->execute([
    $admin_name,
    $email,
    $hashed_password,
    $phone
]);


echo "Admin created successfully";


?>