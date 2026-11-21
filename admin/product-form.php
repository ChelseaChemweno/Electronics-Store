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

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ======================================
// LOAD EXISTING PRODUCT (EDIT MODE)
// ======================================

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit    = $productId > 0;

$product = [
    'name'        => '',
    'description' => '',
    'category_id' => '',
    'price'       => '',
    'sale_price'  => '',
    'stock'       => 0,
    'image'       => '',
];

if ($isEdit) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
        header("Location: products.php");
        exit();
    }

    $product = array_merge($product, $existing);
}

// ======================================
// LOAD CATEGORIES
// ======================================

$categories = [];
$catResult = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
if ($catResult) {
    while ($row = $catResult->fetch_assoc()) {
        $categories[] = $row;
    }
}

// ======================================
// HANDLE FORM SUBMISSION
// ======================================

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid form submission. Please try again.";
    }

    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $categoryId  = (int)($_POST['category_id'] ?? 0);
    $price       = trim($_POST['price'] ?? '');
    $salePrice   = trim($_POST['sale_price'] ?? '');
    $stock       = (int)($_POST['stock'] ?? 0);
    $image       = $product['image'];

    // Validation
    if ($name === '') {
        $errors[] = "Product name is required.";
    }

    if ($price === '' || !is_numeric($price) || (float)$price < 0) {
        $errors[] = "Enter a valid price.";
    }

    if ($salePrice !== '') {
        if (!is_numeric($salePrice) || (float)$salePrice < 0) {
            $errors[] = "Enter a valid sale price.";
        } elseif (is_numeric($price) && (float)$salePrice >= (float)$price) {
            $errors[] = "Sale price must be lower than the regular price.";
        }
    }

    if ($stock < 0) {
        $errors[] = "Stock cannot be negative.";
    }

    // Image upload (optional)
    if (!empty($_FILES['image']['name'])) {

        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Image upload failed. Please try again.";
        } elseif ($_FILES['image']['size'] > 2 * 1024 * 1024) {
            $errors[] = "Image must be smaller than 2MB.";
        } else {
            $finfo   = new finfo(FILEINFO_MIME_TYPE);
            $mime    = $finfo->file($_FILES['image']['tmp_name']);
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
            ];

            if (!isset($allowed[$mime])) {
                $errors[] = "Only JPG, PNG or WEBP images are allowed.";
            } else {
                $uploadDir = __DIR__ . '/../uploads/products/';

                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $fileName = bin2hex(random_bytes(10)) . '.' . $allowed[$mime];

                if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $fileName)) {
                    $image = 'uploads/products/' . $fileName;
                } else {
                    $errors[] = "Could not save the uploaded image.";
                }
            }
        }
    }

    // Save
    if (empty($errors)) {

        $priceVal     = (float)$price;
        $salePriceVal = $salePrice === '' ? null : $salePrice;
        $categoryVal  = $categoryId > 0 ? $categoryId : null;

        if ($isEdit) {
            $stmt = $conn->prepare(
                "UPDATE products
                 SET name = ?, description = ?, category_id = ?, price = ?,
                     sale_price = ?, stock = ?, image = ?
                 WHERE id = ?"
            );
            $stmt->bind_param(
                "ssidsisi",
                $name, $description, $categoryVal, $priceVal,
                $salePriceVal, $stock, $image, $productId
            );
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO products
                 (name, description, category_id, price, sale_price, stock, image)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "ssidsis",
                $name, $description, $categoryVal, $priceVal,
                $salePriceVal, $stock, $image
            );
        }

        if ($stmt->execute()) {
            $stmt->close();
            header("Location: products.php?saved=1");
            exit();
        }

        $errors[] = "Database error: " . $stmt->error;
        $stmt->close();
    }

    // Keep submitted values in the form
    $product = array_merge($product, [
        'name'        => $name,
        'description' => $description,
        'category_id' => $categoryId,
        'price'       => $price,
        'sale_price'  => $salePrice,
        'stock'       => $stock,
        'image'       => $image,
    ]);
}

$adminName = $_SESSION['full_name'] ?? 'Administrator';
$pageTitle = $isEdit ? 'Edit Product' : 'Add New Product';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TechWorld | <?= e($pageTitle) ?></title>

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

.page-heading { margin-bottom: 24px; }
.page-heading h1 { font-size: 27px; letter-spacing: -.8px; margin-bottom: 8px; }
.page-heading p { color: var(--muted); font-size: 13px; }

/* FORM */
.panel {
    background: white; border: 1px solid var(--border);
    border-radius: 12px; padding: 28px; max-width: 860px;
}

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.form-group { display: flex; flex-direction: column; gap: 7px; }
.form-group.full { grid-column: 1 / -1; }

