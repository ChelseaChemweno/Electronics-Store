<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

require_once __DIR__ . '/../config/db.php';

// ======================================
// BASE URL  (e.g. /ElectronicShop)
// ======================================

if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\'));
}

define('SHOP_URL',     BASE_URL . '/shop/products.php');
define('CART_URL',     BASE_URL . '/cart/cart.php');
define('CART_ACTION',  BASE_URL . '/cart/cart-action.php');
define('CHECKOUT_URL', BASE_URL . '/cart/checkout.php');
define('LOGIN_URL',    BASE_URL . '/account/login.php');
define('REGISTER_URL', BASE_URL . '/account/register.php');
define('LOGOUT_URL',   BASE_URL . '/account/logout.php');

const MAX_PER_ITEM = 20;

// ======================================
// HELPERS
// ======================================

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ======================================
// MUST BE LOGGED IN
// ======================================

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . LOGIN_URL);
    exit;
}

$userName = $_SESSION['full_name'] ?? 'Account';

// One-time message from cart-action.php
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// ======================================
// LOAD CART ITEMS FROM THE DATABASE
// (the session only stores product_id => quantity;
//  names, prices and stock always come from the database)
// ======================================

$items      = [];
$subtotal   = 0.0;
$totalUnits = 0;
$notes      = [];

if (!empty($_SESSION['cart'])) {

    $ids = array_map('intval', array_keys($_SESSION['cart']));
    $ids = array_values(array_filter($ids, fn($id) => $id > 0));

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $conn->prepare(
            "SELECT id, name, price, sale_price, stock, image
             FROM products
             WHERE id IN ($placeholders)"
        );
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $found = [];
        foreach ($rows as $row) {
            $found[(int)$row['id']] = $row;
        }

        foreach ($ids as $id) {
            $qty = (int)($_SESSION['cart'][$id] ?? 0);

            // Product deleted from the shop
            if (!isset($found[$id])) {
                unset($_SESSION['cart'][$id]);
                $notes[] = 'An item that is no longer sold was removed from your cart.';
                continue;
            }

            $product = $found[$id];
            $stock   = (int)$product['stock'];

            // Sold out since it was added
            if ($stock <= 0) {
                unset($_SESSION['cart'][$id]);
                $notes[] = $product['name'] . ' is now out of stock and was removed.';
                continue;
            }

            // Fewer in stock than the cart asks for
            $limit = min($stock, MAX_PER_ITEM);
            if ($qty > $limit) {
                $qty = $limit;
                $_SESSION['cart'][$id] = $qty;
                $notes[] = 'Only ' . $limit . ' of ' . $product['name'] . ' available. Quantity adjusted.';
            }

            if ($qty <= 0) {
                unset($_SESSION['cart'][$id]);
                continue;
            }

            $price   = (float)$product['price'];
            $hasSale = $product['sale_price'] !== null
                       && (float)$product['sale_price'] > 0
                       && (float)$product['sale_price'] < $price;
            $unit    = $hasSale ? (float)$product['sale_price'] : $price;
            $line    = $unit * $qty;

            $items[] = [
                'id'        => $id,
                'name'      => $product['name'],
                'image'     => $product['image'],
                'qty'       => $qty,
                'limit'     => $limit,
                'unit'      => $unit,
                'old_price' => $hasSale ? $price : null,
                'line'      => $line,
                'stock'     => $stock,
            ];

            $subtotal   += $line;
            $totalUnits += $qty;
        }
    }
}

// Show a stock note if the flash is empty
if (!$flash && $notes) {
    $flash = ['type' => 'error', 'message' => implode(' ', array_unique($notes))];
}

