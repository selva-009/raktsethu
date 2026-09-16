<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // --- Brute-force protection: max 5 failed attempts per email per 15 min ---
    // Uses the same registration_attempts table (works even without it via try-catch).
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $rateStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM registration_attempts
             WHERE ipAddress = ? AND attemptedAt > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $rateStmt->execute([$clientIp]);
        if ((int) $rateStmt->fetchColumn() >= 15) {
            $errors[] = 'Too many attempts from your network. Please try again in 15 minutes.';
        }
    } catch (Throwable $e) { /* table missing — skip limiting */ }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['passwordHash'])) {
            $errors[] = 'Invalid email or password.';
            // Log the failed attempt for rate limiting
            try {
                $pdo->prepare('INSERT INTO registration_attempts (ipAddress) VALUES (?)')->execute([$clientIp]);
            } catch (Throwable $e) { /* ignore */ }
        } else {
            // Successful login: clear this IP's attempt log
            try {
                $pdo->prepare('DELETE FROM registration_attempts WHERE ipAddress = ?')->execute([$clientIp]);
            } catch (Throwable $e) { /* ignore */ }

                    // --- Email verification gate ---
        if ((int) ($user['emailVerified'] ?? 1) === 0) {
            // Account not verified yet — send them to the verification page.
            // If their token is missing or older than 24h, issue a fresh one.
            $needNewToken = empty($user['verificationToken'])
                || (strtotime($user['verificationTokenAt'] ?? '') !== false
                    && (time() - strtotime($user['verificationTokenAt'])) > 86400);
            if ($needNewToken) {
                $fresh = bin2hex(random_bytes(32));
                $pdo->prepare('UPDATE users SET verificationToken = ?, verificationTokenAt = NOW() WHERE userId = ?')
                    ->execute([$fresh, $user['userId']]);
                $user['verificationToken'] = $fresh;
            }
            $_SESSION['pending_verification'] = [
                'userId' => (int) $user['userId'],
                'name'   => $user['name'],
                'email'  => $user['email'],
                'token'  => $user['verificationToken'],
            ];
            redirect('/thalassemia/auth/verify_notice.php');
        }

        session_regenerate_id(true);
            $_SESSION['userId'] = (int) $user['userId'];
            $_SESSION['name']   = $user['name'];
            $_SESSION['role']   = $user['role'];

            redirect(match ($user['role']) {
                'patient'    => '/thalassemia/patient/dashboard.php',
                'donor'      => '/thalassemia/donor/dashboard.php',
                'doctor_lab' => '/thalassemia/doctor_lab/dashboard.php',
                'admin'      => '/thalassemia/admpanel/dashboard.php',
                default      => '/thalassemia/index.php',
            });
        }
    }
}

$pageTitle = 'Login';
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
  <p class="auth-subtitle">Thalassemia Blood Support System</p>
  <p class="auth-tagline">Connecting patients with verified donors</p>

  <?php foreach ($errors as $error): ?>
    <div class="flash flash-error"><?= h($error) ?></div>
  <?php endforeach; ?>

  <form method="post" action="/thalassemia/auth/login.php">
    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">

    <label>Email / Username
      <input type="email" name="email" required autofocus>
    </label>

    <label>Password
      <input type="password" name="password" required>
    </label>

    <button type="submit" class="btn-primary">Login</button>
  </form>

  <a href="/thalassemia/auth/forgot_password.php" class="forgot-link">Forgot Password?</a>

  <div class="auth-divider">New here? Register as</div>
  <div class="register-btns">
    <a href="/thalassemia/auth/register.php?role=patient" class="btn-register">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Patient
    </a>
    <a href="/thalassemia/auth/register.php?role=donor" class="btn-register">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14l-7 7-7-7"/><path d="M19 5l-7 7-7-7"/></svg>
      Donor
    </a>
    <a href="/thalassemia/auth/register.php?role=doctor_lab" class="btn-register">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.5 C12 2.5, 19 9, 19 14 C19 17.5, 16 20, 12 20 C8 20, 5 17.5, 5 14 C5 9, 12 2.5, 12 2.5Z"/></svg>
      Doctor / Lab
    </a>
  </div>

  <a href="/thalassemia/admpanel/login_admin.php" class="admin-login-link">Admin login</a>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
