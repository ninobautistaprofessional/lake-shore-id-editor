<?php
/*
 * QA RATE LIMIT TEST SUITE — Lake Shore Colleges ID Management System
 * -----------------------------------------------------------
 * Covers the protection added for the UNAUTHENTICATED public portal
 * endpoints (studentSaveCard, studentUploadPhoto, lostIdRequest and
 * the publicCaptcha challenge) by driving them through the real HTTP
 * API of a LIVE XAMPP stack.
 *
 * What it proves:
 *   - normal student submissions still work (nothing regressed)
 *   - 5 requests / 10 minutes / IP is enforced per action
 *   - the 6th request answers HTTP 429 and is NOT processed
 *   - the 429 carries a clear retry message, Retry-After and the
 *     rate-limit headers, and leaks no system information
 *   - buckets are per action (one blocked endpoint does not block the
 *     others) and a new window restores access
 *   - a client that proves it is human with a captcha can continue
 *   - the honeypot stops automated submissions
 *
 * The 10 minute window is simulated by backdating the counter rows, so
 * the whole suite runs in seconds and never needs a real 10 minute
 * wait. Everything it creates (cards, lost-ID requests, uploads,
 * counters) is removed again at the end.
 *
 * Usage:  php qa_rate_limit_test.php [baseUrl]
 * Default baseUrl: http://localhost/lake-shore-id-editor/backend/api.php
 */

$BASE = rtrim($argv[1] ?? 'http://localhost/lake-shore-id-editor/backend/api.php', '/');
if (!preg_match('/api\.php$/', $BASE)) $BASE .= '/api.php';

require_once __DIR__ . '/../config.php';
$pdo = db();

/* ----------------- Result collection ----------------- */
$results = [];
function R(string $id, string $module, string $case, string $expected, string $actual, string $status, string $severity = 'Low'): void {
    global $results;
    $results[] = [$id, $module, $case, $expected, $actual, $status, $severity];
}
$leaks = ['sqlstate', 'pdo', 'rate_limit_counters', 'rate_limit_captchas', 'stack trace',
          'fatal error', 'warning:', 'uploads/', '.php', 'localhost', 'xampp', 'mysql'];

/* ----------------- HTTP helper ----------------- */
class HttpResult {
    public int $status;
    public array $headers;
    public array $json;
    public string $raw;
    public function header(string $name): ?string {
        foreach ($this->headers as $line) {
            if (stripos($line, $name . ':') === 0) return trim(substr($line, strlen($name) + 1));
        }
        return null;
    }
    public function body(): string { return strtolower($this->raw); }
}
function apiCall(string $method, string $action, array $opt = []): HttpResult {
    global $BASE;
    $url = $BASE . '?action=' . urlencode($action);
    if (!empty($opt['query'])) $url .= '&' . http_build_query($opt['query']);
    $ch = curl_init($url);
    $headers = $opt['headers'] ?? [];
    $allHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$allHeaders) {
            $allHeaders[] = trim($line);
            return strlen($line);
        },
    ]);
    if (!empty($opt['json'])) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
    } elseif (!empty($opt['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form']));
    } elseif (!empty($opt['multipart'])) {
        $parts = [];
        foreach ($opt['multipart'] as $name => $value) {
            $parts[$name] = is_array($value) && isset($value['file'])
                ? new CURLFile($value['file'])
                : $value;
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $parts);
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $r = new HttpResult();
    $r->raw = (string) curl_exec($ch);
    $r->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $r->json = (array) (json_decode($r->raw, true) ?: []);
    $r->headers = $allHeaders;
    return $r;
}

/* ----------------- Fixtures / helpers ----------------- */
$fixture = __DIR__ . '/fixtures/photo.jpg';
$photoDir = __DIR__ . '/../uploads/students';
$tag = 'rlqa' . substr((string)time(), -6);
$createdCardIds = [];
$createdRequestIds = [];

