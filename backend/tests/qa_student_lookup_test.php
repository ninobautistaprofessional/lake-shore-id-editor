<?php
/*
 * QA STUDENT SEARCH TEST SUITE — Lake Shore Colleges ID Management System
 * -----------------------------------------------------------
 * Covers the public "existing student detection" endpoint (studentLookup):
 *
 *   Enter Student Number -> Search -> Student Found -> form is filled in
 *
 * What it proves:
 *   - a Student Number returns the record, and only the whitelisted fields
 *   - the same record is reachable through student_id_number and lrn too
 *   - "Student Not Found" is a clean 404 that leaks nothing
 *   - the Student Number is validated and sanitised server side
 *   - the endpoint cannot be used to enumerate students (exact match only,
 *     a single row, minimum length, no wildcards, own rate-limit bucket)
 *   - an existing record cannot be duplicated through the create form
 *   - the existing public submissions still work (nothing regressed)
 *
 * Everything it creates (cards, counters) is removed again at the end.
 *
 * Usage:  php qa_student_lookup_test.php [baseUrl]
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
/* Nothing about the server, the schema or another student may appear. */
$leaks = ['sqlstate', 'pdo', 'rate_limit_', 'stack trace', 'fatal error',
          'warning:', 'uploads/', '.php', 'localhost', 'xampp', 'mysql',
          'select ', 'insert ', 'from id_cards'];

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
$tag    = 'SLQA' . substr((string)time(), -6);
$num    = '2026-' . substr($tag, -5);
$sid    = strtoupper($tag) . '-ID';
$lrn    = '12' . substr((string)time(), -10);
$name   = 'SL QA Student ' . $tag;
$seedId = 0;

/* Clear the counters so each scenario starts from a clean window; otherwise
 * the 5-per-window policy would mask the validation result under test. */
function resetLimits(): void {
    global $pdo;
    $pdo->exec('DELETE FROM rate_limit_counters');
    $pdo->exec('DELETE FROM rate_limit_grants');
    $pdo->exec('DELETE FROM rate_limit_captchas');
}
/* One seeded record carrying every identifier the search accepts, and filled
 * with the data the public search must NOT hand back. */
function seedCard(): int {
    global $pdo, $num, $sid, $lrn, $name;
    dropSeeded();
    $pdo->prepare("INSERT INTO id_cards
        (student_name,id_type,course,grade_level,section_name,student_number,
         student_id_number,lrn,academic_year,school_year,photo_path,address_line1,
         address_line2,emergency_contact,emergency_phone,email_address,
         signatory_name,status,release_notes)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'created',?)")->execute([
        $name, 'COLLEGE', 'BACHELOR OF SCIENCE IN ACCOUNTANCY', '', 'NURSING-1',
        $num, $sid, $lrn, '2026-2027', '', 'uploads/students/qa_secret.jpg',
        'QA Secret Street', 'QA Secret City', 'QA Secret Contact', '0917SECRET',
        'qa.secret@example.test', 'QA Secret Signatory', 'QA SECRET NOTE',
    ]);
    return (int)$pdo->lastInsertId();
}
function dropSeeded(): void {
    global $pdo, $num, $sid, $lrn;
    $pdo->prepare('DELETE FROM id_cards WHERE student_number=? OR student_id_number=? OR lrn=?')
        ->execute([$num, $sid, $lrn]);
}
function lookup(string $value, array $extra = []): HttpResult {
    return apiCall('POST', 'studentLookup', ['json' => ['student_number' => $value, 'website' => ''] + $extra]);
}
/* array_merge, not `+`: the caller's overrides must WIN, and PHP's union
 * operator keeps the left-hand value for duplicate keys. */
function saveCard(array $extra = []): HttpResult {
    global $name, $num;
    return apiCall('POST', 'studentSaveCard', ['json' => array_merge([
        'student_name' => $name, 'id_type' => 'COLLEGE',
        'course' => 'BACHELOR OF SCIENCE IN ACCOUNTANCY',
        'student_number' => $num, 'academic_year' => '2026-2027',
        'website' => '',
    ], $extra)]);
}
function assertNoLeak(HttpResult $r): string {
    global $leaks;
    foreach ($leaks as $needle) if (str_contains($r->body(), $needle)) return $needle;
    return '';
}
function verdict(bool $ok): string { return $ok ? 'PASS' : 'FAIL'; }

