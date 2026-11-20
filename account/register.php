<?php
$conn = mysqli_connect("localhost", "root", "", "electronic_shop");

if (isset($_POST['register'])) {

    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $sql = "INSERT INTO users (full_name, email, phone, password_hash)
            VALUES ('$full_name', '$email', '$phone', '$password')";

    if (mysqli_query($conn, $sql)) {
        echo "Registration successful!";
    } else {
        echo "Registration failed!";
    }
}
?>

<h2>Register</h2>

<form method="POST">
    Full Name: <input type="text" name="full_name" required><br><br>
    Email: <input type="email" name="email" required><br><br>
    Phone: <input type="text" name="phone"><br><br>
    Password: <input type="password" name="password" required><br><br>

    <button type="submit" name="register">Register</button>
</form>