$cartCount = $totalUnits;
$isEmpty   = empty($items);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Cart | TechWorld</title>

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
:root {
    --gold: #b49a6a;
    --gold-dark: #92784e;
    --dark: #282722;
    --background: #f6f4f0;
    --white: #ffffff;
    --muted: #89867f;
    --border: #eeece6;
    --green-btn: #2e9e5b;
    --green-btn-dark: #247d48;
    --red: #b65c4c;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: var(--background);
    color: var(--dark);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

a { text-decoration: none; color: inherit; }

/* HEADER */
.site-header { background: var(--dark); position: sticky; top: 0; z-index: 20; }
.header-inner {
    max-width: 1240px; margin: 0 auto; padding: 16px 28px;
    display: flex; align-items: center; justify-content: space-between; gap: 24px;
}
.logo { color: white; font-size: 22px; font-weight: 750; letter-spacing: -.7px; }
.logo span { color: var(--gold); }

.main-nav { display: flex; gap: 6px; flex-wrap: wrap; }
.main-nav a { color: #c4c2b9; font-size: 13px; padding: 10px 14px; border-radius: 8px; transition: .2s; }
.main-nav a:hover, .main-nav a.active { background: rgba(180,154,106,.17); color: #d9bd8b; }

.header-actions { display: flex; align-items: center; gap: 8px; }
.header-actions a {
    color: #c4c2b9; font-size: 13px; padding: 10px 14px; border-radius: 8px;
    transition: .2s; display: flex; align-items: center; gap: 8px;
}
.header-actions a:hover, .header-actions a.active { background: rgba(180,154,106,.17); color: #d9bd8b; }

.cart-count {
    background: var(--gold); color: var(--dark); font-size: 10px; font-weight: 700;
    min-width: 18px; height: 18px; border-radius: 9px;
    display: grid; place-items: center; padding: 0 5px;
}

/* PAGE */
.container { max-width: 1240px; margin: 0 auto; padding: 36px 28px 60px; width: 100%; flex: 1; }

.page-heading { margin-bottom: 24px; }
.breadcrumb { font-size: 12px; color: var(--muted); margin-bottom: 12px; }
.breadcrumb a:hover { color: var(--gold-dark); }
.page-heading h1 { font-size: 30px; letter-spacing: -.8px; margin-bottom: 8px; }
.page-heading p { color: var(--muted); font-size: 13px; }

/* FLASH MESSAGE */
.flash {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 18px; border-radius: 10px; font-size: 13px; margin-bottom: 24px;
}
.flash-success { background: #e8f5ee; border: 1px solid #c7e6d3; color: #247d48; }
.flash-error   { background: #faeae7; border: 1px solid #f0cfc9; color: var(--red); }

/* BUTTONS */
.btn {
    padding: 11px 18px; border-radius: 8px; font-size: 12px; font-weight: 600;
    border: none; cursor: pointer; transition: .2s; font-family: inherit;
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
}
.btn-primary { background: var(--dark); color: white; }
.btn-primary:hover { background: var(--gold-dark); }
.btn-secondary { background: #f3efe7; color: #806e5e; }
.btn-secondary:hover { background: #ebe5d8; }
.btn-buy { background: var(--green-btn); color: white; }
.btn-buy:hover { background: var(--green-btn-dark); }

/* LAYOUT */
.cart-layout { display: grid; grid-template-columns: 1fr 340px; gap: 28px; align-items: start; }

/* CART ITEMS */
.cart-list {
    background: white; border: 1px solid var(--border); border-radius: 12px; overflow: hidden;
}
.cart-list-head {
    display: flex; justify-content: space-between; align-items: center;
    padding: 16px 22px; border-bottom: 1px solid var(--border);
    font-size: 11px; text-transform: uppercase; letter-spacing: 1.4px; color: var(--muted);
}
.link-clear {
    background: none; border: none; cursor: pointer; font: inherit;
    font-size: 11px; text-transform: uppercase; letter-spacing: 1.4px; color: var(--muted);
}
.link-clear:hover { color: var(--red); }

.cart-item {
    display: grid; grid-template-columns: 88px 1fr auto;
    gap: 18px; align-items: center; padding: 20px 22px; border-bottom: 1px solid var(--border);
}
.cart-item:last-child { border-bottom: none; }

.item-image {
    width: 88px; height: 88px; border-radius: 10px; overflow: hidden;
    background: #f3efe7; display: block;
}
.item-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
.item-image .no-image { width: 100%; height: 100%; display: grid; place-items: center; color: #d6c6a5; font-size: 26px; }

.item-name { font-size: 15px; font-weight: 600; margin-bottom: 6px; line-height: 1.35; }
.item-name a:hover { color: var(--gold-dark); }

.item-price { display: flex; align-items: baseline; gap: 8px; margin-bottom: 12px; }
.item-price .unit { font-size: 13px; color: var(--muted); }
.item-price .unit.sale { color: var(--red); font-weight: 600; }
.item-price .old { font-size: 12px; color: var(--muted); text-decoration: line-through; }

.item-controls { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }

.qty-form { display: flex; align-items: center; gap: 8px; margin: 0; }
.qty-form input[type="number"] {
    width: 70px; padding: 9px 10px; font: inherit; font-size: 13px; text-align: center;
    border: 1px solid #e3e0d8; border-radius: 8px; background: white; color: var(--dark);
}
.qty-form input:focus {
    outline: none; border-color: var(--gold);
    box-shadow: 0 0 0 3px rgba(180,154,106,.15);
}
.qty-form .btn { padding: 9px 14px; }

.remove-form { margin: 0; }
.link-remove {
    background: none; border: none; cursor: pointer; font: inherit; font-size: 12px;
    color: var(--muted); display: inline-flex; align-items: center; gap: 6px;
}
.link-remove:hover { color: var(--red); }

.item-total { font-size: 17px; font-weight: 700; text-align: right; white-space: nowrap; }
.item-total small { display: block; font-size: 11px; font-weight: 400; color: var(--muted); margin-top: 4px; }

/* SUMMARY */
.summary {
    background: white; border: 1px solid var(--border); border-radius: 12px;
    padding: 24px; position: sticky; top: 90px;
}
.summary h3 {
    font-size: 11px; text-transform: uppercase; letter-spacing: 1.4px;
    color: var(--muted); margin-bottom: 18px;
}
.summary-row { display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 12px; }
.summary-row span:first-child { color: var(--muted); }
.summary-total {
    display: flex; justify-content: space-between; align-items: baseline;
    border-top: 1px solid var(--border); padding-top: 16px; margin-top: 6px; margin-bottom: 20px;
    font-weight: 700;
}
.summary-total .amount { font-size: 22px; }
.summary .btn { width: 100%; height: 46px; font-size: 13px; }
.summary .btn + .btn { margin-top: 10px; }
.summary-note { font-size: 11px; color: var(--muted); text-align: center; margin-top: 14px; line-height: 1.5; }

/* EMPTY */
.empty {
    background: white; border: 1px solid var(--border); border-radius: 12px;
    padding: 70px 20px; text-align: center; color: var(--muted);
}
.empty i { font-size: 40px; color: #d6c6a5; display: block; margin-bottom: 16px; }
.empty h3 { color: var(--dark); margin-bottom: 8px; font-size: 17px; }
.empty .btn { margin-top: 20px; }

/* FOOTER */
.site-footer { background: var(--dark); color: #85847c; text-align: center; font-size: 12px; padding: 26px; }

/* RESPONSIVE */
@media (max-width: 900px) {
    .main-nav { display: none; }
    .cart-layout { grid-template-columns: 1fr; }
    .summary { position: static; }
}

@media (max-width: 600px) {
    .header-inner { padding: 14px 18px; }
    .container { padding: 26px 18px 50px; }
    .header-actions .label { display: none; }
    .cart-item { grid-template-columns: 72px 1fr; }
    .item-image { width: 72px; height: 72px; }
    .item-total { grid-column: 1 / -1; text-align: left; display: flex; justify-content: space-between; align-items: baseline; }
    .item-total small { display: inline; margin: 0; }
}
</style>
</head>

<body>

<!-- HEADER -->
<header class="site-header">
    <div class="header-inner">

        <a href="<?= e(BASE_URL) ?>/index.php" class="logo">Tech<span>World.</span></a>

        <nav class="main-nav">
            <a href="<?= e(BASE_URL) ?>/index.php">Home</a>
            <a href="<?= e(SHOP_URL) ?>">Shop</a>
            <a href="<?= e(SHOP_URL) ?>?sort=newest">New Arrivals</a>
            <a href="<?= e(SHOP_URL) ?>?deals=1">Deals</a>
            <a href="#">About</a>
            <a href="#">Contact</a>
        </nav>

        <div class="header-actions">

            <a href="<?= e(LOGOUT_URL) ?>" title="Log out">
                <i class="fa-solid fa-user"></i>
                <span class="label"><?= e($userName) ?></span>
            </a>

            <a href="<?= e(CART_URL) ?>" class="active">
                <i class="fa-solid fa-cart-shopping"></i>
                <span class="label">Cart</span>
                <span class="cart-count"><?= (int)$cartCount ?></span>
            </a>

        </div>
    </div>
</header>


<!-- CONTENT -->
<main class="container">

    <div class="page-heading">
        <div class="breadcrumb">
            <a href="<?= e(BASE_URL) ?>/index.php">Home</a> /
            <a href="<?= e(SHOP_URL) ?>">Shop</a> / Cart
        </div>

        <h1>Your Cart</h1>
        <p>
            <?= $isEmpty
                ? 'You have nothing in your cart yet.'
                : 'Review your items before checking out.' ?>
        </p>
    </div>

    <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>


    <?php if ($isEmpty): ?>

        <div class="empty">
            <i class="fa-solid fa-cart-shopping"></i>
            <h3>Your cart is empty</h3>
            <p>Browse our products and add something you like.</p>
            <a href="<?= e(SHOP_URL) ?>" class="btn btn-primary">Continue shopping</a>
        </div>

    <?php else: ?>

        <div class="cart-layout">

            <!-- ITEMS -->
            <section class="cart-list">

                <div class="cart-list-head">
                    <span><?= (int)$totalUnits ?> item<?= $totalUnits === 1 ? '' : 's' ?></span>

                    <form method="POST" action="<?= e(CART_ACTION) ?>"
                          onsubmit="return confirm('Remove everything from your cart?');">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="link-clear">Clear cart</button>
                    </form>
                </div>

                <?php foreach ($items as $item): ?>

                    <?php $link = BASE_URL . '/shop/product.php?id=' . $item['id']; ?>

                    <article class="cart-item">

                        <a href="<?= e($link) ?>" class="item-image">
                            <?php if (!empty($item['image'])): ?>
                                <img src="<?= e(BASE_URL) ?>/<?= e($item['image']) ?>"
                                     alt="<?= e($item['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <div class="no-image"><i class="fa-regular fa-image"></i></div>
                            <?php endif; ?>
                        </a>

                        <div>
                            <h3 class="item-name">
                                <a href="<?= e($link) ?>"><?= e($item['name']) ?></a>
                            </h3>

                            <div class="item-price">
                                <?php if ($item['old_price'] !== null): ?>
                                    <span class="unit sale">KSh <?= number_format($item['unit'], 2) ?></span>
                                    <span class="old">KSh <?= number_format($item['old_price'], 2) ?></span>
                                <?php else: ?>
                                    <span class="unit">KSh <?= number_format($item['unit'], 2) ?> each</span>
                                <?php endif; ?>
                            </div>

                            <div class="item-controls">

                                <form method="POST" action="<?= e(CART_ACTION) ?>" class="qty-form">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="product_id" value="<?= (int)$item['id'] ?>">

                                    <input type="number" name="quantity"
                                           value="<?= (int)$item['qty'] ?>"
                                           min="1" max="<?= (int)$item['limit'] ?>"
                                           aria-label="Quantity for <?= e($item['name']) ?>">

                                    <button type="submit" class="btn btn-secondary">Update</button>
                                </form>

                                <form method="POST" action="<?= e(CART_ACTION) ?>" class="remove-form">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="product_id" value="<?= (int)$item['id'] ?>">

                                    <button type="submit" class="link-remove">
                                        <i class="fa-regular fa-trash-can"></i> Remove
                                    </button>
                                </form>

                            </div>
                        </div>

                        <div class="item-total">
                            KSh <?= number_format($item['line'], 2) ?>
                            <small><?= (int)$item['qty'] ?> &times; KSh <?= number_format($item['unit'], 2) ?></small>
                        </div>

                    </article>

                <?php endforeach; ?>

            </section>


            <!-- ORDER SUMMARY -->
            <aside class="summary">

                <h3>Order summary</h3>

                <div class="summary-row">
                    <span>Items (<?= (int)$totalUnits ?>)</span>
                    <span>KSh <?= number_format($subtotal, 2) ?></span>
                </div>

                <div class="summary-row">
                    <span>Delivery</span>
                    <span>Calculated at checkout</span>
                </div>

                <div class="summary-total">
                    <span>Subtotal</span>
                    <span class="amount">KSh <?= number_format($subtotal, 2) ?></span>
                </div>

                <a href="<?= e(CHECKOUT_URL) ?>" class="btn btn-buy">
                    <i class="fa-solid fa-lock"></i> Proceed to Checkout
                </a>

                <a href="<?= e(SHOP_URL) ?>" class="btn btn-secondary">
                    <i class="fa-solid fa-arrow-left"></i> Continue shopping
                </a>

                <p class="summary-note">
                    Prices and stock are confirmed again when you place your order.
                </p>

            </aside>

        </div>

    <?php endif; ?>

</main>


<!-- FOOTER -->
<footer class="site-footer">
    © <?= date('Y') ?> TechWorld. All rights reserved.
</footer>

</body>
</html>