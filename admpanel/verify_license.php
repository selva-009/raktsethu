<?php
/**
 * verify_license.php — Admin page to verify a doctor's license.
 *
 * If a SurePass API key is configured, calls the NMC Verification API.
 * If not, shows manual review instructions with a link to the NMC website.
 *
 * Also records the verification attempt in the license_verifications table.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole(['admin']);

$doctorId = (int) ($_GET['doctorId'] ?? $_POST['doctorId'] ?? 0);
if (!$doctorId) {
    setFlash('error', 'Missing doctor ID.');
    redirect('/thalassemia/admpanel/dashboard.php');
}

// Fetch doctor/lab details
$stmt = $pdo->prepare(
    'SELECT dl.*, u.name, u.email
     FROM doctors_labs dl
     JOIN users u ON u.userId = dl.userId
     WHERE dl.doctorId = ?'
);
$stmt->execute([$doctorId]);
$doctor = $stmt->fetch();

if (!$doctor) {
    setFlash('error', 'Doctor/Lab not found.');
    redirect('/thalassemia/admpanel/dashboard.php');
}

// Handle manual admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'run_api_verification' && LICENSE_API_ENABLED) {
        // Call the SurePass NMC Verification API
        $result = verifyLicenseApi(
            $doctor['labLicenseNo'],
            $doctor['name'],
            $doctor['license_state_council'] ?? ''
        );

        // Record the verification attempt
        $pdo->prepare(
            'INSERT INTO license_verifications
             (doctorId, license_number, doctor_name, state_council, api_status,
              api_response, verified_by, verified_at, admin_decision)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
        )->execute([
            $doctorId,
            $doctor['labLicenseNo'],
            $doctor['name'],
            $doctor['license_state_council'] ?? '',
            $result['status'],
            json_encode($result['raw']),
            $_SESSION['userId'],
            $result['status'],
        ]);

        // Update doctor verification status
        if ($result['verified']) {
            $pdo->prepare(
                'UPDATE doctors_labs
                 SET verification_status = ?, approved = 1,
                     verified_at = NOW(), verified_by_admin_id = ?,
                     verification_notes = ?
                 WHERE doctorId = ?'
            )->execute(['approved', $_SESSION['userId'], $result['details'], $doctorId]);

            notify($pdo, (int) $doctor['userId'],
                'Your medical license has been verified via NMC API. Your account is now approved.');
            setFlash('success', 'License verified via API! Doctor approved automatically.');
        } else {
            $newStatus = $result['status'] === 'rejected' ? 'rejected' : 'manual_review';
            $pdo->prepare(
                'UPDATE doctors_labs
                 SET verification_status = ?, verification_notes = ?
                 WHERE doctorId = ?'
            )->execute([$newStatus, $result['details'], $doctorId]);

            setFlash('error', 'Verification result: ' . $result['details']);
        }

        redirect('/thalassemia/admpanel/verify_license.php?doctorId=' . $doctorId);
    }

    if ($action === 'manual_approve') {
        $notes = trim($_POST['admin_notes'] ?? '');

        $pdo->prepare(
            'INSERT INTO license_verifications
             (doctorId, license_number, doctor_name, state_council, api_status,
              api_response, verified_by, verified_at, admin_decision)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
        )->execute([
            $doctorId, $doctor['labLicenseNo'], $doctor['name'],
            $doctor['license_state_council'] ?? '',
            'manual', null, $_SESSION['userId'], 'approved',
        ]);

        $pdo->prepare(
            'UPDATE doctors_labs
             SET verification_status = ?, approved = 1,
                 verified_at = NOW(), verified_by_admin_id = ?,
                 verification_notes = ?
             WHERE doctorId = ?'
        )->execute(['approved', $_SESSION['userId'], $notes ?: 'Manually approved by admin.', $doctorId]);

        notify($pdo, (int) $doctor['userId'],
            'Your lab registration has been approved by the admin. You can now verify donor reports.');
        setFlash('success', 'Doctor/Lab manually approved.');
        redirect('/thalassemia/admpanel/verify_license.php?doctorId=' . $doctorId);
    }

    if ($action === 'manual_reject') {
        $notes = trim($_POST['admin_notes'] ?? 'Rejected by admin.');

        $pdo->prepare(
            'INSERT INTO license_verifications
             (doctorId, license_number, doctor_name, state_council, api_status,
              api_response, verified_by, verified_at, admin_decision)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
        )->execute([
            $doctorId, $doctor['labLicenseNo'], $doctor['name'],
            $doctor['license_state_council'] ?? '',
            'manual', null, $_SESSION['userId'], 'rejected',
        ]);

        $pdo->prepare(
            'UPDATE doctors_labs
             SET verification_status = ?, verification_notes = ?
             WHERE doctorId = ?'
        )->execute(['rejected', $notes, $doctorId]);

        notify($pdo, (int) $doctor['userId'],
            'Your lab registration was not approved. Reason: ' . $notes);
        setFlash('success', 'Doctor/Lab rejected.');
        redirect('/thalassemia/admpanel/verify_license.php?doctorId=' . $doctorId);
    }

    redirect('/thalassemia/admpanel/verify_license.php?doctorId=' . $doctorId);
}

// Fetch verification history for this doctor
$historyStmt = $pdo->prepare(
    'SELECT lv.*, u.name as adminName
     FROM license_verifications lv
     JOIN users u ON u.userId = lv.verified_by
     WHERE lv.doctorId = ?
     ORDER BY lv.verified_at DESC'
);
$historyStmt->execute([$doctorId]);
$verificationHistory = $historyStmt->fetchAll();

$pageTitle = 'Verify Doctor License';
require __DIR__ . '/../includes/header.php';
?>

<h1>License Verification</h1>

<section class="card">
  <h2>Doctor/Lab Details</h2>
  <table class="data-table">
    <tr><th>Name</th><td><?= h($doctor['name']) ?></td></tr>
    <tr><th>Email</th><td><?= h($doctor['email']) ?></td></tr>
    <tr><th>License Number</th><td><?= h($doctor['labLicenseNo']) ?></td></tr>
    <tr><th>State Council</th><td><?= h($doctor['license_state_council'] ?? 'Not specified') ?></td></tr>
    <tr><th>Specialty</th><td><?= h($doctor['specialty'] ?? 'Not specified') ?></td></tr>
    <tr><th>Affiliation</th><td><?= h($doctor['affiliation']) ?></td></tr>
    <tr><th>Registration Year</th><td><?= h($doctor['license_year'] ?? 'Not specified') ?></td></tr>
    <tr><th>Current Status</th>
        <td>
          <?php
          $status = $doctor['verification_status'] ?? 'pending_verification';
          $badgeClass = match($status) {
              'approved' => 'badge-ok',
              'rejected' => 'badge-wait',
              'manual_review' => 'badge-wait',
              'verification_failed' => 'badge-wait',
              default => 'badge-wait',
          };
          $statusLabel = ucwords(str_replace('_', ' ', $status));
          ?>
          <span class="badge <?= $badgeClass ?>"><?= h($statusLabel) ?></span>
          <?php if ($doctor['approved']): ?>
            <span class="badge badge-ok">Approved</span>
          <?php endif; ?>
        </td>
    </tr>
    <?php if (!empty($doctor['verification_notes'])): ?>
      <tr><th>Notes</th><td><?= h($doctor['verification_notes']) ?></td></tr>
    <?php endif; ?>
  </table>
</section>

<section class="card">
  <h2>Verification Actions</h2>

  <?php if (LICENSE_API_ENABLED): ?>
    <!-- API-based verification is available -->
    <p>The SurePass NMC Verification API is configured. You can run automatic verification.</p>
    <form method="post" class="inline-form">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="doctorId" value="<?= (int) $doctorId ?>">
      <button type="submit" name="action" value="run_api_verification" class="btn-primary"
              onclick="return confirm('Run NMC API verification for this license number?');">
        Run API Verification
      </button>
    </form>
  <?php else: ?>
    <!-- No API key — manual review -->
    <div class="flash flash-error" style="margin-bottom: 16px;">
      No SurePass API key is configured. Verification must be done manually.
    </div>
    <p><strong>Steps for manual verification:</strong></p>
    <ol>
      <li>Click the NMC website link below to open the Indian Medical Register search</li>
      <li>Enter the doctor's registration number: <code><?= h($doctor['labLicenseNo']) ?></code></li>
      <li>Select the state council: <?= h($doctor['license_state_council'] ?? 'Any') ?></li>
      <li>Verify that the name matches: <strong><?= h($doctor['name']) ?></strong></li>
      <li>Check if the license is active (not blacklisted/suspended)</li>
      <li>Return here and click Approve or Reject below</li>
    </ol>
    <p>
      <a href="https://www.nmc.org.in/information-desk/indian-medical-register/"
         target="_blank" rel="noopener" class="btn-secondary">
        Open NMC Register Search &rarr;
      </a>
    </p>
  <?php endif; ?>

  <hr style="margin: 20px 0; border: none; border-top: 1px solid #e8ddd0;">

  <!-- Manual approve/reject (always available) -->
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="doctorId" value="<?= (int) $doctorId ?>">
    <label>Admin Notes
      <textarea name="admin_notes" rows="3" placeholder="Verification notes (optional for approve, required for reject)..."></textarea>
    </label>
    <div class="inline-form" style="margin-top: 12px;">
      <button type="submit" name="action" value="manual_approve" class="btn-primary"
              onclick="return confirm('Approve this doctor/lab?');">
        Approve
      </button>
      <button type="submit" name="action" value="manual_reject" class="btn-danger"
              onclick="return confirm('Reject this doctor/lab?');">
        Reject
      </button>
    </div>
  </form>
</section>

<?php if ($verificationHistory): ?>
<section class="card">
  <h2>Verification History</h2>
  <table class="data-table">
    <thead>
      <tr><th>Date</th><th>Method</th><th>Result</th><th>Admin</th><th>Notes</th></tr>
    </thead>
    <tbody>
      <?php foreach ($verificationHistory as $v): ?>
        <tr>
          <td><?= h($v['verified_at']) ?></td>
          <td><?= h($v['api_status'] === 'manual' ? 'Manual' : 'API') ?></td>
          <td>
            <?php $dc = $v['admin_decision']; ?>
            <span class="badge <?= $dc === 'approved' ? 'badge-ok' : 'badge-wait' ?>">
              <?= h(ucwords(str_replace('_', ' ', $dc))) ?>
            </span>
          </td>
          <td><?= h($v['adminName']) ?></td>
          <td>
            <small class="muted">
              <?php
              if ($v['api_response']) {
                  $raw = json_decode($v['api_response'], true);
                  if (isset($raw['name'])) echo 'Name: ' . h($raw['name']);
                  if (isset($raw['state_council'])) echo ' | Council: ' . h($raw['state_council']);
                  if (isset($raw['status'])) echo ' | Status: ' . h($raw['status']);
              } else {
                  echo '-';
              }
              ?>
            </small>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<p><a href="/thalassemia/admpanel/dashboard.php">&larr; Back to Dashboard</a></p>

<?php require __DIR__ . '/../includes/footer.php'; ?>
