<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);


session_start();

require_once __DIR__ . '/../config/db.php';


// ======================================
// ADMIN ACCESS PROTECTION
// ======================================

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// ======================================
// HELPER FUNCTIONS
// ======================================

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExists($conn, $table) {
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}

function getCount($conn, $table) {
    if (!tableExists($conn, $table)) {
        return 0;
    }

    $result = $conn->query("SELECT COUNT(*) AS total FROM `$table`");

    return $result ? (int)$result->fetch_assoc()['total'] : 0;
}

function getColumns($conn, $table) {
    $columns = [];

    if (!tableExists($conn, $table)) {
        return $columns;
    }

    $result = $conn->query("SHOW COLUMNS FROM `$table`");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }

    return $columns;
}

function findColumn($columns, $options) {
    foreach ($options as $option) {
        if (in_array($option, $columns, true)) {
            return $option;
        }
    }

    return null;
}

// ======================================
// DASHBOARD STATISTICS
// ======================================

$totalProducts = getCount($conn, 'products');
$totalCategories = getCount($conn, 'categories');
$totalOrders = getCount($conn, 'orders');
$totalUsers = getCount($conn, 'users');

// ======================================
// PENDING ORDERS
// ======================================

$pendingOrders = 0;

$orderColumns = getColumns($conn, 'orders');

$statusColumn = findColumn(
    $orderColumns,
    ['status', 'order_status']
);

if ($statusColumn) {
    $sql = "SELECT COUNT(*) AS total
            FROM orders
            WHERE `$statusColumn` = 'pending'";

    $result = $conn->query($sql);

    if ($result) {
        $pendingOrders = (int)$result->fetch_assoc()['total'];
    }
}

// ======================================
// RECENT ORDERS
// ======================================

$recentOrders = [];

if (tableExists($conn, 'orders')) {

    $idColumn = findColumn($orderColumns, ['id', 'order_id']);

    $dateColumn = findColumn(
        $orderColumns,
        ['created_at', 'order_date', 'date']
    );

    $totalColumn = findColumn(
        $orderColumns,
        ['total_amount', 'total', 'grand_total']
    );

    if ($idColumn) {

        $select = "`$idColumn` AS order_id";

        $select .= $dateColumn
            ? ", `$dateColumn` AS order_date"
            : ", NULL AS order_date";

        $select .= $totalColumn
            ? ", `$totalColumn` AS order_total"
            : ", NULL AS order_total";

        $select .= $statusColumn
            ? ", `$statusColumn` AS order_status"
            : ", 'Unknown' AS order_status";

        $sql = "SELECT $select FROM orders";

        if ($dateColumn) {
            $sql .= " ORDER BY `$dateColumn` DESC";
        } else {
            $sql .= " ORDER BY `$idColumn` DESC";
        }

        $sql .= " LIMIT 5";

        $result = $conn->query($sql);

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $recentOrders[] = $row;
            }
        }
    }
}

// ======================================
// LOW STOCK PRODUCTS
// ======================================

$lowStock = [];

$productColumns = getColumns($conn, 'products');

$productIdColumn = findColumn($productColumns, ['id', 'product_id']);
$productNameColumn = findColumn($productColumns, ['name', 'product_name']);
$stockColumn = findColumn($productColumns, ['stock', 'quantity', 'stock_quantity']);

if ($productIdColumn && $productNameColumn && $stockColumn) {

    $sql = "SELECT
                `$productIdColumn` AS id,
                `$productNameColumn` AS name,
                `$stockColumn` AS stock
            FROM products
            WHERE `$stockColumn` <= 5
            ORDER BY `$stockColumn` ASC
            LIMIT 5";

    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $lowStock[] = $row;
        }
    }
}

// ======================================
// ADMIN NAME
// ======================================

$adminName = $_SESSION['full_name'] ?? 'Administrator';

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>TechWorld | Admin Dashboard</title>

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

:root {
    --gold: #b49a6a;
    --gold-dark: #92784e;
    --dark: #282722;
    --sidebar: #292923;
    --background: #f6f4f0;
    --white: #ffffff;
    --muted: #89867f;
    --border: #eeece6;
    --green: #4d9270;
    --red: #b65c4c;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: var(--background);
    color: var(--dark);
}

a {
    text-decoration: none;
}

/* SIDEBAR */

.sidebar {
    width: 250px;
    height: 100vh;
    position: fixed;
    left: 0;
    top: 0;
    background: var(--sidebar);
    padding: 28px 18px;
    display: flex;
    flex-direction: column;
    z-index: 10;
}

.brand {
    color: white;
    font-size: 22px;
    font-weight: 750;
    padding: 0 12px 38px;
    letter-spacing: -.7px;
}

