<?php
session_start();
/**
 * KAMITO — Landing Page
 * Pulls the "Outperforming the Standard" spec row data live from MySQL.
 * Falls back to static defaults if XAMPP/MySQL isn't running yet, or if
 * the paddles table doesn't have the expected spec columns yet, so the
 * page still renders correctly before the database has been fully set up.
 */

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

 $flagship = [
    'face_material'  => 'Raw Toray T700 Unidirectional Carbon',
    'core'           => 'Thermoformed Thermo-sealed Honeycomb',
    'edge_perimeter' => 'Hyper-molded Foam-Infused Wall',
    'swing_weight'   => '115 Optimized Head Speed',
    'swing_rating'   => 'Elite Bite: mechanical grip',
];
 $conventional = [
    'face_material'  => 'Standard Carbon Weave',
    'core'           => 'Cabin-hold Honeycomb Core',
    'edge_perimeter' => 'Unfilled hollow plastic channel',
    'swing_weight'   => '100 Baseline standard',
    'swing_rating'   => 'Medium Abrasive grip',
];

 $dbAvailable = false;
 $conn = @mysqli_connect('localhost', 'root', '', 'kamito_db');

if ($conn) {
    $dbAvailable = true;

    try {
        // mysqli throws exceptions on error by default in modern PHP, so a
        // missing column (e.g. is_flagship not added to paddles yet)
        // throws here instead of crashing later — we catch it below and
        // just keep the static defaults defined above.
        $res = mysqli_query($conn, "SELECT * FROM paddles ORDER BY is_flagship DESC");

        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $target = !empty($row['is_flagship']) ? 'flagship' : 'conventional';
                $$target = [
                    'face_material'  => $row['face_material']  ?? $$target['face_material'],
                    'core'           => $row['core']           ?? $$target['core'],
                    'edge_perimeter' => $row['edge_perimeter'] ?? $$target['edge_perimeter'],
                    'swing_weight'   => $row['swing_weight']   ?? $$target['swing_weight'],
                    'swing_rating'   => $row['swing_rating']   ?? $$target['swing_rating'],
                ];
            }
        }
    } catch (\mysqli_sql_exception $e) {
        // Table exists but is missing one or more spec columns
        // (is_flagship, face_material, core, edge_perimeter,
        // swing_weight, swing_rating). Keep the static defaults and
        // flag it below instead of showing a fatal error.
        $dbAvailable = false;
    }

    mysqli_close($conn);
}

function e($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }

