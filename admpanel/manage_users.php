<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $userId = (int) ($_POST['userId'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($userId && $userId !== (int) $_SESSION['userId']) {
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM users WHERE userId = ?')->execute([$userId]);
            setFlash('success', 'User account removed.');
        }
    }
    redirect('/thalassemia/admpanel/manage_users.php');
}

$roleFilter = $_GET['role'] ?? '';
$sql = 'SELECT userId, name, email, role, createdAt FROM users';
$params = [];
if (in_array($roleFilter, ['patient','donor','doctor_lab','admin'], true)) {
    $sql .= ' WHERE role = ?';
    $params[] = $roleFilter;
}
$sql .= ' ORDER BY createdAt DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'Manage Users';
require __DIR__ . '/../includes/header.php';
?>

<h1>Manage Users</h1>

<div class="role-tabs">
  <a class="<?= $roleFilter === '' ? 'active' : '' ?>" href="?">All</a>
  <a class="<?= $roleFilter === 'patient' ? 'active' : '' ?>" href="?role=patient">Patients</a>
  <a class="<?= $roleFilter === 'donor' ? 'active' : '' ?>" href="?role=donor">Donors</a>
  <a class="<?= $roleFilter === 'doctor_lab' ? 'active' : '' ?>" href="?role=doctor_lab">Doctors/Labs</a>
  <a class="<?= $roleFilter === 'admin' ? 'active' : '' ?>" href="?role=admin">Admins</a>
</div>

<section class="card-dark-glass">
<div class="table-wrap">
<table class="data-table data-table-dark">
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Joined</th><th>Actions</th></tr></thead>
  <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td data-label="Name"><?= h($u['name']) ?></td>
        <td data-label="Email"><?= h($u['email']) ?></td>
        <td data-label="Role"><?= h($u['role']) ?></td>
        <td data-label="Joined"><?= h($u['createdAt']) ?></td>
        <td data-label="Actions">
          <?php if ((int) $u['userId'] !== (int) $_SESSION['userId']): ?>
            <form method="post" onsubmit="return confirm('Remove this account?');" class="inline-form">
              <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
              <input type="hidden" name="userId" value="<?= (int) $u['userId'] ?>">
              <button type="submit" name="action" value="delete" class="btn-danger btn-sm">Remove</button>
            </form>
          <?php else: ?>
            <span class="muted-dark">You</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
