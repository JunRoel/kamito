<?php
/**
 * KAMITO — User account dashboard
 * Profile + notifications + pre-orders (old form AND new store)
 * + payment method & delivery address + receive confirmation.
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/db_connect.php';
 $conn = get_db_connection();

require_once __DIR__ . '/stock_setup.php';
ensureStockSchema($conn);
processShippings($conn);   // completes orders whose 60s window ended

/* ---------- notifications table (auto-created, safe every load) ---------- */
 $conn->query("
    CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_email VARCHAR(120) NOT NULL,
        order_id INT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'info',
        message VARCHAR(255) NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

/* ---------- profile ---------- */
 $st = mysqli_prepare($conn, 'SELECT full_name, email, role, status, created_at, last_login FROM users WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($st, 'i', $_SESSION['user_id']);
mysqli_stmt_execute($st);
 $user = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
mysqli_stmt_close($st);

if (!$user) { header('Location: logout.php'); exit; }

/* ---------- mark all notifications as read ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $st = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1 WHERE user_email = ?');
    mysqli_stmt_bind_param($st, 's', $user['email']);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    header('Location: account.php');
    exit;
}

/* ---------- customer confirms receipt of a shipped order ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_received'])) {
    $orderId = (int)$_POST['order_id'];
    $system  = ($_POST['system'] ?? 'new') === 'legacy' ? 'legacy' : 'new';

    if ($system === 'legacy') {
        $st = mysqli_prepare($conn,
            'UPDATE preorders SET status = ? WHERE id = ? AND LOWER(TRIM(email)) = LOWER(?) AND status = ?');
        $newStatus = 'received'; $oldStatus = 'shipped';
        mysqli_stmt_bind_param($st, 'siss', $newStatus, $orderId, $user['email'], $oldStatus);
    } else {
        $st = mysqli_prepare($conn,
            'UPDATE pre_orders SET status = ? WHERE id = ? AND LOWER(TRIM(email)) = LOWER(?) AND status = ?');
        $newStatus = 'received'; $oldStatus = 'shipped';
        mysqli_stmt_bind_param($st, 'siss', $newStatus, $orderId, $user['email'], $oldStatus);
    }
    mysqli_stmt_execute($st);
    $ok = mysqli_stmt_affected_rows($st) > 0;
    mysqli_stmt_close($st);

    if ($ok) {
        // notify every admin
        $adminEmails = [];
        $res = @mysqli_query($conn, "SELECT email FROM users WHERE role = 'admin'");
        if ($res) while ($r = mysqli_fetch_assoc($res)) $adminEmails[] = strtolower(trim($r['email']));
        if (defined('ADMIN_EMAILS')) foreach (ADMIN_EMAILS as $ae) $adminEmails[] = strtolower(trim($ae));
        $adminEmails = array_unique(array_filter($adminEmails));

        $note = $user['email'] . " confirmed receipt of Order #" . $orderId . ". ✅";
        foreach ($adminEmails as $ae) {
            $type = 'received';
            $st = mysqli_prepare($conn,
                'INSERT INTO notifications (user_email, order_id, type, message) VALUES (?, ?, ?, ?)');
            mysqli_stmt_bind_param($st, 'siss', $ae, $orderId, $type, $note);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
    }
    header('Location: account.php');
    exit;
}

/* ---------- unread notifications ---------- */
 $st = mysqli_prepare($conn, 'SELECT id, type, message, created_at FROM notifications
                             WHERE user_email = ? AND is_read = 0 ORDER BY created_at DESC');
mysqli_stmt_bind_param($st, 's', $user['email']);
mysqli_stmt_execute($st);
 $notifs = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
mysqli_stmt_close($st);

/* ---------- this user's pre-orders ---------- */
 $orders = [];

// Legacy system (preorders — the original checkout form)
 $st = mysqli_prepare($conn,
    'SELECT p.id, p.grip_size, p.quantity, p.status, p.created_at, pd.name AS paddle_name
     FROM preorders p
     LEFT JOIN paddles pd ON pd.id = p.paddle_id
     WHERE LOWER(TRIM(p.email)) = LOWER(?)
     ORDER BY p.created_at DESC');
mysqli_stmt_bind_param($st, 's', $user['email']);
mysqli_stmt_execute($st);
foreach (mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC) as $row) {
    $row['grip']    = $row['grip_size'];
    $row['legacy']  = true;
    $row['address'] = null;   // legacy form didn't collect addresses
    $row['payment'] = null;
    $orders[] = $row;
}
mysqli_stmt_close($st);

// New system (pre_orders — the Paddles page pre-order flow)
try {
    $st = mysqli_prepare($conn,
        'SELECT po.id, po.quantity, po.status, po.created_at,
                po.address, po.payment_method, pd.name AS paddle_name
         FROM pre_orders po
         LEFT JOIN products pd ON pd.id = po.product_id
         WHERE LOWER(TRIM(po.email)) = LOWER(?)');
    mysqli_stmt_bind_param($st, 's', $user['email']);
    mysqli_stmt_execute($st);
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC) as $row) {
        $row['grip']   = null;
        $row['legacy'] = false;
        $row['payment'] = $row['payment_method'];
        $orders[] = $row;
    }
    mysqli_stmt_close($st);
} catch (mysqli_sql_exception $ex) { /* table not created yet — skip */ }

// newest first
usort($orders, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));

mysqli_close($conn);

 $isAdmin = (($_SESSION['user_role'] ?? '') === 'admin');
 $statusClass = ['pending' => 'order-pending', 'confirmed' => 'order-confirmed', 'shipping' => 'order-shipping',
                'shipped' => 'order-shipped', 'received' => 'order-received', 'cancelled' => 'order-cancelled'];
 $notifIcon   = ['shipped' => '🚚', 'cancelled' => '⚠️', 'received' => '📦', 'info' => '🔔'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My account — KAMITO</title>
<?php if (in_array('shipping', array_column($orders, 'status'))): ?>
<meta http-equiv="refresh" content="10">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<link rel="stylesheet" href="../css/auth.css">
<style>
  .acct-shell{ max-width:980px; margin:0 auto; padding:48px 24px 96px; }
  .acct-card{ background:var(--card); border:1px solid var(--card-border); border-radius:var(--radius-lg); padding:28px 32px; margin-bottom:24px; }
  .acct-card h2{ margin:0 0 4px; font-size:20px; }
  .acct-card .sub{ color:var(--text-dim); font-size:14px; margin:0 0 20px; }
  .profile-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:18px; }
  .profile-grid .label{ font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--text-dim); margin-bottom:4px; }
  .profile-grid .value{ font-weight:600; }
  .orders-table{ width:100%; border-collapse:collapse; font-size:14px; }
  .orders-table th, .orders-table td{ text-align:left; padding:10px 8px; border-bottom:1px solid var(--card-border); vertical-align:top; }
  .status-pill{ display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:600; background:rgba(255,255,255,.08); }
  .order-pending{ color:#fbbf24; } .order-confirmed{ color:#4ade80; } .order-shipping{ color:#60a5fa; }
  .order-shipped{ color:#60a5fa; } .order-received{ color:#4ade80; } .order-cancelled{ color:#f87171; }
  .legend{ font-size:12px; color:var(--text-dim); margin:-8px 0 16px; }
  .whoami{ font-size:13px; color:var(--text-dim); padding:0 8px; white-space:nowrap; }
  .legacy-note{ color:var(--text-dim); font-size:12px; }
  .addr{ max-width:200px; white-space:normal; font-size:13px; color:var(--text-dim, #9a9a9a); }
  .pay-tag{ display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px;
            font-weight:600; background:rgba(201,242,75,.1); color:#c9f24b;
            border:1px solid rgba(201,242,75,.3); }
  .notif-stack{ display:flex; flex-direction:column; gap:10px; margin-bottom:24px; }
  .notif{ display:flex; gap:12px; align-items:flex-start; background:var(--card);
          border:1px solid var(--card-border); border-left:4px solid #60a5fa;
          border-radius:12px; padding:14px 18px; }
  .notif-shipped{ border-left-color:#4ade80; }
  .notif-cancelled{ border-left-color:#f87171; }
  .notif .icon{ font-size:20px; line-height:1; }
  .notif .msg{ font-weight:600; font-size:14px; }
  .notif .time{ color:var(--text-dim); font-size:12px; margin-top:3px; }
  .mark-read{ background:transparent; border:1px solid var(--card-border); color:var(--text-dim);
              font-size:13px; padding:8px 14px; border-radius:8px; cursor:pointer; align-self:flex-start; }
  .mark-read:hover{ color:var(--text); }
  .receive-btn{ background:#c9f24b; border:0; color:#111; font-weight:700; font-size:12px;
                padding:7px 12px; border-radius:8px; cursor:pointer; }
  .receive-btn:hover{ filter:brightness(1.08); }
</style>
</head>
<body>

<header class="site-header" id="siteHeader">
  <div class="container nav-inner">
    <a href="../index.php" class="logo">
      <img src="../img/logo.png" alt="KAMITO logo" class="logo-img"><span>KAMITO</span>
    </a>
    <nav class="main-nav" id="mainNav">
      <a href="products.php">Paddles</a>
      <a href="../index.php#technology">Technology</a>
      <a href="../index.php#specs">Specs</a>
      <a href="../index.php#affiliates">Affiliates</a>
    </nav>
    <div class="nav-actions">
      <span class="whoami"><?= $isAdmin ? '🛡️ Admin:' : '👤' ?> <?= e($user['full_name']) ?></span>
      <?php if ($isAdmin): ?>
        <a href="admin_orders.php" class="btn btn-ghost btn-sm">Admin orders</a>
      <?php endif; ?>
      <a href="logout.php" class="btn btn-ghost btn-sm">Sign out</a>
    </div>
  </div>
</header>

<main class="acct-shell">
  <span class="eyebrow eyebrow-accent">My Account</span>
  <h1 class="section-title" style="margin-bottom:24px;">
    Welcome back, <?= e($user['full_name']) ?>
    <?php if ($notifs): ?><span style="font-size:16px; color:#c9f24b;">🔔 <?= count($notifs) ?> new</span><?php endif; ?>
  </h1>

  <?php if ($notifs): ?>
    <div class="notif-stack">
      <?php foreach ($notifs as $n): ?>
        <div class="notif notif-<?= e($n['type']) ?>">
          <span class="icon"><?= $notifIcon[$n['type']] ?? '🔔' ?></span>
          <div>
            <div class="msg"><?= e($n['message']) ?></div>
            <div class="time"><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <form method="post"><button class="mark-read" name="mark_read">Mark all as read</button></form>
    </div>
  <?php endif; ?>

  <div class="acct-card">
    <h2>Profile</h2>
    <p class="sub">Your account details on file.</p>
    <div class="profile-grid">
      <div><div class="label">Full name</div><div class="value"><?= e($user['full_name']) ?></div></div>
      <div><div class="label">Email</div><div class="value"><?= e($user['email']) ?></div></div>
      <div><div class="label">Member since</div><div class="value"><?= e(date('M j, Y', strtotime($user['created_at']))) ?></div></div>
      <div><div class="label">Last sign in</div><div class="value"><?= $user['last_login'] ? e(date('M j, Y g:i A', strtotime($user['last_login']))) : '—' ?></div></div>
    </div>
  </div>

  <div class="acct-card">
    <h2>Pre-orders</h2>
    <p class="sub">Everything you've reserved with KAMITO — including where it's being delivered.</p>
    <p class="legend">🟡 Pending · 🟢 Confirmed · 🔵 Shipping (~1 min) · 🚚 Shipped · ✅ Received · 🔴 Cancelled</p>
    <div style="overflow-x:auto;">
    <table class="orders-table">
      <thead>
        <tr><th>Order #</th><th>Paddle</th><th>Grip</th><th>Qty</th><th>Payment</th><th>Deliver to</th><th>Status</th><th>Placed</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td><?= $o['legacy'] ? 'L#' . e($o['id']) . ' <span class="legacy-note">(old form)</span>' : '#' . e($o['id']) ?></td>
            <td><?= e($o['paddle_name'] ?? '—') ?></td>
            <td><?= ($o['grip'] !== null && $o['grip'] !== '') ? e($o['grip']) : '—' ?></td>
            <td><?= e($o['quantity']) ?></td>
            <td><?= !empty($o['payment']) ? '<span class="pay-tag">' . e($o['payment']) . '</span>' : '—' ?></td>
            <td class="addr"><?= !empty($o['address']) ? e($o['address']) : '—' ?></td>
            <td><span class="status-pill <?= e($statusClass[$o['status']] ?? '') ?>"><?= e(ucfirst($o['status'])) ?></span></td>
            <td><?= e(date('M j, Y', strtotime($o['created_at']))) ?></td>
            <td>
              <?php if ($o['status'] === 'shipped'): ?>
                <form method="post" onsubmit="return confirm('Confirm you received this paddle?')">
                  <input type="hidden" name="order_id" value="<?= e($o['id']) ?>">
                  <input type="hidden" name="system" value="<?= $o['legacy'] ? 'legacy' : 'new' ?>">
                  <button class="receive-btn" name="mark_received">📦 Mark as Received</button>
                </form>
              <?php elseif ($o['status'] === 'received'): ?>
                <span style="color:#4ade80; font-size:13px;">✅ Received</span>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$orders): ?>
          <tr><td colspan="9" style="color:var(--text-dim);">No pre-orders yet — <a href="products.php">browse the paddles →</a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</main>

<footer class="site-footer">
  <div class="container footer-bottom">
    <span>© <?= date('Y') ?> KAMITO. All rights reserved.</span>
  </div>
</footer>

</body>
</html>