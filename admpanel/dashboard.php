<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole(['admin']);

$counts = [
    'patients'    => $pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn(),
    'donors'      => $pdo->query('SELECT COUNT(*) FROM donors')->fetchColumn(),
    'labs'        => $pdo->query('SELECT COUNT(*) FROM doctors_labs')->fetchColumn(),
    'activeReqs'  => $pdo->query("SELECT COUNT(*) FROM blood_requests WHERE status IN ('pending','matched')")->fetchColumn(),
    'pendingLabs' => $pdo->query('SELECT COUNT(*) FROM doctors_labs WHERE approved = 0')->fetchColumn(),
    'verifiedDonors'  => $pdo->query('SELECT COUNT(*) FROM donors WHERE verified = 1')->fetchColumn(),
    'totalReports'    => $pdo->query('SELECT COUNT(*) FROM donor_reports')->fetchColumn(),
    'pendingVerifs'   => $pdo->query('SELECT COUNT(*) FROM donor_reports WHERE reportId NOT IN (SELECT reportId FROM verifications)')->fetchColumn(),
];

$recentRequests = $pdo->query(
    "SELECT r.requestId, r.bloodGroup, r.unitsNeeded, r.status, r.createdAt, u.name as patientName
     FROM blood_requests r
     JOIN patients p ON p.patientId = r.patientId
     JOIN users u ON u.userId = p.userId
     ORDER BY r.createdAt DESC LIMIT 10"
)->fetchAll();

$pendingLabs = $pdo->query(
    "SELECT dl.doctorId, dl.labLicenseNo, dl.affiliation, dl.verification_status,
            dl.license_state_council, dl.specialty, dl.license_year,
            dl.verification_notes, dl.approved, u.name, u.email
     FROM doctors_labs dl JOIN users u ON u.userId = dl.userId
     WHERE dl.approved = 0
     ORDER BY dl.verification_status ASC, u.createdAt DESC"
)->fetchAll();

$allLabs = $pdo->query(
    "SELECT dl.doctorId, dl.labLicenseNo, dl.affiliation, dl.verification_status,
            dl.license_state_council, dl.specialty, dl.license_year,
            dl.approved, u.name, u.email, u.phone
     FROM doctors_labs dl JOIN users u ON u.userId = dl.userId
     ORDER BY dl.approved DESC, u.createdAt DESC"
)->fetchAll();

$allDonors = $pdo->query(
    "SELECT d.donorId, d.bloodGroup, d.verified, d.lastDonationDate, d.eligibilityExpiry, d.location,
            u.name, u.email, u.phone, u.createdAt,
            (SELECT COUNT(*) FROM donor_reports dr WHERE dr.donorId = d.donorId) as reportCount,
            (SELECT v.status FROM verifications v
             JOIN donor_reports dr ON dr.reportId = v.reportId
             WHERE dr.donorId = d.donorId
             ORDER BY v.verifiedOn DESC LIMIT 1) as verifyStatus,
            (SELECT dr.reportId FROM donor_reports dr
             WHERE dr.donorId = d.donorId
             ORDER BY dr.uploadDate DESC LIMIT 1) as latestReportId
     FROM donors d
     JOIN users u ON u.userId = d.userId
     ORDER BY u.createdAt DESC"
)->fetchAll();

$allPatients = $pdo->query(
    "SELECT p.patientId, p.bloodGroup, p.thalassemiaType, p.location,
            u.name, u.email, u.phone, u.createdAt,
            (SELECT COUNT(*) FROM blood_requests r WHERE r.patientId = p.patientId) as requestCount,
            (SELECT r.status FROM blood_requests r WHERE r.patientId = p.patientId ORDER BY r.createdAt DESC LIMIT 1) as lastReqStatus
     FROM patients p
     JOIN users u ON u.userId = p.userId
     ORDER BY u.createdAt DESC"
)->fetchAll();

