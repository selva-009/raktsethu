<?php
/**
 * HTTP-triggerable version of the 48-hour auto-expiry sweep, for hosts that
 * don't allow scheduled CLI tasks (e.g. most free hosts).
 *
 * SECURITY-HARDENED VERSION:
 *   1. TRIGGER_TOKEN below is set to a long random value. CHANGE IT to your
 *      own before uploading — generate one at https://randomkeygen.com
 *      (64+ character hex string) or run:
 *        php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 *   2. After changing the token, update your cron-job.org scheduled URL to:
 *        https://yoursite.com/thalassemia/cron/expire_matches_http.php?token=YOUR_NEW_TOKEN
 */

// CHANGE THIS — generate your own 64-character random string before uploading.
define('TRIGGER_TOKEN', '72952346664e28c54354db767a6975d91077c64a5b944655');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../engine/matching_engine.php';

header('Content-Type: text/plain; charset=utf-8');

$token = $_GET['token'] ?? '';
if (!hash_equals(TRIGGER_TOKEN, $token)) {
    http_response_code(403);
    die('Forbidden.');
}

$count = expireOverdueMatches($pdo);
echo date('Y-m-d H:i:s') . " — expired {$count} overdue match(es) and reassigned where possible.\n";
