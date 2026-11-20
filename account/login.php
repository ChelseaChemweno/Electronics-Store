
<?php

session_start();

$conn = mysqli_connect("localhost", "root", "", "electronic_shop");

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

if (isset($_POST['login'])) {

    $email = $_POST['email'];
    $password = $_POST['password'];

    // Use prepared statement
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

        echo "Login successful!";

    } else {

        echo "Invalid email or password!";

    }

}

?>

<h2>Login</h2>

<form method="POST" action="/ElectronicShop/account/login.php">

    Email:

    <input type="email" name="email" required>

    <br><br>

    Password:

    <input type="password" name="password" required>

    <br><br>

    <button type="submit" name="login">Login</button>

</form>