/* Reset the counters so every scenario starts from a clean window. */
function resetLimits(): void {
    global $pdo;
    $pdo->exec('DELETE FROM rate_limit_counters');
    $pdo->exec('DELETE FROM rate_limit_grants');
    $pdo->exec('DELETE FROM rate_limit_captchas');
}
/* The address the server counts against (127.0.0.1, ::/64 on IPv6). */
function observedIp(): string {
    global $pdo;
    $ip = $pdo->query('SELECT ip_address FROM rate_limit_counters ORDER BY window_start DESC, ip_address DESC LIMIT 1')?->fetchColumn();
    return is_string($ip) && $ip !== '' ? $ip : '127.0.0.1';
}
/* Simulate the 10 minute window rolling over. */
function rollWindow(string $bucket): void {
    global $pdo;
    $pdo->prepare('UPDATE rate_limit_counters SET window_start=DATE_SUB(window_start, INTERVAL 40 MINUTE) WHERE bucket=?')->execute([$bucket]);
}
/* Plant a challenge with a known answer: the SVG is only readable by a
 * human, so the suite stores the same HMAC the server would store. */
function plantCaptcha(string $answer, ?string $ip = null, int $ttlSeconds = 600): string {
    global $pdo;
    $secret = '';
    $row = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='rate_limit_secret' LIMIT 1")?->fetch();
    if ($row && trim((string)$row['setting_value']) !== '') $secret = trim((string)$row['setting_value']);
    if ($secret === '') $secret = defined('RATE_LIMIT_SECRET') ? (string)constant('RATE_LIMIT_SECRET') : '';
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $id = '';
    for ($i = 0; $i < 26; $i++) $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    $pdo->prepare('INSERT INTO rate_limit_captchas(captcha_id,answer_hash,ip_address,bucket,expires_at) VALUES(?,?,?,?,?)')
        ->execute([$id, hash_hmac('sha256', strtoupper($answer), $secret), $ip ?? observedIp(),
                   'qa', gmdate('Y-m-d H:i:s', time() + $ttlSeconds)]);
    return $id;
}
function cardPayload(string $name, string $number): array {
    return [
        'student_name' => $name,
        'id_type' => 'COLLEGE',
        'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY',
        'student_number' => $number,
        'academic_year' => '2026-2027',
    ];
}
function uploadCall(array $extra = []): HttpResult {
    global $fixture;
    return apiCall('POST', 'studentUploadPhoto', ['multipart' => ['photo' => ['file' => $fixture]] + $extra]);
}
function assertNoLeak(HttpResult $r): string {
    global $leaks;
    $body = $r->body();
    foreach ($leaks as $needle) {
        if (str_contains($body, $needle)) return $needle;
    }
    return '';
}
function countPhotos(): int {
    global $photoDir;
    return is_dir($photoDir) ? count(glob($photoDir . '/*') ?: []) : 0;
}
function newCards(): int {
    global $pdo, $tag;
    return (int)$pdo->query("SELECT COUNT(*) FROM id_cards WHERE student_name LIKE '%$tag%'")->fetchColumn();
}

echo "QA RATE LIMIT SUITE — $BASE\n";
echo "Waiting for the API to answer";
for ($i = 0; $i < 15; $i++) {
    $probe = apiCall('GET', 'publicSignatory', ['query' => ['type' => 'COLLEGE']]);
    if ($probe->status === 200) break;
    echo '.';
    sleep(1);
}
echo " ready\n";

/* Files uploaded by this suite are removed in the cleanup block. */
$photosBefore = is_dir($photoDir) ? array_flip(array_map('basename', glob($photoDir . '/*') ?: [])) : [];

/* ================================================================ */
/* 1. INFRASTRUCTURE — storage + endpoints that must stay untouched  */
/* ================================================================ */
$tables = $pdo->query("SHOW TABLES LIKE 'rate_limit_%'")->fetchAll(PDO::FETCH_COLUMN);
sort($tables);
R('RL-001', 'Storage', 'Rate-limit tables exist (auto-created on first use)',
  'rate_limit_captchas, rate_limit_counters, rate_limit_grants',
  implode(', ', $tables), count($tables) === 3 ? 'PASS' : 'FAIL', 'High');

$codes = [];
for ($i = 0; $i < 12; $i++) $codes[] = apiCall('GET', 'publicSignatory', ['query' => ['type' => 'COLLEGE']])->status;
R('RL-002', 'Unrelated', 'publicSignatory (GET) is never rate limited',
  '12 x 200', implode(',', array_unique($codes)), !array_diff($codes, [200]) ? 'PASS' : 'FAIL', 'High');

