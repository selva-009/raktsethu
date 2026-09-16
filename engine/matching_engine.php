<?php
require_once __DIR__ . '/../includes/functions.php';

function compatibleDonorGroups(string $bloodGroup): array {
    $map = [
        'O-'  => ['O-'], 'O+'  => ['O-', 'O+'],
        'A-'  => ['O-', 'A-'], 'A+'  => ['O-', 'O+', 'A-', 'A+'],
        'B-'  => ['O-', 'B-'], 'B+'  => ['O-', 'O+', 'B-', 'B+'],
        'AB-' => ['O-', 'A-', 'B-', 'AB-'],
        'AB+' => ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'],
    ];
    return $map[$bloodGroup] ?? [$bloodGroup];
}

function raiseUrgentRequest(PDO $pdo, int $patientId, string $bloodGroup, int $unitsNeeded): int {
    $existing = $pdo->prepare("SELECT requestId FROM blood_requests WHERE patientId = ? AND status IN ('pending','matched')");
    $existing->execute([$patientId]);
    if ($existing->fetch()) {
        throw new RuntimeException('You already have an active urgent request. Only one active request is allowed at a time.');
    }

    // Priority check: has this patient ever donated blood? (matched by email/phone)
    $isPriority = 0;
    $patientUserStmt = $pdo->prepare(
        'SELECT u.email, u.phone FROM users u JOIN patients p ON p.userId = u.userId WHERE p.patientId = ?'
    );
    $patientUserStmt->execute([$patientId]);
    $patientUser = $patientUserStmt->fetch();
    if ($patientUser) {
        $donorCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM donors d JOIN users u ON u.userId = d.userId
             WHERE (u.email = ? OR (u.phone IS NOT NULL AND u.phone = ?))
             AND (SELECT COUNT(*) FROM donation_history dh WHERE dh.donorId = d.donorId) > 0'
        );
        $donorCheck->execute([$patientUser['email'], $patientUser['phone'] ?? '']);
        if ((int) $donorCheck->fetchColumn() > 0) {
            $isPriority = 1;
        }
    }

    $stmt = $pdo->prepare('INSERT INTO blood_requests (patientId, bloodGroup, unitsNeeded, status, isPriority, expiresAt) VALUES (?, ?, ?, "pending", ?, DATE_ADD(NOW(), INTERVAL 7 DAY))');
    $stmt->execute([$patientId, $bloodGroup, $unitsNeeded, $isPriority]);
    $requestId = (int) $pdo->lastInsertId();
    findAndNotifyNextDonor($pdo, $requestId);
    return $requestId;
}

