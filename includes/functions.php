<?php
/**
 * Shared helper functions used across all four role areas.
 *
 * SECURITY-HARDENED VERSION — changes vs. previous release:
 *   1. Session cookies now HttpOnly + SameSite=Lax (blocks cookie theft via XSS
 *      and cross-site request contexts).
 *   2. verifyCsrf() no longer die()s with a raw message; it regenerates the
 *      token, flashes a friendly error and redirects back (fixes the
 *      "Invalid or expired form submission" white screen on shared hosting).
 *   3. Session lifetime extended to 1 hour so long registration forms survive
 *      on aggressive shared hosts.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie before starting the session
    session_set_cookie_params([
        'lifetime' => 3600,
        'path'     => '/',
        'httponly' => true,   // JavaScript cannot read the cookie (XSS protection)
        'samesite' => 'Lax',  // Cookie not sent on cross-site POSTs (CSRF protection)
        // 'secure'   => true // UNCOMMENT after enabling SSL/HTTPS on the host
    ]);
    session_start();
}

/** Redirect to $url and stop execution. */
function redirect(string $url): void {
    header("Location: $url");
    exit;
}

/** Require an active session with one of the given roles; otherwise redirect to login. */
function requireRole(array $allowedRoles): void {
    if (empty($_SESSION['userId']) || !in_array($_SESSION['role'], $allowedRoles, true)) {
        redirect('/thalassemia/auth/login.php');
    }
}

/** Escape output for safe HTML rendering. */
function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Store a one-time flash message in the session. */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** Retrieve and clear the flash message, if any. */
function getFlash(): ?array {
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Compute a donor's eligibility expiry date: lastDonationDate + 90 days.
 * Returns null if the donor has never donated (i.e. always eligible until their first donation).
 */
function computeEligibilityExpiry(?string $lastDonationDate): ?string {
    if (!$lastDonationDate) {
        return null;
    }
    $expiry = new DateTime($lastDonationDate);
    $expiry->modify('+90 days');
    return $expiry->format('Y-m-d');
}

/** Whether a donor is currently eligible to donate (90-day rule). */
function isDonorEligible(?string $eligibilityExpiry): bool {
    if (!$eligibilityExpiry) {
        return true; // never donated yet => eligible
    }
    return new DateTime($eligibilityExpiry) <= new DateTime('today');
}

/** Push a notification row for a user (in-app by default). */
function notify(PDO $pdo, int $userId, string $message, string $channel = 'in-app'): void {
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (userId, message, channel) VALUES (?, ?, ?)'
    );
    $stmt->execute([$userId, $message, $channel]);
}

/** CSRF token helpers (simple session-based token, checked on every POST form). */
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * Verify the CSRF token from a POST request.
 * On failure: regenerate token, flash a friendly message, redirect back —
 * the user never sees a raw die() screen again.
 */
function verifyCsrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        // Session may have expired (especially on shared hosting).
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        setFlash('error', 'Your session expired. Please try again.');

        $referer = $_SERVER['HTTP_REFERER'] ?? '/thalassemia/index.php';
        header("Location: $referer");
        exit;
    }
}
