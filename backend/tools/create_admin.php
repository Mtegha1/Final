<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/validator.php';

$name = trim((string)getenv('IREA_ADMIN_NAME'));
$email = trim((string)getenv('IREA_ADMIN_EMAIL'));
$password = (string)getenv('IREA_ADMIN_PASSWORD');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    fwrite(STDERR, "Set IREA_ADMIN_NAME, IREA_ADMIN_EMAIL, and IREA_ADMIN_PASSWORD in the local process environment.\n");
    exit(1);
}
if (strlen($password) < 12) {
    fwrite(STDERR, "The administrator password must be at least 12 characters.\n");
    exit(1);
}
$passwordErrors = Validator::validatePassword($password);
if ($passwordErrors) {
    fwrite(STDERR, implode(PHP_EOL, $passwordErrors) . PHP_EOL);
    exit(1);
}

try {
    $db = Database::getInstance()->conn;
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $userId = $stmt->fetchColumn();
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($userId) {
        $update = $db->prepare('UPDATE users SET full_name = ?, password = ?, role = ? WHERE id = ?');
        $update->execute([$name, $passwordHash, 'admin', $userId]);
        echo "Administrator account updated.\n";
    } else {
        $insert = $db->prepare('INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)');
        $insert->execute([$name, $email, $passwordHash, 'admin']);
        echo "Administrator account created.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Administrator account setup failed: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (function_exists('sodium_memzero') && isset($password)) {
        sodium_memzero($password);
    }
    unset($passwordHash);
}