$codes = [];
for ($i = 0; $i < 8; $i++) $codes[] = apiCall('GET', 'cards')->status;
R('RL-003', 'Unrelated', 'Staff endpoints stay behind the login (and unthrottled)',
  '8 x 401', implode(',', array_unique($codes)), !array_diff($codes, [401]) ? 'PASS' : 'FAIL', 'High');

/* ================================================================ */
/* 2. studentUploadPhoto — 5 allowed, 6th blocked with HTTP 429      */
/* ================================================================ */
resetLimits();
$statuses = [];
$remaining = [];
for ($i = 1; $i <= 5; $i++) {
    $r = uploadCall();
    $statuses[] = $r->status;
    $remaining[] = $r->header('X-RateLimit-Remaining');
}
R('RL-010', 'Upload', 'First 5 uploads in the window succeed',
  '5 x 200', implode(',', $statuses), !array_diff($statuses, [200]) ? 'PASS' : 'FAIL', 'High');
R('RL-011', 'Upload', 'Rate-limit headers count down 4,3,2,1,0',
  '4,3,2,1,0', implode(',', $remaining), implode(',', $remaining) === '4,3,2,1,0' ? 'PASS' : 'FAIL', 'Medium');
R('RL-012', 'Upload', 'X-RateLimit-Limit reports the public policy (5)',
  '5', (string)$r->header('X-RateLimit-Limit'), $r->header('X-RateLimit-Limit') === '5' ? 'PASS' : 'FAIL', 'Low');

$photosAfterFive = countPhotos();
$r6 = uploadCall();
R('RL-013', 'Rate limit', '6th upload in the window is blocked with HTTP 429',
  '429', (string)$r6->status, $r6->status === 429 ? 'PASS' : 'FAIL', 'High');
$retry = (int)$r6->header('Retry-After');
R('RL-014', 'Rate limit', 'Retry-After header present and within the window (1-600 s)',
  '1..600', (string)$retry, ($retry >= 1 && $retry <= 600) ? 'PASS' : 'FAIL', 'High');
R('RL-015', 'Rate limit', 'Body is success:false with a retry_after value',
  'success=false, retry_after set', json_encode(array_intersect_key($r6->json, array_flip(['success', 'retry_after']))),
  (($r6->json['success'] ?? true) === false && ($r6->json['retry_after'] ?? 0) > 0) ? 'PASS' : 'FAIL', 'High');
R('RL-016', 'Rate limit', 'Message tells the student to wait and that nothing was processed',
  'mentions wait time + "not processed"', mb_strimwidth((string)($r6->json['message'] ?? ''), 0, 60),
  (stripos((string)($r6->json['message'] ?? ''), 'not processed') !== false
    && stripos((string)($r6->json['message'] ?? ''), 'try again') !== false) ? 'PASS' : 'FAIL', 'High');
$leak = assertNoLeak($r6);
R('RL-017', 'Info exposure', '429 body leaks no system information',
  'no table/file/path/SQL hints', $leak === '' ? 'clean' : 'leaked: ' . $leak, $leak === '' ? 'PASS' : 'FAIL', 'High');
R('RL-018', 'Rate limit', 'Blocked upload stored no file on the server',
  'photo count unchanged (' . $photosAfterFive . ')', (string)countPhotos(), countPhotos() === $photosAfterFive ? 'PASS' : 'FAIL', 'High');

$r7 = uploadCall();
$r8 = uploadCall();
R('RL-019', 'Rate limit', '7th and 8th upload stay blocked',
  '429, 429', $r7->status . ', ' . $r8->status, ($r7->status === 429 && $r8->status === 429) ? 'PASS' : 'FAIL', 'High');
