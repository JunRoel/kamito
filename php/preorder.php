<?php
session_start();
 $conn = new mysqli("localhost", "root", "", "kamito_db");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

require_once 'stock_setup.php';
ensureStockSchema($conn);

// ---------- auto-detect the users table ----------
function findUsersTable(mysqli $conn): ?array {
    $tables     = ['users', 'user', 'accounts', 'account', 'customers', 'members'];
    $idCols     = ['id', 'user_id', 'userID'];
    $emailCols  = ['email', 'gmail', 'email_address', 'user_email'];
    $nameCols   = ['name', 'full_name', 'fullname', 'username'];

    foreach ($tables as $t) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->bind_param('s', $t); $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()['c']) continue;

        $found = ['table' => $t, 'id' => null, 'email' => null, 'name' => null];
        foreach (['id' => $idCols, 'email' => $emailCols, 'name' => $nameCols] as $key => $candidates) {
            foreach ($candidates as $c) {
                $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $stmt->bind_param('ss', $t, $c); $stmt->execute();
                if ($stmt->get_result()->fetch_assoc()['c']) { $found[$key] = $c; break; }
            }
        }
        if ($found['id'] && $found['email']) return $found;
    }
    return null;
}
 $usersRef = findUsersTable($conn);

// ---------- logged-in user ----------
function getSessionUserId(): ?int {
    foreach (['user_id', 'id', 'userID', 'uid', 'member_id'] as $k) {
        if (!empty($_SESSION[$k]) && is_numeric($_SESSION[$k])) return (int)$_SESSION[$k];
    }
    return null;
}

 $loggedUser = null;
 $userId = getSessionUserId();
if ($userId && $usersRef) {
    $stmt = $conn->prepare("SELECT * FROM `{$usersRef['table']}` WHERE `{$usersRef['id']}` = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $loggedUser = [
            'name'  => $row[$usersRef['name']]  ?? '',
            'email' => strtolower($row[$usersRef['email']]),
        ];
    }
}

// ---------- product + availability ----------
 $product_id = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);

 $stmt = $conn->prepare("SELECT name, price, stock FROM products WHERE id = ?");
 $stmt->bind_param('i', $product_id);
 $stmt->execute();
 $product = $stmt->get_result()->fetch_assoc();
