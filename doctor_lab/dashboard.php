<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole(['doctor_lab']);

$labStmt = $pdo->prepare('SELECT * FROM doctors_labs WHERE userId = ?');
$labStmt->execute([$_SESSION['userId']]);
$lab = $labStmt->fetch();

// A rejected/removed lab registration leaves no doctors_labs row — stop here
// with a clear message rather than letting the page fail on a missing $lab.
if (!$lab) {
    $pageTitle = 'Doctor/Lab Dashboard';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="flash flash-error">No active lab registration was found for your account. '
       . 'If you believe this is an error, please contact support.</div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action    = $_POST['action'] ?? '';
    $reportId  = (int) ($_POST['reportId'] ?? 0);
    $remarks   = trim($_POST['remarks'] ?? '');

    // Server-side enforcement of the admin-approval gate — the disabled
    // buttons in the HTML below are a UX hint only, not a security control.
    if (!$lab['approved']) {
        setFlash('error', 'Your lab is not yet approved by the admin. You cannot verify reports until approval.');
        redirect('/thalassemia/doctor_lab/dashboard.php');
    }

    if (in_array($action, ['approve', 'reject'], true) && $reportId) {
        $status = $action === 'approve' ? 'approved' : 'rejected';

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO verifications (reportId, doctorId, status, remarks, verifiedOn)
                 VALUES (?, ?, ?, ?, NOW())'
            )->execute([$reportId, $lab['doctorId'], $status, $remarks]);

            if ($status === 'approved') {
                $donorStmt = $pdo->prepare('SELECT donorId FROM donor_reports WHERE reportId = ?');
                $donorStmt->execute([$reportId]);
                $donorId = $donorStmt->fetchColumn();
                $pdo->prepare('UPDATE donors SET verified = 1 WHERE donorId = ?')->execute([$donorId]);
            }
            $pdo->commit();
            setFlash('success', "Report {$status}.");
        } catch (Throwable $e) {
            $pdo->rollBack();
            setFlash('error', 'Could not record the verification. Please try again.');
        }
    }
    redirect('/thalassemia/doctor_lab/dashboard.php');
}

$queueStmt = $pdo->prepare(
    "SELECT dr.reportId, dr.fileUrl, dr.uploadDate, u.name as donorName, d.bloodGroup
     FROM donor_reports dr
     JOIN donors d ON d.donorId = dr.donorId
     JOIN users u ON u.userId = d.userId
     WHERE dr.reportId NOT IN (SELECT reportId FROM verifications)
     ORDER BY dr.uploadDate ASC"
);
$queueStmt->execute();
$queue = $queueStmt->fetchAll();

$pageTitle = 'Doctor/Lab Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<h1>Welcome, <?= h($_SESSION['name']) ?></h1>

<?php
$verStatus = $lab['verification_status'] ?? 'pending_verification';
if (!$lab['approved']): ?>
  <div class="flash flash-error">
    Your lab registration is awaiting verification and approval.<br>
    Verification status: <strong><?= h(ucwords(str_replace('_', ' ', $verStatus))) ?></strong><br>
    <?php if ($verStatus === 'pending_verification'): ?>
      Your license is pending verification by the admin.
    <?php elseif ($verStatus === 'manual_review'): ?>
      Your license requires manual review. Please wait for admin decision.
    <?php elseif ($verStatus === 'verification_failed'): ?>
      License verification could not be completed. Please contact support.
    <?php elseif ($verStatus === 'rejected'): ?>
      Your license verification was rejected. <?= h($lab['verification_notes'] ?? '') ?>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="flash flash-success">
    Your license is verified and your lab is approved. You can verify donor reports.
  </div>
<?php endif; ?>

<section class="card">
  <h2>Pending verification queue</h2>
  <?php if (!$queue): ?>
    <div class="empty-state">
      <span class="empty-icon">📋</span>
      <p>No pathology reports awaiting verification.</p>
    </div>
  <?php else: ?>
    <div class="filter-bar">
      <input type="text" id="queueSearch" placeholder="🔍 Search by donor name..." oninput="filterQueue()">
      <select id="queueBloodGroup" onchange="filterQueue()">
        <option value="">All Blood Groups</option>
        <option value="O-">O-</option><option value="O+">O+</option>
        <option value="A-">A-</option><option value="A+">A+</option>
        <option value="B-">B-</option><option value="B+">B+</option>
        <option value="AB-">AB-</option><option value="AB+">AB+</option>
      </select>
    </div>
    <div id="queueList">
    <?php foreach ($queue as $r): ?>
      <div class="match-row queue-item"
           data-name="<?= h(strtolower($r['donorName'])) ?>"
           data-blood="<?= h($r['bloodGroup']) ?>">
        <p><strong><?= h($r['donorName']) ?></strong> (<?= h($r['bloodGroup']) ?>) —
           uploaded <?= h($r['uploadDate']) ?>
           — <a href="<?= h($r['fileUrl']) ?>" target="_blank" rel="noopener">View report</a></p>
        <form method="post" class="inline-form">
          <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="reportId" value="<?= (int) $r['reportId'] ?>">
          <input type="text" name="remarks" placeholder="Remarks (optional)">
          <button type="submit" name="action" value="approve" class="btn-primary" <?= $lab['approved'] ? '' : 'disabled' ?>>Approve</button>
          <button type="submit" name="action" value="reject" class="btn-danger" <?= $lab['approved'] ? '' : 'disabled' ?>>Reject</button>
        </form>
      </div>
    <?php endforeach; ?>
    </div>
    <p class="muted" id="queueCount" style="margin-top:8px;font-size:0.78rem;"></p>
    <script>
    function filterQueue(){
      var search=document.getElementById('queueSearch').value.toLowerCase();
      var bg=document.getElementById('queueBloodGroup').value;
      var items=document.querySelectorAll('.queue-item');
      var visible=0;
      items.forEach(function(r){
        var name=r.getAttribute('data-name');
        var blood=r.getAttribute('data-blood');
        var show=true;
        if(search && name.indexOf(search)===-1) show=false;
        if(bg && blood!==bg) show=false;
        r.style.display=show?'':'none';
        if(show) visible++;
      });
      document.getElementById('queueCount').textContent='Showing '+visible+' of '+items.length+' reports';
    }
    filterQueue();
    </script>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
