<?php

session_start();

$conn = mysqli_connect("localhost", "root", "", "electronic_shop");

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

if (isset($_POST['login'])) {

    $email = $_POST['email'];
    $password = $_POST['password'];

    $sql = "SELECT * FROM users WHERE email = ?";

    $stmt = mysqli_prepare($conn, $sql);

    mysqli_stmt_bind_param($stmt, "s", $email);

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $user = mysqli_fetch_assoc($result);

    if ($user && password_verify($password, $user['password_hash'])) {

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];

        if ($user['role'] === 'admin') {
            header("Location: ../admin/index.php");
        } else {
            header("Location: ../index.php");
        }
        exit();

    } else {

        echo "Invalid email or password!";

    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
  <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body  class="auth-page">

<div class="auth-container">

   

    <div class="auth-boxes">


        <div class="auth-box">

            <h1>Login</h1>

            <p>Access your account to explore and manage your orders.</p>

            <form method="POST" action="login.php">

                <label>Email</label>
                <input type="email" name="email" placeholder="you@example.com" required>

                <label>Password</label>
                <input type="password" name="password" placeholder="********" required>

                <button type="submit" name="login">Login</button>

            </form>

            <div class="switch-link">
                Don't have an account?
                <a href="register.php">Sign Up</a>
            </div>

        </div>

    </div>

</div>

</body>
</html>