$allMatches = $pdo->query(
    "SELECT dm.matchId, dm.status, dm.matchedAt, dm.responseDeadline, dm.contactRevealed,
            r.bloodGroup, r.unitsNeeded, r.status as reqStatus,
            d_p.name as patientName, d_u.name as donorName
     FROM donor_matches dm
     JOIN blood_requests r ON r.requestId = dm.requestId
     JOIN patients p ON p.patientId = r.patientId
     JOIN users d_p ON d_p.userId = p.userId
     JOIN donors d ON d.donorId = dm.donorId
     JOIN users d_u ON d_u.userId = d.userId
     ORDER BY dm.matchedAt DESC LIMIT 20"
)->fetchAll();

$allVerifications = $pdo->query(
    "SELECT v.verificationId, v.status, v.remarks, v.verifiedOn,
            u_donor.name as donorName, d.bloodGroup,
            u_lab.name as labName, dl.affiliation
     FROM verifications v
     JOIN donor_reports dr ON dr.reportId = v.reportId
     JOIN donors d ON d.donorId = dr.donorId
     JOIN users u_donor ON u_donor.userId = d.userId
     JOIN doctors_labs dl ON dl.doctorId = v.doctorId
     JOIN users u_lab ON u_lab.userId = dl.userId
     ORDER BY v.verifiedOn DESC"
)->fetchAll();

$pageTitle = 'Admin Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<h1>Admin Dashboard</h1>

<section class="card stats-grid">
  <div class="stat-card"><span class="stat-icon">🩸</span><strong><?= (int) $counts['patients'] ?></strong><span>Patients</span></div>
  <div class="stat-card"><span class="stat-icon">❤️</span><strong><?= (int) $counts['donors'] ?></strong><span>Donors</span></div>
  <div class="stat-card"><span class="stat-icon">🏥</span><strong><?= (int) $counts['labs'] ?></strong><span>Doctors/Labs</span></div>
  <div class="stat-card"><span class="stat-icon">🚨</span><strong><?= (int) $counts['activeReqs'] ?></strong><span>Active Requests</span></div>
  <div class="stat-card"><span class="stat-icon">✅</span><strong><?= (int) $counts['verifiedDonors'] ?></strong><span>Verified Donors</span></div>
  <div class="stat-card"><span class="stat-icon">📄</span><strong><?= (int) $counts['totalReports'] ?></strong><span>Reports</span></div>
  <div class="stat-card"><span class="stat-icon">⏳</span><strong><?= (int) $counts['pendingVerifs'] ?></strong><span>Pending Verifications</span></div>
  <div class="stat-card"><span class="stat-icon">⏰</span><strong><?= (int) $counts['pendingLabs'] ?></strong><span>Labs Awaiting Approval</span></div>
</section>

