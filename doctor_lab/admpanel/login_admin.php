<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['passwordHash'])) {
        $errors[] = 'Invalid admin credentials.';
    } else {
        session_regenerate_id(true);
        $_SESSION['userId'] = (int) $user['userId'];
        $_SESSION['name']   = $user['name'];
        $_SESSION['role']   = $user['role'];
        redirect('/thalassemia/admpanel/dashboard.php');
    }
}

$pageTitle = 'Admin Login';
require __DIR__ . '/../includes/header.php';
?>

<div class="auth-card">
  <h1>Admin Login</h1>

  <?php foreach ($errors as $error): ?>
    <div class="flash flash-error"><?= h($error) ?></div>
  <?php endforeach; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
    <label>Email
      <input type="email" name="email" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" required>
    </label>
    <button type="submit" class="btn-primary">Login</button>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
