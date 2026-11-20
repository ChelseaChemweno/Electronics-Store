<?php
$conn = mysqli_connect("localhost", "root", "", "electronic_shop");

if (isset($_POST['reset'])) {

    $email = $_POST['email'];

    $sql = "SELECT * FROM users WHERE email='$email'";
    $result = mysqli_query($conn, $sql);

    if (mysqli_num_rows($result) > 0) {
        echo "Email found! Password reset can proceed.";
    } else {
        echo "Email not found!";
    }
}
?>

<h2>Forgot Password</h2>

<form method="POST">
    Email: <input type="email" name="email" required><br><br>

    <button type="submit" name="reset">Reset Password</button>
</form>