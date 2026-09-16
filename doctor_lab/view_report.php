<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['doctor_lab', 'admin']);
$reportId = (int) ($_GET['reportId'] ?? 0);
if (!$reportId) { http_response_code(400); exit('Missing report ID.'); }
$stmt = $pdo->prepare('SELECT fileUrl FROM donor_reports WHERE reportId = ?');
$stmt->execute([$reportId]);
$report = $stmt->fetch();
if (!$report) { http_response_code(404); exit('Report not found in database.'); }
$filename = basename($report['fileUrl']);
$projectRoot = dirname(__DIR__);
$candidates = [
    $projectRoot . '/uploads/reports/' . $filename,
    $projectRoot . str_replace('/thalassamia/', '/', $report['fileUrl']),
    $projectRoot . $report['fileUrl'],
];
$filePath = null;
foreach ($candidates as $candidate) {
    if (file_exists($candidate) && is_readable($candidate)) { $filePath = $candidate; break; }
}
if ($filePath === null) { http_response_code(404); exit('Report file not found on server. Filename: ' . $filename); }
$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeTypes = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];
$mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($filePath);
exit;
