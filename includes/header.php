<?php
require_once __DIR__ . '/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/ElectronicShop');
}
?>

<header>

    <h2>TECHWORLD</h2>

    <nav>
        <a href="<?php echo BASE_URL; ?>/">Home</a>

        <a href="<?php echo BASE_URL; ?>/shop/">Shop</a>

        <a href="<?php echo BASE_URL; ?>/shop/">New Arrivals</a>

        
        <a href="<?php echo BASE_URL; ?>/shop/">Deals</a>

    </nav>

    <div class="icons">

        <a href="<?php echo BASE_URL; ?>/cart/">🛒</a>

        <a href="<?php echo BASE_URL; ?>/account/login.php">♡</a>

        <a href="<?php echo BASE_URL; ?>/account/login.php">👤</a>

    </div>

</header>