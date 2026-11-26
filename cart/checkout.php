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

define('SHOP_URL',      BASE_URL . '/shop/products.php');
define('CART_URL',      BASE_URL . '/cart/cart.php');
define('PLACE_ORDER',   BASE_URL . '/cart/place-order.php');
define('LOGIN_URL',     BASE_URL . '/account/login.php');
define('LOGOUT_URL',    BASE_URL . '/account/logout.php');

const MAX_PER_ITEM = 20;

// Flat delivery fee in KSh. Set to 0 for free delivery.
// place-order.php must use the same value.
const DELIVERY_FEE = 0;

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

$userId   = (int)$_SESSION['user_id'];
$userName = $_SESSION['full_name'] ?? 'Account';

// One-time message (from cart-action.php or place-order.php)
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Values the shopper typed before an error sent them back here
$old = $_SESSION['checkout_old'] ?? [];
unset($_SESSION['checkout_old']);

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// ======================================
// LOAD CART ITEMS FROM THE DATABASE
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

            if (!isset($found[$id])) {
                unset($_SESSION['cart'][$id]);
                $notes[] = 'An item that is no longer sold was removed from your order.';
                continue;
            }

            $product = $found[$id];
            $stock   = (int)$product['stock'];

            if ($stock <= 0) {
                unset($_SESSION['cart'][$id]);
                $notes[] = $product['name'] . ' is now out of stock and was removed.';
                continue;
            }

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
                'id'    => $id,
                'name'  => $product['name'],
                'image' => $product['image'],
                'qty'   => $qty,
                'unit'  => $unit,
                'line'  => $line,
            ];

            $subtotal   += $line;
            $totalUnits += $qty;
        }
    }
}

// Nothing to check out: go back to the cart
if (empty($items)) {
    $_SESSION['flash'] = [
        'type'    => 'error',
        'message' => $notes ? implode(' ', array_unique($notes)) : 'Your cart is empty.',
    ];
    header('Location: ' . CART_URL);
    exit;
}

if (!$flash && $notes) {
    $flash = ['type' => 'error', 'message' => implode(' ', array_unique($notes))];
}

$delivery  = (float)DELIVERY_FEE;
$total     = $subtotal + $delivery;
$cartCount = $totalUnits;

// ======================================
// PREFILL FROM THE USER'S ACCOUNT
// ======================================