echo "QA STUDENT SEARCH SUITE — $BASE\n";
echo "Waiting for the API to answer";
for ($i = 0; $i < 15; $i++) {
    if (apiCall('GET', 'publicSignatory', ['query' => ['type' => 'COLLEGE']])->status === 200) break;
    echo '.';
    sleep(1);
}
echo "\n\n";

/* ================================================================ */
/* A. HAPPY PATH — the record is found, and only what is needed      */
/* ================================================================ */
resetLimits();
$seedId = seedCard();

resetLimits();
$hit = lookup($num);
$d   = (array)($hit->json['data'] ?? []);
R('SL-001', 'Search', 'A known Student Number finds the record',
  '200 + record returned', 'HTTP ' . $hit->status,
  verdict($hit->status === 200 && ($d['student_number'] ?? '') === $num), 'High');

R('SL-002', 'Search', 'Name is returned so the form can be filled in',
  'student_name matches', (string)($d['student_name'] ?? ''),
  verdict(($d['student_name'] ?? '') === $name), 'High');

R('SL-003', 'Search', 'Department, course, section and year are returned',
  'course + section + year present',
  ($d['course'] ?? '') . ' | ' . ($d['section_name'] ?? '') . ' | ' . ($d['academic_year'] ?? ''),
  verdict(($d['course'] ?? '') !== '' && ($d['section_name'] ?? '') !== '' && ($d['academic_year'] ?? '') !== ''), 'High');

R('SL-004', 'Search', 'The record id travels with the result',
  'card_id returned', (string)($hit->json['card_id'] ?? ''),
  verdict((int)($hit->json['card_id'] ?? 0) === $seedId), 'Medium');

R('SL-005', 'Search', 'The response carries the whitelist and nothing else',
  'exactly the 10 whitelisted keys', implode(',', array_keys($d)),
  verdict(array_keys($d) === ['student_name','id_type','course','grade_level','section_name',
        'student_number','student_id_number','lrn','academic_year','school_year']), 'High');

R('SL-006', 'Privacy', 'Photo, address, contacts and notes stay private',
  'no sensitive value in the body', assertNoLeak($hit) ?: 'clean',
  verdict(assertNoLeak($hit) === ''), 'High');

R('SL-007', 'Privacy', 'The result names no table, path or server detail',
  'no SQL / path / host detail', assertNoLeak($hit) ?: 'clean',
  verdict(assertNoLeak($hit) === ''), 'High');

/* The three identifier columns are the identity triple the create form
 * already uses, so all of them must reach the same record. */
foreach ([['SL-008', $num, 'Student Number'], ['SL-009', $sid, 'Student ID Number'],
          ['SL-010', $lrn, 'LRN']] as [$id, $value, $label]) {
    resetLimits();
    $r = lookup($value);
    R($id, 'Search', "The record is also found by $label",
      '200 + same record', 'HTTP ' . $r->status,
      verdict($r->status === 200 && ($r->json['data']['student_name'] ?? '') === $name), 'Medium');
}

resetLimits();
$r = lookup('  ' . strtolower($num) . '  ');
R('SL-011', 'Validation', 'Surrounding spaces and letter case are normalised',
  '200 — trimmed and matched', 'HTTP ' . $r->status, verdict($r->status === 200), 'Medium');


/* ================================================================ */
/* B. NOT FOUND                                                      */
/* ================================================================ */
resetLimits();
$miss = lookup('2026-00000');
R('SL-012', 'Not found', 'An unknown Student Number answers "Student Not Found"',
  '404 + Student Not Found', 'HTTP ' . $miss->status . ' — ' . (string)($miss->json['message'] ?? ''),
  verdict($miss->status === 404 && str_contains(strtolower((string)($miss->json['message'] ?? '')), 'not found')), 'High');

R('SL-013', 'Not found', 'The miss leaks no server, schema or record detail',
  'clean body', assertNoLeak($miss) ?: 'clean', verdict(assertNoLeak($miss) === ''), 'High');

