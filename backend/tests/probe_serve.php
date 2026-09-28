<?php
// Temporary probe: create QA user, login, test photoLibraryServe, cleanup.
// Fixed 2026-09-15: use a FRESH curl handle per request. Reusing one handle
// and clearing CURLOPT_POSTFIELDS re-enables POST (libcurl quirk), so the
// serve request went out as POST -> "Unknown action". Also sets
// CURLOPT_COOKIEFILE so the session cookie is actually sent.
function serve_req($url, $jar, $post = null) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
  if ($post !== null) {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
  }
  $body = curl_exec($ch);
  $info = curl_getinfo($ch);
  return [$body, $info];
}
$pdo = new PDO('mysql:host=127.0.0.1;dbname=lake_shore_id_system;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DELETE FROM users WHERE username='qa_serve_probe'");
$pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_serve_probe',?,'QA Serve Probe','admin',1)")
    ->execute([password_hash('Probe#2026x', PASSWORD_DEFAULT)]);
$jar = __DIR__ . '/serve_probe_cookie.txt';
@unlink($jar);
[$login] = serve_req('http://localhost/lake-shore-id-editor/backend/api.php?action=login', $jar, ['username' => 'qa_serve_probe', 'password' => 'Probe#2026x']);
echo "LOGIN: " . substr((string)$login, 0, 120) . "\n";
$pid = $pdo->query("SELECT id FROM student_photos ORDER BY id DESC LIMIT 1")->fetchColumn();
echo "PHOTO_ID: $pid\n";
[$img, $info] = serve_req("http://localhost/lake-shore-id-editor/backend/api.php?action=photoLibraryServe&id=$pid", $jar);
echo "BODY: " . substr((string)$img, 0, 60) . "\n";
echo "SERVE: status=" . $info['http_code'] . " type=" . $info['content_type'] . " bytes=" . strlen((string)$img) . "\n";
echo "FIRST_BYTES: " . bin2hex(substr((string)$img, 0, 8)) . "\n";
$pdo->exec("DELETE FROM users WHERE username='qa_serve_probe'");
@unlink($jar);
echo "CLEANED\n";
