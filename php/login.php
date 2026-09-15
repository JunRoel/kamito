<?php
session_start();

// Already signed in? Skip straight to the account page.
if (isset($_SESSION['user_id'])) {
    header('Location: account.php');
    exit;
}

// CSRF token for the auth forms.
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

 $initialMode = (($_GET['mode'] ?? '') === 'register') ? 'register' : 'login';
 $loggedOut   = isset($_GET['logged_out']);

function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — KAMITO</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<link rel="stylesheet" href="../css/auth.css">
</head>
<body class="auth-body" data-csrf="<?= e($_SESSION['csrf']) ?>" data-initial-mode="<?= e($initialMode) ?>">

<main class="auth-shell">

  <!-- Left: brand visual (reuses .paddle-cluster / .hero-visual from style.css) -->
<aside class="auth-visual" aria-hidden="true">
  <a href="../index.php" class="logo auth-brand">
    <img src="../img/logo.png" alt="KAMITO logo" class="logo-img">
    <span>KAMITO</span>
  </a>

    <div class="auth-stage">
      <div class="hero-visual">
        <img src="../img/hero.png" alt="Kamito Series J-PRO pickleball paddle" class="hero-image">
        <div class="hero-glow"></div>
      </div>
    </div>

    <div class="auth-tagline">
      <h2>Weapons of every athlete.</h2>
      <p>Your account keeps pre-orders, early access, and limited-release drops in one place.</p>
    </div>
  </aside>

  <!-- Right: forms -->
  <section class="auth-panel">
    <div class="auth-card">

      <a href="../index.php" class="logo auth-brand-mobile">
        <svg class="logo-mark" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M12 2 L20 8 L17 20 L7 20 L4 8 Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
          <path d="M12 2 L12 20" stroke="currentColor" stroke-width="1.6"/>
        </svg>
        <span>KAMITO</span>
      </a>

      <?php if ($loggedOut): ?>
        <p class="form-message success">You have been signed out.</p>
      <?php endif; ?>

      <div class="auth-tabs" role="tablist">
        <button type="button" class="auth-tab" role="tab" id="tabLogin" data-mode="login" aria-selected="true">Sign in</button>
        <button type="button" class="auth-tab" role="tab" id="tabRegister" data-mode="register" aria-selected="false">Create account</button>
      </div>

      <!-- ===== SIGN IN ===== -->
      <div id="loginPanel" class="auth-panel-form" role="tabpanel" aria-labelledby="tabLogin">
        <h1 class="auth-title">Welcome back</h1>
        <p class="auth-sub">Sign in to your KAMITO account.</p>

        <form id="loginForm" novalidate>
          <div class="form-row">
            <label for="loginEmail">Email</label>
            <input type="email" id="loginEmail" name="email" autocomplete="email" placeholder="you@example.com">
          </div>
          <div class="form-row">
            <label for="loginPassword">Password</label>
            <div class="auth-field">
              <input type="password" id="loginPassword" name="password" autocomplete="current-password" placeholder="••••••••">
              <button type="button" class="password-toggle" data-toggle-password aria-label="Show password">Show</button>
            </div>
          </div>
          <div class="form-row">
            <button type="submit" class="btn btn-primary btn-block" id="loginSubmit">Sign in</button>
          </div>
          <p class="form-message" id="loginMessage" role="status"></p>
        </form>

        <p class="auth-alt">New to KAMITO? <button type="button" class="link-btn" data-switch="register">Create an account</button></p>
      </div>

      <!-- ===== CREATE ACCOUNT ===== -->
      <div id="registerPanel" class="auth-panel-form" role="tabpanel" aria-labelledby="tabRegister" hidden>
        <h1 class="auth-title">Create your account</h1>
        <p class="auth-sub">Join the list for early access and order tracking.</p>

        <form id="registerForm" novalidate>
          <div class="form-row">
            <label for="regName">Full name</label>
            <input type="text" id="regName" name="full_name" autocomplete="name" placeholder="Alex Rivera">
          </div>
          <div class="form-row">
            <label for="regEmail">Email</label>
            <input type="email" id="regEmail" name="email" autocomplete="email" placeholder="you@example.com">
          </div>
          <div class="form-row">
            <label for="regPassword">Password</label>
            <div class="auth-field">
              <input type="password" id="regPassword" name="password" autocomplete="new-password" placeholder="At least 8 characters">
              <button type="button" class="password-toggle" data-toggle-password aria-label="Show password">Show</button>
            </div>
          </div>
          <div class="form-row">
            <label for="regConfirm">Confirm password</label>
            <input type="password" id="regConfirm" name="confirm_password" autocomplete="new-password" placeholder="Repeat your password">
          </div>
          <div class="form-row">
            <button type="submit" class="btn btn-primary btn-block" id="registerSubmit">Create account</button>
          </div>
          <p class="form-message" id="registerMessage" role="status"></p>
        </form>

        <p class="auth-alt">Already have an account? <button type="button" class="link-btn" data-switch="login">Sign in</button></p>
      </div>

    </div>
  </section>
</main>

<script src="../js/auth.js"></script>
</body>
</html>