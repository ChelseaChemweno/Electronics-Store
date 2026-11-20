<?php
session_start();

$conn = mysqli_connect("localhost", "root", "", "electronic_shop");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (isset($_POST['save'])) {

    $id = $_SESSION['user_id'];
    $town = $_POST['town'];
    $street = $_POST['street'];

    $sql = "UPDATE users SET town='$town', street='$street'
            WHERE id='$id'";

    if (mysqli_query($conn, $sql)) {
        echo "Address saved successfully!";
    }
}
?>

<h2>My Address</h2>

<form method="POST">
    Town: <input type="text" name="town"><br><br>
    Street: <input type="text" name="street"><br><br>

    <button type="submit" name="save">Save Address</button>
</form>