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

define('CART_URL',     BASE_URL . '/cart/cart.php');
define('CHECKOUT_URL', BASE_URL . '/cart/checkout.php');
define('LOGIN_URL',    BASE_URL . '/account/login.php');

// Where the shopper goes after the order is saved.
// Later, change this to BASE_URL . '/payments/mpesa-stk.php'
// so the M-Pesa prompt is sent straight after the order is placed.
define('NEXT_STEP_URL', BASE_URL . '/orders/order-detail.php');

const MAX_PER_ITEM = 20;

// Must match DELIVERY_FEE in checkout.php
const DELIVERY_FEE = 0;

// An error message that is safe to show the shopper
class OrderProblem extends Exception {}

// ======================================
// HELPERS
// ======================================

function flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function go($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * Trim, remove control characters and cap the length.
 */
function clean($value, $max) {
    $value = trim((string)$value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $max);
}

/**
 * Turn 0712345678, +254712345678 or 254712345678 into 254712345678.
 * Returns null if it is not a valid Kenyan mobile number.
 */
function normalizePhone($phone) {
    $phone = preg_replace('/[\s\-().]/', '', (string)$phone);

    if (strpos($phone, '+') === 0) {
        $phone = substr($phone, 1);
    }

    if (strpos($phone, '0') === 0) {
        $phone = '254' . substr($phone, 1);
    }

    return preg_match('/^254[17]\d{8}$/', $phone) ? $phone : null;
}

// ======================================
// ONLY ACCEPT FORM POSTS
// ======================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    go(CHECKOUT_URL);
}

// ======================================
// CSRF CHECK
// ======================================

$sentToken    = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if ($sessionToken === '' || !hash_equals($sessionToken, $sentToken)) {
    flash('error', 'Your session expired. Please try again.');
    go(CHECKOUT_URL);
}

// ======================================
// MUST BE LOGGED IN, MUST HAVE A CART
// ======================================

if (!isset($_SESSION['user_id'])) {
    go(LOGIN_URL);
}

$userId = (int)$_SESSION['user_id'];

if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    flash('error', 'Your cart is empty.');
    go(CART_URL);
}

// ======================================
// READ AND VALIDATE THE FORM
// ======================================

$fullName = clean($_POST['full_name'] ?? '', 100);
$rawPhone = clean($_POST['phone'] ?? '', 20);
$county   = clean($_POST['county'] ?? '', 50);
$town     = clean($_POST['town'] ?? '', 100);
$address  = clean($_POST['address'] ?? '', 255);
$notes    = clean($_POST['notes'] ?? '', 500);
$payment  = $_POST['payment_method'] ?? '';

$phone  = normalizePhone($rawPhone);
$errors = [];

if ($fullName === '')  { $errors[] = 'Please enter your full name.'; }
if ($phone === null)   { $errors[] = 'Please enter a valid Safaricom number, for example 0712345678.'; }
if ($county === '')    { $errors[] = 'Please select your county.'; }
if ($town === '')      { $errors[] = 'Please enter your town or area.'; }
if ($address === '')   { $errors[] = 'Please enter your delivery address.'; }
if ($payment !== 'mpesa') { $errors[] = 'Please choose M-Pesa as the payment method.'; }

// Keep what they typed so they don't have to start again
$_SESSION['checkout_old'] = [
    'full_name' => $fullName,
    'phone'     => $rawPhone,
    'county'    => $county,
    'town'      => $town,
    'address'   => $address,
    'notes'     => $notes,
];

if ($errors) {
    flash('error', implode(' ', $errors));
    go(CHECKOUT_URL);
}

