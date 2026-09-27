<?php
/**
 * HTTP-triggerable version of the 48-hour auto-expiry sweep, for hosts that
 * don't allow scheduled CLI tasks (e.g. most free hosts).
 *
 * SECURITY: the trigger token lives in config/cron_token.php (git-ignored) or
 * in the CRON_TRIGGER_TOKEN environment variable — never in this file.
 * Schedule cron-job.org to hit:
 *   https://yoursite.com/cron/expire_matches_http.php?token=YOUR_TOKEN
 */

// SECURITY: the trigger token is loaded from config/cron_token.php, which is
// git-ignored, so it never enters version control. To set it up:
//   1. Copy config/cron_token.php.example -> config/cron_token.php
//   2. Paste a 64-char random string inside it
//   3. Point cron-job.org at: https://yoursite.com[/folder]/cron/expire_matches_http.php?token=YOUR_TOKEN
// (Alternative: set a CRON_TRIGGER_TOKEN environment variable on the server.)
$raktsethuCronTokenFile = __DIR__ . '/../config/cron_token.php';
$raktsethuCronToken = is_file($raktsethuCronTokenFile) ? (require $raktsethuCronTokenFile) : '';
if (!is_string($raktsethuCronToken)) {
    $raktsethuCronToken = '';
}
define('TRIGGER_TOKEN', $raktsethuCronToken !== '' ? $raktsethuCronToken : (getenv('CRON_TRIGGER_TOKEN') ?: ''));

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../engine/matching_engine.php';

header('Content-Type: text/plain; charset=utf-8');

if (TRIGGER_TOKEN === '') {
    http_response_code(503);
    die('Cron trigger token is not configured. Copy config/cron_token.php.example to config/cron_token.php and set a random token.');
}

$token = $_GET['token'] ?? '';
if (!hash_equals(TRIGGER_TOKEN, $token)) {
    http_response_code(403);
    die('Forbidden.');
}

$count = expireOverdueMatches($pdo);
echo date('Y-m-d H:i:s') . " — expired {$count} overdue match(es) and reassigned where possible.\n";