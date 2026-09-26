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
    'SELECT userId, name, email, emailVerified, verificationTokenAt FROM users
     WHERE verificationToken = ?'
);
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    // Token is wrong or doesn't exist
    setFlash('error', 'This verification link is invalid.');
    redirect('/thalassemia/auth/login.php');
}

if ((int) $user['emailVerified'] === 1) {
    // Correct token but the account is already active
    setFlash('success', 'This email is already verified — please log in.');
    redirect('/thalassemia/auth/login.php');
}

// Check if the token has expired (24-hour window)
if ($user['verificationTokenAt']) {
    $tokenAge = time() - strtotime($user['verificationTokenAt']);
    if ($tokenAge > 86400) { // 24 hours
        // Link expired — issue a FRESH token instead of dead-ending the
        // user. (The old behaviour said "register again", which then fails
        // with "email already exists".) We store the new token in the
        // session and send them back to the send-email page.
        $newToken = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE users SET verificationToken = ?, verificationTokenAt = NOW() WHERE userId = ?')
            ->execute([$newToken, $user['userId']]);
        $_SESSION['pending_verification'] = [
            'userId' => (int) $user['userId'],
            'name'   => $user['name'],
            'email'  => $user['email'],
            'token'  => $newToken,
        ];
        setFlash('error', 'Your verification link had expired, so we made a fresh one. Press "Send Verification Email" and check your inbox.');
        redirect('/thalassemia/auth/verify_notice.php');
    }
}

// Activate the account
$pdo->prepare('UPDATE users SET emailVerified = 1, verificationToken = NULL, verificationTokenAt = NULL WHERE userId = ?')
    ->execute([$user['userId']]);

// Clear the pending session if present
unset($_SESSION['pending_verification']);

setFlash('success', 'Email verified! Your account is now active. Please log in.');
redirect('/thalassemia/auth/login.php');
