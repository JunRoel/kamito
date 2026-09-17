<?php
session_start();
 $conn = new mysqli("localhost", "root", "", "kamito_db");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

require_once 'stock_setup.php';
ensureStockSchema($conn);

// ==========================================================
// PRODUCT IMAGES — type the exact filename from kamito/img/
// Leave '' to auto-detect (matches product name in filename)
// ==========================================================
 $IMAGE_MAP = [
    'OX Pro Control' => '',   // e.g. 'alpha-2.jpg'
    'OX Pro Power'   => '',
    'OX Edge'        => '',
    'OX Apex'        => '',
];

// ---------- logo detection ----------
 $imgDir = dirname(__DIR__) . '/img';
 $logo = null;
foreach (glob($imgDir . '/*') ?: [] as $f) {
    if (stripos(basename($f), 'logo') !== false) {
        $logo = '../img/' . rawurlencode(basename($f));
        break;
    }
}

// ---------- auto-detect product image by name ----------
function findProductImage(string $productName, string $imgDir): ?string {
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($productName)), '-');
    foreach (glob($imgDir . '/*') ?: [] as $f) {
        $file = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo($f, PATHINFO_FILENAME))), '-');
        if ($file !== '' && (strpos($file, $slug) !== false || strpos($slug, $file) !== false)) {
            return '../img/' . rawurlencode(basename($f));
        }
    }
    return null;
}

// ---------- load products ----------
 $hasImage = false;
 $res = $conn->query("SHOW COLUMNS FROM products");
while ($r = $res->fetch_assoc()) if ($r['Field'] === 'image') $hasImage = true;

 $sql = "SELECT id, name, description, price, stock" . ($hasImage ? ", image" : "") . " FROM products ORDER BY id";
 $products = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Paddles — KAMITO</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', Arial, sans-serif; }
  body { background:#0a0a0a; color:#fff; min-height:100vh; }
  nav { display:flex; align-items:center; gap:28px; padding:20px 6%; }
  .brand { display:flex; align-items:center; gap:10px; text-decoration:none; }
  .brand img { height:36px; width:auto; display:block; }
  .logo-text { font-weight:800; letter-spacing:2px; color:#fff; }
  nav a { color:#cfcfcf; text-decoration:none; font-size:15px; }
  nav a:hover { color:#fff; }
  nav .right { margin-left:auto; display:flex; gap:22px; }
  main { padding:60px 6%; }
  .pill { display:inline-block; background:#c9f24b; color:#111; font-weight:700; font-size:14px;
          padding:8px 18px; border-radius:999px; margin-bottom:18px; }
  h1 { font-size:52px; margin-bottom:40px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px,1fr)); gap:24px; }
  .card { background:#151515; border:1px solid #232323; border-radius:18px; padding:28px;
          display:flex; flex-direction:column; }
  .paddle-img { width:100%; max-width:260px; height:200px; margin:0 auto 22px; display:block;
                object-fit:contain; }
  .card h2 { font-size:22px; margin-bottom:8px; }
  .card p { color:#9a9a9a; font-size:15px; margin-bottom:16px; }
  .grips { display:flex; gap:8px; margin-bottom:20px; }
  .grip { background:#1f1f1f; border:1px solid #2e2e2e; color:#bbb; font-size:13px;
          padding:5px 12px; border-radius:999px; }
  .stock-badge { display:inline-block; padding:5px 14px; border-radius:999px;
                 font-size:13px; font-weight:600; margin-bottom:16px; align-self:flex-start; }
  .in-stock     { background:rgba(200,255,80,.12); color:#c9f24b; border:1px solid rgba(200,255,80,.35); }
  .low-stock    { background:rgba(255,180,0,.12);  color:#ffb400; border:1px solid rgba(255,180,0,.35); }
  .out-of-stock { background:rgba(255,70,70,.12);  color:#ff6b6b; border:1px solid rgba(255,70,70,.35); }
  .price { font-size:26px; font-weight:800; margin-bottom:18px; }
  .btn { display:block; text-align:center; background:#c9f24b; color:#111; font-weight:700;
         padding:13px; border-radius:12px; text-decoration:none; margin-top:auto; }
  .btn:hover { filter:brightness(1.08); }
  .btn.disabled { background:#2a2a2a; color:#777; pointer-events:none; }
</style>
</head>
<body>

<nav>
  <a class="brand" href="../index.php">
    <?php if ($logo): ?><img src="<?= $logo ?>" alt="KAMITO logo"><?php endif; ?>
    <span class="logo-text">KAMITO</span>
  </a>
    <a href="../index.php">Home</a>
  <a href="products.php">Paddles</a>
  <a href="../index.php#technology">Technology</a>
  <a href="../index.php#specs">Specs</a>
  <a href="../index.php#affiliates">Affiliates</a>
  <div class="right">
    <?php if (isAdminUser($conn)): ?><a href="admin_orders.php">Admin orders</a><?php endif; ?>
    <a href="account.php">My account</a>
    <a href="logout.php">Sign out</a>
  </div>
</nav>

<main>
  <span class="pill">The Lineup</span>
  <h1>Paddles</h1>

  <div class="grid">
    <?php foreach ($products as $p):
        $stock = (int)$p['stock'];

        // 1) map wins, 2) DB image column, 3) auto-detect by name
        $img = trim($IMAGE_MAP[$p['name']] ?? '') ?: ($p['image'] ?? null);
        if ($img && !str_starts_with($img, '../')) $img = '../img/' . rawurlencode($img);
        if (!$img) $img = findProductImage($p['name'], $imgDir);
    ?>
      <div class="card">
        <?php if ($img): ?>
          <img class="paddle-img" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
        <?php else: ?>
          <svg class="paddle-img" viewBox="0 0 100 120"><path d="M50 5c25 0 40 16 40 38 0 18-12 30-25 34v20h-8v-8h-14v8h-8V77C22 73 10 61 10 43 10 21 25 5 50 5z" fill="#2a2a2a"/></svg>
        <?php endif; ?>

        <h2><?= htmlspecialchars($p['name']) ?></h2>
        <p><?= htmlspecialchars($p['description']) ?></p>

        <div class="grips"><span class="grip">4 in</span><span class="grip">4.125 in</span><span class="grip">4.25 in</span></div>

        <?php if ($stock <= 0): ?>
          <span class="stock-badge out-of-stock">Out of stock</span>
        <?php elseif ($stock <= 5): ?>
          <span class="stock-badge low-stock">Only <?= $stock ?> left</span>
        <?php else: ?>
          <span class="stock-badge in-stock"><?= $stock ?> in stock</span>
        <?php endif; ?>

        <div class="price">$<?= number_format($p['price'], 2) ?></div>

        <?php if ($stock > 0): ?>
          <a class="btn" href="preorder.php?product_id=<?= $p['id'] ?>">Pre-order</a>
        <?php else: ?>
          <span class="btn disabled">Out of stock</span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</main>

</body>
</html>