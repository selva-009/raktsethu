<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/thalassemia/admpanel/dashboard.php');
}
verifyCsrf();

$doctorId = (int) ($_POST['doctorId'] ?? 0);
$action   = $_POST['action'] ?? '';

if ($doctorId && $action === 'approve') {
    $pdo->prepare('UPDATE doctors_labs SET approved = 1 WHERE doctorId = ?')->execute([$doctorId]);

    $userStmt = $pdo->prepare('SELECT userId FROM doctors_labs WHERE doctorId = ?');
    $userStmt->execute([$doctorId]);
    $userId = $userStmt->fetchColumn();
    if ($userId) {
        notify($pdo, (int) $userId, 'Your lab registration has been approved. You can now verify donor reports.');
    }
    setFlash('success', 'Lab approved.');
} elseif ($doctorId && $action === 'reject') {
    $userStmt = $pdo->prepare('SELECT userId FROM doctors_labs WHERE doctorId = ?');
    $userStmt->execute([$doctorId]);
    $userId = $userStmt->fetchColumn();

    $pdo->prepare('DELETE FROM doctors_labs WHERE doctorId = ?')->execute([$doctorId]);
    if ($userId) {
        notify($pdo, (int) $userId, 'Your lab registration was not approved. Please contact support for details.');
    }
    setFlash('success', 'Lab registration rejected.');
}

redirect('/thalassemia/admpanel/dashboard.php');
