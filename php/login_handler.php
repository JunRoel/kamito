<?php
/**
 * KAMITO — Login handler
 * JSON POST { email, password, csrf } -> { success, message, redirect }
 * Admins are redirected to ../admin/admin.php, users to account.php.
 * Accounts with status 'pending' or 'rejected' cannot sign in.
 */

header('Content-Type: application/json');
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

 $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// CSRF check
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($data['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please reload the page.']);
    exit;
}

 $email    = strtolower(trim($data['email'] ?? ''));
 $password = (string)($data['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email and password.']);
    exit;
}

require_once __DIR__ . '/db_connect.php';
 $conn = get_db_connection();

 $stmt = mysqli_prepare(
    $conn,
    'SELECT id, full_name, password_hash, role, status, failed_attempts,
            TIMESTAMPDIFF(MINUTE, NOW(), locked_until) AS lock_minutes
     FROM users WHERE email = ? LIMIT 1'
);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn) . ' — did you add the status column (see add_user_status_column.sql)?']);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
 $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Account locked?
if ($user && (int)($user['lock_minutes'] ?? 0) > 0) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Too many failed attempts. This account is locked for '
                     . max(1, (int)$user['lock_minutes']) . ' more minute(s).',
    ]);
    exit;
}

if ($user && password_verify($password, $user['password_hash'])) {

    // Correct credentials — but is the account approved yet?
    if (($user['status'] ?? 'approved') === 'pending') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Your account is awaiting admin approval. Please check back soon.',
        ]);
        mysqli_close($conn);
        exit;
    }
    if (($user['status'] ?? 'approved') === 'rejected') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Your account request was declined. Contact support if you think this is a mistake.',
        ]);
        mysqli_close($conn);
        exit;
    }

    // Approved — reset attempts, record login
    $updateStmt = mysqli_prepare($conn, 'UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?');
    if ($updateStmt === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_bind_param($updateStmt, 'i', $user['id']);
    mysqli_stmt_execute($updateStmt);

    session_regenerate_id(true);

    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['user_name']  = $user['full_name'];
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role']  = $user['role'];

    // ✅ UPDATED: admins now go to the new /admin/ folder location
    $redirect = ($user['role'] === 'admin') ? '../admin/admin.php' : 'account.php';
    echo json_encode(['success' => true, 'message' => 'Welcome back.', 'redirect' => $redirect]);
    mysqli_close($conn);
    exit;
}

// Failure — generic message, count the attempt
if ($user) {
    $failed = (int)$user['failed_attempts'] + 1;
    if ($failed >= 5) {
        $failStmt = mysqli_prepare($conn, 'UPDATE users SET failed_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?');
    } else {
        $failStmt = mysqli_prepare($conn, 'UPDATE users SET failed_attempts = ? WHERE id = ?');
    }

    if ($failStmt !== false) {
        $userId = (int)$user['id'];
        mysqli_stmt_bind_param($failStmt, 'ii', $failed, $userId);
        mysqli_stmt_execute($failStmt);
    }
    // If prepare failed here, we still fall through to the generic
    // "Invalid email or password" response below rather than crashing —
    // the failed-attempt counter just won't increment that one time.
}

http_response_code(401);
echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
mysqli_close($conn);