.brand span {
    color: var(--gold);
}

.menu-label {
    color: #85847c;
    font-size: 10px;
    letter-spacing: 1.6px;
    padding: 0 12px 14px;
    text-transform: uppercase;
}

.sidebar a {
    display: flex;
    align-items: center;
    gap: 13px;
    color: #c4c2b9;
    padding: 13px 14px;
    border-radius: 9px;
    margin-bottom: 6px;
    font-size: 13px;
    transition: .2s;
}

.sidebar a i {
    width: 18px;
    text-align: center;
}

.sidebar a:hover,
.sidebar a.active {
    background: rgba(180,154,106,.17);
    color: #d9bd8b;
}

.sidebar-bottom {
    margin-top: auto;
    border-top: 1px solid #44443c;
    padding-top: 18px;
}

/* MAIN CONTENT */

.main-content {
    margin-left: 250px;
    padding: 28px 35px 45px;
    min-height: 100vh;
}

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 34px;
}

.breadcrumb {
    font-size: 12px;
    color: var(--muted);
}

.breadcrumb strong {
    color: var(--dark);
}

.admin-profile {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    font-weight: 600;
}

.profile-avatar {
    width: 38px;
    height: 38px;
    background: #e8dfcf;
    color: #806e5e;
    display: grid;
    place-items: center;
    border-radius: 50%;
}

/* WELCOME SECTION */

.welcome-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 28px;
}

.welcome-section h1 {
    font-size: 27px;
    letter-spacing: -.8px;
    margin-bottom: 8px;
}

.welcome-section p {
    color: var(--muted);
    font-size: 13px;
}

.add-btn {
    background: var(--dark);
    color: white;
    padding: 13px 19px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    transition: .2s;
}

.add-btn:hover {
    background: var(--gold-dark);
}

.add-btn i {
    margin-right: 8px;
}

/* STATISTICS */

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 17px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--white);
    padding: 21px;
    border-radius: 12px;
    border: 1px solid var(--border);
    transition: .2s;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,.045);
}

.stat-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.stat-icon {
    width: 39px;
    height: 39px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    color: var(--gold-dark);
    background: #f3efe7;
    font-size: 15px;
}

.stat-card h2 {
    font-size: 28px;
    letter-spacing: -1px;
    margin-bottom: 5px;
}

.stat-card p {
    color: var(--muted);
    font-size: 12px;
}

/* PANELS */

.content-grid {
    display: grid;
    grid-template-columns: 1.6fr 1fr;
    gap: 19px;
    margin-bottom: 20px;
}

.panel {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 23px;
    min-width: 0;
}

.panel-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 21px;
}

.panel-heading h3 {
    font-size: 15px;
}

.view-all {
    color: var(--gold-dark);
    font-size: 11px;
    font-weight: 650;
}

/* PERFORMANCE CHART */

.chart-subtitle {
    color: var(--muted);
    font-size: 11px;
    margin-top: 5px;
}

.chart-area {
    height: 190px;
    display: flex;
    align-items: end;
    gap: 13px;
    padding-top: 20px;
    border-bottom: 1px solid var(--border);
    background: repeating-linear-gradient(
        to bottom,
        transparent 0,
        transparent 46px,
        #f0eee9 47px
    );
}

.chart-column {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: end;
    height: 100%;
    align-items: center;
    gap: 9px;
}

.chart-bar {
    width: min(29px, 75%);
    border-radius: 5px 5px 0 0;
    background: #d6c6a5;
}

.chart-bar.highlight {
    background: var(--gold);
}

.chart-column span {
    font-size: 10px;
    color: var(--muted);
}

/* QUICK ACTIONS */

.quick-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.action-link {
    padding: 17px 12px;
    background: #f8f6f2;
    border: 1px solid #f0ede6;
    border-radius: 9px;
    color: var(--dark);
    transition: .2s;
}

.action-link:hover {
    border-color: var(--gold);
    background: #f4f0e7;
}

.action-link i {
    color: var(--gold-dark);
    display: block;
    font-size: 17px;
    margin-bottom: 13px;
}

.action-link strong {
    font-size: 11px;
    display: block;
}

.action-link small {
    font-size: 10px;
    color: var(--muted);
    display: block;
    margin-top: 5px;
}

/* TABLES */

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    white-space: nowrap;
}

th {
    text-align: left;
    color: var(--muted);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .5px;
    font-weight: 600;
    padding: 12px 10px;
    border-bottom: 1px solid var(--border);
}

td {
    padding: 15px 10px;
    font-size: 12px;
    border-bottom: 1px solid #f2f0eb;
}

.status {
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 10px;
    text-transform: capitalize;
    background: #f2efe8;
    color: #806e5e;
}

