<?php
// ============================================================
// stock_setup.php — complete file
// Admin config, auto schema (incl. address + payment), 60s
// shipping timer, notifications, admin check.
// Safe to include on every page load.
// ============================================================

// ====== ADMIN EMAILS — put your admin account's Gmail here ======
if (!defined('ADMIN_EMAILS')) {
    define('ADMIN_EMAILS', ['yourgmail@gmail.com']);
}

// ------------------------------------------------------------
// AUTO SCHEMA
// ------------------------------------------------------------
if (!function_exists('ensureStockSchema')) {
function ensureStockSchema(mysqli $conn): void {

    // products table — created + seeded ONLY the first time
    $tableExists = $conn->query("
        SELECT COUNT(*) AS c FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
    ")->fetch_assoc()['c'];

    if (!$tableExists) {
        $conn->query("
            CREATE TABLE products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                description VARCHAR(255) NOT NULL DEFAULT '',
                price DECIMAL(10,2) NOT NULL DEFAULT 0,
                image VARCHAR(255) DEFAULT NULL,
                stock INT NOT NULL DEFAULT 0
            )
        ");
    }

    $count = $conn->query("SELECT COUNT(*) AS c FROM products")->fetch_assoc()['c'];
    if ($count == 0) {
        $conn->query("
            INSERT INTO products (name, description, price, stock) VALUES
            ('OX Pro Control', 'Precision first. Weapons of every athlete.', 249.00, 25),
            ('OX Pro Power',   'Full swing, full send.',                     249.00, 25),
            ('OX Edge',        'The everyday contender.',                    199.00, 25),
            ('OX Apex',        'Tour-level pop at feather weight.',          279.00, 25)
        ");
    }

    // products.stock column
    $exists = $conn->query("
        SELECT COUNT(*) AS c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'stock'
    ")->fetch_assoc()['c'];
    if (!$exists) {
        $conn->query("ALTER TABLE products ADD COLUMN stock INT NOT NULL DEFAULT 0");
        $conn->query("UPDATE products SET stock = 25");
    }

    // pre_orders table (fresh installs get everything)
    $conn->query("
        CREATE TABLE IF NOT EXISTS pre_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            customer_name VARCHAR(100) NOT NULL,
            email VARCHAR(120) DEFAULT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            payment_method VARCHAR(30) DEFAULT NULL,
            quantity INT NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            shipped_at DATETIME NULL,
            ship_requested_at DATETIME NULL
        )
    ");

    // older pre_orders — add missing columns
    $cols = [
        'email'            => 'VARCHAR(120) DEFAULT NULL',
        'phone'            => 'VARCHAR(30) DEFAULT NULL',
        'ship_requested_at'=> 'DATETIME NULL',
        'address'          => 'TEXT DEFAULT NULL',
        'payment_method'   => 'VARCHAR(30) DEFAULT NULL',
    ];
    foreach ($cols as $col => $def) {
        $exists = $conn->query("
            SELECT COUNT(*) AS c FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pre_orders' AND COLUMN_NAME = '$col'
        ")->fetch_assoc()['c'];
        if (!$exists) $conn->query("ALTER TABLE pre_orders ADD COLUMN $col $def");
    }
}
}

// ------------------------------------------------------------
// NOTIFICATIONS TABLE
// ------------------------------------------------------------
if (!function_exists('ensureNotificationsTable')) {
function ensureNotificationsTable(mysqli $conn): void {
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
}
}

// ------------------------------------------------------------
// SHIPPING TIMER ENGINE — completes orders whose 60s elapsed
// ------------------------------------------------------------
if (!function_exists('processShippings')) {
function processShippings(mysqli $conn, int $waitSeconds = 60): void {
    ensureNotificationsTable($conn);

    $due = $conn->query("
        SELECT id, email FROM pre_orders
        WHERE status = 'shipping'
          AND ship_requested_at IS NOT NULL
          AND ship_requested_at <= NOW() - INTERVAL $waitSeconds SECOND
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($due as $o) {
        $stmt = $conn->prepare("UPDATE pre_orders SET status = 'shipped', shipped_at = NOW()
                                WHERE id = ? AND status = 'shipping'");
        $stmt->bind_param('i', $o['id']);
        $stmt->execute();

        if ($stmt->affected_rows > 0 && !empty($o['email'])) {
            $note = "Good news! Your paddle (Order #{$o['id']}) has been shipped and is on the way. 🚚";
            $stmt = $conn->prepare("INSERT INTO notifications (user_email, order_id, type, message)
                                    VALUES (?, ?, 'shipped', ?)");
            $stmt->bind_param('sis', $o['email'], $o['id'], $note);
            $stmt->execute();
        }
    }
}
}

// ------------------------------------------------------------
// ADMIN CHECK
// ------------------------------------------------------------
if (!function_exists('isAdminUser')) {
function isAdminUser(mysqli $conn): bool {
    $adminValues = ['1', 'true', 'yes', 'admin', 'administrator'];

    foreach (['is_admin', 'admin', 'role', 'user_role', 'user_type'] as $k) {
        if (isset($_SESSION[$k])) {
            $v = strtolower(trim((string)$_SESSION[$k]));
            if (in_array($v, $adminValues, true)) return true;
        }
    }

    $sessionEmail = null;
    foreach (['email', 'gmail', 'user_email'] as $k) {
        if (!empty($_SESSION[$k])) {
            $sessionEmail = strtolower(trim((string)$_SESSION[$k]));
            break;
        }
    }
    if ($sessionEmail && defined('ADMIN_EMAILS') && in_array($sessionEmail, ADMIN_EMAILS, true)) {
        return true;
    }

    $userId = null;
    foreach (['user_id', 'id', 'userID', 'uid', 'member_id'] as $k) {
        if (!empty($_SESSION[$k]) && is_numeric($_SESSION[$k])) { $userId = (int)$_SESSION[$k]; break; }
    }
    if ($userId) {
        foreach (['users', 'user', 'accounts', 'account'] as $t) {
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES
                                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
                $stmt->bind_param('s', $t);
                $stmt->execute();
                if (!$stmt->get_result()->fetch_assoc()['c']) continue;

                foreach (['id', 'user_id', 'userID', 'uid'] as $idc) {
                    try {
                        $stmt = $conn->prepare("SELECT * FROM `$t` WHERE `$idc` = ? LIMIT 1");
                        $stmt->bind_param('i', $userId);
                        $stmt->execute();
                        $user = $stmt->get_result()->fetch_assoc();
                        if (!$user) continue;

                        foreach (['role', 'is_admin', 'admin', 'user_type', 'type', 'level'] as $rc) {
                            if (array_key_exists($rc, $user)) {
                                $v = strtolower(trim((string)$user[$rc]));
                                if (in_array($v, $adminValues, true)) return true;
                            }
                        }
                        foreach (['email', 'gmail', 'email_address', 'user_email'] as $ec) {
                            if (!empty($user[$ec])
                                && defined('ADMIN_EMAILS')
                                && in_array(strtolower(trim($user[$ec])), ADMIN_EMAILS, true)) {
                                return true;
                            }
                        }
                        return false;
                    } catch (mysqli_sql_exception $e) {
                        continue;
                    }
                }
            } catch (mysqli_sql_exception $e) {
                continue;
            }
        }
    }
    return false;
}
}