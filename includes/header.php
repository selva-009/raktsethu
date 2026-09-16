<?php
$flash = getFlash();
$pageTitle = $pageTitle ?? 'RaktSethu';

// Check for unread notifications
$unreadCount = 0;
if (!empty($_SESSION['userId'])) {
    try {
        $ns = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE userId = ? AND is_read = 0');
        $ns->execute([$_SESSION['userId']]);
        $unreadCount = (int) $ns->fetchColumn();
    } catch (Throwable $e) { /* notifications table may not have is_read column yet */ }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?> — RaktSethu</title>
  <link rel="stylesheet" href="/thalassemia/assets/css/style.css?v=gold2028">
  <link rel="preconnect" href="https://fonts.googleapis.com/">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-grid-top"></div>
<div class="bg-grid-floor"></div>
<div class="bg-glow"></div>
<div class="blob blob-1"></div>
<div class="blob blob-2"></div>
<header class="site-header">
  <a class="brand" href="/thalassemia/index.php">
    <span class="brand-icon heartbeat">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#d4af37" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 2.5 C12 2.5, 19 9, 19 14 C19 17.5, 16 20, 12 20 C8 20, 5 17.5, 5 14 C5 9, 12 2.5, 12 2.5Z"/>
        <path d="M8 14 Q12 11, 16 14" stroke-width="1.5"/>
        <path d="M9 16 Q12 14, 15 16" stroke-width="1.2" opacity="0.6"/>
      </svg>
    </span>
    <span class="brand-text">Rakt<span class="accent">Sethu</span></span>
  </a>
  <?php if (!empty($_SESSION['userId'])): ?>
    <nav class="nav">
      <?php if ($_SESSION['role'] === 'patient'): ?>
        <a href="/thalassemia/patient/dashboard.php">Dashboard</a>
      <?php elseif ($_SESSION['role'] === 'donor'): ?>
        <a href="/thalassemia/donor/dashboard.php">Dashboard</a>
        <a href="/thalassemia/donor/upload_report.php">Upload Report</a>
      <?php elseif ($_SESSION['role'] === 'doctor_lab'): ?>
        <a href="/thalassemia/doctor_lab/dashboard.php">Dashboard</a>
      <?php elseif ($_SESSION['role'] === 'admin'): ?>
        <a href="/thalassemia/admpanel/dashboard.php">Dashboard</a>
        <a href="/thalassemia/admpanel/manage_users.php">Manage Users</a>
      <?php endif; ?>

      <?php if ($unreadCount > 0): ?>
        <span class="notif-bell" title="<?= $unreadCount ?> new notification(s)">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d4af37" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <span class="notif-dot"></span>
        </span>
      <?php endif; ?>

      <span class="nav-user"><?= h($_SESSION['name']) ?> · <?= h(ucfirst(str_replace('_', '/', $_SESSION['role']))) ?></span>
      <a class="logout" href="/thalassemia/auth/logout.php">Logout</a>
    </nav>
  <?php endif; ?>
</header>
<?php if ($flash): ?>
  <div class="flash flash-<?= h($flash['type']) ?>" style="max-width:960px;margin:16px auto 0;">
    <?= h($flash['message']) ?>
  </div>
<?php endif; ?>
<main class="content">
