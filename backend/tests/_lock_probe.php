<?php
/* Standalone concurrency probe: N parallel identical studentSaveCard requests.
 * With GET_LOCK serialization, EXACTLY ONE must insert (others 409). */
$BASE = $argv[1] ?? 'http://localhost/lake-shore-id-editor/backend/api.php';
$N = 6;
$name = 'QATest2 Probe ' . substr(bin2hex(random_bytes(4)), 0, 8);
$payload = json_encode([
    'student_name' => $name, 'id_type' => 'COLLEGE',
    'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'student_number' => 'QAT2-PROBE-0001',
]);
$mh = curl_multi_init(); $chs = [];
for ($i = 0; $i < $N; $i++) {
    $c = curl_init($BASE . '?action=studentSaveCard');
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 30]);
    curl_multi_add_handle($mh, $c); $chs[] = $c;
}
$active = null;
do { $mrc = curl_multi_exec($mh, $active); } while ($mrc === CURLM_CALL_MULTI_PERFORM);
while ($active && $mrc === CURLM_OK) { if (curl_multi_select($mh) === -1) usleep(10000); do { $mrc = curl_multi_exec($mh, $active); } while ($mrc === CURLM_CALL_MULTI_PERFORM); }
$statuses = []; $bodies = [];
foreach ($chs as $c) { $statuses[] = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); $bodies[] = substr((string) curl_multi_getcontent($c), 0, 80); curl_multi_remove_handle($mh, $c); }
curl_multi_close($mh);
require __DIR__ . '/../config.php';
$pdo = db();
$st = $pdo->prepare('SELECT COUNT(*) FROM id_cards WHERE student_name=?'); $st->execute([$name]);
$rows = (int) $st->fetchColumn();
$pdo->prepare('DELETE FROM id_cards WHERE student_name=?')->execute([$name]);
echo 'name=' . $name . "\n";
echo 'statuses=[' . implode(',', $statuses) . "]\n";
echo 'rows=' . $rows . "\n";
foreach ($bodies as $i => $b) echo "  body[$i]: $b\n";
echo ($rows === 1) ? "PROBE_OK\n" : "PROBE_RACE\n";
