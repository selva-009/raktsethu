<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../engine/matching_engine.php';

requireRole(['donor']);

$donorStmt = $pdo->prepare('SELECT * FROM donors WHERE userId = ?');
$donorStmt->execute([$_SESSION['userId']]);
$donor = $donorStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_last_donation') {
        $lastDonation = $_POST['lastDonationDate'] ?: null;
        $expiry = computeEligibilityExpiry($lastDonation);
        $pdo->prepare('UPDATE donors SET lastDonationDate = ?, eligibilityExpiry = ? WHERE donorId = ?')
            ->execute([$lastDonation, $expiry, $donor['donorId']]);
        setFlash('success', 'Donation record updated.');
    } elseif ($action === 'toggle_availability') {
        $newVal = $donor['isAvailable'] ? 0 : 1;
        $pdo->prepare('UPDATE donors SET isAvailable = ? WHERE donorId = ?')
            ->execute([$newVal, $donor['donorId']]);
        setFlash('success', $newVal ? 'You are now available to receive blood requests.' : 'You are now paused. You will not receive new requests.');
    } elseif ($action === 'accept' && !empty($_POST['matchId'])) {
        try {
            acceptMatch($pdo, (int) $_POST['matchId'], (int) $donor['donorId']);
            setFlash('success', 'Match accepted. Contact details are now visible to the patient.');
        } catch (RuntimeException $e) {
            setFlash('error', $e->getMessage());
        }
    } elseif ($action === 'decline' && !empty($_POST['matchId'])) {
        try {
            declineMatch($pdo, (int) $_POST['matchId'], (int) $donor['donorId']);
            setFlash('success', 'Match declined. The request has been reassigned.');
        } catch (RuntimeException $e) {
            setFlash('error', $e->getMessage());
        }
    }
    redirect('/thalassemia/donor/dashboard.php');
}

$donorStmt->execute([$_SESSION['userId']]);
$donor = $donorStmt->fetch();

$pendingStmt = $pdo->prepare(
    "SELECT dm.*, r.bloodGroup, r.unitsNeeded FROM donor_matches dm
     JOIN blood_requests r ON r.requestId = dm.requestId
     WHERE dm.donorId = ? AND dm.status = 'pending'
     ORDER BY dm.matchedAt DESC"
);
$pendingStmt->execute([$donor['donorId']]);
$pendingMatches = $pendingStmt->fetchAll();

$eligible = isDonorEligible($donor['eligibilityExpiry']);

// --- NEW: Donation history ---
$historyStmt = $pdo->prepare(
    'SELECT * FROM donation_history WHERE donorId = ? ORDER BY donationDate DESC'
);
$historyStmt->execute([$donor['donorId']]);
$donationHistory = $historyStmt->fetchAll();

// --- NEW: Impact stats ---
$totalDonations = count($donationHistory);
$totalUnits = array_sum(array_map(fn($d) => (int)$d['units'], $donationHistory));
$livesImpacted = $totalDonations; // 1 donation ≈ 1 life

// --- NEW: Next donation countdown ---
$daysUntilEligible = 0;
if ($donor['eligibilityExpiry']) {
    $today = new DateTime();
    $expiry = new DateTime($donor['eligibilityExpiry']);
    $daysUntilEligible = $today < $expiry ? $today->diff($expiry)->days : 0;
}

// --- NEW: Milestone badges ---
$badges = [
    ['icon' => '🌱', 'label' => 'First Donation', 'earned' => $totalDonations >= 1],
    ['icon' => '🩸', 'label' => '3 Donations',   'earned' => $totalDonations >= 3],
    ['icon' => '⭐', 'label' => '5 Donations',    'earned' => $totalDonations >= 5],
    ['icon' => '🏆', 'label' => '10 Donations',   'earned' => $totalDonations >= 10],
    ['icon' => '💎', 'label' => '20 Donations',   'earned' => $totalDonations >= 20],
];

// --- NEW: Has at least 1 verified donation for certificate ---
$verifiedDonations = array_filter($donationHistory, fn($d) => (int)$d['verified'] === 1);

// Check if donor has uploaded a report
$reportStmt = $pdo->prepare('SELECT COUNT(*) FROM donor_reports WHERE donorId = ?');
$reportStmt->execute([$donor['donorId']]);
$hasReport = (int) $reportStmt->fetchColumn() > 0;

// Verification progress steps
$progressSteps = [
    ['label' => 'Registered', 'done' => true],
    ['label' => 'Report Uploaded', 'done' => $hasReport],
    ['label' => 'Verified by Lab', 'done' => (bool)$donor['verified']],
    ['label' => 'Eligible to Donate', 'done' => $eligible && $donor['verified']],
];

$pageTitle = 'Donor Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<h1>Welcome, <?= h($_SESSION['name']) ?></h1>

<!-- Available to Donate Toggle -->
<section class="card">
  <h2>Availability</h2>
  <div class="toggle-wrap">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="action" value="toggle_availability">
      <label class="toggle">
        <input type="submit" hidden>
        <span class="toggle-slider" onclick="this.parentElement.submit()"></span>
      </label>
    </form>
    <span class="toggle-label">
      <?= $donor['isAvailable'] ? '🟢 Available — receiving requests' : '⏸ Paused — not receiving requests' ?>
    </span>
  </div>
  <p class="muted" style="margin-top:8px;font-size:0.78rem;">Toggle this off if you're temporarily unable to donate. You won't receive new requests until you toggle back on.</p>