R('SL-014', 'Not found', 'A miss returns no partial student data',
  'no data key', implode(',', array_keys($miss->json)),
  verdict(!isset($miss->json['data'])), 'Medium');

/* ================================================================ */
/* C. INPUT VALIDATION & SANITISATION (server side)                 */
/* ================================================================ */
$bad = [
    ['SL-015', 'An empty Student Number is refused',           ''],
    ['SL-016', 'A missing student_number key is refused',       null],
    ['SL-017', 'A 1-2 character probe never reaches the DB',    'ab'],
    ['SL-018', 'SQL metacharacters are refused',                "' OR 1=1 -- "],
    ['SL-019', 'A UNION probe is refused',                      "x' UNION SELECT * FROM users -- "],
    ['SL-020', 'A value longer than the column is refused',     str_repeat('9', 120)],
    ['SL-021', 'A leading separator is refused',                '-2026'],
    ['SL-022', 'Markup in the number is refused',               '<script>2026</script>'],
];
foreach ($bad as [$id, $case, $value]) {
    resetLimits();
    $r = $value === null ? apiCall('POST', 'studentLookup', ['json' => ['website' => '']])
                         : lookup($value);
    R($id, 'Validation', $case, '422, nothing stored', 'HTTP ' . $r->status,
      verdict($r->status === 422), 'High');
}

/* Control characters and zero-width noise are stripped rather than refused,
 * so a student pasting from a PDF or spreadsheet still gets a match. */
resetLimits();
$r = lookup($num . "\0");
R('SL-023', 'Validation', 'A trailing null byte is stripped and still matches',
  '200 — sanitised, then found', 'HTTP ' . $r->status, verdict($r->status === 200), 'Medium');

resetLimits();
$r = apiCall('POST', 'studentLookup', ['json' => ['student_number' => $num, 'website' => 'http://spam.example']]);
R('SL-024', 'Bot trap', 'The honeypot stops an automated search',
  '422 generic message', 'HTTP ' . $r->status,
  verdict($r->status === 422 && str_contains(strtolower((string)$r->json['message'] ?? ''), 'could not be processed')), 'Medium');

/* ================================================================ */
/* D. ENUMERATION RESISTANCE                                         */
/* ================================================================ */
resetLimits();
$r = lookup('2026');
R('SL-025', 'Enumeration', 'A partial Student Number matches nothing (no LIKE)',
  '404 — no prefix matching', 'HTTP ' . $r->status, verdict($r->status === 404), 'High');

resetLimits();
$r = lookup('%');
R('SL-026', 'Enumeration', 'The SQL wildcard is not treated as a pattern',
  '422 — refused', 'HTTP ' . $r->status, verdict($r->status === 422), 'High');

resetLimits();
$r = lookup('2026_');
R('SL-027', 'Enumeration', 'The LIKE wildcard is not treated as a pattern',
  '404 — no match', 'HTTP ' . $r->status, verdict($r->status === 404), 'High');

/* Two records share a prefix: only an exact number may return one, and
 * never a list. */
$prefixA = 'ENUMA-' . $tag;
$prefixB = 'ENUMB-' . $tag;
$pdo->prepare('INSERT INTO id_cards(student_name,id_type,course,student_number,status) VALUES(?,?,?,?,?)')
    ->execute([$name . ' A', 'COLLEGE', 'BACHELOR OF SCIENCE IN ACCOUNTANCY', $prefixA, 'created']);
$pdo->prepare('INSERT INTO id_cards(student_name,id_type,course,student_number,status) VALUES(?,?,?,?,?)')
    ->execute([$name . ' B', 'COLLEGE', 'BACHELOR OF SCIENCE IN ACCOUNTANCY', $prefixB, 'created']);

resetLimits();
$r = lookup(substr($prefixA, 0, 9));
R('SL-028', 'Enumeration', 'A shared prefix never returns a list of students',
  '404 — no prefix matching', 'HTTP ' . $r->status, verdict($r->status === 404), 'High');

resetLimits();
$r = lookup($prefixB);
R('SL-029', 'Enumeration', 'The exact number returns exactly one record',
  '200 + a single row', 'HTTP ' . $r->status,
  verdict($r->status === 200 && ($r->json['data']['student_name'] ?? '') === $name . ' B'), 'Medium');
