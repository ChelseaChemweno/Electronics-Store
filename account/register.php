
<?php

$conn = mysqli_connect("localhost", "root", "", "electronic_shop");

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

if (isset($_POST['register'])) {

    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $sql = "INSERT INTO users (full_name, email, phone, password_hash)
            VALUES (?, ?, ?, ?)";

    $stmt = mysqli_prepare($conn, $sql);

    mysqli_stmt_bind_param($stmt, "ssss", $full_name, $email, $phone, $password);

    if (mysqli_stmt_execute($stmt)) {

        header("Location: login.php");
        exit();

    } else {

        echo "Registration failed!";

    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Account</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="auth-page">

<div class="auth-container">

  

    <div class="auth-boxes">

        <div class="auth-box">

            <h1>Create Account</h1>

            <p>Sign up to access exclusive collections and manage your orders.</p>

            <form method="POST" action="register.php">

                <label>Full Name</label>
                <input type="text" name="full_name" placeholder="Your Name" required>

                <label>Email</label>
                <input type="email" name="email" placeholder="you@example.com" required>

                <label>Phone</label>
                <input type="text" name="phone" placeholder="Your Phone Number">

                <label>Password</label>
                <input type="password" name="password" placeholder="********" required>

                <button type="submit" name="register">Sign Up</button>

            </form>

            <div class="switch-link">
                Already have an account?
                <a href="login.php">Login</a>
            </div>

        </div>

    </div>

</div>

</body>
</html>
