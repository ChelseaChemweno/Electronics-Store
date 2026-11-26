<?php

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db.php';

// ======================================
// BASE URL  (e.g. /ElectronicShop)
// ======================================

if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\'));
}

define('SHOP_URL',     BASE_URL . '/shop/products.php');
define('CART_URL',     BASE_URL . '/cart/cart.php');
define('CHECKOUT_URL', BASE_URL . '/cart/checkout.php');
define('LOGIN_URL',    BASE_URL . '/account/login.php');

// ======================================
// HELPERS
// ======================================

/**
 * Store a message that the next page can show once.
 * type: 'success' | 'error'
 */
function flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Redirect and stop.
 */
function go($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * Only allow redirects back to pages inside this site.
 * Stops anyone sending a shopper to an outside website.
 */
function safeReturnUrl($url, $fallback) {
    $url = (string)$url;

    if ($url === '') {
        return $fallback;
    }

    $parts = parse_url($url);

    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return $fallback;
    }

    // Must be an absolute path on this site, and not "//other-site.com"
    if (strpos($url, '/') !== 0 || strpos($url, '//') === 0) {
        return $fallback;
    }

    // Must stay inside the shop folder
    if (BASE_URL !== '' && strpos($url, BASE_URL . '/') !== 0) {
        return $fallback;
    }

    return $url;
}

/**
 * Fetch one product's id, name and stock from the database.
 */
function findProduct($conn, $productId) {
    $stmt = $conn->prepare("SELECT id, name, stock FROM products WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $product ?: null;
}

// ======================================
// ONLY ACCEPT FORM POSTS
// ======================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    go(CART_URL);
}

$returnUrl = safeReturnUrl($_POST['return_url'] ?? '', SHOP_URL);

// ======================================
// CSRF CHECK
// ======================================

$sentToken    = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if ($sessionToken === '' || !hash_equals($sessionToken, $sentToken)) {
    flash('error', 'Your session expired. Please try again.');
    go($returnUrl);
}

// ======================================
// MUST BE LOGGED IN
// ======================================

if (!isset($_SESSION['user_id'])) {
    go(LOGIN_URL);
}

// ======================================
// READ THE REQUEST
// ======================================

$action    = $_POST['action'] ?? '';
$productId = (int)($_POST['product_id'] ?? 0);
$quantity  = (int)($_POST['quantity'] ?? 1);

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Never let one order grow past this many of the same item
const MAX_PER_ITEM = 20;

// ======================================
// ACTIONS
// ======================================

switch ($action) {

    // ----- BUY NOW: add the item, then go straight to checkout -----
    // ----- ADD:     add the item, then go to the cart ------------
    case 'buy_now':
    case 'add':

        if ($productId <= 0) {
            flash('error', 'Product not found.');
            go($returnUrl);
        }

        $product = findProduct($conn, $productId);

        if (!$product) {
            flash('error', 'That product no longer exists.');
            go($returnUrl);
        }

        $stock = (int)$product['stock'];

        if ($stock <= 0) {
            flash('error', $product['name'] . ' is out of stock.');
            go($returnUrl);
        }

        $quantity   = max(1, $quantity);
        $alreadyIn  = (int)($_SESSION['cart'][$productId] ?? 0);
        $limit      = min($stock, MAX_PER_ITEM);
        $newQty     = $alreadyIn + $quantity;

        if ($newQty > $limit) {
            $newQty = $limit;
            flash('error', 'Only ' . $limit . ' of ' . $product['name'] . ' can be ordered. Quantity adjusted.');
        } else {
            flash('success', $product['name'] . ' added to your cart.');
        }

        $_SESSION['cart'][$productId] = $newQty;

        go($action === 'buy_now' ? CHECKOUT_URL : CART_URL);
        break;


    // ----- UPDATE: set an exact quantity (0 removes the item) -----
    case 'update':

        if ($productId <= 0 || !isset($_SESSION['cart'][$productId])) {
            flash('error', 'That item is not in your cart.');
            go(CART_URL);
        }

        if ($quantity <= 0) {
            unset($_SESSION['cart'][$productId]);
            flash('success', 'Item removed from your cart.');
            go(CART_URL);
        }

        $product = findProduct($conn, $productId);

        if (!$product) {
            unset($_SESSION['cart'][$productId]);
            flash('error', 'That product no longer exists and was removed.');
            go(CART_URL);
        }

        $limit = min((int)$product['stock'], MAX_PER_ITEM);

        if ($limit <= 0) {
            unset($_SESSION['cart'][$productId]);
            flash('error', $product['name'] . ' is now out of stock and was removed.');
            go(CART_URL);
        }

        if ($quantity > $limit) {
            $quantity = $limit;
            flash('error', 'Only ' . $limit . ' of ' . $product['name'] . ' available. Quantity adjusted.');
        } else {
            flash('success', 'Cart updated.');
        }

        $_SESSION['cart'][$productId] = $quantity;

        go(CART_URL);
        break;


    // ----- REMOVE: take one item out -----
    case 'remove':

        if ($productId > 0 && isset($_SESSION['cart'][$productId])) {
            unset($_SESSION['cart'][$productId]);
            flash('success', 'Item removed from your cart.');
        }

        go(CART_URL);
        break;


    // ----- CLEAR: empty the whole cart -----
    case 'clear':

        $_SESSION['cart'] = [];
        flash('success', 'Your cart is now empty.');

        go(CART_URL);
        break;


    // ----- ANYTHING ELSE -----
    default:

        flash('error', 'Unknown action.');
        go(CART_URL);
}