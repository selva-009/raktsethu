<?php
/**
 * verify_email.php — Activates an account when the user clicks the link
 * in their verification email.
 *
 * The token in the URL is validated against the database. If it matches
 * and is less than 24 hours old, the account is marked as emailVerified = 1
 * and the user is redirected to login.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$token = $_GET['token'] ?? '';

if ($token === '') {
    setFlash('error', 'Invalid verification link. No token provided.');
    redirect('/thalassemia/auth/login.php');
}

// Look up the token in the database
$stmt = $pdo->prepare(
    'SELECT userId, email, verificationTokenAt FROM users
     WHERE verificationToken = ? AND emailVerified = 0'
);
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    // Either token is wrong, already verified, or doesn't exist
    setFlash('error', 'This verification link is invalid or has already been used.');
    redirect('/thalassemia/auth/login.php');
}

// Check if the token has expired (24-hour window)
if ($user['verificationTokenAt']) {
    $tokenAge = time() - strtotime($user['verificationTokenAt']);
    if ($tokenAge > 86400) { // 24 hours
        // Clear the expired token so they'd need a fresh registration
        $pdo->prepare('UPDATE users SET verificationToken = NULL, verificationTokenAt = NULL WHERE userId = ?')
            ->execute([$user['userId']]);
        setFlash('error', 'Your verification link has expired (older than 24 hours). Please register again.');
        redirect('/thalassemia/auth/register.php');
    }
}

// Activate the account
$pdo->prepare('UPDATE users SET emailVerified = 1, verificationToken = NULL, verificationTokenAt = NULL WHERE userId = ?')
    ->execute([$user['userId']]);

// Clear the pending session if present
unset($_SESSION['pending_verification']);

setFlash('success', 'Email verified! Your account is now active. Please log in.');
redirect('/thalassemia/auth/login.php');
