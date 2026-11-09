<?php
require __DIR__ . "/../config.php";
$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ids = $pdo->query("SELECT id,student_name FROM id_cards WHERE student_name LIKE 'ZZFINAL%'")->fetchAll();
foreach ($ids as $r) echo "removing card ", $r["id"], " ", $r["student_name"], PHP_EOL;
$pdo->exec("DELETE FROM student_photos WHERE student_id IN (SELECT id FROM id_cards WHERE student_name LIKE 'ZZFINAL%')");
$pdo->exec("DELETE FROM id_cards WHERE student_name LIKE 'ZZFINAL%'");
$pdo->exec("DELETE FROM users WHERE username='zz_final_check'");
$pdo->exec("DELETE FROM audit_logs WHERE (new_value LIKE '%ZZFINAL%' OR old_value LIKE '%ZZFINAL%')");
echo "remaining ZZ cards: ", $pdo->query("SELECT COUNT(*) FROM id_cards WHERE student_name LIKE 'ZZ%'")->fetchColumn(), "\n";
echo "remaining zz users: ", $pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'zz_%'")->fetchColumn(), "\n";
echo "total cards: ", $pdo->query("SELECT COUNT(*) FROM id_cards")->fetchColumn(), "\n";
