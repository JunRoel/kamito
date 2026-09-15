<?php
/**
 * KAMITO — Newsletter signup handler
 * Saves the email to kamito_db.newsletter_subscribers,
 * then shows its own confirmation page.
 */

// Only real form submissions make sense here
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty(trim($_POST['email'] ?? ''))) {
    header('Location: ../index.php');
    exit;
}

 $conn = new mysqli("localhost", "root", "", "kamito_db");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

// Auto-create subscribers table (safe on every load)
 $conn->query("
    CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(120) NOT NULL UNIQUE,
        subscribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

 $email   = strtolower(trim($_POST['email']));
 $status  = 'ok';
 $message = "✅ You're in! We'll keep you posted on drops and launches.";

// Validate
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $status  = 'error';
    $message = "Please enter a valid email address.";
} else {
    // Duplicate check
    $stmt = $conn->prepare("SELECT 1 FROM newsletter_subscribers WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    if ($stmt->get_result()->fetch_row()) {
        $status  = 'error';
        $message = "You're already subscribed with that email.";
    } else {
        // Save it
        $stmt = $conn->prepare("INSERT INTO newsletter_subscribers (email) VALUES (?)");
        $stmt->bind_param('s', $email);
        $stmt->execute();
    }
}

 $conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="4;url=../index.php">
<title>Newsletter — KAMITO</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', Arial, sans-serif; }
  body { background:#0a0a0a; color:#fff; min-height:100vh;
         display:flex; align-items:center; justify-content:center; padding:20px; }
  .box { background:#151515; border:1px solid #232323; border-radius:18px;
         padding:44px; width:420px; text-align:center; }
  .logo { font-weight:800; letter-spacing:3px; font-size:14px; color:#9a9a9a; margin-bottom:26px; }
  .icon { font-size:44px; margin-bottom:16px; }
  h1 { font-size:22px; margin-bottom:10px; }
  p { color:#9a9a9a; font-size:15px; line-height:1.5; }
  .ok  { color:#c9f24b; }
  .err { color:#ff6b6b; }
  a.btn { display:inline-block; margin-top:26px; background:#c9f24b; color:#111;
          font-weight:700; padding:12px 26px; border-radius:12px; text-decoration:none; }
  .timer { margin-top:18px; font-size:12px; color:#666; }
</style>
</head>
<body>
<div class="box">
  <div class="logo">KAMITO</div>
  <div class="icon"><?= $status === 'ok' ? '📬' : '⚠️' ?></div>
  <h1 class="<?= $status === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($message) ?></h1>
  <p><?= $status === 'ok'
        ? 'Subscribed as <strong>' . htmlspecialchars($email) . '</strong>.'
        : 'No worries — try another email.' ?></p>
  <a class="btn" href="../index.php">← Back to home</a>
  <div class="timer">Taking you back automatically…</div>
</div>
</body>
</html>