<?php
// Simple test to see if API.php works
header('Content-Type: application/json');
echo json_encode(['status' => 'test', 'time' => date('Y-m-d H:i:s')]);
?>