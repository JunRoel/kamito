<?php
session_start();
 $conn = new mysqli("localhost", "root", "", "kamito_db");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

require_once 'stock_setup.php';
ensureStockSchema($conn);
ensureNotificationsTable($conn);

// complete any COD shipments whose 60s window has elapsed
processShippings($conn);

// ---------- ADMIN ONLY ----------
if (!isAdminUser($conn)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Access denied</title>
    <style>body{background:#0a0a0a;color:#fff;font-family:"Segoe UI",Arial,sans-serif;
    display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
    .box{background:#151515;border:1px solid #232323;border-radius:18px;padding:40px;text-align:center;width:380px;}
    h1{color:#ff6b6b;font-size:26px;margin-bottom:10px;} p{color:#9a9a9a;font-size:15px;margin-bottom:22px;}
    a{color:#c9f24b;text-decoration:none;font-weight:700;}</style></head>
    <body><div class="box"><h1>⛔ Access denied</h1>
    <p>This page is for administrators only.<br>Please log in with an admin account.</p>
    <a href="login.php">→ Go to login</a></div></body></html>';
    exit;
}

// --- Customer "received" confirmations: show + dismiss ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dismiss_notifs'])) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE type = 'received' AND is_read = 0");
    header('Location: admin_orders.php');
    exit;
}
 $recvNotifs = $conn->query("SELECT message, created_at FROM notifications
                            WHERE type = 'received' AND is_read = 0
                            ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);

define('SHIP_WAIT_SECONDS', 60);

 $msg = '';

// --- Mark as Shipped → COD waits 60s, Pickup is instant ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ship_order'])) {
    $order_id = (int)$_POST['order_id'];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT product_id, quantity, email, payment_method FROM pre_orders
                                WHERE id = ? AND status = 'pending' FOR UPDATE");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        if (!$order) throw new Exception("Error: Order #$order_id not pending or not found.");

        // stock deducted now, either way
        $stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
        $stmt->bind_param('iii', $order['quantity'], $order['product_id'], $order['quantity']);
        $stmt->execute();
        if ($stmt->affected_rows === 0) throw new Exception("Error: Not enough stock to ship order #$order_id. Restock first.");

        if ($order['payment_method'] === 'Cash on Pickup') {
            // 🏪 PICKUP — no timer, ready immediately
            $stmt = $conn->prepare("UPDATE pre_orders SET status = 'shipped', shipped_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $order_id);
            $stmt->execute();

            if (!empty($order['email'])) {
                $note = "Your paddle (Order #{$order_id}) is ready for pickup — pay cash when you collect it. 🏪";
                $stmt = $conn->prepare("INSERT INTO notifications (user_email, order_id, type, message)
                                        VALUES (?, ?, 'shipped', ?)");
                $stmt->bind_param('sis', $order['email'], $order_id, $note);
                $stmt->execute();
            }

            $conn->commit();
            $msg = "✅ Order #$order_id is ready for pickup — customer notified instantly.";
        } else {
            // 💵 CASH ON DELIVERY — 60 second transit timer
            $stmt = $conn->prepare("UPDATE pre_orders SET status = 'shipping', ship_requested_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $order_id);
            $stmt->execute();

            $conn->commit();
            $msg = "⏳ Order #$order_id is shipping — customer will be notified in " . SHIP_WAIT_SECONDS . " seconds.";
        }
    } catch (Exception $e) {
        $conn->rollback();
        $msg = $e->getMessage();
    }
}

// --- Cancel an order ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order'])) {
    $order_id = (int)$_POST['order_id'];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT product_id, quantity, status, email FROM pre_orders
                                WHERE id = ? AND status IN ('pending','shipping','shipped') FOR UPDATE");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        if (!$order) throw new Exception("Error: Order #$order_id already cancelled or not found.");

        if ($order['status'] !== 'pending') {
            $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            $stmt->bind_param('ii', $order['quantity'], $order['product_id']);
            $stmt->execute();
        }

        $stmt = $conn->prepare("UPDATE pre_orders SET status = 'cancelled' WHERE id = ?");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();

        $conn->commit();

        if (!empty($order['email'])) {
            $note = $order['status'] === 'shipped'
                ? "Your shipped order #{$order_id} was cancelled — it will not arrive."
                : "Your pre-order #{$order_id} was cancelled by the admin.";
            $stmt = $conn->prepare("INSERT INTO notifications (user_email, order_id, type, message)
                                    VALUES (?, ?, 'cancelled', ?)");
            $stmt->bind_param('sis', $order['email'], $order_id, $note);
            $stmt->execute();
        }

        $msg = "✅ Order #$order_id cancelled. Customer notified.";
    } catch (Exception $e) {
        $conn->rollback();
        $msg = $e->getMessage();
    }
}

// --- Restock ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restock'])) {
    $pid = (int)$_POST['product_id'];
    $qty = max(1, (int)$_POST['add_qty']);
    $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
    $stmt->bind_param('ii', $qty, $pid);
    $stmt->execute();
    $msg = "✅ Stock added.";
}

 $orders = $conn->query("
    SELECT po.*, p.name,
           TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(po.ship_requested_at, INTERVAL " . SHIP_WAIT_SECONDS . " SECOND)) AS ship_remaining
    FROM pre_orders po
    JOIN products p ON p.id = po.product_id
    ORDER BY po.id DESC
")->fetch_all(MYSQLI_ASSOC);
 $products = $conn->query("SELECT id, name, stock FROM products ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// --- Registered users ---
 $users = []; $usersError = null;
try {
    $users = mysqli_fetch_all(mysqli_query(
        $conn,
        "SELECT id, full_name, email, role, status, created_at, last_login
         FROM users ORDER BY (status = 'pending') DESC, created_at DESC"
    ), MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    $usersError = "Users table not found.";
}

// --- Newsletter subscribers ---
 $subs = []; $subsError = null;
try {
    $subs = $conn->query("SELECT email, subscribed_at FROM newsletter_subscribers
                          ORDER BY subscribed_at DESC")->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    $subsError = "No subscribers yet.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Orders — KAMITO Admin</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', Arial, sans-serif; }
  body { background:#0a0a0a; color:#fff; padding:40px 6%; }
  h1 { font-size:32px; margin-bottom:24px; }
  a.back { color:#9a9a9a; font-size:14px; display:inline-block; margin-bottom:18px; }
  .msg { padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; }
  .ok  { background:rgba(200,255,80,.12); color:#c9f24b; border:1px solid rgba(200,255,80,.35); }
  .err { background:rgba(255,70,70,.12); color:#ff6b6b; border:1px solid rgba(255,70,70,.35); }
  table { width:100%; border-collapse:collapse; background:#151515; border-radius:14px; overflow:hidden; }
  th, td { padding:14px 16px; text-align:left; border-bottom:1px solid #232323; font-size:14px; }
  th { color:#9a9a9a; font-weight:600; }
  .ship   { background:#c9f24b; border:0; color:#111; font-weight:700; padding:8px 14px;
            border-radius:8px; cursor:pointer; }
  .cancel { background:transparent; border:1px solid rgba(255,107,107,.5); color:#ff6b6b;
            font-weight:600; padding:7px 14px; border-radius:8px; cursor:pointer; }
  .cancel:hover { background:rgba(255,70,70,.12); }
  td form { display:inline-block; margin-right:6px; }
  .tag { padding:4px 12px; border-radius:999px; font-size:12px; font-weight:600; }
  .pending   { background:rgba(255,180,0,.12);  color:#ffb400; }
  .shipping  { background:rgba(96,165,250,.12); color:#60a5fa; }
  .shipped   { background:rgba(200,255,80,.12); color:#c9f24b; }
  .received  { background:rgba(74,222,128,.15); color:#4ade80; }
  .cancelled { background:rgba(255,70,70,.12);  color:#ff6b6b; text-decoration:line-through; }
  .countdown { font-variant-numeric:tabular-nums; color:#60a5fa; font-weight:700; }
  .restock, .card { margin-top:28px; background:#151515; border:1px solid #232323;
                    border-radius:14px; padding:20px; }
  .card h2 { font-size:20px; margin-bottom:4px; }
  .card .sub { color:#9a9a9a; font-size:14px; margin-bottom:16px; }
  .card .table-wrap { overflow-x:auto; }
  select, input[type=number] { background:#1f1f1f; border:1px solid #2e2e2e; color:#fff;
          padding:9px; border-radius:8px; }
  .restock button { background:#c9f24b; border:0; color:#111; font-weight:700;
          padding:9px 18px; border-radius:8px; cursor:pointer; }
  .role-admin { color:#c9f24b; font-weight:600; }
  .ustatus { padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; }
  .u-approved { background:rgba(200,255,80,.12); color:#c9f24b; }
  .u-pending  { background:rgba(255,180,0,.12);  color:#ffb400; }
  .u-rejected { background:rgba(255,70,70,.12);  color:#ff6b6b; }
</style>
</head>
<body>
<a class="back" href="products.php">← Back to store</a>
<h1>Pre-orders <span style="font-size:14px;color:#9a9a9a;">(admin only)</span></h1>

<?php if ($recvNotifs): ?>
<div class="msg ok">
  <strong>📦 Customer confirmations (<?= count($recvNotifs) ?>):</strong>
  <?php foreach ($recvNotifs as $n): ?>
    <div style="margin-top:6px;"><?= htmlspecialchars($n['message']) ?>
      <span style="color:#777;">— <?= htmlspecialchars(date('M j, Y g:i A', strtotime($n['created_at']))) ?></span></div>
  <?php endforeach; ?>
  <form method="post" style="margin-top:10px;">
    <button name="dismiss_notifs" style="background:#2a2a2a; border:1px solid #2e2e2e; color:#c9f24b;
            font-weight:700; padding:7px 14px; border-radius:8px; cursor:pointer;">Mark as seen</button>
  </form>
</div>
<?php endif; ?>

<?php if ($msg): ?><div class="msg <?= strpos($msg, 'Error') === 0 ? 'err' : 'ok' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<table>
  <tr>
    <th>Order</th><th>Customer</th><th>Gmail</th><th>Phone</th>
    <th>Product</th><th>Qty</th><th>Payment</th><th>Address</th><th>Status</th><th>Action</th>
  </tr>
  <?php if (!$orders): ?>
    <tr><td colspan="10" style="color:#777;">No pre-orders yet.</td></tr>
  <?php endif; ?>
  <?php foreach ($orders as $o): ?>
  <tr>
    <td>#<?= $o['id'] ?></td>
    <td><?= htmlspecialchars($o['customer_name']) ?></td>
    <td><?= htmlspecialchars($o['email'] ?? '—') ?></td>
    <td><?= htmlspecialchars($o['phone'] ?? '—') ?></td>
    <td><?= htmlspecialchars($o['name']) ?></td>
    <td><?= $o['quantity'] ?></td>
    <td><?= htmlspecialchars($o['payment_method'] ?? '—') ?></td>
    <td style="max-width:220px; white-space:normal; font-size:13px; color:#bbb;"><?= htmlspecialchars($o['address'] ?? '—') ?></td>
    <td>
      <?php if ($o['status'] === 'shipping' && $o['ship_remaining'] !== null): ?>
        <span class="tag shipping">Shipping…</span>
        <div class="countdown" data-remaining="<?= max(0, (int)$o['ship_remaining']) ?>"><?= max(0, (int)$o['ship_remaining']) ?>s</div>
      <?php else: ?>
        <span class="tag <?= $o['status'] ?>"><?= ucfirst($o['status']) ?></span>
      <?php endif; ?>
    </td>
    <td>
      <?php if ($o['status'] === 'pending'): ?>
        <form method="post" onsubmit="return confirm('Ship this order? Stock will be deducted now.')">
          <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
          <button class="ship" name="ship_order">Mark as Shipped</button>
        </form>
        <form method="post" onsubmit="return confirm('Cancel this order?')">
          <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
          <button class="cancel" name="cancel_order">Cancel</button>
        </form>
      <?php elseif ($o['status'] === 'shipping'): ?>
        <span style="color:#60a5fa; font-size:13px;">⏳ In transit…</span>
        <form method="post" onsubmit="return confirm('Cancel this IN-TRANSIT order? Stock will be returned.')">
          <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
          <button class="cancel" name="cancel_order">Cancel</button>
        </form>
      <?php elseif ($o['status'] === 'shipped'): ?>
        <form method="post" onsubmit="return confirm('Cancel this SHIPPED order? Stock will be returned.')">
          <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
          <button class="cancel" name="cancel_order">Cancel &amp; restock</button>
        </form>
      <?php else: ?>—<?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>

<div class="restock">
  <strong>Restock a paddle</strong>
  <form method="post" style="margin-top:12px; display:flex; gap:10px; align-items:center;">
    <select name="product_id">
      <?php foreach ($products as $p): ?>
        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['stock'] ?> left)</option>
      <?php endforeach; ?>
    </select>
    <input type="number" name="add_qty" value="10" min="1" style="width:80px;">
    <button name="restock">Add stock</button>
  </form>
</div>

<div class="card">
  <h2>Registered users</h2>
  <p class="sub"><?= count($users) ?> registered user<?= count($users) === 1 ? '' : 's' ?>.</p>
  <?php if ($usersError): ?>
    <p style="color:#ff6b6b; font-size:14px;"><?= htmlspecialchars($usersError) ?></p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Last login</th></tr>
        <?php if (!$users): ?><tr><td colspan="6" style="color:#777;">No registered users yet.</td></tr><?php endif; ?>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><?= htmlspecialchars($u['full_name']) ?></td>
          <td><?= htmlspecialchars($u['email']) ?></td>
          <td><span class="role-<?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars(ucfirst($u['role'])) ?></span></td>
          <td><span class="ustatus u-<?= htmlspecialchars($u['status'] ?? 'approved') ?>"><?= htmlspecialchars(ucfirst($u['status'] ?? 'approved')) ?></span></td>
          <td><?= htmlspecialchars(date('M j, Y', strtotime($u['created_at']))) ?></td>
          <td><?= $u['last_login'] ? htmlspecialchars(date('M j, Y', strtotime($u['last_login']))) : 'Never' ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Newsletter subscribers</h2>
  <p class="sub"><?= count($subs) ?> subscriber<?= count($subs) === 1 ? '' : 's' ?>.</p>
  <?php if ($subsError): ?>
    <p style="color:#9a9a9a; font-size:14px;"><?= htmlspecialchars($subsError) ?></p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <tr><th>#</th><th>Email</th><th>Subscribed</th></tr>
        <?php if (!$subs): ?><tr><td colspan="3" style="color:#777;">No subscribers yet.</td></tr><?php endif; ?>
        <?php foreach ($subs as $i => $s): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= htmlspecialchars($s['email']) ?></td>
          <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($s['subscribed_at']))) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('.countdown').forEach(el => {
  let s = parseInt(el.dataset.remaining, 10);
  const t = setInterval(() => {
    s--;
    if (s <= 0) { clearInterval(t); location.reload(); }
    else el.textContent = s + 's';
  }, 1000);
});
</script>

</body>
</html>