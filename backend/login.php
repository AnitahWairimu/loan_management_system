<?php

session_start();

include "db.php";


if($_SERVER["REQUEST_METHOD"] == "POST"){


$email = $_POST['email'];
$password = $_POST['password'];



$sql = "SELECT * FROM admin WHERE admin_email = ?";


$stmt = $conn->prepare($sql);


$stmt->execute([$email]);


$admin = $stmt->fetch(PDO::FETCH_ASSOC);



if($admin){


    if(password_verify($password, $admin['password'])){


        $_SESSION['admin_id'] = $admin['admin_id'];

        $_SESSION['admin_name'] = $admin['admin_name'];


        header("Location: ../frontend/dashboard.php");

        exit();


    }

    else{

        echo "Incorrect password";

    }


}

else{

    echo "Admin not found";

}


}

?>