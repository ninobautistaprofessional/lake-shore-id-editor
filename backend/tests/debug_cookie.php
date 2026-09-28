<?php
/* Debug: why does the session cookie not authenticate? */
require_once __DIR__ . '/../config.php';
$pdo = db();
$pdo->exec("DELETE FROM users WHERE username='qa_debug_user'");
$pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_debug_user',?,'Debug','staff',1)")
    ->execute([password_hash('Debug#2026x', PASSWORD_DEFAULT)]);

$BASE = 'http://localhost/lake-shore-id-editor/backend/api.php';
$ch = curl_init($BASE . '?action=login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['username' => 'qa_debug_user', 'password' => 'Debug#2026x']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_HEADER => true,
]);
$raw = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);
$headers = substr($raw, 0, $headerSize);
echo "LOGIN STATUS: $status\n--- headers ---\n$headers\n";
preg_match('/Set-Cookie:\s*(LSC_ID_SESSION=[^;]+)/i', $headers, $m);
$cookie = $m[1] ?? '(NOT FOUND)';
echo "EXTRACTED COOKIE: $cookie\n";

$ch = curl_init($BASE . '?action=me');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIE => $cookie,
]);
$raw2 = curl_exec($ch);
$status2 = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
echo "ME STATUS: $status2\nBODY: " . substr((string) $raw2, 0, 200) . "\n";
