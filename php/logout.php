<?php
/**
 * KAMITO — Logout
 * Accepts POST (sign-out form on account.php) or GET (plain link).
 * Always kills the session, then sends the user to the homepage.
 *
 * NOTE: this file sits inside the php/ folder, so the redirect uses ../
 * to climb one level up to index.php at the site root.
 */

session_start();

// Wipe session data
 $_SESSION = [];

// Expire the session cookie
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();

// ../ = one folder up, out of php/ and into the site root
header('Location: ../index.php');
exit;