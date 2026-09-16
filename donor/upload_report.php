<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['donor']);
$donorStmt = $pdo->prepare('SELECT * FROM donors WHERE userId = ?');
$donorStmt->execute([$_SESSION['userId']]);
$donor = $donorStmt->fetch();
$uploadDir = __DIR__ . '/../uploads/reports/';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (empty($_FILES['report']) || $_FILES['report']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose a valid file. Error code: ' . ($_FILES['report']['error'] ?? 'no file');
    } else {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['report']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) $errors[] = 'Only PDF, JPG, or PNG files are accepted.';
        elseif ($_FILES['report']['size'] > 5 * 1024 * 1024) $errors[] = 'File must be under 5 MB.';
    }
    if (!$errors) {
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
        if (is_dir($uploadDir)) @chmod($uploadDir, 0777);
        $safeName = 'donor_' . $donor['donorId'] . '_' . time() . '.' . $ext;
        $destination = $uploadDir . $safeName;
        $publicUrl = '/thalassemia/uploads/reports/' . $safeName;
        $uploaded = false;
        if (@move_uploaded_file($_FILES['report']['tmp_name'], $destination)) $uploaded = true;
        elseif (@copy($_FILES['report']['tmp_name'], $destination)) { @unlink($_FILES['report']['tmp_name']); $uploaded = true; }
        else { $fileData = @file_get_contents($_FILES['report']['tmp_name']); if ($fileData !== false && @file_put_contents($destination, $fileData) !== false) $uploaded = true; }
        if ($uploaded) {
            $pdo->prepare('INSERT INTO donor_reports (donorId, fileUrl, expiryDate) VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 1 YEAR))')->execute([$donor['donorId'], $publicUrl]);
            setFlash('success', 'Report uploaded successfully. A doctor/lab will verify it shortly.');
            redirect('/thalassemia/donor/dashboard.php');
        } else $errors[] = 'Upload failed. Could not save the file.';
    }
}
$pageTitle = 'Upload Pathology Report';
require __DIR__ . '/../includes/header.php';
?>
<h1>Upload pathology report</h1>
<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>
<section class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
    <label>Pathology report (PDF, JPG, or PNG, max 5 MB)
      <input type="file" name="report" accept=".pdf,.jpg,.jpeg,.png" required>
    </label>
    <button type="submit" class="btn-primary">Upload</button>
  </form>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
