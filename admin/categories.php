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

$errors = [];

// ======================================
// HANDLE ACTIONS (ADD / UPDATE / DELETE)
// ======================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid form submission. Please try again.";
    } else {

        $action = $_POST['action'] ?? '';
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');

        // ---------- ADD ----------
        if ($action === 'add') {

            if ($name === '') {
                $errors[] = "Category name is required.";
            } else {
                $stmt = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?)");
                $stmt->bind_param("s", $name);
                $stmt->execute();
                $exists = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if ($exists) {
                    $errors[] = "A category with that name already exists.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
                    $stmt->bind_param("s", $name);

                    if ($stmt->execute()) {
                        $stmt->close();
                        header("Location: categories.php?msg=added");
                        exit();
                    }

                    $errors[] = "Database error: " . $stmt->error;
                    $stmt->close();
                }
            }
        }

        // ---------- UPDATE ----------
        if ($action === 'update' && $id > 0) {

            if ($name === '') {
                $errors[] = "Category name is required.";
            } else {
                $stmt = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND id != ?");
                $stmt->bind_param("si", $name, $id);
                $stmt->execute();
                $exists = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if ($exists) {
                    $errors[] = "Another category already uses that name.";
                } else {
                    $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
                    $stmt->bind_param("si", $name, $id);

                    if ($stmt->execute()) {
                        $stmt->close();
                        header("Location: categories.php?msg=updated");
                        exit();
                    }

                    $errors[] = "Database error: " . $stmt->error;
                    $stmt->close();
                }
            }
        }

        // ---------- DELETE ----------
        if ($action === 'delete' && $id > 0) {

            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM products WHERE category_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $inUse = (int)$stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();

            if ($inUse > 0) {
                $errors[] = "This category has $inUse product(s). Move or delete those products first.";
            } else {
                $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
                $stmt->bind_param("i", $id);

                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: categories.php?msg=deleted");
                    exit();
                }

                $errors[] = "Database error: " . $stmt->error;
                $stmt->close();
            }
        }
    }
}

// ======================================
// EDIT MODE
// ======================================

$editId   = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editName = '';

if ($editId > 0) {
    $stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
    $stmt->bind_param("i", $editId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $editName = $row['name'];
    } else {
        $editId = 0;
    }
}

// Keep typed value if a validation error occurred
if (!empty($errors) && isset($_POST['name'])) {
    $editName = trim($_POST['name']);
    if (($_POST['action'] ?? '') === 'update') {
        $editId = (int)($_POST['id'] ?? 0);
    }
}

// ======================================
// FETCH CATEGORIES WITH PRODUCT COUNTS
// ======================================

$sql = "SELECT categories.id, categories.name, COUNT(products.id) AS product_count
        FROM categories
        LEFT JOIN products ON products.category_id = categories.id
        GROUP BY categories.id, categories.name
        ORDER BY categories.name ASC";

$result = $conn->query($sql);

if (!$result) {
    die("Error retrieving categories: " . e($conn->error));
}

$categories = [];
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}

$messages = [
    'added'   => 'Category added successfully.',
    'updated' => 'Category updated successfully.',
    'deleted' => 'Category deleted successfully.',
];

$flash     = $messages[$_GET['msg'] ?? ''] ?? '';
$adminName = $_SESSION['full_name'] ?? 'Administrator';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TechWorld | Categories</title>

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

.page-heading { margin-bottom: 26px; }
.page-heading h1 { font-size: 27px; letter-spacing: -.8px; margin-bottom: 8px; }
.page-heading p { color: var(--muted); font-size: 13px; }

