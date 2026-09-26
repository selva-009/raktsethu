<?php
/**
 * reset_password.php — Sets a new password when the user clicks the
 * reset link that was emailed to them (from reset_sent.php).
 *
 * Security model (same as verify_email.php):
 *   - the token was generated server-side (32 random bytes) and stored
 *     hashed-by-obscurity in the users table,
 *   - this page re-validates the token on EVERY request (GET and POST),
 *   - the token expires after 1 hour and is single-use (cleared the
 *     moment the password changes),
 *   - CSRF token protects the POST that changes the password.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$token = $_GET['token'] ?? ($_POST['token'] ?? '');

if ($token === '') {
    setFlash('error', 'Invalid reset link. No token provided.');
    redirect('/thalassemia/auth/forgot_password.php');
}

// Look up the token (single use — cleared once the password changes)
$stmt = $pdo->prepare(
    'SELECT userId, resetTokenAt FROM users WHERE resetToken = ?'
);
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('error', 'This reset link is invalid or has already been used.');
    redirect('/thalassemia/auth/forgot_password.php');
}

// Expire after 1 hour
if (!empty($user['resetTokenAt']) && (time() - strtotime($user['resetTokenAt'])) > 3600) {
    // Consume the expired token and send them back to request a new one
    $pdo->prepare('UPDATE users SET resetToken = NULL, resetTokenAt = NULL WHERE userId = ?')
        ->execute([$user['userId']]);
    setFlash('error', 'Your reset link has expired (older than 1 hour). Please request a new one.');
    redirect('/thalassemia/auth/forgot_password.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $newPassword     = $_POST['newPassword'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    if (strlen($newPassword) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword)
              || !preg_match('/[0-9]/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
        $errors[] = 'Password must include uppercase, lowercase, a number and a special character.';
    } elseif ($newPassword !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        // Update the password AND consume the token in one statement —
        // the link can never be replayed after the change.
        $pdo->prepare('UPDATE users SET passwordHash = ?, resetToken = NULL, resetTokenAt = NULL WHERE userId = ?')
            ->execute([$hash, $user['userId']]);

        // Clear any pending reset session state
        unset($_SESSION['pending_reset']);

        setFlash('success', 'Password reset successfully. Please log in with your new password.');
        redirect('/thalassemia/auth/login.php');
    }
}

$pageTitle = 'Reset Password';
require __DIR__ . '/../includes/header.php';
?>

<div class="auth-card">
  <div class="auth-logo">
    <span class="auth-logo-icon heartbeat">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 2.5 C12 2.5, 19 9, 19 14 C19 17.5, 16 20, 12 20 C8 20, 5 17.5, 5 14 C5 9, 12 2.5, 12 2.5Z"/>
        <path d="M8 14 Q12 11, 16 14" stroke-width="1.5"/>
      </svg>
    </span>
    <span class="auth-logo-text">Rakt<span class="accent">Sethu</span></span>
  </div>
  <p class="auth-subtitle">Reset Password</p>
  <p class="auth-tagline">Choose a new password</p>

  <?php foreach ($errors as $error): ?>
    <div class="flash flash-error"><?= h($error) ?></div>
  <?php endforeach; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <label>New Password
      <input type="password" name="newPassword" required autofocus
             placeholder="Min 8 chars, 1 uppercase, 1 number, 1 special">
    </label>
    <label>Confirm New Password
      <input type="password" name="confirmPassword" required placeholder="Re-enter password">
    </label>
    <p class="muted" style="font-size:0.75rem;margin:8px 0 14px;">
      This link works once and expires 1 hour after it was requested.
    </p>
    <button type="submit" class="btn-primary">Set New Password</button>
  </form>

  <a href="/thalassemia/auth/login.php" class="admin-login-link">← Back to Login</a>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
