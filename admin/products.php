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

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// ======================================
// FETCH PRODUCTS
// ======================================

$sql = "SELECT products.*, categories.name AS category_name
        FROM products
        LEFT JOIN categories ON products.category_id = categories.id
        ORDER BY products.id DESC";

$result = $conn->query($sql);

if (!$result) {
    die("Error retrieving products: " . e($conn->error));
}

$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

$totalProducts = count($products);
$adminName     = $_SESSION['full_name'] ?? 'Administrator';
$saved         = isset($_GET['saved']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TechWorld | Products</title>

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

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: var(--background);
    color: var(--dark);
}

a { text-decoration: none; }

/* SIDEBAR */
.sidebar {
    width: 250px; height: 100vh; position: fixed; left: 0; top: 0;
    background: var(--sidebar); padding: 28px 18px;
    display: flex; flex-direction: column; z-index: 10;
}
.brand { color: white; font-size: 22px; font-weight: 750; padding: 0 12px 38px; letter-spacing: -.7px; }
.brand span { color: var(--gold); }
.menu-label { color: #85847c; font-size: 10px; letter-spacing: 1.6px; padding: 0 12px 14px; text-transform: uppercase; }
.sidebar a {
    display: flex; align-items: center; gap: 13px; color: #c4c2b9;
    padding: 13px 14px; border-radius: 9px; margin-bottom: 6px;
    font-size: 13px; transition: .2s;
}
.sidebar a i { width: 18px; text-align: center; }
.sidebar a:hover, .sidebar a.active { background: rgba(180,154,106,.17); color: #d9bd8b; }
.sidebar-bottom { margin-top: auto; border-top: 1px solid #44443c; padding-top: 18px; }

/* MAIN */
.main-content { margin-left: 250px; padding: 28px 35px 45px; min-height: 100vh; }
.topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 34px; }
.breadcrumb { font-size: 12px; color: var(--muted); }
.breadcrumb strong { color: var(--dark); }
.admin-profile { display: flex; align-items: center; gap: 12px; font-size: 13px; font-weight: 600; }
.profile-avatar {
    width: 38px; height: 38px; background: #e8dfcf; color: #806e5e;
    display: grid; place-items: center; border-radius: 50%;
}

.page-heading {
    display: flex; justify-content: space-between; align-items: center;
    gap: 20px; margin-bottom: 26px;
}
.page-heading h1 { font-size: 27px; letter-spacing: -.8px; margin-bottom: 8px; }
.page-heading p { color: var(--muted); font-size: 13px; }

.add-btn {
    background: var(--dark); color: white; padding: 13px 19px;
    border-radius: 8px; font-size: 12px; font-weight: 600; transition: .2s;
}
.add-btn:hover { background: var(--gold-dark); }
.add-btn i { margin-right: 8px; }

.alert-success {
    background: #eaf4ee; color: var(--green); border: 1px solid #cfe5d8;
    padding: 14px 18px; border-radius: 9px; font-size: 12px; margin-bottom: 22px;
}

/* PANEL + TABLE */
.panel {
    background: white; border: 1px solid var(--border);
    border-radius: 12px; padding: 23px; min-width: 0;
}
.panel-heading { display: flex; align-items: center; justify-content: space-between; margin-bottom: 21px; }
.panel-heading h3 { font-size: 15px; }
.count { color: var(--muted); font-size: 11px; }

.table-wrap { overflow-x: auto; }

table { width: 100%; border-collapse: collapse; white-space: nowrap; }

th {
    text-align: left; color: var(--muted); font-size: 10px;
    text-transform: uppercase; letter-spacing: .5px; font-weight: 600;
    padding: 12px 10px; border-bottom: 1px solid var(--border);
}

td { padding: 14px 10px; font-size: 12px; border-bottom: 1px solid #f2f0eb; vertical-align: middle; }

.product-img {
    width: 52px; height: 52px; object-fit: cover;
    border-radius: 8px; border: 1px solid var(--border); display: block;
}
.no-img {
    width: 52px; height: 52px; border-radius: 8px; background: #f3efe7;
    color: var(--gold-dark); display: grid; place-items: center; font-size: 15px;
}

.product-name { font-weight: 600; }

.old-price { color: var(--muted); text-decoration: line-through; font-size: 11px; display: block; }
.sale-price { color: var(--red); font-weight: 700; }

.badge {
    padding: 6px 10px; border-radius: 20px; font-size: 10px; font-weight: 600;
    background: #eaf4ee; color: var(--green);
}
.badge.low { background: #f9f1df; color: #a17a31; }
.badge.out { background: #faeae7; color: var(--red); }

.edit-link {
    display: inline-flex; align-items: center; gap: 6px;
    color: var(--gold-dark); font-weight: 650; font-size: 11px;
    padding: 8px 12px; border-radius: 7px; background: #f8f6f2;
    border: 1px solid #f0ede6; transition: .2s;
}
.edit-link:hover { border-color: var(--gold); background: #f4f0e7; }

.empty { color: var(--muted); padding: 40px 10px; text-align: center; }
.empty i { font-size: 28px; color: #d6c6a5; display: block; margin-bottom: 12px; }
.empty a { color: var(--gold-dark); font-weight: 650; }

@media (max-width: 700px) {
    .sidebar { width: 65px; padding: 22px 8px; }
    .brand { font-size: 0; padding: 0 8px 35px; }
    .brand span { font-size: 19px; }
    .menu-label, .sidebar a span { display: none; }
    .sidebar a { justify-content: center; padding: 14px 5px; }
    .main-content { margin-left: 65px; padding: 22px 15px; }
    .page-heading { flex-direction: column; align-items: flex-start; }
    .admin-profile span { display: none; }
}
</style>
</head>

<body>

<aside class="sidebar">
    <div class="brand">Tech<span>World.</span></div>

    <p class="menu-label">Main Menu</p>

    <a href="index.php"><i class="fa-solid fa-house"></i><span>Dashboard</span></a>
    <a href="products.php" class="active"><i class="fa-solid fa-box"></i><span>Products</span></a>
    <a href="categories.php"><i class="fa-solid fa-layer-group"></i><span>Categories</span></a>
    <a href="orders.php"><i class="fa-solid fa-cart-shopping"></i><span>Orders</span></a>
    <a href="users.php"><i class="fa-solid fa-users"></i><span>Customers</span></a>

    <div class="sidebar-bottom">
        <a href="../index.php"><i class="fa-solid fa-store"></i><span>Visit Store</span></a>
        <a href="../auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </div>
</aside>

<main class="main-content">

    <header class="topbar">
        <div class="breadcrumb">
            Admin Panel / <strong>Products</strong>
        </div>

        <div class="admin-profile">
            <div class="profile-avatar"><i class="fa-solid fa-user"></i></div>
            <span><?= e($adminName) ?></span>
        </div>
    </header>

    <section class="page-heading">
        <div>
            <h1>Products</h1>
            <p>Manage the products displayed in your online shop.</p>
        </div>

        <a href="product-form.php" class="add-btn">
            <i class="fa-solid fa-plus"></i>
            Add New Product
        </a>
    </section>

    <?php if ($saved): ?>
        <div class="alert-success">
            <i class="fa-solid fa-circle-check"></i>
            Product saved successfully.
        </div>
    <?php endif; ?>

    <section class="panel">

        <div class="panel-heading">
            <h3>All Products</h3>
            <span class="count"><?= number_format($totalProducts) ?> total</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!empty($products)): ?>

                    <?php foreach ($products as $product): ?>

                        <?php
                        $stock = (int)($product['stock'] ?? 0);

                        if ($stock <= 0) {
                            $stockClass = 'out';
                            $stockLabel = 'Out of stock';
                        } elseif ($stock <= 5) {
                            $stockClass = 'low';
                            $stockLabel = $stock . ' left';
                        } else {
                            $stockClass = '';
                            $stockLabel = $stock . ' in stock';
                        }

                        $hasSale = isset($product['sale_price'])
                                   && $product['sale_price'] !== null
                                   && $product['sale_price'] !== ''
                                   && (float)$product['sale_price'] > 0;
                        ?>

                        <tr>
                            <td>
                                <?php if (!empty($product['image'])): ?>
                                    <img src="../<?= e($product['image']) ?>"
                                         alt="<?= e($product['name']) ?>"
                                         class="product-img">
                                <?php else: ?>
                                    <div class="no-img"><i class="fa-regular fa-image"></i></div>
                                <?php endif; ?>
                            </td>

                            <td class="product-name"><?= e($product['name']) ?></td>

                            <td><?= e($product['category_name'] ?? 'Uncategorised') ?></td>

                            <td>
                                <?php if ($hasSale): ?>
                                    <span class="old-price">KSh <?= number_format((float)$product['price'], 2) ?></span>
                                    <span class="sale-price">KSh <?= number_format((float)$product['sale_price'], 2) ?></span>
                                <?php else: ?>
                                    KSh <?= number_format((float)$product['price'], 2) ?>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge <?= e($stockClass) ?>"><?= e($stockLabel) ?></span>
                            </td>

                            <td>
                                <a href="product-form.php?id=<?= (int)$product['id'] ?>" class="edit-link">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </a>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="6" class="empty">
                            <i class="fa-solid fa-box-open"></i>
                            No products have been added yet.
                            <a href="product-form.php">Add your first product →</a>
                        </td>
                    </tr>

                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </section>

</main>

</body>
</html>