</section>

<!-- Verification Progress Bar -->
<section class="card">
  <h2>Your Verification Progress</h2>
  <div class="progress-bar">
    <?php foreach ($progressSteps as $i => $step): ?>
      <div class="progress-step <?= $step['done'] ? 'done' : '' ?>">
        <span class="progress-circle">
          <?php if ($step['done']): ?>✓<?php else: ?><?= $i + 1 ?><?php endif; ?>
        </span>
        <span class="progress-label"><?= h($step['label']) ?></span>
      </div>
      <?php if ($i < count($progressSteps) - 1): ?>
        <div class="progress-line <?= $progressSteps[$i + 1]['done'] ? 'active' : '' ?>"></div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</section>

<!-- Eligibility & Next Donation Countdown -->
<section class="card">
  <h2>Eligibility status</h2>
  <p>Blood group: <strong><?= h($donor['bloodGroup']) ?></strong>
     &nbsp;|&nbsp; Verified: <strong><?= $donor['verified'] ? 'Yes' : 'Pending lab verification' ?></strong></p>
  <?php if ($eligible): ?>
    <p class="badge badge-ok">Eligible to donate</p>
  <?php else: ?>
    <div class="countdown-box">
      <span class="countdown-num"><?= $daysUntilEligible ?></span>
      <span class="countdown-label">days until you can donate again</span>
    </div>
    <p class="muted" style="font-size:0.78rem;">Eligibility date: <?= h($donor['eligibilityExpiry']) ?></p>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="action" value="update_last_donation">
    <label>Last donation date
      <input type="date" name="lastDonationDate" value="<?= h($donor['lastDonationDate']) ?>">
    </label>
    <button type="submit" class="btn-secondary">Update availability</button>
  </form>
</section>

<!-- Impact Tracker -->
<section class="card">
  <h2>Your Impact</h2>
  <div class="impact-grid">
    <div class="impact-card">
      <div class="impact-num"><?= $totalDonations ?></div>
      <div class="impact-label">Donations</div>
    </div>
    <div class="impact-card">
      <div class="impact-num"><?= $totalUnits ?></div>
      <div class="impact-label">Units Given</div>
    </div>
    <div class="impact-card">
      <div class="impact-num"><?= $livesImpacted ?></div>
      <div class="impact-label">Lives Touched</div>
    </div>
  </div>
  <?php if ($totalDonations === 0): ?>
    <p class="muted" style="text-align:center;font-size:0.82rem;">Your donations will appear here once you complete your first donation.</p>
  <?php endif; ?>
</section>

<!-- Milestone Badges -->
<section class="card">
  <h2>Milestones</h2>
  <div class="milestone-grid">
    <?php foreach ($badges as $badge): ?>
      <div class="milestone <?= $badge['earned'] ? 'earned' : 'locked' ?>">
        <span><?= $badge['icon'] ?></span>
        <span><?= h($badge['label']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- Donation History -->
<section class="card">
  <h2>Donation History</h2>
  <?php if (!$donationHistory): ?>
    <div class="empty-state">
      <span class="empty-icon">🩸</span>
      <p>No donations yet.</p>
      <p class="muted">Your donation history will appear here after your first donation.</p>
    </div>
  <?php else: ?>
    <ul class="donation-list">
      <?php foreach ($donationHistory as $dh): ?>
        <li class="donation-item">
          <div>
            <strong><?= h($dh['bloodGroup']) ?></strong>
            <span class="donation-date"> · <?= h($dh['donationDate']) ?></span>
            <?php if ($dh['verified']): ?>
              <span class="badge badge-ok" style="font-size:0.65rem;padding:2px 8px;">Verified</span>
            <?php else: ?>
              <span class="badge badge-wait" style="font-size:0.65rem;padding:2px 8px;">Pending verification</span>
            <?php endif; ?>
          </div>
          <span class="donation-units"><?= (int)$dh['units'] ?> unit(s)</span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<!-- Digital Certificate -->
<?php if (!empty($verifiedDonations)): ?>
<section class="card">
  <h2>Appreciation Certificate</h2>
  <p style="margin-bottom:12px;">You've earned a digital appreciation certificate for your verified blood donations!</p>
  <a href="/thalassemia/donor/download_certificate.php" class="btn-certificate" target="_blank">
    📜 Download Certificate
  </a>
</section>
<?php endif; ?>

<!-- Incoming Match Requests -->
<section class="card">
  <h2>Incoming match requests</h2>
  <?php if (!$pendingMatches): ?>
    <div class="empty-state">
      <span class="empty-icon">📭</span>
      <p>No pending match requests right now.</p>
      <p class="muted">You'll be notified here when a patient needs your blood type.</p>
    </div>
  <?php else: ?>
    <?php foreach ($pendingMatches as $m): ?>
      <div class="match-row">
        <p><?= h($m['bloodGroup']) ?> · <?= (int) $m['unitsNeeded'] ?> unit(s) needed
           · respond by <?= h($m['responseDeadline']) ?></p>
        <form method="post" class="inline-form">
          <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="matchId" value="<?= (int) $m['matchId'] ?>">
          <button type="submit" name="action" value="accept" class="btn-primary">Accept</button>
          <button type="submit" name="action" value="decline" class="btn-danger">Decline</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