// ======================================
// SAVE THE ORDER (all or nothing)
// ======================================

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    $conn->begin_transaction();

    // ---- 1. Lock the products and read the real prices and stock ----

    $ids = array_map('intval', array_keys($_SESSION['cart']));
    $ids = array_values(array_filter($ids, fn($id) => $id > 0));

    if (!$ids) {
        throw new OrderProblem('Your cart is empty.');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $conn->prepare(
        "SELECT id, name, price, sale_price, stock
         FROM products
         WHERE id IN ($placeholders)
         FOR UPDATE"
    );
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $found = [];
    foreach ($rows as $row) {
        $found[(int)$row['id']] = $row;
    }

    // ---- 2. Check every item and work out the totals ----

    $lines    = [];
    $subtotal = 0.0;

    foreach ($ids as $id) {
        $qty = (int)($_SESSION['cart'][$id] ?? 0);

        if ($qty <= 0) {
            continue;
        }

        if (!isset($found[$id])) {
            throw new OrderProblem('An item in your cart is no longer sold. Please review your cart.');
        }

        $product = $found[$id];
        $stock   = (int)$product['stock'];

        if ($stock <= 0) {
            throw new OrderProblem($product['name'] . ' is out of stock. Please remove it from your cart.');
        }

        if ($qty > $stock || $qty > MAX_PER_ITEM) {
            $allowed = min($stock, MAX_PER_ITEM);
            throw new OrderProblem('Only ' . $allowed . ' of ' . $product['name'] . ' can be ordered. Please update your cart.');
        }

        $price   = (float)$product['price'];
        $hasSale = $product['sale_price'] !== null
                   && (float)$product['sale_price'] > 0
                   && (float)$product['sale_price'] < $price;
        $unit    = round($hasSale ? (float)$product['sale_price'] : $price, 2);
        $line    = round($unit * $qty, 2);

        $lines[] = [
            'product_id' => $id,
            'name'       => $product['name'],
            'qty'        => $qty,
            'unit'       => $unit,
            'line'       => $line,
        ];

        $subtotal += $line;
    }

    if (!$lines) {
        throw new OrderProblem('Your cart is empty.');
    }

    $subtotal = round($subtotal, 2);
    $delivery = round((float)DELIVERY_FEE, 2);
    $total    = round($subtotal + $delivery, 2);

    // ---- 3. Create the order ----

    $stmt = $conn->prepare(
        "INSERT INTO orders
            (user_id, full_name, phone, county, town, address, notes,
             subtotal, delivery_fee, total, payment_method, payment_status, status)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'mpesa', 'unpaid', 'pending')"
    );
    $stmt->bind_param(
        'issssssddd',
        $userId, $fullName, $phone, $county, $town, $address, $notes,
        $subtotal, $delivery, $total
    );
    $stmt->execute();
    $orderId = (int)$conn->insert_id;
    $stmt->close();

    // ---- 4. Save each item and take it out of stock ----

    $itemStmt = $conn->prepare(
        "INSERT INTO order_items
            (order_id, product_id, product_name, unit_price, quantity, line_total)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    $stockStmt = $conn->prepare(
        "UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?"
    );

    foreach ($lines as $line) {

        $itemStmt->bind_param(
            'iisdid',
            $orderId, $line['product_id'], $line['name'],
            $line['unit'], $line['qty'], $line['line']
        );
        $itemStmt->execute();

        $stockStmt->bind_param('iii', $line['qty'], $line['product_id'], $line['qty']);
        $stockStmt->execute();

        if ($stockStmt->affected_rows !== 1) {
            throw new OrderProblem('Sorry, ' . $line['name'] . ' just sold out. Please review your cart.');
        }
    }

    $itemStmt->close();
    $stockStmt->close();

    // ---- 5. Done ----

    $conn->commit();

} catch (OrderProblem $problem) {

    // Something the shopper can fix (stock, removed product)
    $conn->rollback();
    flash('error', $problem->getMessage());
    go(CART_URL);

} catch (Throwable $error) {

    // A real fault: log the details, show a plain message
    $conn->rollback();
    error_log('place-order.php failed: ' . $error->getMessage());
flash('error', 'DEBUG: ' . $error->getMessage());
    go(CHECKOUT_URL);
}

// ======================================
// CLEAN UP AND MOVE ON
// ======================================

$_SESSION['cart'] = [];
unset($_SESSION['checkout_old']);
$_SESSION['last_order_id'] = $orderId;

go(NEXT_STEP_URL . '?id=' . $orderId);