$stmt = $conn->prepare("SELECT full_name, phone FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$formName    = $old['full_name'] ?? ($account['full_name'] ?? $userName);
$formPhone   = $old['phone']     ?? ($account['phone'] ?? '');
$formCounty  = $old['county']    ?? '';
$formTown    = $old['town']      ?? '';
$formAddress = $old['address']   ?? '';
$formNotes   = $old['notes']     ?? '';

$counties = [
    'Baringo', 'Bomet', 'Bungoma', 'Busia', 'Elgeyo-Marakwet', 'Embu', 'Garissa',
    'Homa Bay', 'Isiolo', 'Kajiado', 'Kakamega', 'Kericho', 'Kiambu', 'Kilifi',
    'Kirinyaga', 'Kisii', 'Kisumu', 'Kitui', 'Kwale', 'Laikipia', 'Lamu',
    'Machakos', 'Makueni', 'Mandera', 'Marsabit', 'Meru', 'Migori', 'Mombasa',
    "Murang'a", 'Nairobi', 'Nakuru', 'Nandi', 'Narok', 'Nyamira', 'Nyandarua',
    'Nyeri', 'Samburu', 'Siaya', 'Taita-Taveta', 'Tana River', 'Tharaka-Nithi',
    'Trans Nzoia', 'Turkana', 'Uasin Gishu', 'Vihiga', 'Wajir', 'West Pokot',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Checkout | TechWorld</title>

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
.main-nav a:hover { background: rgba(180,154,106,.17); color: #d9bd8b; }

.header-actions { display: flex; align-items: center; gap: 8px; }
.header-actions a {
    color: #c4c2b9; font-size: 13px; padding: 10px 14px; border-radius: 8px;
    transition: .2s; display: flex; align-items: center; gap: 8px;
}
.header-actions a:hover { background: rgba(180,154,106,.17); color: #d9bd8b; }

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

/* FLASH */
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
.btn-secondary { background: #f3efe7; color: #806e5e; }
.btn-secondary:hover { background: #ebe5d8; }
.btn-buy { background: var(--green-btn); color: white; }
.btn-buy:hover { background: var(--green-btn-dark); }
.btn:disabled { background: #e3e0d8; color: var(--muted); cursor: not-allowed; }

/* LAYOUT */
.checkout-layout { display: grid; grid-template-columns: 1fr 380px; gap: 28px; align-items: start; }

/* FORM SECTIONS */
.box {
    background: white; border: 1px solid var(--border); border-radius: 12px;
    padding: 26px; margin-bottom: 22px;
}
.box:last-child { margin-bottom: 0; }

.box-title {
    display: flex; align-items: center; gap: 10px;
    font-size: 11px; text-transform: uppercase; letter-spacing: 1.4px;
    color: var(--muted); margin-bottom: 20px;
}
.box-title .step {
    width: 22px; height: 22px; border-radius: 50%;
    background: var(--dark); color: white; font-size: 11px; letter-spacing: 0;
    display: grid; place-items: center;
}

.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.field { margin-bottom: 16px; }
.field:last-child { margin-bottom: 0; }
.field-row .field { margin-bottom: 0; }
.field-row { margin-bottom: 16px; }
.field-row:last-child { margin-bottom: 0; }

.field label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 7px; }
.field label small { font-weight: 400; color: var(--muted); }

.field input, .field select, .field textarea {
    width: 100%; padding: 12px 14px; font: inherit; font-size: 13px;
    border: 1px solid #e3e0d8; border-radius: 8px; background: white; color: var(--dark);
}
.field textarea { resize: vertical; min-height: 80px; }
.field input:focus, .field select:focus, .field textarea:focus {
    outline: none; border-color: var(--gold);
    box-shadow: 0 0 0 3px rgba(180,154,106,.15);
}
.field .hint { font-size: 11px; color: var(--muted); margin-top: 6px; }

/* PAYMENT OPTION */
.pay-option {
    display: flex; align-items: center; gap: 14px;
    border: 2px solid var(--green-btn); background: #f3faf6;
    border-radius: 10px; padding: 16px 18px;
}
.pay-option .radio {
    width: 18px; height: 18px; border-radius: 50%; border: 5px solid var(--green-btn);
    background: white; flex-shrink: 0;
}
.pay-option strong { display: block; font-size: 14px; margin-bottom: 3px; }
.pay-option span { font-size: 12px; color: var(--muted); line-height: 1.5; }
.pay-option .pay-icon { margin-left: auto; font-size: 24px; color: var(--green-btn); }

/* SUMMARY */
.summary {
    background: white; border: 1px solid var(--border); border-radius: 12px;
    padding: 24px; position: sticky; top: 90px;
}
.summary h3 {
    font-size: 11px; text-transform: uppercase; letter-spacing: 1.4px;
    color: var(--muted); margin-bottom: 18px;
}

.summary-items { max-height: 280px; overflow-y: auto; margin-bottom: 18px; }
.summary-item { display: grid; grid-template-columns: 52px 1fr auto; gap: 12px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border); }
.summary-item:last-child { border-bottom: none; }
.summary-thumb {
    position: relative; width: 52px; height: 52px; border-radius: 8px; background: #f3efe7;
}
.summary-thumb .thumb-inner { width: 100%; height: 100%; border-radius: 8px; overflow: hidden; }
.summary-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.summary-thumb .no-image { width: 100%; height: 100%; display: grid; place-items: center; color: #d6c6a5; }
.summary-thumb .qty-badge {
    position: absolute; top: -6px; right: -6px; min-width: 20px; height: 20px; padding: 0 5px;
    background: var(--dark); color: white; border-radius: 10px; font-size: 10px; font-weight: 700;
    display: grid; place-items: center;
}
.summary-item .name { font-size: 13px; line-height: 1.35; }
.summary-item .line { font-size: 13px; font-weight: 600; white-space: nowrap; }

.summary-row { display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 12px; }
.summary-row span:first-child { color: var(--muted); }
.summary-row .free { color: var(--green-btn); font-weight: 600; }
.summary-total {
    display: flex; justify-content: space-between; align-items: baseline;
    border-top: 1px solid var(--border); padding-top: 16px; margin-top: 6px; margin-bottom: 20px;
    font-weight: 700;
}
.summary-total .amount { font-size: 22px; }

.summary .btn { width: 100%; height: 48px; font-size: 13px; }
.summary .btn + .btn { margin-top: 10px; }
.summary-note { font-size: 11px; color: var(--muted); text-align: center; margin-top: 14px; line-height: 1.5; }

/* FOOTER */
.site-footer { background: var(--dark); color: #85847c; text-align: center; font-size: 12px; padding: 26px; }

/* RESPONSIVE */
@media (max-width: 900px) {
    .main-nav { display: none; }
    .checkout-layout { grid-template-columns: 1fr; }
    .summary { position: static; }
}

@media (max-width: 600px) {
    .header-inner { padding: 14px 18px; }
    .container { padding: 26px 18px 50px; }
    .header-actions .label { display: none; }
    .field-row { grid-template-columns: 1fr; }
    .box { padding: 20px; }
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

            <a href="<?= e(CART_URL) ?>">
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
            <a href="<?= e(CART_URL) ?>">Cart</a> / Checkout
        </div>

        <h1>Checkout</h1>
        <p>Tell us where to deliver and confirm how you will pay.</p>
    </div>

    <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= e(PLACE_ORDER) ?>" id="checkoutForm" class="checkout-layout">

        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="payment_method" value="mpesa">

        <!-- LEFT: DETAILS -->
        <div>

            <!-- 1. CONTACT -->
            <section class="box">
                <div class="box-title"><span class="step">1</span> Contact details</div>

                <div class="field">
                    <label for="full_name">Full name</label>
                    <input type="text" id="full_name" name="full_name"
                           value="<?= e($formName) ?>" maxlength="100" required>
                </div>

                <div class="field">
                    <label for="phone">M-Pesa phone number</label>
                    <input type="tel" id="phone" name="phone"
                           value="<?= e($formPhone) ?>"
                           placeholder="0712 345 678"
                           pattern="(?:\+?254|0)[17][0-9]{8}"
                           title="Enter a Safaricom number like 0712345678 or 254712345678"
                           maxlength="15" required>
                    <div class="hint">The payment prompt will be sent to this number.</div>
                </div>
            </section>

            <!-- 2. DELIVERY -->
            <section class="box">
                <div class="box-title"><span class="step">2</span> Delivery address</div>

                <div class="field-row">
                    <div class="field">
                        <label for="county">County</label>
                        <select id="county" name="county" required>
                            <option value="">Select county</option>
                            <?php foreach ($counties as $county): ?>
                                <option value="<?= e($county) ?>" <?= $formCounty === $county ? 'selected' : '' ?>>
                                    <?= e($county) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="town">Town / Area</label>
                        <input type="text" id="town" name="town"
                               value="<?= e($formTown) ?>"
                               placeholder="e.g. Section 58, Nakuru"
                               maxlength="100" required>
                    </div>
                </div>

                <div class="field">
                    <label for="address">Street / building / landmark</label>
                    <input type="text" id="address" name="address"
                           value="<?= e($formAddress) ?>"
                           placeholder="e.g. Kenyatta Avenue, next to Naivas, 2nd floor"
                           maxlength="255" required>
                </div>

                <div class="field">
                    <label for="notes">Order notes <small>(optional)</small></label>
                    <textarea id="notes" name="notes" maxlength="500"
                              placeholder="Anything the delivery person should know?"><?= e($formNotes) ?></textarea>
                </div>
            </section>

            <!-- 3. PAYMENT -->
            <section class="box">
                <div class="box-title"><span class="step">3</span> Payment method</div>

                <div class="pay-option">
                    <div class="radio"></div>
                    <div>
                        <strong>M-Pesa</strong>
                        <span>
                            After you place the order, you will get a prompt on your phone
                            to enter your M-Pesa PIN.
                        </span>
                    </div>
                    <i class="fa-solid fa-mobile-screen-button pay-icon"></i>
                </div>
            </section>

        </div>


        <!-- RIGHT: SUMMARY -->
        <aside class="summary">

            <h3>Your order</h3>

            <div class="summary-items">
                <?php foreach ($items as $item): ?>
                    <div class="summary-item">

                        <div class="summary-thumb">
                            <div class="thumb-inner">
                                <?php if (!empty($item['image'])): ?>
                                    <img src="<?= e(BASE_URL) ?>/<?= e($item['image']) ?>"
                                         alt="<?= e($item['name']) ?>">
                                <?php else: ?>
                                    <div class="no-image"><i class="fa-regular fa-image"></i></div>
                                <?php endif; ?>
                            </div>
                            <span class="qty-badge"><?= (int)$item['qty'] ?></span>
                        </div>

                        <div class="name"><?= e($item['name']) ?></div>

                        <div class="line">KSh <?= number_format($item['line'], 2) ?></div>

                    </div>
                <?php endforeach; ?>
            </div>

            <div class="summary-row">
                <span>Subtotal (<?= (int)$totalUnits ?> item<?= $totalUnits === 1 ? '' : 's' ?>)</span>
                <span>KSh <?= number_format($subtotal, 2) ?></span>
            </div>

            <div class="summary-row">
                <span>Delivery</span>
                <?php if ($delivery > 0): ?>
                    <span>KSh <?= number_format($delivery, 2) ?></span>
                <?php else: ?>
                    <span class="free">Free</span>
                <?php endif; ?>
            </div>

            <div class="summary-total">
                <span>Total</span>
                <span class="amount">KSh <?= number_format($total, 2) ?></span>
            </div>

            <button type="submit" class="btn btn-buy" id="placeOrderBtn">
                <i class="fa-solid fa-lock"></i> Place Order
            </button>

            <a href="<?= e(CART_URL) ?>" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left"></i> Back to cart
            </a>

            <p class="summary-note">
                Prices and stock are confirmed again when your order is placed.
            </p>

        </aside>

    </form>

</main>


<!-- FOOTER -->
<footer class="site-footer">
    © <?= date('Y') ?> TechWorld. All rights reserved.
</footer>

<script>
// Stop double-clicks from sending the order twice
document.getElementById('checkoutForm').addEventListener('submit', function () {
    var btn = document.getElementById('placeOrderBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Placing order...';
});
</script>

</body>
</html>