.status.completed,
.status.delivered {
    background: #eaf4ee;
    color: var(--green);
}

.status.pending,
.status.processing {
    background: #f9f1df;
    color: #a17a31;
}

.status.cancelled {
    background: #faeae7;
    color: var(--red);
}

.low-stock {
    color: var(--red);
    font-weight: 700;
}

.stock-link {
    color: var(--gold-dark);
    font-weight: 650;
    font-size: 11px;
}

.empty {
    color: var(--muted);
    padding: 20px 10px;
    text-align: center;
}

/* FOOTER */

.footer {
    color: var(--muted);
    text-align: center;
    font-size: 10px;
    padding: 25px 0 0;
}

/* RESPONSIVE DESIGN */

@media(max-width: 1100px) {

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .content-grid {
        grid-template-columns: 1fr;
    }
}

@media(max-width: 700px) {

    .sidebar {
        width: 65px;
        padding: 22px 8px;
    }

    .brand {
        font-size: 0;
        padding: 0 8px 35px;
    }

    .brand span {
        font-size: 19px;
    }

    .menu-label,
    .sidebar a span {
        display: none;
    }

    .sidebar a {
        justify-content: center;
        padding: 14px 5px;
    }

    .main-content {
        margin-left: 65px;
        padding: 22px 15px;
    }

    .welcome-section {
        align-items: flex-start;
        flex-direction: column;
    }

    .welcome-section h1 {
        font-size: 23px;
    }
}

@media(max-width: 430px) {

    .stats-grid {
        gap: 9px;
    }

    .stat-card {
        padding: 14px;
    }

    .stat-card h2 {
        font-size: 23px;
    }

    .admin-profile span {
        display: none;
    }
}

</style>

</head>

<body>

<!-- ======================================
     SIDEBAR
====================================== -->

<aside class="sidebar">

    <div class="brand">
        Tech<span>World.</span>
    </div>

    <p class="menu-label">Main Menu</p>

    <a href="index.php" class="active">
        <i class="fa-solid fa-house"></i>
        <span>Dashboard</span>
    </a>

    <a href="products.php">
        <i class="fa-solid fa-box"></i>
        <span>Products</span>
    </a>

    <a href="categories.php">
        <i class="fa-solid fa-layer-group"></i>
        <span>Categories</span>
    </a>

    <a href="orders.php">
        <i class="fa-solid fa-cart-shopping"></i>
        <span>Orders</span>
    </a>

    <a href="users.php">
        <i class="fa-solid fa-users"></i>
        <span>Customers</span>
    </a>

    <div class="sidebar-bottom">

        <a href="../index.php">
            <i class="fa-solid fa-store"></i>
            <span>Visit Store</span>
        </a>

        <a href="../auth/logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>

    </div>

</aside>


<!-- ======================================
     MAIN CONTENT
====================================== -->