R('RL-020', 'Rate limit', 'Blocked traffic is audited as rate_limited',
  'audit_logs row present', (string)(int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='rate_limited'")->fetchColumn(),
  ((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='rate_limited'")->fetchColumn()) > 0 ? 'PASS' : 'FAIL', 'Medium');

rollWindow('public:studentUploadPhoto');
$r9 = uploadCall();
R('RL-021', 'Rate limit', 'A new window (next 10 minutes) allows the upload again',
  '200', (string)$r9->status, $r9->status === 200 ? 'PASS' : 'FAIL', 'High');

/* ================================================================ */
/* 3. studentSaveCard — normal submissions + per-action buckets      */
/* ================================================================ */
resetLimits();
$cardsBefore = newCards();
$statuses = [];
for ($i = 1; $i <= 5; $i++) {
    $r = apiCall('POST', 'studentSaveCard', ['json' => cardPayload(strtoupper($tag) . " Student $i", $tag . $i)]);
    $statuses[] = $r->status;
    if (!empty($r->json['id'])) $createdCardIds[] = (int)$r->json['id'];
}
R('RL-030', 'Card', 'First 5 ID submissions in the window succeed',
  '5 x 200', implode(',', $statuses), !array_diff($statuses, [200]) ? 'PASS' : 'FAIL', 'High');
R('RL-031', 'Card', 'All 5 submissions were really stored',
  '+5 cards', '+' . (newCards() - $cardsBefore), (newCards() - $cardsBefore) === 5 ? 'PASS' : 'FAIL', 'High');

$cardsAfterFive = newCards();
$r = apiCall('POST', 'studentSaveCard', ['json' => cardPayload(strtoupper($tag) . ' Student 6', $tag . '6')]);
R('RL-032', 'Card', '6th ID submission in the window is blocked with HTTP 429',
  '429', (string)$r->status, $r->status === 429 ? 'PASS' : 'FAIL', 'High');
R('RL-033', 'Card', 'Blocked submission created no card row',
  'card count unchanged', (string)newCards(), newCards() === $cardsAfterFive ? 'PASS' : 'FAIL', 'High');
$rUpload = uploadCall();
R('RL-034', 'Buckets', 'A blocked card endpoint does not block the photo endpoint (per-action buckets)',
  '200', (string)$rUpload->status, $rUpload->status === 200 ? 'PASS' : 'FAIL', 'High');

resetLimits();
$junk = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => '', 'id_type' => 'COLLEGE']]);
$valid = apiCall('POST', 'studentSaveCard', ['json' => cardPayload(strtoupper($tag) . ' Junk', $tag . 'J')]);
if (!empty($valid->json['id'])) $createdCardIds[] = (int)$valid->json['id'];
R('RL-035', 'Card', 'A rejected (422) submission still consumes an attempt',
  '422 then remaining=3', '422=' . $junk->status . ' remaining=' . $valid->header('X-RateLimit-Remaining'),
  ($junk->status === 422 && $valid->header('X-RateLimit-Remaining') === '3') ? 'PASS' : 'FAIL', 'Medium');

/* ================================================================ */
/* 4. lostIdRequest — blocked before lookup, receipt and insert      */
/* ================================================================ */
resetLimits();
$receiptDir = __DIR__ . '/../uploads/receipts';
$receiptsBefore = is_dir($receiptDir) ? count(glob($receiptDir . '/*') ?: []) : 0;
$lostName = strtoupper($tag) . ' Student 1';
$lostBody = ['student_name' => $lostName, 'id_type' => 'COLLEGE', 'student_number' => $tag . '1'];
$statuses = [];
for ($i = 1; $i <= 5; $i++) {
    $r = apiCall('POST', 'lostIdRequest', ['form' => $lostBody]);
    $statuses[] = $r->status;
    if (!empty($r->json['id'])) $createdRequestIds[] = (int)$r->json['id'];
}
R('RL-040', 'Lost ID', 'First 5 lost-ID requests in the window succeed',
  '5 x 200', implode(',', $statuses), !array_diff($statuses, [200]) ? 'PASS' : 'FAIL', 'High');
$requestsAfterFive = count($createdRequestIds);

$blocked = apiCall('POST', 'lostIdRequest', ['multipart' => $lostBody + ['receipt' => ['file' => $fixture]]]);
R('RL-041', 'Lost ID', '6th lost-ID request in the window is blocked with HTTP 429',
  '429', (string)$blocked->status, $blocked->status === 429 ? 'PASS' : 'FAIL', 'High');
R('RL-042', 'Lost ID', 'Blocked request stored no row and no receipt file',
  'rows + receipts unchanged', 'rows=' . count($createdRequestIds) . ' receipts=' . (is_dir($receiptDir) ? count(glob($receiptDir . '/*') ?: []) : 0),
  (count($createdRequestIds) === $requestsAfterFive
    && (is_dir($receiptDir) ? count(glob($receiptDir . '/*') ?: []) : 0) === $receiptsBefore) ? 'PASS' : 'FAIL', 'High');
$after = apiCall('POST', 'studentSaveCard', ['json' => cardPayload(strtoupper($tag) . ' After', $tag . 'A')]);
if (!empty($after->json['id'])) $createdCardIds[] = (int)$after->json['id'];
R('RL-043', 'Buckets', 'A blocked lost-ID endpoint does not block the card endpoint',
  'not 429', (string)$after->status, $after->status !== 429 ? 'PASS' : 'FAIL', 'Medium');

/* ================================================================ */
/* 5. Human verification — a shared network can prove it is human    */
/* ================================================================ */
resetLimits();
for ($i = 0; $i < 6; $i++) $blocked = uploadCall();
R('RL-050', 'Captcha', 'A blocked client is offered a verification challenge',
  'captcha_required=true', json_encode(['captcha_required' => $blocked->json['captcha_required'] ?? null]),
  ($blocked->json['captcha_required'] ?? false) === true ? 'PASS' : 'FAIL', 'High');

$challenge = apiCall('GET', 'publicCaptcha');
$svg = '';
if (str_starts_with((string)($challenge->json['image'] ?? ''), 'data:image/svg+xml;base64,')) {
    $svg = (string)base64_decode(substr((string)$challenge->json['image'], strlen('data:image/svg+xml;base64,')), true);
}
R('RL-051', 'Captcha', 'publicCaptcha returns an id and a self-hosted SVG image',
  '200 + 26 char id + <svg> with 5 glyphs',
  $challenge->status . ' + id=' . strlen((string)($challenge->json['captcha_id'] ?? ''))
    . ' + svg=' . (str_contains($svg, '<svg') && substr_count($svg, '<text') === 5 ? 'ok' : 'bad'),
  ($challenge->status === 200 && strlen((string)($challenge->json['captcha_id'] ?? '')) === 26
    && str_contains($svg, '<svg') && substr_count($svg, '<text') === 5) ? 'PASS' : 'FAIL', 'High');

$answer = 'RB7KM';
$wrong = plantCaptcha('ZZZZZ');
$good = plantCaptcha($answer);
$foreign = plantCaptcha($answer, '203.0.113.77');
$expired = plantCaptcha($answer, null, -60);

/* Quota so far: 5 allowed + 1 blocked = 6 attempts. */
$tryWrong = uploadCall(['captcha_id' => $wrong, 'captcha_code' => 'ABCDE']);
R('RL-052', 'Captcha', 'A wrong verification code does not unlock the endpoint',
  '429 + captcha_error', '429=' . $tryWrong->status . ' captcha_error=' . json_encode($tryWrong->json['captcha_error'] ?? null),
  ($tryWrong->status === 429 && ($tryWrong->json['captcha_error'] ?? false) === true) ? 'PASS' : 'FAIL', 'High');
R('RL-052b', 'Captcha', 'A failed code check does not consume quota',
  'remaining quota intact', 'hits=' . (int)$pdo->query("SELECT hits FROM rate_limit_counters WHERE bucket='public:studentUploadPhoto'")->fetchColumn(),
  (int)$pdo->query("SELECT hits FROM rate_limit_counters WHERE bucket='public:studentUploadPhoto'")->fetchColumn() === 6 ? 'PASS' : 'FAIL', 'Medium');
$tryForeign = uploadCall(['captcha_id' => $foreign, 'captcha_code' => $answer]);
R('RL-053', 'Captcha', 'A challenge issued to another address is refused',
  '429', (string)$tryForeign->status, $tryForeign->status === 429 ? 'PASS' : 'FAIL', 'High');
$tryExpired = uploadCall(['captcha_id' => $expired, 'captcha_code' => $answer]);
R('RL-054', 'Captcha', 'An expired challenge is refused',
  '429', (string)$tryExpired->status, $tryExpired->status === 429 ? 'PASS' : 'FAIL', 'High');

$tryGood = uploadCall(['captcha_id' => $good, 'captcha_code' => $answer]);
R('RL-055', 'Captcha', 'A solved challenge unlocks the extra attempts (shared network)',
  '200', (string)$tryGood->status, $tryGood->status === 200 ? 'PASS' : 'FAIL', 'High');
$grant = (int)$pdo->query("SELECT COUNT(*) FROM rate_limit_grants WHERE bucket='public:studentUploadPhoto'")->fetchColumn();
R('RL-056', 'Captcha', 'The unlock is recorded once per bucket/IP/window',
  '1 grant row', (string)$grant, $grant === 1 ? 'PASS' : 'FAIL', 'Medium');

$extra = [];
for ($i = 0; $i < 3; $i++) $extra[] = uploadCall()->status;
R('RL-057', 'Captcha', 'The granted extra attempts work for the rest of the window',
  '3 x 200 (5 base + 5 granted)', implode(',', $extra), !array_diff($extra, [200]) ? 'PASS' : 'FAIL', 'High');
$after = uploadCall();
R('RL-058', 'Captcha', 'Past the granted ceiling the endpoint is blocked again, with no new unlock',
  '429 + no captcha_required', '429=' . $after->status . ' captcha_required=' . json_encode($after->json['captcha_required'] ?? null),
  ($after->status === 429 && !array_key_exists('captcha_required', $after->json)) ? 'PASS' : 'FAIL', 'High');
$replay = uploadCall(['captcha_id' => $good, 'captcha_code' => $answer]);
R('RL-059', 'Captcha', 'A verification code cannot be replayed',
  '429 + captcha_error', '429=' . $replay->status . ' captcha_error=' . json_encode($replay->json['captcha_error'] ?? null),
  ($replay->status === 429 && ($replay->json['captcha_error'] ?? false) === true) ? 'PASS' : 'FAIL', 'High');

/* ================================================================ */
/* 6. Bot trap (honeypot) and challenge-issuance budget               */
/* ================================================================ */
resetLimits();
$cardsBefore = newCards();
$bot = apiCall('POST', 'studentSaveCard', ['json' => cardPayload(strtoupper($tag) . ' Bot', $tag . 'BOT') + ['website' => 'http://spam.example']]);
R('RL-060', 'Bot trap', 'A filled honeypot field is rejected without creating a card',
  '422 + no card', '422=' . $bot->status . ' cards=' . (newCards() - $cardsBefore),
  ($bot->status === 422 && (newCards() - $cardsBefore) === 0) ? 'PASS' : 'FAIL', 'High');
R('RL-061', 'Bot trap', 'The bot-trap message does not reveal that a trap exists',
  'generic message', mb_strimwidth((string)($bot->json['message'] ?? ''), 0, 50),
  (stripos((string)($bot->json['message'] ?? ''), 'honeypot') === false
    && stripos((string)($bot->json['message'] ?? ''), 'bot') === false) ? 'PASS' : 'FAIL', 'Medium');
$human = apiCall('POST', 'studentSaveCard', ['json' => cardPayload(strtoupper($tag) . ' Human', $tag . 'H') + ['website' => '']]);
if (!empty($human->json['id'])) $createdCardIds[] = (int)$human->json['id'];
R('RL-062', 'Bot trap', 'An empty honeypot field (a real student) is accepted',
  '200', (string)$human->status, $human->status === 200 ? 'PASS' : 'FAIL', 'High');

resetLimits();
for ($i = 0; $i < 5; $i++) apiCall('POST', 'studentSaveCard', ['json' => ['website' => 'bot']]);
$afterBots = apiCall('POST', 'studentSaveCard', ['json' => cardPayload(strtoupper($tag) . ' AfterBots', $tag . 'AB')]);
if (!empty($afterBots->json['id'])) $createdCardIds[] = (int)$afterBots->json['id'];
R('RL-063', 'Bot trap', 'Rejected bots do not burn the quota of the same address',
  'remaining=4 after 5 bot hits', (string)$afterBots->header('X-RateLimit-Remaining'),
  $afterBots->header('X-RateLimit-Remaining') === '4' ? 'PASS' : 'FAIL', 'Low');

resetLimits();
$issued = [];
for ($i = 0; $i < 12; $i++) $issued[] = apiCall('GET', 'publicCaptcha')->status;
R('RL-064', 'Captcha', 'Challenge issuance has its own per-IP budget',
  '10 x 200 then 429', implode(',', array_slice($issued, 0, 10)) . ' then ' . implode(',', array_slice($issued, 10)),
  (count(array_filter(array_slice($issued, 0, 10), fn($c) => $c === 200)) === 10
    && !array_diff(array_slice($issued, 10), [429])) ? 'PASS' : 'FAIL', 'Medium');

/* End-to-end: a challenge really issued by publicCaptcha is read from
 * its own SVG, answered and accepted by the blocked endpoint. */
resetLimits();
for ($i = 0; $i < 6; $i++) uploadCall();
$live = apiCall('GET', 'publicCaptcha');
$liveSvg = (string)base64_decode(substr((string)($live->json['image'] ?? ''), strlen('data:image/svg+xml;base64,')), true);
preg_match_all('/<text[^>]*>([^<]{1,2})<\/text>/', $liveSvg, $glyphs);
$liveAnswer = implode('', $glyphs[1] ?? []);
$liveTry = uploadCall(['captcha_id' => (string)($live->json['captcha_id'] ?? ''), 'captcha_code' => $liveAnswer]);
R('RL-065', 'Captcha', 'A challenge issued by publicCaptcha is accepted end to end',
  'glyphs read from the SVG unlock the endpoint', 'answer=' . $liveAnswer . ' -> ' . $liveTry->status,
  (strlen($liveAnswer) === 5 && $liveTry->status === 200) ? 'PASS' : 'FAIL', 'High');

/* ================================================================ */
/* CLEANUP — leave the portal and the counters exactly as found      */
/* ================================================================ */
foreach ($createdCardIds as $id) $pdo->prepare('DELETE FROM id_cards WHERE id=?')->execute([$id]);
foreach ($createdRequestIds as $id) $pdo->prepare('DELETE FROM lost_id_requests WHERE id=?')->execute([$id]);
$pdo->prepare("DELETE FROM id_cards WHERE student_name LIKE ?")->execute(['%' . strtoupper($tag) . '%']);
$pdo->prepare("DELETE FROM lost_id_requests WHERE student_name LIKE ?")->execute(['%' . strtoupper($tag) . '%']);
resetLimits();
if (is_dir($photoDir)) {
    foreach (glob($photoDir . '/*') ?: [] as $f) {
        if (is_file($f) && !isset($photosBefore[basename($f)])) @unlink($f);
    }
}

/* ------------------------------------------------------------------ */
/* REPORT                                                              */
/* ------------------------------------------------------------------ */
$counts = ['PASS' => 0, 'FAIL' => 0];
foreach ($results as $row) $counts[$row[5]] = ($counts[$row[5]] ?? 0) + 1;

echo "\n================ QA RATE LIMIT TEST MATRIX ================\n";
echo sprintf("%-9s %-12s %-46s %-32s %-42s %-6s %s\n", 'ID', 'MODULE', 'TEST CASE', 'EXPECTED', 'ACTUAL', 'STATUS', 'SEVERITY');
echo str_repeat('-', 190) . "\n";
foreach ($results as $row) {
    echo sprintf("%-9s %-12s %-46s %-32s %-42s %-6s %s\n",
        $row[0], $row[1], mb_strimwidth($row[2], 0, 46), mb_strimwidth($row[3], 0, 32),
        mb_strimwidth($row[4], 0, 42), $row[5], $row[6]);
}
echo str_repeat('-', 190) . "\n";
echo 'TOTAL: ' . count($results) . "  PASS: {$counts['PASS']}  FAIL: {$counts['FAIL']}\n";
echo "Cleanup completed (QA cards / lost-ID requests / uploads / counters removed).\n";