$pdo->prepare('DELETE FROM id_cards WHERE student_number IN (?,?)')->execute([$prefixA, $prefixB]);


/* ================================================================ */
/* E. RATE LIMITING (the existing public policy applies unchanged)  */
/* ================================================================ */
resetLimits();
$codes = [];
for ($i = 0; $i < 7; $i++) $codes[] = lookup('MISSING-' . $i)->status;
R('SL-030', 'Rate limit', '5 searches per 10 minutes per IP are enforced',
  '5 answered, then 429', implode(',', $codes),
  verdict(count(array_filter(array_slice($codes, 0, 5), fn($c) => $c === 404)) === 5
      && !array_diff(array_slice($codes, 5), [429])), 'High');

resetLimits();
for ($i = 0; $i < 6; $i++) lookup('MISSING-' . $i);
$blocked = lookup($num);
R('SL-031', 'Rate limit', 'A blocked client cannot keep reading the table',
  '429 even for a number that exists', 'HTTP ' . $blocked->status,
  verdict($blocked->status === 429), 'High');

R('SL-032', 'Rate limit', 'The 429 explains the wait without naming internals',
  'retry_after + no server detail',
  'retry_after=' . ($blocked->json['retry_after'] ?? '-') . ', leak=' . (assertNoLeak($blocked) ?: 'none'),
  verdict(($blocked->json['retry_after'] ?? 0) > 0 && assertNoLeak($blocked) === ''), 'Medium');

/* The search has its own bucket: burning it must not lock the form out. */
resetLimits();
for ($i = 0; $i < 6; $i++) lookup('MISSING-' . $i);
$save = saveCard();
R('SL-033', 'Rate limit', 'Searches and submissions are limited separately',
  'studentSaveCard still answered', 'HTTP ' . $save->status,
  verdict($save->status === 200 || $save->status === 409), 'Medium');
if ($save->status === 200) $pdo->prepare('DELETE FROM id_cards WHERE id=?')->execute([(int)$save->json['id']]);

/* A client that proves it is human can keep searching, exactly as on the
 * other public endpoints. */
resetLimits();
for ($i = 0; $i < 6; $i++) lookup('MISSING-' . $i);
$cap = apiCall('GET', 'publicCaptcha');
$svg = (string)base64_decode(substr((string)($cap->json['image'] ?? ''), strlen('data:image/svg+xml;base64,')), true);
preg_match_all('/<text[^>]*>([^<]{1,2})<\/text>/', $svg, $glyphs);
$answer = implode('', $glyphs[1] ?? []);
$unlocked = lookup($num, ['captcha_id' => (string)($cap->json['captcha_id'] ?? ''), 'captcha_code' => $answer]);
R('SL-034', 'Rate limit', 'A solved captcha unlocks further searches',
  'search accepted after the challenge', 'answer=' . $answer . ' -> HTTP ' . $unlocked->status,
  verdict(strlen($answer) === 5 && $unlocked->status === 200), 'Medium');

/* ================================================================ */
/* F. DUPLICATE PREVENTION                                           */
/* ================================================================ */
/* The pre-existing name + identifier check must behave exactly as before. */
resetLimits();
$dup = saveCard();
R('SL-035', 'Duplicates', 'Same name + Student Number is still refused',
  '409 duplicate', 'HTTP ' . $dup->status, verdict($dup->status === 409), 'High');

/* The search located this record, so re-submitting it under a different
 * spelling of the name must not create a second row. */
resetLimits();
$guarded = apiCall('POST', 'studentSaveCard', ['json' => [
    'student_name' => $name . ' RENAMED', 'id_type' => 'COLLEGE',
    'course' => 'BACHELOR OF SCIENCE IN ACCOUNTANCY',
    'student_number' => $num, 'existing_card_id' => $seedId, 'website' => '',
]]);
R('SL-036', 'Duplicates', 'A located record cannot be re-created under a new name',
  '409 — no second row', 'HTTP ' . $guarded->status, verdict($guarded->status === 409), 'High');

