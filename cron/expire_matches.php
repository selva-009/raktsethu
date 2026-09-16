<?php
/**
 * Scheduled sweep for the 48-hour auto-expiry rule (Chapter 3, business rules).
 * Run periodically (e.g. every 10–15 minutes) via Windows Task Scheduler / cron
 * pointed at: php C:\xampp\htdocs\thalassemia\cron\expire_matches.php
 *
 * Not meant to be hit over HTTP — run from the command line only.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../engine/matching_engine.php';

$count = expireOverdueMatches($pdo);
echo date('Y-m-d H:i:s') . " — expired {$count} overdue match(es) and reassigned where possible.\n";
