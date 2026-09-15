<?php
/**
 * KAMITO — Registration handler
 * JSON POST { full_name, email, password, confirm_password, csrf }
 * New accounts are created with status = 'pending' and must be approved
 * by an admin (see admin.php / admin_update.php) before they can sign in.
 */

header('Content-Type: application/json');
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

 $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($data['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please reload the page.']);
    exit;
}

 $full_name = trim($data['full_name'] ?? '');
 $email     = strtolower(trim($data['email'] ?? ''));
 $password  = (string)($data['password'] ?? '');
 $confirm   = (string)($data['confirm_password'] ?? '');

 $errors = [];
if (mb_strlen($full_name) < 2)                    $errors[] = 'Please enter your full name.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))   $errors[] = 'Please enter a valid email address.';
if (strlen($password) < 8)                        $errors[] = 'Password must be at least 8 characters.';
if ($password !== $confirm)                       $errors[] = 'Passwords do not match.';

if ($errors) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

require_once __DIR__ . '/db_connect.php';
 $conn = get_db_connection();

// duplicate account?
 $stmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE email = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'An account with this email already exists. Try signing in instead.']);
    exit;
}

 $hash = password_hash($password, PASSWORD_DEFAULT); // bcrypt — never store plaintext

// New accounts start as 'pending' — an admin must approve before they can sign in.
 $stmt = mysqli_prepare(
    $conn,
    "INSERT INTO users (full_name, email, password_hash, status) VALUES (?, ?, ?, 'pending')"
);
mysqli_stmt_bind_param($stmt, 'sss', $full_name, $email, $hash);

if (mysqli_stmt_execute($stmt)) {
    // No auto-login — the account needs admin approval first.
    echo json_encode([
        'success'  => true,
        'message'  => 'Account created. An admin needs to approve your account before you can sign in — we\'ll be quick.',
        'redirect' => null,
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not create your account. Please try again.']);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