/* ============================================================
   NEWSLETTER — handled right here on this page (no 404 possible)
   ============================================================ */
 $nlStatus = null;   // 'ok' | 'error'
 $nlMsg    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newsletter_email'])) {
    $nl = strtolower(trim($_POST['newsletter_email']));
    $nlconn = @mysqli_connect('localhost', 'root', '', 'kamito_db');

    if (!$nlconn) {
        $nlStatus = 'error';
        $nlMsg    = 'Server offline — start XAMPP (Apache + MySQL) and try again.';
    } elseif (!filter_var($nl, FILTER_VALIDATE_EMAIL)) {
        $nlStatus = 'error';
        $nlMsg    = 'Please enter a valid email address.';
    } else {
        mysqli_query($nlconn, "
            CREATE TABLE IF NOT EXISTS newsletter_subscribers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(120) NOT NULL UNIQUE,
                subscribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");

        $stmt = mysqli_prepare($nlconn, "SELECT 1 FROM newsletter_subscribers WHERE email = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $nl);
        mysqli_stmt_execute($stmt);
        $already = mysqli_fetch_row(mysqli_stmt_get_result($stmt));

        if ($already) {
            $nlStatus = 'error';
            $nlMsg    = "You're already subscribed with that email.";
        } else {
            $stmt = mysqli_prepare($nlconn, "INSERT INTO newsletter_subscribers (email) VALUES (?)");
            mysqli_stmt_bind_param($stmt, 's', $nl);
            mysqli_stmt_execute($stmt);
            $nlStatus = 'ok';
            $nlMsg    = "✅ You're in! We'll keep you posted on drops and launches.";
        }
        mysqli_close($nlconn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KAMITO — Weapons of Every Athlete</title>
<meta name="description" content="Kamito Series J-PRO — engineered with Raw Toray T700 carbon fiber and a hyper-molded perimeter for spin, lethal speed, and uncompromising accuracy.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>

<!-- ============ NAV ============ -->
<header class="site-header" id="siteHeader">
  <div class="container nav-inner">

    <a href="#top" class="logo">
      <img src="img/logo.png" alt="KAMITO logo" class="logo-img">
      <span>KAMITO</span>
    </a>

    <nav class="main-nav" id="mainNav">
      <a href="#paddles">Paddles</a>
      <a href="#technology">Technology</a>
      <a href="#specs">Specs</a>
      <a href="#affiliates">Affiliates</a>
      <a href="#journal">Journal</a>
    </nav>

    <div class="nav-actions">
      <a href="#specs" class="btn btn-ghost btn-sm">Compare</a>
      <?php if (isset($_SESSION['user_id'])): ?>
        <a href="php/account.php" class="btn btn-ghost btn-sm">My account</a>
        <a href="php/logout.php" class="btn btn-ghost btn-sm">Sign out</a>
      <?php else: ?>
        <a href="php/login.php" class="btn btn-ghost btn-sm">Sign in</a>
      <?php endif; ?>
      <button class="btn btn-primary btn-sm" data-open-preorder>Shop Series J-PRO</button>
      <button class="nav-toggle" id="navToggle" aria-label="Toggle menu" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</header>

<main id="top">

  <!-- ============ HERO ============ -->
  <section class="hero" id="paddles">
    <div class="container hero-inner">
      <div class="hero-copy">
        <span class="eyebrow eyebrow-accent">Introducing the J-PRO</span>
        <h1 class="hero-title">Weapons of<br>every athletes</h1>
        <p class="hero-desc">
          Engineered with Raw Toray T700 Carbon Fiber and a hyper-molded
          perimeter, the Kamito Series J-PRO is built for spin, lethal speed,
          and uncompromising accuracy.
        </p>
        <div class="hero-cta">
         
          <a href="#technology" class="btn btn-outline">View Technology</a>
        </div>

        <dl class="hero-stats">
          <div class="hero-stat">
            <dt>16mm</dt>
            <dd>Core Thickness</dd>
          </div>
          <div class="hero-stat">
            <dt>8.0oz</dt>
            <dd>Average Weight</dd>
          </div>
          <div class="hero-stat">
            <dt>5.5in</dt>
            <dd>Grip Length</dd>
          </div>
        </dl>
      </div>


<div class="hero-visual">
  <img src="img/hero.png" alt="Kamito Series J-PRO pickleball paddle" class="hero-image">
  <div class="hero-glow"></div>
</div>


  <!-- ============ STAT STRIP ============ -->
  <section class="stat-strip">
    <div class="container stat-strip-inner">
      <div class="stat-block">
        <span class="eyebrow">Spin Potential</span>
        <p class="stat-number"><span class="counter" data-count="2400">0</span><span class="stat-unit">RPM</span></p>
      </div>
      <div class="stat-block">
        <span class="eyebrow">Sweet Spot</span>
        <p class="stat-number">+<span class="counter" data-count="30">0</span><span class="stat-unit">% AREA</span></p>
      </div>
      <div class="stat-block">
        <span class="eyebrow">Core Deflection</span>
        <p class="stat-number"><span class="counter" data-count="0.14" data-decimals="2">0.00</span><span class="stat-unit">MM</span></p>
      </div>
    </div>
  </section>

  <!-- ============ MATERIAL SCIENCE ============ -->
<section class="section material-science" id="technology">
  <div class="container">
    <span class="eyebrow eyebrow-accent">Material Science</span>
    <h2 class="section-title">Built from the molecule up</h2>
    <p class="section-desc">
      We do not source off-the-shelf blanks. Every component of Kamito is
      custom engineered for elite tournament play.
    </p>


        <div class="material-grid">
      <article class="material-card">
        <div class="material-swatch"><img src="img/carbon.jpg" alt="Raw Toray T700 carbon fiber weave"></div>
        <h3>Toray T700 Raw Carbon</h3>
        <p>The gold standard of tennis-level spin. Our raw carbon face features advanced texturing that grips the ball for uncompromised contact.</p>
      </article>
      <article class="material-card">
        <div class="material-swatch"><img src="img/core.jpg" alt="Dampened polypropylene honeycomb core"></div>
        <h3>Dampened Poly Core</h3>
        <p>A custom-density propylene honeycomb core is tuned for maximum dampening and additional control.</p>
      </article>
      <article class="material-card">
        <div class="material-swatch"><img src="img/throat.jpg" alt="Thermoformed flex throat detail"></div>
        <h3>Thermoformed Flex Throat</h3>
        <p>A singular solid-edge design and torsion-molded throat flex just enough to release dynamic pop on strikes while maintaining rigid block response.</p>
      </article>
    </div>
  </div>
</section>
  <!-- ============ COMPARISON TABLE ============ -->
  <section class="section comparison" id="specs">
    <div class="container">
      <span class="eyebrow eyebrow-accent">Side-by-Side Comparison</span>
      <h2 class="section-title">Outperforming the standard</h2>
      <p class="section-desc">
        Rival paddles rely on generic cosmetic coatings. Kamito uses
        state-of-the-art thermoforming that makes standard paddles obsolete.
      </p>

      <div class="compare-table-wrap">
        <table class="compare-table">
          <thead>
            <tr>
              <th scope="col">Specification</th>
              <th scope="col" class="col-kamito">Kamito Series J-PRO</th>
              <th scope="col">Conventional Paddle</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <th scope="row">Face Material</th>
              <td class="col-kamito"><span class="check">✓</span> <?= e($flagship['face_material']) ?></td>
              <td><?= e($conventional['face_material']) ?></td>
            </tr>
            <tr>
              <th scope="row">Core Construction</th>
              <td class="col-kamito"><span class="check">✓</span> <?= e($flagship['core']) ?></td>
              <td><?= e($conventional['core']) ?></td>
            </tr>
            <tr>
              <th scope="row">Edge Perimeter</th>
              <td class="col-kamito"><span class="check">✓</span> <?= e($flagship['edge_perimeter']) ?></td>
              <td><?= e($conventional['edge_perimeter']) ?></td>
            </tr>
            <tr>
              <th scope="row">Swing Weight</th>
              <td class="col-kamito"><span class="check">✓</span> <?= e($flagship['swing_weight']) ?></td>
              <td><?= e($conventional['swing_weight']) ?></td>
            </tr>
            <tr>
              <th scope="row">Swell Time Rating</th>
              <td class="col-kamito"><span class="check">✓</span> <?= e($flagship['swing_rating']) ?></td>
              <td><?= e($conventional['swing_rating']) ?></td>
            </tr>
          </tbody>
        </table>
      </div>
      <?php if (!$dbAvailable): ?>
        <p class="db-note">Showing default specs — connect XAMPP/MySQL and import <code>database/kamito_db.sql</code> to serve this table live from the database.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- ============ ANATOMY OF ADVANTAGE ============ -->
  <section class="section anatomy">
    <div class="container anatomy-inner">

      <div class="anatomy-visual">
        <img src="img/anatomy.jpg" alt="Cutaway view of the Kamito paddle internal structure" class="anatomy-image">
      </div>

      <div class="anatomy-copy">
        <span class="eyebrow eyebrow-accent">Engineering Rigor</span>
        <h2 class="section-title">The anatomy of advantage</h2>
        <p class="section-desc">Every swing requires milliseconds of exact calculation. Your equipment should not be variable.</p>

        <ol class="anatomy-steps">
          <li>
            <span class="step-index">01</span>
            <div>
              <h3>Perimeter Weighted Foam-Injection</h3>
              <p>We inject specialized foam around the entire outer edge to instantly improve vibration dampening while offering a dense, predictable rebound across the entire face.</p>
            </div>
          </li>
          <li>
            <span class="step-index">02</span>
            <div>
              <h3>3D Molded Solid-Grid Grip</h3>
              <p>Our grip is a direct extension of the inner throat, molded to precision. Deadening vibration during off-string ratings without losing feel.</p>
            </div>
          </li>
        </ol>
      </div>
    </div>
  </section>

  <!-- ============ TESTIMONIALS ============ -->
  <section class="section testimonials" id="affiliates">
    <div class="container">
      <span class="eyebrow eyebrow-accent">Approved on Center Court</span>
      <h2 class="section-title">The athlete's verdict</h2>
      <p class="section-desc">Kamito is trusted by professional players who require elite consistency to win under pressure.</p>

      <div class="testimonial-grid" id="testimonialGrid">
        <article class="testimonial-card">
          <img src="img/avatar1.jpg" alt="Athlete portrait" class="avatar-img">
          <p class="quote">"The hand speed I get at the kitchen line with the J-1 is absolutely unmatched. Blasting third-shot drives never felt so light."</p>
          <p class="athlete"><span class="athlete-name">Ben Johns Thua</span><span class="athlete-title">PPA Touring Pro</span></p>
        </article>
        <article class="testimonial-card">
          <img src="img/avatar2.jpg" alt="Athlete portrait" class="avatar-img">
          <p class="quote">"I can shape spin on drives and dinks that standard carbon paddles simply cannot generate. It completely changed my offensive game."</p>
          <p class="athlete"><span class="athlete-name">Anniileigh Waters</span><span class="athlete-title">Professional Doubles Champion</span></p>
        </article>
      </div>
    </div>
  </section>

  <!-- ============ CTA ============ -->
  <section class="section cta-section" id="journal">
    <div class="container cta-inner">
      <span class="eyebrow eyebrow-accent">Limited First Release</span>
      <h2 class="cta-title">Be first on the court</h2>
      <p class="cta-desc">The first production run of the Series J-PRO is limited. Pre-order now to lock in yours before the drop.</p>
      <div class="hero-cta" style="justify-content:center">
        <button class="btn btn-primary" data-open-preorder>Pre-Order Now</button>
        <a href="#specs" class="btn btn-outline">Compare Specs</a>
      </div>
    </div>
  </section>

</main>

<!-- ============ FOOTER ============ -->
<footer class="site-footer">
  <div class="container">
    <div class="footer-top">
      <div class="footer-brand">
        <a href="#top" class="logo">
          <img src="img/logo.png" alt="KAMITO logo" class="logo-img">
          <span>KAMITO</span>
        </a>
        <p>Engineered for elite tournament play. Spin, speed, and accuracy — built from the molecule up.</p>
        <form method="post" class="newsletter-form">
          <input type="email" name="newsletter_email" placeholder="Email address" aria-label="Email address" required>
          <button type="submit" class="btn btn-primary btn-sm">Join</button>
        </form>
        <?php if ($nlStatus !== null): ?>
          <p class="form-message" role="status"
             style="color:<?= $nlStatus === 'ok' ? '#c9f24b' : '#f87171' ?>; margin-top:10px;">
            <?= htmlspecialchars($nlMsg) ?>
          </p>
        <?php endif; ?>
      </div>
      <div class="footer-col">
        <h4>Product</h4>
        <ul>
          <li><a href="#paddles">Paddles</a></li>
          <li><a href="#technology">Technology</a></li>
          <li><a href="#specs">Specs</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Company</h4>
        <ul>
          <li><a href="#affiliates">Affiliates</a></li>
          <li><a href="#journal">Journal</a></li>
          <li><a href="php/login.php">Sign in</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Legal</h4>
        <ul>
          <li><a href="#">Privacy Policy</a></li>
          <li><a href="#">Terms of Service</a></li>
          <li><a href="#">Warranty</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© <?= date('Y') ?> KAMITO. All rights reserved.</span>
      <div class="footer-legal">
        <a href="#">Privacy</a>
        <a href="#">Terms</a>
      </div>
    </div>
  </div>
  <div class="footer-watermark">KAMITO</div>
</footer>

<!-- ============ PRE-ORDER MODAL ============ -->
<div class="modal-overlay" id="preorderOverlay" role="dialog" aria-modal="true" aria-labelledby="preorderTitle">
  <div class="modal">
    <button class="modal-close" id="preorderClose" aria-label="Close">✕</button>
    <span class="eyebrow eyebrow-accent">Pre-Order</span>
    <h3 id="preorderTitle">Kamito Series J-PRO</h3>
    <p class="modal-sub">$289.00 — reserve yours today. No charge until we ship.</p>

    <form id="preorderForm" novalidate>
      <input type="hidden" id="preorderCsrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
      <input type="hidden" id="preorderPaddleId" value="1">
      <div class="form-row">
        <label for="fullName">Full name</label>
        <input type="text" id="fullName" autocomplete="name" placeholder="Alex Rivera">
      </div>
      <div class="form-row">
        <label for="email">Email</label>
        <input type="email" id="email" autocomplete="email" placeholder="you@example.com">
      </div>
      <div class="form-row">
        <label for="phone">Phone (optional)</label>
        <input type="tel" id="phone" autocomplete="tel" placeholder="+1 555 000 0000">
      </div>
      <div class="form-row two-col">
        <div>
          <label for="gripSize">Grip size</label>
          <select id="gripSize">
            <option value='4 1/8"'>4 1/8"</option>
            <option value='4 1/4"' selected>4 1/4"</option>
            <option value='4 1/2"'>4 1/2"</option>
          </select>
        </div>
        <div>
          <label for="quantity">Quantity</label>
          <select id="quantity">
            <option value="1" selected>1</option>
            <option value="2">2</option>
            <option value="3">3</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <label for="notes">Notes (optional)</label>
        <textarea id="notes" rows="3" placeholder="Anything we should know?"></textarea>
      </div>
      <div class="form-row">
        <button type="submit" class="btn btn-primary btn-block" id="preorderSubmit">Confirm Pre-Order</button>
      </div>
      <p class="form-message" id="preorderMessage" role="status"></p>
    </form>
  </div>
</div>

<script src="js/script.js"></script>
</body>
</html>