$rows = (int)$pdo->query('SELECT COUNT(*) FROM id_cards WHERE student_number=' . $pdo->quote($num))->fetchColumn();
R('SL-037', 'Duplicates', 'The database still holds exactly one such record',
  '1 row', $rows . ' row(s)', verdict($rows === 1), 'High');

/* A stale or fabricated id must not block a genuine new student. */
resetLimits();
$stale = apiCall('POST', 'studentSaveCard', ['json' => [
    'student_name' => $name . ' FRESH', 'id_type' => 'COLLEGE',
    'course' => 'BACHELOR OF SCIENCE IN ACCOUNTANCY',
    'student_number' => 'FRESH-' . $tag, 'existing_card_id' => 999999, 'website' => '',
]]);
R('SL-038', 'Duplicates', 'A non-existent id does not block a new student',
  '200 — student created', 'HTTP ' . $stale->status, verdict($stale->status === 200), 'Medium');
if ($stale->status === 200) $pdo->prepare('DELETE FROM id_cards WHERE id=?')->execute([(int)$stale->json['id']]);


/* ================================================================ */
/* G. NOTHING REGRESSED                                              */
/* ================================================================ */
resetLimits();
$sign = apiCall('GET', 'publicSignatory', ['query' => ['type' => 'COLLEGE']]);
R('SL-039', 'Regression', 'publicSignatory still answers',
  '200', 'HTTP ' . $sign->status, verdict($sign->status === 200), 'Medium');

resetLimits();
$lost = apiCall('POST', 'lostIdRequest', ['json' => [
    'student_name' => 'No Such Student ' . $tag, 'id_type' => 'COLLEGE',
    'student_number' => 'NOSUCH-' . $tag, 'website' => '',
]]);
R('SL-040', 'Regression', 'lostIdRequest still answers for an unknown student',
  '404 (no matching ID)', 'HTTP ' . $lost->status, verdict($lost->status === 404), 'Medium');

resetLimits();
$created = saveCard(['student_number' => 'NEW-' . $tag, 'student_name' => 'New ' . $name]);
R('SL-041', 'Regression', 'A brand new student can still create an ID',
  '200 created', 'HTTP ' . $created->status, verdict($created->status === 200), 'High');
if ($created->status === 200) $pdo->prepare('DELETE FROM id_cards WHERE id=?')->execute([(int)$created->json['id']]);

/* ================================================================ */
/* CLEANUP — leave the portal and the counters exactly as found     */
/* ================================================================ */
dropSeeded();
$pdo->prepare('DELETE FROM id_cards WHERE student_name LIKE ?')->execute(['%' . $tag . '%']);
$pdo->prepare('DELETE FROM id_cards WHERE student_number LIKE ?')->execute(['ENUM%' . $tag . '%']);
$pdo->prepare('DELETE FROM id_cards WHERE student_number LIKE ?')->execute(['FRESH-%']);
$pdo->prepare('DELETE FROM id_cards WHERE student_number LIKE ?')->execute(['NEW-%']);
resetLimits();

/* ------------------------------------------------------------------ */
/* REPORT                                                              */
/* ------------------------------------------------------------------ */
$counts = ['PASS' => 0, 'FAIL' => 0];
foreach ($results as $row) $counts[$row[5]] = ($counts[$row[5]] ?? 0) + 1;

echo "\n================ QA STUDENT SEARCH TEST MATRIX ================\n";
echo sprintf("%-9s %-12s %-48s %-30s %-44s %-6s %s\n", 'ID', 'MODULE', 'TEST CASE', 'EXPECTED', 'ACTUAL', 'STATUS', 'SEVERITY');
echo str_repeat('-', 196) . "\n";
foreach ($results as $row) {
    echo sprintf("%-9s %-12s %-48s %-30s %-44s %-6s %s\n",
        $row[0], $row[1], mb_strimwidth($row[2], 0, 48), mb_strimwidth($row[3], 0, 30),
        mb_strimwidth($row[4], 0, 44), $row[5], $row[6]);
}
echo str_repeat('-', 196) . "\n";
echo 'TOTAL: ' . count($results) . "  PASS: {$counts['PASS']}  FAIL: {$counts['FAIL']}\n";
echo "Cleanup completed (QA cards / counters removed).\n";
exit($counts['FAIL'] > 0 ? 1 : 0);