<main class="main-content">

    <!-- TOP BAR -->

    <header class="topbar">

        <div class="breadcrumb">
            Admin Panel / <strong>Dashboard</strong>
        </div>

        <div class="admin-profile">

            <div class="profile-avatar">
                <i class="fa-solid fa-user"></i>
            </div>

            <span><?= e($adminName) ?></span>

        </div>

    </header>


    <!-- WELCOME SECTION -->

    <section class="welcome-section">

        <div>

            <h1>
                Welcome back, <?= e($adminName) ?> 👋
            </h1>

            <p>
                Here's what's happening with your store today.
            </p>

        </div>

        <a href="product-form.php" class="add-btn">
            <i class="fa-solid fa-plus"></i>
            Add New Product
        </a>

    </section>


    <!-- ======================================
         DASHBOARD STATISTICS
    ====================================== -->

    <section class="stats-grid">

        <div class="stat-card">

            <div class="stat-top">
                <p>Total Products</p>

                <div class="stat-icon">
                    <i class="fa-solid fa-box"></i>
                </div>
            </div>

            <h2><?= number_format($totalProducts) ?></h2>

            <p>Products in your store</p>

        </div>


        <div class="stat-card">

            <div class="stat-top">
                <p>Total Categories</p>

                <div class="stat-icon">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
            </div>

            <h2><?= number_format($totalCategories) ?></h2>

            <p>Product categories</p>

        </div>


        <div class="stat-card">

            <div class="stat-top">
                <p>Total Orders</p>

                <div class="stat-icon">
                    <i class="fa-solid fa-bag-shopping"></i>
                </div>
            </div>

            <h2><?= number_format($totalOrders) ?></h2>

            <p>Orders received</p>

        </div>


        <div class="stat-card">

            <div class="stat-top">
                <p>Total Customers</p>

                <div class="stat-icon">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>

            <h2><?= number_format($totalUsers) ?></h2>

            <p>Registered accounts</p>

        </div>

    </section>


    <!-- ======================================
         CHART AND QUICK ACTIONS
    ====================================== -->

    <section class="content-grid">


        <!-- STORE PERFORMANCE -->

        <div class="panel">

            <div class="panel-heading">

                <div>

                    <h3>Store Performance</h3>

                    <p class="chart-subtitle">
                        Sample activity overview
                    </p>

                </div>

                <i class="fa-solid fa-chart-line"
                   style="color:#b49a6a"></i>

            </div>


            <div class="chart-area">

                <?php

                $heights = [
                    35, 55, 43, 72, 58, 88,
                    68, 100, 75, 92, 65, 83
                ];

                $months = [
                    'Jan', 'Feb', 'Mar', 'Apr',
                    'May', 'Jun', 'Jul', 'Aug',
                    'Sep', 'Oct', 'Nov', 'Dec'
                ];

                foreach ($heights as $index => $height):

                ?>

                    <div class="chart-column">

                        <div
                            class="chart-bar <?= $index === 7 ? 'highlight' : '' ?>"
                            style="height:<?= $height ?>%">
                        </div>

                        <span><?= $months[$index] ?></span>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>


        <!-- QUICK ACTIONS -->

        <div class="panel">

            <div class="panel-heading">
                <h3>Quick Actions</h3>
            </div>

            <div class="quick-actions">

                <a href="product-form.php" class="action-link">

                    <i class="fa-solid fa-plus"></i>

                    <strong>Add Product</strong>

                    <small>Add a new item</small>

                </a>


                <a href="products.php" class="action-link">

                    <i class="fa-solid fa-boxes-stacked"></i>

                    <strong>Products</strong>

                    <small>Manage inventory</small>

                </a>


                <a href="categories.php" class="action-link">

                    <i class="fa-solid fa-folder"></i>

                    <strong>Categories</strong>

                    <small>Organise products</small>

                </a>


                <a href="orders.php" class="action-link">

                    <i class="fa-solid fa-receipt"></i>

                    <strong>Orders</strong>

                    <small>Manage purchases</small>

                </a>

            </div>

        </div>

    </section>


    <!-- ======================================
         RECENT ORDERS
    ====================================== -->

    <section class="panel" style="margin-bottom:20px">

        <div class="panel-heading">

            <h3>Recent Orders</h3>

            <a href="orders.php" class="view-all">
                View all orders →
            </a>

        </div>


        <div class="table-wrap">

            <table>

                <thead>

                    <tr>
                        <th>Order ID</th>
                        <th>Date</th>
                        <th>Order Amount</th>
                        <th>Status</th>
                    </tr>

                </thead>


                <tbody>

                <?php if (!empty($recentOrders)): ?>

                    <?php foreach ($recentOrders as $order): ?>

                        <?php
                        $status = strtolower(
                            $order['order_status'] ?? 'unknown'
                        );
                        ?>

                        <tr>

                            <td>
                                <strong>
                                    #<?= e($order['order_id']) ?>
                                </strong>
                            </td>


                            <td>

                                <?= !empty($order['order_date'])
                                    ? e(date(
                                        'd M Y',
                                        strtotime($order['order_date'])
                                    ))
                                    : '—' ?>

                            </td>


                            <td>

                                <?= $order['order_total'] !== null
                                    ? 'KSh ' . number_format(
                                        (float)$order['order_total'], 2
                                    )
                                    : '—' ?>

                            </td>


                            <td>

                                <span class="status <?= e($status) ?>">
                                    <?= e($status) ?>
                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="4" class="empty">
                            No orders available yet.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- ======================================
         LOW STOCK ALERTS
    ====================================== -->

    <section class="panel">

        <div class="panel-heading">

            <h3>Low Stock Alerts</h3>

            <a href="products.php" class="view-all">
                Manage inventory →
            </a>

        </div>


        <div class="table-wrap">

            <table>

                <thead>

                    <tr>
                        <th>Product Name</th>
                        <th>Available Stock</th>
                        <th>Action</th>
                    </tr>

                </thead>


                <tbody>

                <?php if (!empty($lowStock)): ?>

                    <?php foreach ($lowStock as $product): ?>

                        <tr>

                            <td>
                                <?= e($product['name']) ?>
                            </td>

                            <td class="low-stock">

                                <?= (int)$product['stock'] ?> left

                            </td>

                            <td>

                                <a
                                    class="stock-link"
                                    href="product-form.php?id=<?= (int)$product['id'] ?>">

                                    Update stock

                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="3" class="empty">

                            All products have sufficient stock.

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- FOOTER -->

    <footer class="footer">

        © <?= date('Y') ?> ElectroShop. Admin Dashboard.

    </footer>

</main>

</body>
</html>