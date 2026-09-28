<?php
/* Probe: reproduce the exact templates payload the editor receives. */
$BASE = 'http://localhost/lake-shore-id-editor/backend/api.php';
require_once __DIR__ . '/../config.php';
$pdo = db();

/* temp admin */
$pdo->exec("DELETE FROM users WHERE username='qa_tpl_probe'");
$pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_tpl_probe',?,'QA Tpl Probe','admin',1)")
    ->execute([password_hash('Probe#2026x', PASSWORD_DEFAULT)]);

function call(string $action, string $sess, array $opt = []) {
    global $BASE;
    $ch = curl_init($BASE . '?action=' . urlencode($action));
    $h = $opt['headers'] ?? [];
    if ($sess !== '') curl_setopt($ch, CURLOPT_COOKIE, $sess);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
    if (!empty($opt['json'])) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json'])); }
    if ($h) curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $raw = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($raw, true), $raw];
}

/* login, capture session cookie */
$ch = curl_init($BASE . '?action=login');
$cookieJar = '';
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['username' => 'qa_tpl_probe', 'password' => 'Probe#2026x']),
    CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$cookieJar) { $cookieJar .= $line; return strlen($line); },
]);
$loginRaw = (string) curl_exec($ch);
preg_match_all('/LSC_ID_SESSION=([^;]+)/', $cookieJar, $m);
$sess = 'LSC_ID_SESSION=' . (end($m[1]) ?: ''); /* LAST Set-Cookie wins (login may send several) */
$login = json_decode($loginRaw, true);
printf("login: %s csrf=%s\n", curl_getinfo($ch, CURLINFO_RESPONSE_CODE), !empty($login['csrf_token']) ? 'yes' : 'NO');

[, $tpls, $raw] = call('templates', $sess);
echo "templates action:\n";
if (!isset($tpls['data'])) { echo "  UNEXPECTED: $raw\n"; }
foreach (($tpls['data'] ?? []) as $t) {
    printf("  id=%s name=%-22s id_type=%-12s is_active=%s is_system=%s mode=%s\n",
        $t['id'], $t['name'], $t['id_type'], $t['is_active'], $t['is_system'], $t['photo_processing_mode'] ?? '(null)');
}

/* what create(type) would pick for each department */
foreach (['COLLEGE', 'JUNIOR_HIGH', 'SENIOR_HIGH'] as $type) {
    $act = null;
    foreach (($tpls['data'] ?? []) as $t) if ($t['id_type'] === $type && (string) $t['is_active'] === '1') { $act = $t; break; }
    printf("activeTemplateFor(%s) => %s\n", $type, $act ? ('#' . $act['id'] . ' ' . $act['name']) : 'NULL (built-in design)');
}

$pdo->exec("DELETE FROM users WHERE username='qa_tpl_probe'");
echo "probe done\n";
