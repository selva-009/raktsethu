<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$step = 1;
$userId = null;
$securityQuestion = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (($_POST['step'] ?? '') === '1') {
        // Step 1: User enters email + phone + security answer — all three at once
        $email         = trim($_POST['email'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $securityAnswer = trim($_POST['securityAnswer'] ?? '');

        // No field-specific error messages — just "information doesn't match"
        $stmt = $pdo->prepare(
            'SELECT userId, securityQuestion, securityAnswer FROM users
             WHERE email = ? AND phone = ? LIMIT 1'
        );
        $stmt->execute([$email, $phone]);
        $user = $stmt->fetch();

        if (!$user) {
            // Don't reveal which field is wrong
            $error = 'The information you entered does not match our records. Please check and try again.';
            $step = 1;
        } elseif (empty($user['securityAnswer'])) {
            // User registered before security questions were added
            $error = 'Your account does not have a security question set. Please contact the admin to reset your password.';
            $step = 1;
        } else {
            // Verify security answer (bcrypt hashed)
            if (password_verify(strtolower($securityAnswer), $user['securityAnswer'])) {
                $userId = (int) $user['userId'];
                $securityQuestion = $user['securityQuestion'];
                $step = 2;
            } else {
                $error = 'The information you entered does not match our records. Please check and try again.';
                $step = 1;
            }
        }
    } elseif (($_POST['step'] ?? '') === '2') {
        // Step 2: Set new password
        $userId = (int) ($_POST['userId'] ?? 0);
        $newPassword = $_POST['newPassword'] ?? '';
        $confirmPassword = $_POST['confirmPassword'] ?? '';

        if (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters long.';
            $step = 2;
        } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            $error = 'Password must include uppercase, lowercase, and a number.';
            $step = 2;
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
            $step = 2;
        } else {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE users SET passwordHash = ? WHERE userId = ?')
                ->execute([$hash, $userId]);
            setFlash('success', 'Password reset successfully. Please login with your new password.');
            redirect('/thalassemia/auth/login.php');
        }
    }
}

$pageTitle = 'Forgot Password';
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
  <p class="auth-subtitle">Forgot Password</p>
  <p class="auth-tagline">Verify your identity to reset</p>

  <?php if ($error): ?>
    <div class="flash flash-error"><?= h($error) ?></div>
  <?php endif; ?>

  <!-- Progress indicator -->
  <div class="fp-steps">
    <span class="fp-step <?= $step >= 1 ? 'active' : '' ?> <?= $step > 1 ? 'done' : '' ?>">1</span>
    <div class="fp-line <?= $step > 1 ? 'active' : '' ?>"></div>
    <span class="fp-step <?= $step >= 2 ? 'active' : '' ?>">2</span>
  </div>
  <div class="fp-labels">
    <span>Verify Identity</span><span>Reset Password</span>
  </div>

  <?php if ($step === 1): ?>
    <!-- Step 1: Verify identity — email + phone + security answer -->
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="step" value="1">
      <p class="muted" style="font-size:0.75rem;margin-bottom:12px;">Enter all three fields below. If they match our records, you can reset your password.</p>
      <label>Registered Email
        <input type="email" name="email" required autofocus placeholder="you@example.com">
      </label>
      <label>Registered Phone Number
        <input type="tel" name="phone" required placeholder="9876543210" pattern="[6-9][0-9]{9}" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
      </label>
      <label>Security Question
        <select name="securityQuestionSelect" required onchange="document.getElementById('securityAnswerField').focus()">
          <option value="">Select your question</option>
          <option>What was the name of your first school?</option>
          <option>What is your mother's maiden name?</option>
          <option>What was the name of your first pet?</option>
          <option>What is your favourite book?</option>
          <option>What city were you born in?</option>
        </select>
      </label>
      <label>Your Answer
        <input type="text" name="securityAnswer" id="securityAnswerField" required placeholder="Enter your answer">
      </label>
      <button type="submit" class="btn-primary">Verify Identity</button>
    </form>

  <?php elseif ($step === 2): ?>
    <!-- Step 2: Set new password -->
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="step" value="2">
      <input type="hidden" name="userId" value="<?= (int) $userId ?>">
      <div class="flash flash-success" style="margin-bottom:14px;">Identity verified! Set your new password.</div>
      <label>New Password
        <input type="password" name="newPassword" placeholder="Min 8 chars, 1 uppercase, 1 number" required autofocus>
      </label>
      <label>Confirm New Password
        <input type="password" name="confirmPassword" placeholder="Re-enter password" required>
      </label>
      <p class="muted" style="font-size:0.75rem;margin:8px 0 14px;">Choose a strong password you haven't used before.</p>
      <button type="submit" class="btn-primary">Reset Password</button>
    </form>
  <?php endif; ?>

  <a href="/thalassemia/auth/login.php" class="admin-login-link">← Back to Login</a>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