.alert {
    padding: 14px 18px; border-radius: 9px; font-size: 12px; margin-bottom: 22px;
}
.alert-success { background: #eaf4ee; color: var(--green); border: 1px solid #cfe5d8; }
.alert-error { background: #faeae7; color: var(--red); border: 1px solid #f0cfc9; }
.alert-error ul { padding-left: 18px; }

/* LAYOUT */
.categories-grid {
    display: grid; grid-template-columns: 1fr 1.7fr; gap: 19px; align-items: start;
}

.panel {
    background: white; border: 1px solid var(--border);
    border-radius: 12px; padding: 23px; min-width: 0;
}
.panel-heading { display: flex; align-items: center; justify-content: space-between; margin-bottom: 21px; }
.panel-heading h3 { font-size: 15px; }
.count { color: var(--muted); font-size: 11px; }

/* FORM */
label { font-size: 12px; font-weight: 600; display: block; margin-bottom: 7px; }

input[type="text"] {
    width: 100%; padding: 12px 14px; font: inherit; font-size: 13px;
    border: 1px solid #e3e0d8; border-radius: 8px; background: #fcfbf9;
    color: var(--dark); transition: .2s;
}
input[type="text"]:focus {
    outline: none; border-color: var(--gold); background: white;
    box-shadow: 0 0 0 3px rgba(180,154,106,.15);
}

.form-actions { display: flex; gap: 10px; margin-top: 18px; }

.btn {
    padding: 12px 20px; border-radius: 8px; font-size: 12px; font-weight: 600;
    border: none; cursor: pointer; transition: .2s; font-family: inherit; display: inline-block;
}
.btn-primary { background: var(--dark); color: white; }
.btn-primary:hover { background: var(--gold-dark); }
.btn-secondary { background: #f3efe7; color: #806e5e; }
.btn-secondary:hover { background: #ebe5d8; }
.btn i { margin-right: 7px; }

/* TABLE */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; white-space: nowrap; }
th {
    text-align: left; color: var(--muted); font-size: 10px;
    text-transform: uppercase; letter-spacing: .5px; font-weight: 600;
    padding: 12px 10px; border-bottom: 1px solid var(--border);
}
td { padding: 14px 10px; font-size: 12px; border-bottom: 1px solid #f2f0eb; vertical-align: middle; }

.cat-name { font-weight: 600; }

.badge {
    padding: 6px 10px; border-radius: 20px; font-size: 10px; font-weight: 600;
    background: #f3efe7; color: #806e5e;
}

.actions { display: flex; gap: 8px; align-items: center; }

.action-btn {
    display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 650;
    padding: 8px 12px; border-radius: 7px; background: #f8f6f2;
    border: 1px solid #f0ede6; cursor: pointer; transition: .2s; font-family: inherit;
}
.edit-btn { color: var(--gold-dark); }
.edit-btn:hover { border-color: var(--gold); background: #f4f0e7; }
.delete-btn { color: var(--red); }
.delete-btn:hover { border-color: #e6bdb5; background: #faeae7; }

.empty { color: var(--muted); padding: 40px 10px; text-align: center; }
.empty i { font-size: 28px; color: #d6c6a5; display: block; margin-bottom: 12px; }

@media (max-width: 1100px) {
    .categories-grid { grid-template-columns: 1fr; }
}

@media (max-width: 700px) {
    .sidebar { width: 65px; padding: 22px 8px; }
    .brand { font-size: 0; padding: 0 8px 35px; }
    .brand span { font-size: 19px; }
    .menu-label, .sidebar a span { display: none; }
    .sidebar a { justify-content: center; padding: 14px 5px; }
    .main-content { margin-left: 65px; padding: 22px 15px; }
    .admin-profile span { display: none; }
}
</style>
</head>

<body>

<aside class="sidebar">
    <div class="brand">Tech<span>World.</span></div>

    <p class="menu-label">Main Menu</p>

    <a href="index.php"><i class="fa-solid fa-house"></i><span>Dashboard</span></a>
    <a href="products.php"><i class="fa-solid fa-box"></i><span>Products</span></a>
    <a href="categories.php" class="active"><i class="fa-solid fa-layer-group"></i><span>Categories</span></a>
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
            Admin Panel / <strong>Categories</strong>
        </div>

        <div class="admin-profile">
            <div class="profile-avatar"><i class="fa-solid fa-user"></i></div>
            <span><?= e($adminName) ?></span>
        </div>
    </header>

    <section class="page-heading">
        <h1>Categories</h1>
        <p>Organise your products into categories.</p>
    </section>

    <?php if ($flash): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i> <?= e($flash) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="categories-grid">

        <!-- ADD / EDIT FORM -->
        <div class="panel">

            <div class="panel-heading">
                <h3><?= $editId ? 'Edit Category' : 'Add New Category' ?></h3>
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="<?= $editId ? 'update' : 'add' ?>">

                <?php if ($editId): ?>
                    <input type="hidden" name="id" value="<?= (int)$editId ?>">
                <?php endif; ?>

                <label for="name">Category Name</label>
                <input type="text" id="name" name="name"
                       value="<?= e($editName) ?>"
                       placeholder="e.g. Laptops" required>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <?= $editId ? 'Update Category' : 'Add Category' ?>
                    </button>

                    <?php if ($editId): ?>
                        <a href="categories.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>

        </div>

        <!-- CATEGORY LIST -->
        <div class="panel">

            <div class="panel-heading">
                <h3>All Categories</h3>
                <span class="count"><?= count($categories) ?> total</span>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Products</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!empty($categories)): ?>

                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td class="cat-name"><?= e($cat['name']) ?></td>

                                <td>
                                    <span class="badge">
                                        <?= (int)$cat['product_count'] ?>
                                        product<?= (int)$cat['product_count'] === 1 ? '' : 's' ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="actions">

                                        <a href="categories.php?edit=<?= (int)$cat['id'] ?>"
                                           class="action-btn edit-btn">
                                            <i class="fa-solid fa-pen"></i> Edit
                                        </a>

                                        <form method="POST"
                                              onsubmit="return confirm('Delete this category? This cannot be undone.');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">

                                            <button type="submit" class="action-btn delete-btn">
                                                <i class="fa-solid fa-trash"></i> Delete
                                            </button>
                                        </form>

                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="3" class="empty">
                                <i class="fa-solid fa-folder-open"></i>
                                No categories yet. Add your first one using the form.
                            </td>
                        </tr>

                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

    </section>

</main>

</body>
</html>