label { font-size: 12px; font-weight: 600; }
label small { color: var(--muted); font-weight: 400; }

input[type="text"],
input[type="number"],
input[type="file"],
select,
textarea {
    width: 100%; padding: 12px 14px; font: inherit; font-size: 13px;
    border: 1px solid #e3e0d8; border-radius: 8px; background: #fcfbf9;
    color: var(--dark); transition: .2s;
}

input:focus, select:focus, textarea:focus {
    outline: none; border-color: var(--gold); background: white;
    box-shadow: 0 0 0 3px rgba(180,154,106,.15);
}

textarea { min-height: 120px; resize: vertical; }

.current-image { display: flex; align-items: center; gap: 14px; font-size: 12px; color: var(--muted); }
.current-image img { width: 64px; height: 64px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border); }

#preview { display: none; width: 90px; height: 90px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border); }

.form-actions { display: flex; gap: 12px; margin-top: 28px; }

.btn {
    padding: 13px 22px; border-radius: 8px; font-size: 12px; font-weight: 600;
    border: none; cursor: pointer; transition: .2s; font-family: inherit;
}
.btn-primary { background: var(--dark); color: white; }
.btn-primary:hover { background: var(--gold-dark); }
.btn-secondary { background: #f3efe7; color: #806e5e; display: inline-block; }
.btn-secondary:hover { background: #ebe5d8; }
.btn i { margin-right: 8px; }

.alert {
    background: #faeae7; color: var(--red); border: 1px solid #f0cfc9;
    padding: 14px 18px; border-radius: 9px; font-size: 12px;
    margin-bottom: 22px; max-width: 860px;
}
.alert ul { padding-left: 18px; }

@media (max-width: 700px) {
    .sidebar { width: 65px; padding: 22px 8px; }
    .brand { font-size: 0; padding: 0 8px 35px; }
    .brand span { font-size: 19px; }
    .menu-label, .sidebar a span { display: none; }
    .sidebar a { justify-content: center; padding: 14px 5px; }
    .main-content { margin-left: 65px; padding: 22px 15px; }
    .form-grid { grid-template-columns: 1fr; }
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
            Admin Panel / <a href="products.php">Products</a> / <strong><?= e($pageTitle) ?></strong>
        </div>

        <div class="admin-profile">
            <div class="profile-avatar"><i class="fa-solid fa-user"></i></div>
            <span><?= e($adminName) ?></span>
        </div>
    </header>

    <div class="page-heading">
        <h1><?= e($pageTitle) ?></h1>
        <p><?= $isEdit
            ? 'Update the details of this product.'
            : 'Fill in the details below to add a product to your store.' ?></p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="panel">

        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

        <div class="form-grid">

            <div class="form-group full">
                <label for="name">Product Name</label>
                <input type="text" id="name" name="name"
                       value="<?= e($product['name']) ?>" required>
            </div>

            <div class="form-group">
                <label for="category_id">Category</label>
                <select id="category_id" name="category_id">
                    <option value="0">Uncategorised</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>"
                            <?= (int)$product['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= e($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="stock">Stock Quantity</label>
                <input type="number" id="stock" name="stock" min="0"
                       value="<?= (int)$product['stock'] ?>" required>
            </div>

            <div class="form-group">
                <label for="price">Price (KSh)</label>
                <input type="number" id="price" name="price" step="0.01" min="0"
                       value="<?= e($product['price']) ?>" required>
            </div>

            <div class="form-group">
                <label for="sale_price">Sale Price (KSh) <small>— optional</small></label>
                <input type="number" id="sale_price" name="sale_price" step="0.01" min="0"
                       value="<?= e($product['sale_price']) ?>">
            </div>

            <div class="form-group full">
                <label for="description">Description</label>
                <textarea id="description" name="description"><?= e($product['description']) ?></textarea>
            </div>

            <div class="form-group full">
                <label for="image">Product Image <small>— JPG, PNG or WEBP, max 2MB</small></label>

                <?php if (!empty($product['image'])): ?>
                    <div class="current-image">
                        <img src="../<?= e($product['image']) ?>" alt="Current image">
                        <span>Current image. Upload a new one to replace it.</span>
                    </div>
                <?php endif; ?>

                <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp">
                <img id="preview" alt="New image preview">
            </div>

        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-floppy-disk"></i>
                <?= $isEdit ? 'Update Product' : 'Save Product' ?>
            </button>

            <a href="products.php" class="btn btn-secondary">Cancel</a>
        </div>

    </form>

</main>

<script>
document.getElementById('image').addEventListener('change', function () {
    const preview = document.getElementById('preview');
    const file = this.files[0];

    if (file) {
        preview.src = URL.createObjectURL(file);
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
});
</script>

</body>
</html>