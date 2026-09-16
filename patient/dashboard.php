<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../engine/matching_engine.php';

requireRole(['patient']);

$patientStmt = $pdo->prepare('SELECT * FROM patients WHERE userId = ?');
$patientStmt->execute([$_SESSION['userId']]);
$patient = $patientStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'raise_request') {
    verifyCsrf();
    try {
        raiseUrgentRequest($pdo, (int) $patient['patientId'], $patient['bloodGroup'], (int) $_POST['unitsNeeded']);
        setFlash('success', 'Urgent request raised. We are searching for a matching donor.');
    } catch (RuntimeException $e) {
        setFlash('error', $e->getMessage());
    }
    redirect('/thalassemia/patient/dashboard.php');
}

$reqStmt = $pdo->prepare(
    "SELECT * FROM blood_requests WHERE patientId = ? AND status IN ('pending','matched')
     ORDER BY createdAt DESC LIMIT 1"
);
$reqStmt->execute([$patient['patientId']]);
$activeRequest = $reqStmt->fetch();

$match = null;
$donorContact = null;
if ($activeRequest) {
    $matchStmt = $pdo->prepare(
        "SELECT dm.*, d.bloodGroup as donorBloodGroup, u.name as donorName, u.email as donorEmail
         FROM donor_matches dm
         JOIN donors d ON d.donorId = dm.donorId
         JOIN users u ON u.userId = d.userId
         WHERE dm.requestId = ?
         ORDER BY dm.matchedAt DESC LIMIT 1"
    );
    $matchStmt->execute([$activeRequest['requestId']]);
    $latestMatch = $matchStmt->fetch();
    if ($latestMatch && in_array($latestMatch['status'], ['pending', 'accepted'], true)) {
        $match = $latestMatch;
        if ($match['contactRevealed']) {
            $donorContact = ['name' => $match['donorName'], 'email' => $match['donorEmail']];
        }
    }
}

$pageTitle = 'Patient Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<h1>Welcome, <?= h($_SESSION['name']) ?></h1>

<section class="card">
  <h2>Your active request</h2>
  <?php if (!$activeRequest): ?>
    <div class="empty-state">
      <span class="empty-icon">🩸</span>
      <p>You have no active urgent request.</p>
      <p class="muted">Fill in the details below to raise a request.</p>
    </div>
    <form method="post" id="raiseRequestForm">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="action" value="raise_request">
      <label>Units needed
        <input type="number" name="unitsNeeded" min="1" max="10" value="1" required>
      </label>
      <p class="muted">Blood group on file: <strong><?= h($patient['bloodGroup']) ?></strong></p>
      <button type="submit" class="btn-primary" id="raiseRequestBtn">Raise Urgent Blood Request</button>
    </form>
    <script>
    document.getElementById('raiseRequestForm')?.addEventListener('submit', function() {
      var btn = document.getElementById('raiseRequestBtn');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span> Searching for donors...';
    });
    </script>
  <?php else: ?>
    <div class="request-status-bar">
      <span class="request-blood-group"><?= h($activeRequest['bloodGroup']) ?></span>
      <span class="request-units"><?= (int) $activeRequest['unitsNeeded'] ?> unit(s)</span>
      <span class="badge <?= $activeRequest['status'] === 'matched' ? 'badge-ok' : 'badge-wait' ?>"><?= h(ucfirst($activeRequest['status'])) ?></span>
      <?php if (!empty($activeRequest['isPriority'])): ?>
        <span class="badge badge-priority">⭐ Priority Donor</span>
      <?php endif; ?>
    </div>

    <?php if ($match): ?>
      <p>Match status: <strong><?= h(ucfirst($match['status'])) ?></strong>
         (response due by <?= h($match['responseDeadline']) ?>)</p>

      <?php if ($donorContact): ?>
        <div class="contact-reveal">
          <h3>Donor contact (revealed)</h3>
          <p><?= h($donorContact['name']) ?> — <?= h($donorContact['email']) ?></p>
        </div>
      <?php else: ?>
        <div class="searching-donor">
          <span class="spinner"></span>
          <p>Waiting for donor to accept your request...</p>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="searching-donor">
        <span class="spinner"></span>
        <p>Searching for an eligible donor nearby...</p>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
