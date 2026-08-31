<?php

session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: login.html");
    exit();
}

include "../backend/db.php";
include "../backend/helpers.php";

$id = $_GET['id'];

$stmt = $conn->prepare(
    "SELECT * FROM loan_applicants WHERE applicant_id=?"
);

$stmt->execute([$id]);
$applicant = $stmt->fetch(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Applicant</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .edit-container {
            max-width: 600px;
            margin: 30px auto;
        }

        .edit-card {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .edit-card h1 {
            color: #1f2937;
            margin-bottom: 30px;
            font-size: 24px;
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

        .form-group input, .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: #0f9d58;
            box-shadow: 0 0 0 3px rgba(15, 157, 88, 0.1);
        }

        .form-buttons {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }

        .btn-submit {
            flex: 1;
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

        .btn-cancel {
            flex: 1;
            padding: 12px;
            background: #6b7280;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
        }

        .btn-cancel:hover {
            background: #4b5563;
            transform: translateY(-2px);
        }

        .section-title {
            color: #0f9d58;
            font-size: 16px;
            font-weight: 600;
            margin-top: 25px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }
    </style>
</head>

<body>

<div class="edit-container">
    <div class="edit-card">
        <h1>✏️ Edit Applicant</h1>

        <?php if(isset($_SESSION['success'])): ?>
        <div class="success-message">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
        <div class="error-message">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
        <?php endif; ?>

        <form action="../backend/update_applicant.php" method="POST">

            <input type="hidden" name="applicant_id" value="<?php echo $applicant['applicant_id']; ?>">

            <div class="section-title">👤 Personal Information</div>

            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" value="<?php echo $applicant['full_name']; ?>" required>
            </div>

            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?php echo $applicant['email']; ?>">
            </div>

            <div class="form-group">
                <label>Phone Number *</label>
                <input type="text" name="phone_number" value="<?php echo $applicant['phone_number']; ?>" required>
            </div>

            <div class="form-group">
                <label>National ID *</label>
                <input type="text" name="national_id" value="<?php echo $applicant['national_id']; ?>" required>
            </div>

            
           

           

            <div class="section-title">💼 Employment Information</div>

            <div class="form-group">
                <label>Employment Status</label>
                <input type="text" name="employment_status" value="<?php echo h($applicant['employment_status'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>Monthly Income</label>
                <input type="number" step="0.01" name="monthly_income" value="<?php echo h($applicant['monthly_income'] ?? ''); ?>">
            </div>

           

            <div class="section-title">💰 Loan Information</div>

            <div class="form-group">
                <label>Loan Purpose</label>
                <input type="text" name="loan_purpose" value="<?php echo h($applicant['loan_purpose'] ?? ''); ?>">
            </div>

            

            <div class="form-buttons">
                <button type="submit" class="btn-submit">💾 Save Changes</button>
                <a href="applicants.php" class="btn-cancel">Cancel</a>
            </div>

        </form>
    </div>
</div>

</body>

</html>