if (!$product) die("Product not found.");

 $stmt = $conn->prepare("SELECT COALESCE(SUM(quantity),0) AS reserved FROM pre_orders
                        WHERE product_id = ? AND status IN ('pending','shipping')");
 $stmt->bind_param('i', $product_id);
 $stmt->execute();
 $reserved = (int)$stmt->get_result()->fetch_assoc()['reserved'];

 $available = max(0, (int)$product['stock'] - $reserved);
 $error = ''; $success = false; $order_id = 0;
 $paidWith = '';

// ---------- submission ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['customer_name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $payment  = $_POST['payment_method'] ?? '';
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $gmail    = $loggedUser['email'] ?? strtolower(trim($_POST['gmail'] ?? ''));

    $validPayments = ['Cash on Delivery', 'Cash on Pickup'];

    if ($usersRef === null) {
        $error = "Server setup: no users table found — cannot verify registration.";
    } elseif ($name === '') {
        $error = "Please enter your full name.";
    } elseif (!$loggedUser) {
        if (!filter_var($gmail, FILTER_VALIDATE_EMAIL) || !str_ends_with($gmail, '@gmail.com')) {
            $error = "Please enter a valid Gmail address (example@gmail.com).";
        } else {
            $stmt = $conn->prepare("SELECT 1 FROM `{$usersRef['table']}` WHERE `{$usersRef['email']}` = ? LIMIT 1");
            $stmt->bind_param('s', $gmail); $stmt->execute();
            if (!$stmt->get_result()->fetch_row()) {
                $error = "❌ This Gmail is not registered. Please create an account first — pre-orders are for registered users only.";
            }
        }
    }

    if ($error === '' && !preg_match('/^[0-9+()\-\s]{7,15}$/', $phone)) {
        $error = "Please enter a valid contact number (7–15 digits).";
    }
    if ($error === '' && !in_array($payment, $validPayments, true)) {
        $error = "Please choose a payment method.";
    }
    if ($error === '' && $payment === 'Cash on Delivery' && mb_strlen($address) < 10) {
        $error = "Please enter your complete delivery address (street, barangay, city).";
    }
    if ($error === '' && $quantity > $available) {
        $error = "Sorry, only $available unit(s) available for pre-order.";
    }

    if ($error === '') {
        if ($payment === 'Cash on Pickup') $address = '— (pickup)';
        $paidWith = $payment;

        $stmt = $conn->prepare("INSERT INTO pre_orders
            (product_id, customer_name, email, phone, address, payment_method, quantity)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssssi', $product_id, $name, $gmail, $phone, $address, $payment, $quantity);
        $stmt->execute();
        $order_id = $stmt->insert_id;
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Pre-order — KAMITO</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', Arial, sans-serif; }
  body { background:#0a0a0a; color:#fff; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
  .box { background:#151515; border:1px solid #232323; border-radius:18px; padding:36px; width:420px; }
  h1 { font-size:24px; margin-bottom:6px; }
  .price { color:#c9f24b; font-weight:800; font-size:20px; margin-bottom:6px; }
  .avail { color:#9a9a9a; font-size:14px; margin-bottom:22px; }
  label { display:block; font-size:14px; color:#bbb; margin:12px 0 6px; }
  input, select, textarea { width:100%; background:#1f1f1f; border:1px solid #2e2e2e; color:#fff;
          padding:11px; border-radius:10px; font-size:15px; font-family:inherit; }
  textarea { resize:vertical; min-height:70px; }
  input:focus, select:focus, textarea:focus { outline:none; border-color:#c9f24b; }
  input.locked { background:#242424; color:#9a9a9a; }
  button { width:100%; margin-top:22px; background:#c9f24b; border:0; color:#111;
           font-weight:700; padding:13px; border-radius:12px; font-size:15px; cursor:pointer; }
  button:hover { filter:brightness(1.08); }
  .error { background:rgba(255,70,70,.12); color:#ff6b6b; border:1px solid rgba(255,70,70,.35);
           padding:10px 14px; border-radius:10px; font-size:14px; margin-bottom:14px; }
  .ok { background:rgba(200,255,80,.12); color:#c9f24b; border:1px solid rgba(200,255,80,.35);
        padding:14px; border-radius:10px; font-size:15px; margin-bottom:16px; }
  .notice { background:rgba(255,180,0,.08); color:#ffb400; border:1px solid rgba(255,180,0,.3);
        padding:12px 14px; border-radius:10px; font-size:14px; margin-bottom:16px; }
  .as-user { display:flex; align-items:center; gap:8px; font-size:14px; color:#c9f24b; margin-bottom:14px; }
  a.back { display:block; text-align:center; margin-top:16px; color:#9a9a9a; font-size:14px; }
  .hint { text-align:center; font-size:13px; color:#9a9a9a; margin-top:14px; }
  .hint a { color:#c9f24b; }
  .pay-note { font-size:12px; color:#777; margin-top:6px; }
</style>
</head>
<body>
<div class="box">
  <h1><?= htmlspecialchars($product['name']) ?></h1>
  <div class="price">$<?= number_format($product['price'], 2) ?></div>

  <?php if ($success): ?>
    <div class="ok">✅ Pre-order placed! Order #<?= $order_id ?><br>
      Payment: <strong><?= htmlspecialchars($paidWith) ?></strong><br>
      We'll contact you at <?= htmlspecialchars($loggedUser['email'] ?? ($_POST['gmail'] ?? '')) ?>.</div>
  <?php elseif ($available <= 0): ?>
    <div class="error">Sorry, this paddle is fully pre-ordered. Check back soon.</div>
  <?php else: ?>
    <div class="avail"><?= $available ?> unit(s) available for pre-order</div>

    <?php if ($loggedUser): ?>
      <div class="as-user">🔐 Ordering as <?= htmlspecialchars($loggedUser['email']) ?></div>
    <?php else: ?>
      <div class="notice">⚠️ Pre-orders are for <strong>registered users only</strong>. Your Gmail will be verified against our accounts.</div>
    <?php endif; ?>

    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="product_id" value="<?= $product_id ?>">

      <label>Full name</label>
      <input name="customer_name" required
             value="<?= htmlspecialchars($loggedUser['name'] ?: ($_POST['customer_name'] ?? '')) ?>">

      <?php if ($loggedUser): ?>
        <label>Gmail (from your account)</label>
        <input class="locked" value="<?= htmlspecialchars($loggedUser['email']) ?>" readonly>
      <?php else: ?>
        <label>Gmail address</label>
        <input type="email" name="gmail" placeholder="example@gmail.com" required
               value="<?= htmlspecialchars($_POST['gmail'] ?? '') ?>">
      <?php endif; ?>

      <label>Contact number</label>
      <input name="phone" placeholder="0917 123 4567" required
             value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">

      <label>Payment method</label>
      <select name="payment_method" id="payMethod" required>
        <option value="">— choose —</option>
        <option value="Cash on Delivery" <?= ($_POST['payment_method'] ?? '') === 'Cash on Delivery' ? 'selected' : '' ?>>💵 Cash on Delivery (COD)</option>
        <option value="Cash on Pickup"  <?= ($_POST['payment_method'] ?? '') === 'Cash on Pickup'  ? 'selected' : '' ?>>🏪 Cash on Pickup</option>
      </select>
      <div class="pay-note" id="payNote">Choose COD to have it delivered, or pick it up from us and pay in person.</div>

      <label id="addrLabel">Delivery address</label>
      <textarea name="address" id="addrField" placeholder="Street, barangay, city, province"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>

      <label>Quantity</label>
      <input type="number" name="quantity" value="1" min="1" max="<?= $available ?>">

      <button>Place pre-order</button>
    </form>

    <?php if (!$loggedUser): ?>
      <div class="hint">No account yet? <a href="login.php">Register / log in here</a></div>
    <?php endif; ?>
  <?php endif; ?>

  <a class="back" href="products.php">← Back to paddles</a>
</div>

<script>
const pay = document.getElementById('payMethod');
const addr = document.getElementById('addrField');
const addrLabel = document.getElementById('addrLabel');
function syncAddress() {
  if (pay.value === 'Cash on Pickup') {
    addr.style.display = 'none'; addrLabel.style.display = 'none'; addr.required = false; addr.value = '';
  } else {
    addr.style.display = ''; addrLabel.style.display = ''; addr.required = true;
  }
}
pay.addEventListener('change', syncAddress);
syncAddress();
</script>
</body>
</html>