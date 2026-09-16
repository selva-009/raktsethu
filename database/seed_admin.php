<?php
/**
 * One-time CLI script to create the first admin account.
 * Run from the command line (not the browser) after importing schema.sql:
 *
 *      php seed_admin.php
 *
 * It prompts for a name, email, and password, hashes the password with
 * PHP's own password_hash() (bcrypt), and inserts it into users.
 * This avoids ever hardcoding a password hash in the repo or SQL file.
 */

if (php_sapi_name() !== 'cli') {
    die('Run this script from the command line: php seed_admin.php');
}

require __DIR__ . '/../config/db.php';

function prompt(string $label): string {
    echo $label;
    return trim(fgets(STDIN));
}

$name  = prompt('Admin name: ');
$email = prompt('Admin email: ');
$pass  = prompt('Admin password: ');

if (strlen($pass) < 8) {
    die("Password must be at least 8 characters.\n");
}

$hash = password_hash($pass, PASSWORD_BCRYPT);

$stmt = $pdo->prepare(
    'INSERT INTO users (name, email, passwordHash, role) VALUES (?, ?, ?, "admin")'
);

try {
    $stmt->execute([$name, $email, $hash]);
    echo "Admin account created for {$email}.\n";
} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        die("An account with that email already exists.\n");
    }
    throw $e;
}
