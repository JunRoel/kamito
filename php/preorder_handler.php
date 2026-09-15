<?php
/**
 * KAMITO — Pre-order handler (FINAL)
 * Always returns HTTP 200 + JSON, so the frontend always shows the real message.
 * ✅ Registered-user check ("No account found...")
 * ✅ Duplicate blocker
 * ✅ CSRF optional
 */

session_start();
header('Content-Type: application/json');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' =>
            'PHP error: ' . $e['message'] . ' (line ' . $e['line'] . ')']);
    }
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Handler is alive. (File loads OK.)']);
    exit;
}

 $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

/* ---- CSRF: reject ONLY if a token was sent and it is WRONG ---- */
if (!empty($data['csrf']) && !empty($_SESSION['csrf'])
    && !hash_equals($_SESSION['csrf'], (string)$data['csrf'])) {
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please reload the page (Ctrl+F5).']);
    exit;
}

 $paddleId = (int)($data['paddle_id'] ?? 0);
 $fullName = trim((string)($data['full_name'] ?? ''));
 $email    = trim((string)($data['email'] ?? ''));
 $phone    = trim((string)($data['phone'] ?? ''));
 $gripSize = trim((string)($data['grip_size'] ?? ''));
 $quantity = (int)($data['quantity'] ?? 0);
 $notes    = trim((string)($data['notes'] ?? ''));

/* ---- Validation ---- */
 $errors = [];
if ($paddleId <= 0)                             $errors[] = 'Please choose a paddle.';
if (mb_strlen($fullName) < 2)                   $errors[] = 'Please enter your full name.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
if ($quantity < 1 || $quantity > 10)            $errors[] = 'Quantity must be between 1 and 10.';
if ($errors) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

require_once __DIR__ . '/db_connect.php';
 $conn = get_db_connection();

mysqli_query($conn,
    "CREATE TABLE IF NOT EXISTS preorders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        paddle_id INT DEFAULT 1,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL,
        phone VARCHAR(30),
        grip_size VARCHAR(20),
        quantity INT DEFAULT 1,
        notes TEXT,
        status ENUM('pending','confirmed','shipped','cancelled') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ---- ✅ Must be a REGISTERED & APPROVED user ---- */
 $reg = mysqli_prepare($conn,
    "SELECT id, status FROM users
     WHERE LOWER(TRIM(full_name)) = LOWER(?) AND LOWER(TRIM(email)) = LOWER(?)
     LIMIT 1");
mysqli_stmt_bind_param($reg, 'ss', $fullName, $email);
mysqli_stmt_execute($reg);
 $regUser = mysqli_fetch_assoc(mysqli_stmt_get_result($reg));
mysqli_stmt_close($reg);

if (!$regUser) {
    echo json_encode(['success' => false,
        'message' => 'No account found with this name and email. Please register and wait for approval before pre-ordering.']);
    mysqli_close($conn);
    exit;
}
if (($regUser['status'] ?? '') !== 'approved') {
    echo json_encode(['success' => false,
        'message' => 'Your account is not approved yet. Please wait for admin approval before pre-ordering.']);
    mysqli_close($conn);
    exit;
}

/* ---- Paddle must exist ---- */
 $check = mysqli_prepare($conn, 'SELECT id FROM paddles WHERE id = ? LIMIT 1');
if ($check) {
    mysqli_stmt_bind_param($check, 'i', $paddleId);
    mysqli_stmt_execute($check);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($check))) {
        echo json_encode(['success' => false, 'message' => 'That paddle could not be found.']);
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_close($check);
}

/* ---- Duplicate blocker: one ACTIVE order per name + email ---- */
 $dup = mysqli_prepare($conn,
    "SELECT id FROM preorders
     WHERE LOWER(TRIM(full_name)) = LOWER(?) AND LOWER(TRIM(email)) = LOWER(?)
       AND status IN ('pending','confirmed')
     LIMIT 1");
mysqli_stmt_bind_param($dup, 'ss', $fullName, $email);
mysqli_stmt_execute($dup);
if (mysqli_fetch_assoc(mysqli_stmt_get_result($dup))) {
    echo json_encode(['success' => false,
        'message' => 'You already have an active pre-order with this name and email. Only one pre-order per customer is allowed.']);
    mysqli_stmt_close($dup);
    mysqli_close($conn);
    exit;
}
mysqli_stmt_close($dup);

/* ---- Insert ---- */
 $stmt = mysqli_prepare($conn,
    "INSERT INTO preorders (paddle_id, full_name, email, phone, grip_size, quantity, notes, status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");

if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'issssis', $paddleId, $fullName, $email, $phone, $gripSize, $quantity, $notes);
} else {
    $stmt = mysqli_prepare($conn,
        "INSERT INTO preorders (paddle_id, full_name, email, phone, grip_size, quantity, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
    if ($stmt) mysqli_stmt_bind_param($stmt, 'issssi', $paddleId, $fullName, $email, $phone, $gripSize, $quantity);
}

if ($stmt && mysqli_stmt_execute($stmt)) {
    echo json_encode(['success' => true, 'message' => "Pre-order placed! We'll follow up by email to confirm."]);
} else {
    echo json_encode(['success' => false,
        'message' => 'Could not save your order: ' . ($stmt ? mysqli_stmt_error($stmt) : mysqli_error($conn))]);
}
if ($stmt) mysqli_stmt_close($stmt);
mysqli_close($conn);