<section class="card">
  <h2>Lab registrations awaiting approval</h2>
  <?php if (!$pendingLabs): ?>
    <div class="empty-state">
      <span class="empty-icon">📋</span>
      <p>No pending lab registrations.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr><th>Name</th><th>Email</th><th>License No</th><th>State Council</th><th>Specialty</th><th>Affiliation</th><th>Verification Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($pendingLabs as $lab): ?>
          <tr>
            <td><?= h($lab['name']) ?></td>
            <td><?= h($lab['email']) ?></td>
            <td><?= h($lab['labLicenseNo']) ?></td>
            <td><?= h($lab['license_state_council'] ?? '-') ?></td>
            <td><?= h($lab['specialty'] ?? '-') ?></td>
            <td><?= h($lab['affiliation']) ?></td>
            <td>
              <?php $vs = $lab['verification_status'] ?? 'pending_verification'; ?>
              <span class="badge <?= $vs === 'approved' ? 'badge-ok' : 'badge-wait' ?>">
                <?= h(ucwords(str_replace('_', ' ', $vs))) ?>
              </span>
            </td>
            <td>
              <a href="/thalassemia/admpanel/verify_license.php?doctorId=<?= (int) $lab['doctorId'] ?>" class="btn-primary btn-sm">Verify License</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>All Doctors / Labs</h2>
  <?php if (!$allLabs): ?>
    <div class="empty-state"><span class="empty-icon">🏥</span><p>No doctors or labs registered.</p></div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>License No</th><th>State Council</th><th>Specialty</th><th>Affiliation</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($allLabs as $lab): ?>
          <tr>
            <td data-label="Name"><?= h($lab['name']) ?></td>
            <td data-label="Email"><?= h($lab['email']) ?></td>
            <td data-label="Phone"><?= h($lab['phone'] ?? '-') ?></td>
            <td data-label="License No"><?= h($lab['labLicenseNo']) ?></td>
            <td data-label="State Council"><?= h($lab['license_state_council'] ?? '-') ?></td>
            <td data-label="Specialty"><?= h($lab['specialty'] ?? '-') ?></td>
            <td data-label="Affiliation"><?= h($lab['affiliation']) ?></td>
            <td data-label="Status">
              <?php if ($lab['approved']): ?>
                <span class="badge badge-ok">Approved</span>
              <?php else: ?>
                <?php $vs = $lab['verification_status'] ?? 'pending_verification'; ?>
                <span class="badge badge-wait"><?= h(ucwords(str_replace('_', ' ', $vs))) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>All Donors & Their Reports</h2>
  <?php if (!$allDonors): ?>
    <div class="empty-state"><span class="empty-icon">🤝</span><p>No donors registered yet.</p></div>
  <?php else: ?>
    <div class="filter-bar">
      <input type="text" id="donorSearch" placeholder="🔍 Search by name, email, phone..." oninput="filterDonors()">
      <select id="donorBloodGroup" onchange="filterDonors()">
        <option value="">All Blood Groups</option>
        <option value="O-">O-</option><option value="O+">O+</option>
        <option value="A-">A-</option><option value="A+">A+</option>
        <option value="B-">B-</option><option value="B+">B+</option>
        <option value="AB-">AB-</option><option value="AB+">AB+</option>
      </select>
      <select id="donorVerified" onchange="filterDonors()">
        <option value="">All Donors</option>
        <option value="verified">✅ Verified Only</option>
        <option value="pending">⏳ Pending Only</option>
      </select>
    </div>
    <div class="table-wrap">
    <table class="data-table" id="donorsTable">
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Blood Group</th><th>City</th><th>Verified</th><th>Reports</th><th>Last Verification</th><th>View Report</th></tr></thead>
      <tbody>
        <?php foreach ($allDonors as $d): ?>
          <tr data-name="<?= h(strtolower($d['name'] . ' ' . $d['email'] . ' ' . ($d['phone'] ?? ''))) ?>"
              data-blood="<?= h($d['bloodGroup']) ?>"
              data-verified="<?= $d['verified'] ? 'verified' : 'pending' ?>">
            <td data-label="Name"><?= h($d['name']) ?></td>
            <td data-label="Email"><?= h($d['email']) ?></td>
            <td data-label="Phone"><?= h($d['phone'] ?? '-') ?></td>
            <td data-label="Blood Group"><?= h($d['bloodGroup']) ?></td>
            <td data-label="City"><?= h($d['location'] ?? '-') ?></td>
            <td data-label="Verified"><?= $d['verified'] ? '<span class="badge badge-ok">Verified</span>' : '<span class="badge badge-wait">Pending</span>' ?></td>
            <td data-label="Reports"><?= (int) $d['reportCount'] ?></td>
            <td data-label="Last Verification"><?= h($d['verifyStatus'] ? ucfirst($d['verifyStatus']) : '-') ?></td>
            <td data-label="View Report">
              <?php if (isset($d['latestReportId'])): ?>
                <a href="/thalassemia/doctor_lab/view_report.php?reportId=<?= (int) $d['latestReportId'] ?>" target="_blank">View</a>
              <?php else: ?>
                <span class="muted">No report</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="muted" id="donorCount" style="margin-top:8px;font-size:0.78rem;"></p>
    <script>
    function filterDonors(){
      var search=document.getElementById('donorSearch').value.toLowerCase();
      var bg=document.getElementById('donorBloodGroup').value;
      var ver=document.getElementById('donorVerified').value;
      var rows=document.querySelectorAll('#donorsTable tbody tr');
      var visible=0;
      rows.forEach(function(r){
        var name=(r.getAttribute('data-name')||'').toLowerCase();
        var blood=r.getAttribute('data-blood')||'';
        var verified=r.getAttribute('data-verified')||'';
        var show=true;
        if(search && name.indexOf(search)===-1) show=false;
        if(bg && blood!==bg) show=false;
        if(ver && verified!==ver) show=false;
        r.style.display=show?'table-row':'none';
        if(show) visible++;
      });
      document.getElementById('donorCount').textContent='Showing '+visible+' of '+rows.length+' donors';
    }
    filterDonors();
    </script>
  <?php endif; ?>
