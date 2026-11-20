<?php
session_start();

$conn = mysqli_connect("localhost", "root", "", "electronic_shop");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$id = $_SESSION['user_id'];

$sql = "SELECT * FROM users WHERE id='$id'";
$result = mysqli_query($conn, $sql);

$user = mysqli_fetch_assoc($result);
?>

<h2>My Profile</h2>

<p>Name: <?php echo $user['full_name']; ?></p>
<p>Email: <?php echo $user['email']; ?></p>
<p>Phone: <?php echo $user['phone']; ?></p>
<p>Town: <?php echo $user['town']; ?></p>
<p>Street: <?php echo $user['street']; ?></p>

<a href="logout.php">Logout</a>