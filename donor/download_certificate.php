<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole(['donor']);

$donorStmt = $pdo->prepare('SELECT d.*, u.name, u.email FROM donors d JOIN users u ON u.userId = d.userId WHERE d.userId = ?');
$donorStmt->execute([$_SESSION['userId']]);
$donor = $donorStmt->fetch();

// Get verified donations
$historyStmt = $pdo->prepare(
    'SELECT * FROM donation_history WHERE donorId = ? AND verified = 1 ORDER BY donationDate DESC'
);
$historyStmt->execute([$donor['donorId']]);
$verifiedDonations = $historyStmt->fetchAll();

$totalDonations = count($verifiedDonations);
$totalUnits = array_sum(array_map(fn($d) => (int)$d['units'], $verifiedDonations));

if ($totalDonations === 0) {
    setFlash('error', 'No verified donations yet. You need at least one verified donation to download a certificate.');
    redirect('/thalassemia/donor/dashboard.php');
}

$donorName = $donor['name'];
$bloodGroup = $donor['bloodGroup'];
$dateStr = date('F j, Y');

// Generate PDF using FPDF-compatible approach (inline PHP, no library needed)
// We'll output an HTML page that can be printed as PDF by the browser
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Donor Certificate — <?= h($donorName) ?></title>
<style>
  @page { size: A4 landscape; margin: 0; }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: Georgia, 'Times New Roman', serif; }
  .certificate {
    width: 100%; min-height: 100vh;
    background: linear-gradient(135deg, #fdf8f0 0%, #f5ede0 50%, #fdf8f0 100%);
    border: 3px double #d4af37;
    padding: 60px 50px;
    display: flex; flex-direction: column; align-items: center;
    position: relative;
  }
  .corner { position: absolute; width: 60px; height: 60px; border: 2px solid #d4af37; }
  .corner-tl { top: 20px; left: 20px; border-right: none; border-bottom: none; }
  .corner-tr { top: 20px; right: 20px; border-left: none; border-bottom: none; }
  .corner-bl { bottom: 20px; left: 20px; border-right: none; border-top: none; }
  .corner-br { bottom: 20px; right: 20px; border-left: none; border-top: none; }
  .seal { width: 80px; height: 80px; margin: 0 auto 16px; }
  .org { font-size: 14px; letter-spacing: 4px; text-transform: uppercase; color: #8b2020; font-weight: bold; }
  .title { font-size: 32px; color: #6b1d1d; margin: 16px 0 8px; font-weight: bold; }
  .subtitle { font-size: 16px; color: #8a6a6a; font-style: italic; margin-bottom: 32px; }
  .recipient { font-size: 28px; color: #1a0606; border-bottom: 2px solid #d4af37; padding-bottom: 8px; margin-bottom: 24px; font-weight: bold; }
  .body-text { font-size: 15px; color: #2d1b1b; line-height: 1.8; text-align: center; max-width: 600px; margin: 0 auto 24px; }
  .body-text strong { color: #8b2020; }
  .stats { display: flex; gap: 40px; justify-content: center; margin: 24px 0; }
  .stat { text-align: center; }
  .stat-num { font-size: 28px; color: #d4af37; font-weight: bold; }
  .stat-label { font-size: 11px; color: #8a6a6a; text-transform: uppercase; letter-spacing: 1px; }
  .date-section { margin-top: 32px; font-size: 13px; color: #8a6a6a; text-align: center; }
  .signature { margin-top: 40px; text-align: center; }
  .sig-line { width: 200px; border-top: 1px solid #8a6a6a; margin: 40px auto 8px; }
  .sig-name { font-size: 13px; color: #2d1b1b; font-weight: bold; }
  .sig-title { font-size: 11px; color: #8a6a6a; }
  .print-btn { position: fixed; top: 16px; right: 16px; padding: 10px 20px; background: #6b1d1d; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; z-index: 999; }
  @media print { .print-btn { display: none; } .certificate { page-break-after: avoid; } }
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
<div class="certificate">
  <div class="corner corner-tl"></div>
  <div class="corner corner-tr"></div>
  <div class="corner corner-bl"></div>
  <div class="corner corner-br"></div>

  <svg class="seal" viewBox="0 0 100 100" fill="none" stroke="#d4af37" stroke-width="2">
    <circle cx="50" cy="50" r="45" stroke-width="1.5"/>
    <circle cx="50" cy="50" r="38" stroke-width="1"/>
    <path d="M50 20 C50 20, 65 35, 65 48 C65 55, 58 60, 50 60 C42 60, 35 55, 35 48 C35 35, 50 20, 50 20Z" fill="#8b2020" stroke="#8b2020"/>
    <text x="50" y="85" text-anchor="middle" font-size="8" fill="#d4af37" font-family="serif">RAKTSETHU</text>
  </svg>

  <div class="org">RaktSethu — Thalassemia Blood Support System</div>
  <div class="title">Certificate of Appreciation</div>
  <div class="subtitle">This certificate is proudly presented to</div>
  <div class="recipient"><?= h($donorName) ?></div>

  <div class="body-text">
    In recognition of their selfless contribution to the Thalassemia community.
    Your verified blood donations have helped save lives and brought hope to those in need.
    You donated <strong><?= h($bloodGroup) ?></strong> blood, totaling <strong><?= $totalUnits ?> unit(s)</strong>
    across <strong><?= $totalDonations ?> verified donation(s)</strong>.
  </div>

  <div class="stats">
    <div class="stat">
      <div class="stat-num"><?= $totalDonations ?></div>
      <div class="stat-label">Verified Donations</div>
    </div>
    <div class="stat">
      <div class="stat-num"><?= $totalUnits ?></div>
      <div class="stat-label">Units Donated</div>
    </div>
    <div class="stat">
      <div class="stat-num"><?= h($bloodGroup) ?></div>
      <div class="stat-label">Blood Group</div>
    </div>
  </div>

  <div class="date-section">Issued on <?= h($dateStr) ?></div>

  <div class="signature">
    <div class="sig-line"></div>
    <div class="sig-name">RaktSethu Admin</div>
    <div class="sig-title">Thalassemia Blood Support System</div>
  </div>
</div>
</body>
</html>
