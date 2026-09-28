<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);
$BASE = "http://localhost/lake-shore-id-editor/backend/api.php";

// Create a test user first
$db = new PDO("mysql:host=localhost;dbname=lake_shore_id_system;charset=utf8mb4", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("DELETE FROM users WHERE username = "test_tpl"");
$db->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES("test_tpl",?,"Test","staff",1)")->execute([password_hash("Test#2026", PASSWORD_DEFAULT)]);

// Login
$ch = curl_init($BASE . "?action=login");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => json_encode(["username" => "test_tpl", "password" => "Test#2026"]),
  CURLOPT_HTTPHEADER => ["Content-Type: application/json", "User-Agent: Test/1.0"],
  CURLOPT_HEADER => true,
  CURLOPT_TIMEOUT => 30,
]);
$raw = curl_exec($ch);
$hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headerBlock = substr($raw, 0, $hsize);
$body = substr($raw, $hsize);
curl_close($ch);

echo "Login status: " . curl_getinfo($ch, CURLINFO_RESPONSE_CODE) . PHP_EOL;

// Extract cookie
preg_match_all("/^Set-Cookie:\\s*([^;]+)/mi", $headerBlock, $m);
$cookie = "";
foreach ($m[1] as $c) { if (strpos($c, "LSC_ID_SESSION") === 0) $cookie = $c; }
echo "Cookie: $cookie" . PHP_EOL;

if ($cookie !== "") {
  $ch = curl_init($BASE . "?action=importTemplate");
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["User-Agent: Test/1.0", "Cookie: " . $cookie],
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 30,
  ]);
  $raw = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $headerBlock = substr($raw, 0, $hsize);
  $body = substr($raw, $hsize);
  curl_close($ch);
  echo "Template status: $status" . PHP_EOL;
  echo "Headers: " . $headerBlock . PHP_EOL;
  echo "Body: " . substr($body, 0, 500) . PHP_EOL;
}

// Cleanup
$db->exec("DELETE FROM users WHERE username = "test_tpl"");
