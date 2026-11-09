<?php
$pdo = new PDO('mysql:host=localhost;dbname=lake_shore_id_system', 'root', '');
$stmt = $pdo->query('SELECT COUNT(*) FROM id_cards');
echo 'Cards count: ' . $stmt->fetchColumn();