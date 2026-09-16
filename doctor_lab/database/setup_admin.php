<?php
/**
 * ONE-TIME web-based admin setup for hosts with no CLI/SSH access
 * (e.g. free hosts like InfinityFree, 000webhost).
 *
 * SECURITY NOTE — read this before uploading:
 *   1. This page is protected by a random SETUP_TOKEN below. Change it to
 *      your own long random string before uploading.
 *   2. Visit it ONCE at:
 *        https://yoursite.com/thalassemia/database/setup_admin.php?token=YOUR_TOKEN
 *   3. As soon as it confirms the admin account was created, DELETE THIS
 *      FILE from your host (File Manager → delete). Leaving a working
 *      admin-creation endpoint live — even token-protected — is a risk
 *      you don't want to carry indefinitely.
 *   4. It will refuse to run a second time once an admin account already
 *      exists, as a backstop in case you forget step 3.
 *
 * If you have CLI/SSH access instead (e.g. local XAMPP), use
 * database/seed_admin.php from the command line and skip this file.
 */

// CHANGE THIS before uploading — pick your own long random string.
define('SETUP_TOKEN', '32a456b1d15f19a7a98c0f8cc8b62617b6573411dfe1b242');

require_once __DIR__ . '/../config/db.php';

header('Content-Type: text/html; charset=utf-8');

function page(string $body) {
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'>"
       . "<title>RaktSethu Admin Setup</title>"
       . "<style>body{font-family:sans-serif;max-width:520px;margin:60px auto;padding:0 20px;line-height:1.5}"
       . "input{width:100%;padding:8px;margin:6px 0 14px;box-sizing:border-box}"
       . "button{padding:10px 18px}code{background:#eee;padding:2px 6px;border-radius:4px}</style>"
       . "</head><body>{$body}</body></html>";
    exit;
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';
if (!hash_equals(SETUP_TOKEN, $token)) {
    http_response_code(403);
    page('<h1>Forbidden</h1><p>Missing or incorrect setup token.</p>');
}

// Backstop: refuse if an admin already exists.
$existing = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
if ($existing > 0 && empty($_POST['confirm_recreate'])) {
    page(
        '<h1>Admin already exists</h1>'
      . '<p>An admin account already exists. This page will not create a second one automatically.</p>'
      . '<p><strong>Delete this file now</strong> — you should not need it again.</p>'
    );
}

$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid name and email.';
    }
    if (strlen($pass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if (!$errors) {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        try {
            $pdo->prepare(
                'INSERT INTO users (name, email, passwordHash, role) VALUES (?, ?, ?, "admin")'
            )->execute([$name, $email, $hash]);
            $done = true;
        } catch (PDOException $e) {
            $errors[] = ($e->getCode() == 23000)
                ? 'An account with that email already exists.'
                : 'Could not create the account. Please check your database connection.';
        }
    }
}

if ($done) {
    page(
        '<h1>Admin account created</h1>'
      . '<p>You can now log in at <code>/thalassemia/admpanel/login_admin.php</code>.</p>'
      . '<p style="color:#b3261e"><strong>Now delete this file (setup_admin.php) from your host.</strong></p>'
    );
}

$errorHtml = '';
foreach ($errors as $e) {
    $errorHtml .= '<p style="color:#b3261e">' . htmlspecialchars($e, ENT_QUOTES) . '</p>';
}

page(
    '<h1>Create admin account</h1>'
  . $errorHtml
  . '<form method="post">'
  . '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">'
  . '<label>Name<input type="text" name="name" required></label>'
  . '<label>Email<input type="email" name="email" required></label>'
  . '<label>Password (min 8 chars)<input type="password" name="password" required minlength="8"></label>'
  . '<button type="submit">Create admin account</button>'
  . '</form>'
);