function findAndNotifyNextDonor(PDO $pdo, int $requestId): ?int {
    $reqStmt = $pdo->prepare('SELECT * FROM blood_requests WHERE requestId = ?');
    $reqStmt->execute([$requestId]);
    $request = $reqStmt->fetch();
    if (!$request || $request['status'] === 'fulfilled') return null;

    $compatible = compatibleDonorGroups($request['bloodGroup']);
    $placeholders = implode(',', array_fill(0, count($compatible), '?'));

    // Only match donors who are available (isAvailable = 1) — respects donor toggle
    $sql = "SELECT d.donorId FROM donors d WHERE d.bloodGroup IN ($placeholders) AND d.verified = 1 AND d.isAvailable = 1 AND (d.eligibilityExpiry IS NULL OR d.eligibilityExpiry <= CURDATE()) AND d.donorId NOT IN (SELECT donorId FROM donor_matches WHERE requestId = ? AND status IN ('pending','accepted')) ORDER BY d.donorId ASC LIMIT 1";
    $params = array_merge($compatible, [$requestId]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $donor = $stmt->fetch();

    if (!$donor) {
        $sql2 = "SELECT d.donorId FROM donors d WHERE d.bloodGroup IN ($placeholders) AND d.isAvailable = 1 AND (d.eligibilityExpiry IS NULL OR d.eligibilityExpiry <= CURDATE()) AND d.donorId NOT IN (SELECT donorId FROM donor_matches WHERE requestId = ? AND status IN ('pending','accepted')) ORDER BY d.donorId ASC LIMIT 1";
        $params2 = array_merge($compatible, [$requestId]);
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute($params2);
        $donor = $stmt2->fetch();
    }

    if (!$donor) return null;

    $matchStmt = $pdo->prepare('INSERT INTO donor_matches (requestId, donorId, status, responseDeadline) VALUES (?, ?, "pending", DATE_ADD(NOW(), INTERVAL 48 HOUR))');
    $matchStmt->execute([$requestId, $donor['donorId']]);

    $donorUserStmt = $pdo->prepare('SELECT userId FROM donors WHERE donorId = ?');
    $donorUserStmt->execute([$donor['donorId']]);
    $donorUser = $donorUserStmt->fetch();
    notify($pdo, (int) $donorUser['userId'], "A patient needs {$request['bloodGroup']} blood urgently. Please respond within 48 hours.");
    return (int) $donor['donorId'];
}

function acceptMatch(PDO $pdo, int $matchId, int $donorId): void {
    $stmt = $pdo->prepare('SELECT * FROM donor_matches WHERE matchId = ? AND donorId = ?');
    $stmt->execute([$matchId, $donorId]);
    $match = $stmt->fetch();
    if (!$match || $match['status'] !== 'pending') throw new RuntimeException('This match is no longer available.');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE donor_matches SET status = "accepted", contactRevealed = 1 WHERE matchId = ?')->execute([$matchId]);
        $pdo->prepare('UPDATE blood_requests SET status = "matched" WHERE requestId = ?')->execute([$match['requestId']]);

        // Record in donation_history
        $reqStmt = $pdo->prepare('SELECT bloodGroup, unitsNeeded FROM blood_requests WHERE requestId = ?');
        $reqStmt->execute([$match['requestId']]);
        $req = $reqStmt->fetch();
        if ($req) {
            $pdo->prepare('INSERT INTO donation_history (donorId, bloodGroup, units, donationDate, verified) VALUES (?, ?, ?, CURDATE(), 0)')
                ->execute([$donorId, $req['bloodGroup'], $req['unitsNeeded']]);

            // Update donor's lastDonationDate + reset eligibility to 90 days from now
            $expiry = date('Y-m-d', strtotime('+90 days'));
            $pdo->prepare('UPDATE donors SET lastDonationDate = CURDATE(), eligibilityExpiry = ? WHERE donorId = ?')
                ->execute([$expiry, $donorId]);
        }

        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    $patientUserStmt = $pdo->prepare('SELECT u.userId FROM users u JOIN patients p ON p.userId = u.userId JOIN blood_requests r ON r.patientId = p.patientId WHERE r.requestId = ?');
    $patientUserStmt->execute([$match['requestId']]);
    $patientUser = $patientUserStmt->fetch();
    if ($patientUser) notify($pdo, (int) $patientUser['userId'], 'A donor has accepted your request. Contact details are now available on your dashboard.');
}

function declineMatch(PDO $pdo, int $matchId, int $donorId): void {
    $stmt = $pdo->prepare('SELECT * FROM donor_matches WHERE matchId = ? AND donorId = ?');
    $stmt->execute([$matchId, $donorId]);
    $match = $stmt->fetch();
    if (!$match || $match['status'] !== 'pending') throw new RuntimeException('This match is no longer available.');
    $pdo->prepare('UPDATE donor_matches SET status = "declined" WHERE matchId = ?')->execute([$matchId]);
    findAndNotifyNextDonor($pdo, (int) $match['requestId']);
}

function expireOverdueMatches(PDO $pdo): int {
    $stmt = $pdo->query("SELECT matchId, requestId FROM donor_matches WHERE status = 'pending' AND responseDeadline < NOW()");
    $expired = $stmt->fetchAll();
    foreach ($expired as $row) {
        $pdo->prepare('UPDATE donor_matches SET status = "expired" WHERE matchId = ?')->execute([$row['matchId']]);
        findAndNotifyNextDonor($pdo, (int) $row['requestId']);
    }
    return count($expired);
}
