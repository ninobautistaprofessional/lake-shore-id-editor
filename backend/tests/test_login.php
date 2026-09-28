<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php.php';
require __DIR__ . '/../import.php';
require __DIR__ . '/../audit.php';

$pdo = db();
echo "DB OK\n";

// Test login
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = '';
file_put_contents('php://input', json_encode(['username' => 'qa_import_staff', 'password' => 'QaImp#2026']));

// Simulate the login action
$username = 'qa_import_staff';
$password = 'QaImp#2026';
$stmt = $pdo->prepare('SELECT id,username,password,full_name,role,is_active FROM users WHERE BINARY username=? LIMIT 1');
$stmt->execute([$username]);
$user = $stmt->fetch();
if ($user) {
    echo "User found: " . $user['username'] . "\n";
    echo "Password verify: " . (password_verify($password, $user['password']) ? 'yes' : 'no') . "\n";
} else {
    echo "User not found\n";
}