</section>

<section class="card">
  <h2>All Patients</h2>
  <?php if (!$allPatients): ?>
    <div class="empty-state"><span class="empty-icon">👥</span><p>No patients registered yet.</p></div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Blood Group</th><th>Thalassemia Type</th><th>City</th><th>Requests</th><th>Last Request Status</th></tr></thead>
      <tbody>
        <?php foreach ($allPatients as $p): ?>
          <tr>
            <td data-label="Name"><?= h($p['name']) ?></td>
            <td data-label="Email"><?= h($p['email']) ?></td>
            <td data-label="Phone"><?= h($p['phone'] ?? '-') ?></td>
            <td data-label="Blood Group"><?= h($p['bloodGroup']) ?></td>
            <td data-label="Thalassemia Type"><?= h($p['thalassemiaType'] ?? '-') ?></td>
            <td data-label="City"><?= h($p['location'] ?? '-') ?></td>
            <td data-label="Requests"><?= (int) $p['requestCount'] ?></td>
            <td data-label="Last Request"><?= h($p['lastReqStatus'] ? ucfirst($p['lastReqStatus']) : '-') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Recent blood requests</h2>
  <?php if (!$recentRequests): ?>
    <div class="empty-state"><span class="empty-icon">🩸</span><p>No blood requests yet.</p></div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Patient</th><th>Blood Group</th><th>Units</th><th>Status</th><th>Raised</th></tr></thead>
      <tbody>
        <?php foreach ($recentRequests as $r): ?>
          <tr>
            <td data-label="Patient"><?= h($r['patientName']) ?></td>
            <td data-label="Blood Group"><?= h($r['bloodGroup']) ?></td>
            <td data-label="Units"><?= (int) $r['unitsNeeded'] ?></td>
            <td data-label="Status"><?= h(ucfirst($r['status'])) ?></td>
            <td data-label="Raised"><?= h($r['createdAt']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Donor matches</h2>
  <?php if (!$allMatches): ?>
    <div class="empty-state"><span class="empty-icon">🔗</span><p>No matches created yet.</p></div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Patient</th><th>Donor</th><th>Blood Group</th><th>Units</th><th>Match Status</th><th>Contact Revealed</th><th>Matched At</th></tr></thead>
      <tbody>
        <?php foreach ($allMatches as $m): ?>
          <tr>
            <td data-label="Patient"><?= h($m['patientName']) ?></td>
            <td data-label="Donor"><?= h($m['donorName']) ?></td>
            <td data-label="Blood Group"><?= h($m['bloodGroup']) ?></td>
            <td data-label="Units"><?= (int) $m['unitsNeeded'] ?></td>
            <td data-label="Status"><?= h(ucfirst($m['status'])) ?></td>
            <td data-label="Contact"><?= $m['contactRevealed'] ? 'Yes' : 'No' ?></td>
            <td data-label="Matched At"><?= h($m['matchedAt']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Report verifications by doctors/labs</h2>
  <?php if (!$allVerifications): ?>
    <div class="empty-state"><span class="empty-icon">🔬</span><p>No verifications done yet.</p></div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Donor</th><th>Blood Group</th><th>Verified By</th><th>Affiliation</th><th>Status</th><th>Remarks</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($allVerifications as $v): ?>
          <tr>
            <td data-label="Donor"><?= h($v['donorName']) ?></td>
            <td data-label="Blood Group"><?= h($v['bloodGroup']) ?></td>
            <td data-label="Verified By"><?= h($v['labName']) ?></td>
            <td data-label="Affiliation"><?= h($v['affiliation']) ?></td>
            <td data-label="Status"><?= h(ucfirst($v['status'])) ?></td>
            <td data-label="Remarks"><?= h($v['remarks'] ?: '-') ?></td>
            <td data-label="Date"><?= h($v['verifiedOn']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>

<p><a href="/thalassemia/admpanel/manage_users.php" class="btn-secondary">Manage users &rarr;</a></p>

<?php require __DIR__ . '/../includes/footer.php'; ?>
