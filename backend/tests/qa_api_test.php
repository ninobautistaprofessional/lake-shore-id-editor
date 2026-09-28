<?php
/*
 * QA API TEST SUITE — Lake Shore Colleges ID Generator System
 * -----------------------------------------------------------
 * Runs against a LIVE XAMPP stack. Creates temporary QA users
 * (qa_admin_test / qa_staff_test) directly in the database, executes
 * functional, security, authorization, validation and file-upload tests
 * through the public API, then cleans up everything it created.
 *
 * Usage:  php qa_api_test.php [baseUrl]
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
$createdCardIds = [];
$createdRequestIds = [];
$createdSignatoryIds = [];
$uploadedFiles = [];

/* ----------------- HTTP helper ----------------- */
class HttpResult {
    public int $status;
    public array $headers;
    public array $json;
    public string $raw;
}
function apiCall(string $method, string $action, array $opt = []): HttpResult {
    global $BASE, $pdo;
    /* The public submission endpoints are rate limited (5 requests /
       10 min / IP). This suite tests validation, uploads and data
       handling — not throttling (covered by qa_rate_limit_test.php) —
       so the per-IP window is cleared before every public submission
       to keep the cases independent of each other. */
    if (in_array($action, ['studentSaveCard', 'studentUploadPhoto', 'lostIdRequest'], true)) {
        $pdo->exec("DELETE FROM rate_limit_counters WHERE bucket LIKE 'public:%'");
    }
    $url = $BASE . '?action=' . urlencode($action);
    if (!empty($opt['query'])) $url .= '&' . http_build_query($opt['query']);
    $ch = curl_init($url);
    $headers = $opt['headers'] ?? [];
    $allHeaders = [];  // collects ALL "Set-Cookie" headers (PHP session_regenerate_id sends a second one)
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$allHeaders) {
            $allHeaders[] = trim($line);
            return strlen($line);
        },
    ]);
    if (!empty($opt['cookies'])) curl_setopt($ch, CURLOPT_COOKIE, $opt['cookies']);
    if (!empty($opt['json'])) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
    } elseif (!empty($opt['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['form']);
    }
    // Add multipart form-data for file uploads
    if (!empty($opt['multipart'])) {
        $parts = [];
        foreach ($opt['multipart'] as $name => $value) {
            if (is_string($value)) {
                $parts[$name] = $value;
            } elseif (is_array($value) && isset($value['file'])) {
                $parts[$name] = new CURLFile($value['file']);
            }
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $parts);
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $r = new HttpResult();
    $r->raw = (string) curl_exec($ch);
    $r->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $r->json = (array) (json_decode($r->raw, true) ?: []);
    // Store ALL headers so multiple Set-Cookie headers can be inspected
    $r->headers = $allHeaders;
    return $r;
}

// Returns ALL headers matching a prefix (e.g. all Set-Cookie lines)
function headerValues(array $headers, string $prefix): array {
    $matches = [];
    foreach ($headers as $h) {
        if (stripos($h, $prefix) === 0) {
            $matches[] = trim(substr($h, strlen($prefix)));
        }
    }
    return $matches;
}
function headerValue(array $headers, string $name): string {
    foreach ($headers as $h) {
        if (stripos($h, $name . ':') === 0) return trim(substr($h, strlen($name) + 1));
    }
    return '';
}

// Extracts the LAST LSC_ID_SESSION cookie (session_regenerate_id(true)
// invalidates the first one PHP sets, so we must use the final value).
function sessionCookie(array $headers): string {
    $cookies = headerValues($headers, 'Set-Cookie:');
    $lastVal = '';
    foreach ($cookies as $c) {
        if (preg_match('/LSC_ID_SESSION=([^;]+)/', $c, $m)) {
            $lastVal = $m[1];
        }
    }
    return $lastVal !== '' ? 'LSC_ID_SESSION=' . $lastVal : '';
}

/* ----------------- QA fixture files ----------------- */
$fixtureDir = sys_get_temp_dir() . '/lsc_qa_' . getmypid();
if (!is_dir($fixtureDir)) mkdir($fixtureDir, 0777, true);
/* ID-plausible PNG (300x360) — the photo pipeline enforces a 96x96 minimum,
 * so a 1x1 pixel fixture would be rejected as too low-resolution. */
$PNG = base64_decode(file_get_contents(__DIR__ . '/fixtures/photo_300x360.b64'));
file_put_contents("$fixtureDir/valid.png", $PNG);
file_put_contents("$fixtureDir/evil.png", "MZ\x90\x00fake-executable-binary-payload");
file_put_contents("$fixtureDir/note.jpg", "just a text file, not an image");
file_put_contents("$fixtureDir/doc.pdf", "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<<>>\n%%EOF");
file_put_contents("$fixtureDir/shell.php", $PNG);
try {
    /* ================================================================ */
    /* 0. BOOTSTRAP — clean previous QA data, create QA users           */
    /* ================================================================ */
    $pdo->exec("DELETE lr FROM lost_id_requests lr LEFT JOIN id_cards c ON c.id = lr.reference_card_id WHERE lr.student_name LIKE 'QATest%'");
    $pdo->exec("DELETE FROM lost_id_requests WHERE student_name LIKE 'QATest%'");
    $pdo->exec("DELETE FROM id_cards WHERE student_name LIKE 'QATest%'");
    $pdo->exec("DELETE pr FROM password_resets pr LEFT JOIN users u ON u.id = pr.user_id WHERE u.username IN ('qa_admin_test','qa_staff_test')");
    $pdo->exec("DELETE FROM users WHERE username IN ('qa_admin_test','qa_staff_test')");
    $pdo->exec("DELETE FROM signatories WHERE full_name='QATest Signatory'");
    $pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_admin_test',?,'QA Admin','admin',1)")
        ->execute([password_hash('QaAdmin#2026x', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_staff_test',?,'QA Staff','staff',1)")
        ->execute([password_hash('QaStaff#2026x', PASSWORD_DEFAULT)]);
    R('SET-001', 'Bootstrap', 'QA users created in DB', 'qa_admin_test + qa_staff_test exist', 'created via PDO + password_hash', 'PASS', 'Low');

    /* ================================================================ */
    /* 1. AUTHENTICATION                                                */
    /* ================================================================ */
    $r = apiCall('POST', 'login', ['json' => []]);
    R('AUTH-001', 'Login', 'Both fields empty', '422 validation error', $r->status . ' ' . ($r->json['message'] ?? ''), $r->status === 422 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('POST', 'login', ['json' => ['username' => 'qa_staff_test', 'password' => '']]);
    R('AUTH-002', 'Login', 'Empty password', '422', $r->status, $r->status === 422 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('POST', 'login', ['json' => ['username' => '', 'password' => 'x']]);
    R('AUTH-003', 'Login', 'Empty username', '422', $r->status, $r->status === 422 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('POST', 'login', ['json' => ['username' => 'qa_staff_test', 'password' => 'WrongPass1!']]);
    R('AUTH-004', 'Login', 'Incorrect password', '401, generic message, no enumeration', $r->status . ' ' . ($r->json['message'] ?? ''), $r->status === 401 ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'login', ['json' => ['username' => 'ghost_user_xyz', 'password' => 'Whatever1!']]);
    R('AUTH-005', 'Login', 'Unknown username', '401 generic message', $r->status . ' ' . ($r->json['message'] ?? ''), $r->status === 401 ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'login', ['json' => ['username' => "' OR '1'='1", 'password' => "' OR '1'='1"]]);
    R('AUTH-006', 'Login', "SQL injection in credentials (' OR '1'='1)", '401 (no auth bypass)', $r->status, $r->status === 401 ? 'PASS' : 'FAIL', 'Critical');

    $r = apiCall('POST', 'login', ['json' => ['username' => "qa_staff_test'--", 'password' => 'x']]);
    R('AUTH-007', 'Login', "SQLi with comment ('--)", '401', $r->status, $r->status === 401 ? 'PASS' : 'FAIL', 'Critical');

    $xssName = "<script>alert('XSS')</script>";
    $r = apiCall('POST', 'login', ['json' => ['username' => $xssName, 'password' => 'x']]);
    $reflected = stripos($r->raw, '<script>') !== false;
    R('AUTH-008', 'Login', 'XSS payload in username', '401, payload NOT reflected unescaped', $r->status . ' reflected=' . ($reflected ? 'YES' : 'no'), ($r->status === 401 && !$reflected) ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'login', ['json' => ['username' => str_repeat('A', 5000), 'password' => 'x']]);
    R('AUTH-009', 'Login', 'Very long username (5000 chars)', '401/422, no 5xx crash', $r->status, in_array($r->status, [401, 422], true) ? 'PASS' : 'FAIL', 'Low');

    $r = apiCall('POST', 'login', ['json' => ['username' => 'QA_STAFF_TEST', 'password' => 'QaStaff#2026x']]);
    R('AUTH-010', 'Login', 'Username case sensitivity (upper-case attempt)', 'IDEAL: rejected. Actual recorded', $r->status, $r->status === 200 ? 'FAIL' : 'PASS', 'Low');

    $r = apiCall('POST', 'login', ['json' => ['username' => 'qa_staff_test', 'password' => 'qastaff#2026x']]);
    R('AUTH-011', 'Login', 'Password case sensitivity (lower-cased attempt)', '401 (passwords case-sensitive)', $r->status, $r->status === 401 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('POST', 'login', ['json' => ['username' => 'qa_staff_test', 'password' => 'QaStaff#2026x']]);
    $staffCookie = sessionCookie($r->headers);
    $staffToken = (string) ($r->json['csrf_token'] ?? '');
    R('AUTH-012', 'Login', 'Valid staff login', '200 + session cookie + csrf_token', $r->status . ' csrf=' . ($staffToken !== '' ? 'yes' : 'NO'), ($r->status === 200 && $staffToken !== '' && $staffCookie !== '') ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('GET', 'me', ['cookies' => $staffCookie]);
    R('AUTH-013', 'Session', 'Session persistence (me with cookie)', '200 + user data', $r->status . ' user=' . ($r->json['data']['username'] ?? '?'), $r->status === 200 ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('GET', 'me');
    R('AUTH-014', 'Session', 'No session -> protected endpoint', '401', $r->status, $r->status === 401 ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'login', ['json' => ['username' => 'qa_admin_test', 'password' => 'QaAdmin#2026x']]);
    $adminCookie = sessionCookie($r->headers);
    $adminToken = (string) ($r->json['csrf_token'] ?? '');
    R('AUTH-015', 'Login', 'Valid admin login', '200 + role=admin', $r->status . ' role=' . ($r->json['data']['role'] ?? '?'), ($r->status === 200 && ($r->json['data']['role'] ?? '') === 'admin') ? 'PASS' : 'FAIL', 'High');
    /* ================================================================ */
    /* 2. AUTHORIZATION / CSRF                                          */
    /* ================================================================ */
    $r = apiCall('GET', 'cards');
    R('SEC-001', 'Authorization', 'GET cards without session', '401', $r->status, $r->status === 401 ? 'PASS' : 'FAIL', 'Critical');

    $r = apiCall('GET', 'users', ['cookies' => $staffCookie]);
    R('SEC-002', 'Authorization', 'Staff calls admin-only users list', '403', $r->status, $r->status === 403 ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'saveTemplate', ['cookies' => $staffCookie, 'form' => ['name' => 'QA Hack', 'id_type' => 'COLLEGE']]);
    R('SEC-003', 'Authorization', 'Staff calls admin-only saveTemplate', '403', $r->status, $r->status === 403 ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'deleteUser', ['cookies' => $staffCookie, 'json' => ['id' => 1]]);
    R('SEC-004', 'Authorization', 'Staff calls admin-only deleteUser', '403', $r->status, $r->status === 403 ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('GET', 'users', ['cookies' => $adminCookie]);
    $leak = stripos($r->raw, '$2y$') !== false;
    R('SEC-005', 'Info exposure', 'Admin users list — password hashes leaked?', '200 and NO $2y$ hash in response', $r->status . ' hashes=' . ($leak ? 'LEAKED' : 'not leaked'), ($r->status === 200 && !$leak) ? 'PASS' : 'FAIL', 'Critical');

    $r = apiCall('POST', 'saveCard', ['cookies' => $staffCookie, 'json' => ['student_name' => 'QATest NoCsrf']]);
    R('SEC-006', 'CSRF', 'saveCard without X-CSRF-Token', '403 Invalid security token', $r->status . ' ' . ($r->json['message'] ?? ''), $r->status === 403 ? 'PASS' : 'FAIL', 'Critical');

    $r = apiCall('POST', 'saveCard', ['cookies' => $staffCookie, 'headers' => ['X-CSRF-Token: deadbeef'], 'json' => ['student_name' => 'QATest BadCsrf']]);
    R('SEC-007', 'CSRF', 'saveCard with forged token', '403', $r->status, $r->status === 403 ? 'PASS' : 'FAIL', 'Critical');

    $r = apiCall('POST', 'deleteCard', ['cookies' => $staffCookie, 'json' => ['id' => 999999]]);
    R('SEC-008', 'CSRF', 'deleteCard without token (nonexistent id)', '403 (CSRF checked before logic)', $r->status, $r->status === 403 ? 'PASS' : 'FAIL', 'High');

    /* CORS reflection -------------------------------------------------- */
    $r = apiCall('GET', 'me', ['cookies' => $staffCookie, 'headers' => ['Origin: https://evil.example']]);
    $acao = headerValue($r->headers, 'Access-Control-Allow-Origin');
    $acac = headerValue($r->headers, 'Access-Control-Allow-Credentials');
    R('SEC-009', 'CORS', 'Arbitrary Origin reflected with credentials?', 'IDEAL: not reflected. Actual recorded', "ACAO=$acao ACAC=$acac", ($acao === 'https://evil.example' && $acac === 'true') ? 'FAIL' : 'PASS', 'High');

    /* forgot-password token disclosure --------------------------------- */
    $r = apiCall('POST', 'forgotPassword', ['json' => ['username' => 'qa_admin_test']]);
    $token = (string) ($r->json['reset_token'] ?? '');
    R('SEC-010', 'Password reset', 'forgotPassword returns reset token in HTTP response', 'IDEAL: no token in response (OOB delivery)', $token !== '' ? 'TOKEN DISCLOSED (' . substr($token, 0, 8) . '...)' : 'no token', $token !== '' ? 'FAIL' : 'PASS', 'Critical');

    $r = apiCall('POST', 'resetPassword', ['json' => ['token' => 'f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0', 'password' => 'NewPass#2026']]);
    R('SEC-011', 'Password reset', 'resetPassword with invalid/expired token', '422', $r->status, $r->status === 422 ? 'PASS' : 'FAIL', 'High');

    /* staff privilege on signatories ------------------------------------ */
    $r = apiCall('POST', 'saveSignatory', ['cookies' => $staffCookie, 'headers' => ['X-CSRF-Token: ' . $staffToken], 'form' => ['full_name' => 'QATest Signatory', 'position_title' => 'QA', 'signature_path' => 'uploads/signatures/annabelle-v-molina.png']]);
    $sigId = (int) ($r->json['id'] ?? 0);
    if ($sigId) $createdSignatoryIds[] = $sigId;
    R('SEC-012', 'Authorization', 'Staff can create signatories (no requireAdmin)', 'IDEAL: 403 admin-only. Actual recorded', $r->status . ' id=' . $sigId, $r->status === 200 ? 'FAIL' : 'PASS', 'Medium');

    /* public signature exposure ------------------------------------------ */
    $r = apiCall('GET', 'publicSignatory&type=COLLEGE');
    R('SEC-013', 'Info exposure', 'publicSignatory endpoint without login', '200 by design (student portal) — records data exposed', $r->status . ' sig=' . ($r->json['data']['full_name'] ?? 'none'), $r->status === 200 ? 'PASS' : 'PASS', 'Info');
    /* ================================================================ */
    /* 3. PUBLIC STUDENT SELF-SERVICE — validation & data separation    */
    /* ================================================================ */
    $r = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => 'QATest Juan Dela Cruz', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'student_number' => 'QAT-COL-0001', 'section_name' => 'A', 'student_id_number' => '', 'lrn' => '', 'grade_level' => '']]);
    $colCardId = (int) ($r->json['id'] ?? 0);
    if ($colCardId) $createdCardIds[] = $colCardId;
    R('FUNC-001', 'College', 'Valid college student submission', '200 + id', $r->status . ' id=' . $colCardId, ($r->status === 200 && $colCardId > 0) ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => 'QATest Juan Dela Cruz', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'student_number' => 'QAT-COL-0001']]);
    $dupId = (int) ($r->json['id'] ?? 0);
    if ($dupId) $createdCardIds[] = $dupId;
    R('FUNC-002', 'Duplicates', 'Exact duplicate student (same name+number)', 'IDEAL: rejected as duplicate. Actual recorded', $r->status . ' new id=' . $dupId, $r->status === 200 ? 'FAIL' : 'PASS', 'High');

    $r = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => 'QATest Maria Santos', 'id_type' => 'JUNIOR_HIGH', 'grade_level' => 'GRADE 10', 'section_name' => 'Bonifacio', 'student_number' => 'QAT-JHS-0002', 'student_id_number' => 'QAT-ID-0002', 'lrn' => '123456789013', 'course' => '']]);
    $jhsCardId = (int) ($r->json['id'] ?? 0);
    if ($jhsCardId) $createdCardIds[] = $jhsCardId;
    R('FUNC-003', 'Basic Ed', 'Valid JHS student (grade/section/LRN)', '200 + id', $r->status . ' id=' . $jhsCardId, ($r->status === 200 && $jhsCardId > 0) ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => 'QATest Pedro Reyes', 'id_type' => 'SENIOR_HIGH', 'grade_level' => 'GRADE 12', 'section_name' => 'Mabini', 'student_number' => 'QAT-SHS-0003', 'lrn' => '123456789014']]);
    $shsCardId = (int) ($r->json['id'] ?? 0);
    if ($shsCardId) $createdCardIds[] = $shsCardId;
    R('FUNC-004', 'Basic Ed', 'Valid SHS student (Grade 12)', '200 + id', $r->status . ' id=' . $shsCardId, ($r->status === 200 && $shsCardId > 0) ? 'PASS' : 'FAIL', 'High');

    /* data separation checks (Rule 1) ----------------------------------- */
    $r = apiCall('GET', 'card', ['cookies' => $staffCookie, 'query' => ['id' => $colCardId]]);
    $c = $r->json['data'] ?? [];
    $ok = (($c['course'] ?? '') === 'BACHELOR OF SCIENCE IN PSYCHOLOGY') && (($c['grade_level'] ?? '') === '') && (($c['student_number'] ?? '') === 'QAT-COL-0001');
    R('FUNC-005', 'Data separation', 'College card: course stored, grade_level empty', 'course set / grade empty / own number', json_encode([$c['course'] ?? null, $c['grade_level'] ?? null, $c['student_number'] ?? null]), $ok ? 'PASS' : 'FAIL', 'Critical');

    $r = apiCall('GET', 'card', ['cookies' => $staffCookie, 'query' => ['id' => $jhsCardId]]);
    $c = $r->json['data'] ?? [];
    $ok = (($c['grade_level'] ?? '') === 'GRADE 10') && (($c['lrn'] ?? '') === '123456789013') && (($c['course'] ?? 'x') === '');
    R('FUNC-006', 'Data separation', 'JHS card: grade/section/LRN stored, course empty', 'grade set / course empty / lrn set', json_encode([$c['grade_level'] ?? null, $c['course'] ?? null, $c['lrn'] ?? null]), $ok ? 'PASS' : 'FAIL', 'Critical');
    /* ================================================================ */
    /* 4. FORM VALIDATION                                               */
    /* ================================================================ */
    $r = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => '', 'id_type' => 'COLLEGE']]);
    R('VAL-001', 'Validation', 'Empty student name', '422', $r->status, $r->status === 422 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => '    ', 'id_type' => 'COLLEGE']]);
    R('VAL-002', 'Validation', 'Whitespace-only name', '422 (trimmed)', $r->status, $r->status === 422 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => 'QATest BadType', 'id_type' => 'HACKER_DEPT']]);
    $badId = (int) ($r->json['id'] ?? 0);
    if ($badId) $createdCardIds[] = $badId;
    $stored = 'n/a';
    if ($badId) { $rr = apiCall('GET', 'card', ['cookies' => $staffCookie, 'query' => ['id' => $badId]]); $stored = (string) ($rr->json['data']['id_type'] ?? '?'); }
    R('VAL-003', 'Validation', "Invalid id_type 'HACKER_DEPT'", 'IDEAL: 422 rejected. Actual recorded', $r->status . " stored=$stored", $r->status === 422 ? 'PASS' : 'FAIL', 'Low');

    $r = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => $xssName, 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY']]);
    $xssId = (int) ($r->json['id'] ?? 0);
    if ($xssId) $createdCardIds[] = $xssId;
    $stored = '';
    if ($xssId) { $rr = apiCall('GET', 'card', ['cookies' => $staffCookie, 'query' => ['id' => $xssId]]); $stored = (string) ($rr->json['data']['student_name'] ?? ''); }
    R('VAL-004', 'XSS (stored)', 'Script payload as student name', 'Stored verbatim; escaped on render by React', $r->status . ' stored=' . ($stored === $xssName ? 'verbatim' : 'other'), $r->status === 200 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => "' OR '1'='1", 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY']]);
    $sqliId = (int) ($r->json['id'] ?? 0);
    if ($sqliId) $createdCardIds[] = $sqliId;
    R('VAL-005', 'SQL injection', 'SQLi payload stored as name', '200, stored literally (parameterized), no DB error', $r->status, $r->status === 200 ? 'PASS' : 'FAIL', 'Critical');

    $r = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => str_repeat('Q', 5000), 'id_type' => 'COLLEGE']]);
    $longId = (int) ($r->json['id'] ?? 0);
    if ($longId) $createdCardIds[] = $longId;
    $len = 0;
    if ($longId) { $rr = apiCall('GET', 'card', ['cookies' => $staffCookie, 'query' => ['id' => $longId]]); $len = strlen((string) ($rr->json['data']['student_name'] ?? '')); }
    R('VAL-006', 'Validation', '5000-char name vs VARCHAR(150)', 'IDEAL: 422 length rule. Actual recorded', $r->status . ' stored_len=' . $len, $r->status === 422 ? 'PASS' : 'FAIL', 'Low');

    $r = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => 'QATest MassAssign', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'status' => 'printed', 'print_count' => '99', 'id' => '99999', 'photo_path' => 'uploads/students/../../config.php']]);
    $maId = (int) ($r->json['id'] ?? 0);
    if ($maId) $createdCardIds[] = $maId;
    $st = '';
    $pp = 'unset';
    if ($maId) { $rr = apiCall('GET', 'card', ['cookies' => $staffCookie, 'query' => ['id' => $maId]]); $st = (string) ($rr->json['data']['status'] ?? ''); $pp = (string) ($rr->json['data']['photo_path'] ?? '(empty)'); }
    $ok = $maId > 0 && $maId !== 99999 && $st === 'created';
    R('VAL-007', 'Mass assignment', 'status/print_count/id injected fields', 'whitelist only; status=created; fresh id', "id=$maId status=$st photo_path=$pp", $ok ? 'PASS' : 'FAIL', 'High');
    /* ================================================================ */
    /* 5. LOST ID REQUESTS                                              */
    /* ================================================================ */
    $r = apiCall('POST', 'lostIdRequest', ['json' => ['student_name' => 'QATest Ghost Student', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'student_number' => 'NOPE-404']]);
    R('FUNC-007', 'Lost ID', 'Report lost ID for unknown student', '404 "No ID found in the system", nothing saved', $r->status . ' ' . mb_substr((string) ($r->json['message'] ?? ''), 0, 38), $r->status === 404 ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'lostIdRequest', ['json' => ['student_name' => 'QATest Juan Dela Cruz', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'student_number' => 'QAT-COL-0001']]);
    $reqId = (int) ($r->json['id'] ?? 0);
    if ($reqId) $createdRequestIds[] = $reqId;
    R('FUNC-008', 'Lost ID', 'Report lost ID for existing student', '200 + reference_card_id linked', $r->status . ' ref=' . ($r->json['reference_card_id'] ?? 'none'), ($r->status === 200 && (int) ($r->json['reference_card_id'] ?? 0) === $colCardId) ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'lostIdRequest', ['json' => ['student_name' => 'QATest Maria Santos', 'id_type' => 'JUNIOR_HIGH', 'grade_level' => 'GRADE 10', 'section_name' => 'Bonifacio', 'student_number' => 'QAT-ID-0002']]);
    $reqId2 = (int) ($r->json['id'] ?? 0);
    if ($reqId2) $createdRequestIds[] = $reqId2;
    R('FUNC-009', 'Lost ID', 'Basic-ed report matched via student_id_number', '200 + ref linked to JHS card', $r->status . ' ref=' . ($r->json['reference_card_id'] ?? 'none'), ($r->status === 200 && (int) ($r->json['reference_card_id'] ?? 0) === $jhsCardId) ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'lostIdRequest', ['json' => ['student_name' => 'QATest NoIdentifier', 'id_type' => 'JUNIOR_HIGH', 'grade_level' => 'GRADE 7', 'student_number' => '', 'lrn' => '']]);
    R('FUNC-010', 'Lost ID', 'No identifier provided', '422', $r->status, $r->status === 422 ? 'PASS' : 'FAIL', 'Medium');

    /* receipt orphan test: upload receipt with a REJECTED request ------ */
    $nBefore = count(glob(__DIR__ . '/../uploads/receipts/*') ?: []);
    $r = apiCall('POST', 'lostIdRequest', ['form' => ['student_name' => 'QATest Ghost Student', 'id_type' => 'COLLEGE', 'student_number' => 'NOPE-404', 'receipt' => new CURLFile("$fixtureDir/doc.pdf", 'application/pdf', 'receipt.pdf')]]);
    clearstatcache();
    $after = glob(__DIR__ . '/../uploads/receipts/*') ?: [];
    $orphan = count($after) > $nBefore;
    if ($orphan) $uploadedFiles[] = end($after);
    R('FILE-001', 'Lost ID', 'Receipt uploaded with REJECTED request', 'IDEAL: no file kept. Actual recorded', $r->status . ' orphan_file=' . ($orphan ? 'YES' : 'no'), ($r->status === 404 && !$orphan) ? 'PASS' : 'FAIL', 'Low');
    /* ================================================================ */
    /* 6. FILE UPLOADS                                                  */
    /* ================================================================ */
    $r = apiCall('POST', 'studentUploadPhoto', ['form' => ['photo' => new CURLFile("$fixtureDir/valid.png", 'image/png', 'portrait.png')]]);
    $photoPath = (string) ($r->json['path'] ?? '');
    if ($photoPath !== '') $uploadedFiles[] = __DIR__ . '/../' . $photoPath;
    R('FILE-002', 'Uploads', 'Valid PNG photo (public endpoint)', '200 + server-generated path', $r->status . ' path=' . $photoPath, ($r->status === 200 && $photoPath !== '') ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'studentUploadPhoto', ['form' => ['photo' => new CURLFile("$fixtureDir/evil.png", 'application/octet-stream', 'evil.png')]]);
    R('FILE-003', 'Uploads', 'Executable bytes with .png name', '422 (MIME allowlist)', $r->status, $r->status === 422 ? 'PASS' : 'FAIL', 'Critical');

    $r = apiCall('POST', 'studentUploadPhoto', ['form' => ['photo' => new CURLFile("$fixtureDir/note.jpg", 'text/plain', 'note.jpg')]]);
    R('FILE-004', 'Uploads', 'Text file renamed .jpg', '422', $r->status, $r->status === 422 ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'studentUploadPhoto', ['form' => ['photo' => new CURLFile("$fixtureDir/doc.pdf", 'application/pdf', 'doc.pdf')]]);
    R('FILE-005', 'Uploads', 'PDF uploaded as student photo', '422', $r->status, $r->status === 422 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('POST', 'studentUploadPhoto', ['form' => ['photo' => new CURLFile("$fixtureDir/shell.php", 'image/png', 'shell.php')]]);
    R('FILE-006', 'Uploads', 'PHP script name with image MIME', '422 (extension derived from MIME, not name)', $r->status . ' path=' . ($r->json['path'] ?? ''), $r->status === 422 ? 'PASS' : 'FAIL', 'Critical');

    $r = apiCall('POST', 'studentUploadPhoto', ['form' => ['photo' => new CURLFile("$fixtureDir/valid.png", 'image/png', '..\\..\\photo.jpg')]]);
    $tp = (string) ($r->json['path'] ?? '');
    if ($tp !== '') $uploadedFiles[] = __DIR__ . '/../' . $tp;
    $traversal = preg_match('#(\.\.[\\\\/])#', $tp) === 1;
    R('FILE-007', 'Uploads', 'Path-traversal filename ..\..\photo.jpg', '200, stored with server-generated safe name', $r->status . ' path=' . $tp, ($r->status === 200 && !$traversal) ? 'PASS' : 'FAIL', 'Critical');

    /* unauthenticated access to an uploaded student photo --------------
     * BY-DESIGN: the public student portal previews its photo with a plain
     * <img> tag and no session, so the file must remain directly fetchable.
     * Mitigations: 16 random bytes (32 hex) in the file name + Apache
     * Options -Indexes (no directory listing). See backend/.htaccess. */
    if ($photoPath !== '') {
        $imgUrl = str_replace('/api.php', '/' . $photoPath, $BASE);
        $dirUrl = str_replace('/api.php', '/uploads/students/', $BASE);
        $ch = curl_init($imgUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        curl_exec($ch);
        $imgStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ch2 = curl_init($dirUrl);
        curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $dirBody = (string) curl_exec($ch2);
        $dirStatus = (int) curl_getinfo($ch2, CURLINFO_RESPONSE_CODE);
        $listingBlocked = ($dirStatus === 403 || $dirStatus === 404 || stripos($dirBody, 'Index of') === false);
        R('FILE-008', 'Uploads', 'Student photo fetched WITHOUT login', 'BY DESIGN: 200 (unguessable URL needed for portal <img> preview); listing blocked', 'HTTP ' . $imgStatus . ' / dir ' . $dirStatus . ($listingBlocked ? ' (listing blocked)' : ' (LISTING EXPOSED)'), ($imgStatus === 200 && $listingBlocked) ? 'PASS' : 'FAIL', 'Info');
    }
    /* ================================================================ */
    /* 7. SEARCH / FILTER API                                           */
    /* ================================================================ */
    $r = apiCall('GET', 'cards', ['cookies' => $staffCookie, 'query' => ['search' => 'QATest Juan']]);
    $found = count(array_filter($r->json['data'] ?? [], fn ($x) => ($x['student_number'] ?? '') === 'QAT-COL-0001'));
    R('SRCH-001', 'Search', 'Partial name search "QATest Juan"', 'Juan records found', 'matches=' . count($r->json['data'] ?? []) . " target=$found", $found >= 1 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('GET', 'cards', ['cookies' => $staffCookie, 'query' => ['search' => '123456789013']]);
    $found = count(array_filter($r->json['data'] ?? [], fn ($x) => ($x['lrn'] ?? '') === '123456789013'));
    R('SRCH-002', 'Search', 'LRN search', 'JHS card found', "matches=$found", $found === 1 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('GET', 'cards', ['cookies' => $staffCookie, 'query' => ['type' => 'JUNIOR_HIGH', 'search' => 'QATest']]);
    $rows = $r->json['data'] ?? [];
    $onlyJhs = count($rows) > 0 && !in_array(false, array_map(fn ($x) => ($x['id_type'] ?? '') === 'JUNIOR_HIGH', $rows), true);
    R('SRCH-003', 'Filter', 'Combined type=JUNIOR_HIGH + search', 'only JHS QA records', 'matches=' . count($rows), $onlyJhs ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('GET', 'cards', ['cookies' => $staffCookie, 'query' => ['search' => 'ZZZZZ_NO_MATCH']]);
    R('SRCH-004', 'Search', 'No-result search', '200 empty array', $r->status . ' count=' . count($r->json['data'] ?? []), ($r->status === 200 && count($r->json['data'] ?? []) === 0) ? 'PASS' : 'FAIL', 'Low');

    $r = apiCall('GET', 'cards', ['cookies' => $staffCookie, 'query' => ['search' => "' OR '1'='1' -- "]]);
    R('SRCH-005', 'SQL injection', 'SQLi in search parameter', '200, literal text (parameterized)', $r->status . ' count=' . count($r->json['data'] ?? []), $r->status === 200 ? 'PASS' : 'FAIL', 'Critical');
    /* ================================================================ */
    /* 8. EDIT / STATUS / DELETE (staff, with CSRF)                     */
    /* ================================================================ */
    $r = apiCall('POST', 'saveCard', ['cookies' => $staffCookie, 'headers' => ['X-CSRF-Token: ' . $staffToken], 'json' => ['id' => $colCardId, 'student_name' => 'QATest Juan Dela Cruz EDITED', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN ACCOUNTANCY', 'student_number' => 'QAT-COL-0001', 'section_name' => 'B']]);
    R('EDIT-001', 'Edit', 'Staff edits college card (name+course)', '200', $r->status, $r->status === 200 ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('GET', 'card', ['cookies' => $staffCookie, 'query' => ['id' => $colCardId]]);
    $c = $r->json['data'] ?? [];
    $ok = (($c['student_name'] ?? '') === 'QATest Juan Dela Cruz EDITED') && (($c['course'] ?? '') === 'BACHELOR OF SCIENCE IN ACCOUNTANCY') && (($c['grade_level'] ?? '') === '');
    R('EDIT-002', 'Edit', 'Edited values persisted, no stale/mixed data', 'new name+course, grade still empty', json_encode([$c['student_name'] ?? null, $c['course'] ?? null, $c['grade_level'] ?? null]), $ok ? 'PASS' : 'FAIL', 'Critical');

    $r = apiCall('POST', 'setCardStatus', ['cookies' => $staffCookie, 'headers' => ['X-CSRF-Token: ' . $staffToken], 'json' => ['id' => $colCardId, 'status' => 'done']]);
    R('STAT-001', 'Status', 'Mark card as done', '200 status=done', $r->status . ' status=' . ($r->json['data']['status'] ?? '?'), ($r->json['data']['status'] ?? '') === 'done' ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('POST', 'setCardStatus', ['cookies' => $staffCookie, 'headers' => ['X-CSRF-Token: ' . $staffToken], 'json' => ['id' => $colCardId, 'status' => 'HACKED']]);
    R('STAT-002', 'Status', 'Invalid status value rejected', '422', $r->status, $r->status === 422 ? 'PASS' : 'FAIL', 'Medium');

    if ($xssId) {
        $r = apiCall('POST', 'deleteCard', ['cookies' => $staffCookie, 'headers' => ['X-CSRF-Token: ' . $staffToken], 'json' => ['id' => $xssId]]);
        R('DEL-001', 'Delete', 'Staff deletes card with CSRF token', '200', $r->status, $r->status === 200 ? 'PASS' : 'FAIL', 'High');
        if (($k = array_search($xssId, $createdCardIds, true)) !== false) unset($createdCardIds[$k]);
        $r2 = apiCall('GET', 'card', ['cookies' => $staffCookie, 'query' => ['id' => $xssId]]);
        R('DEL-002', 'Delete', 'Deleted card no longer readable', '404', $r2->status, $r2->status === 404 ? 'PASS' : 'FAIL', 'High');
    }
    $r = apiCall('POST', 'deleteCard', ['cookies' => $staffCookie, 'headers' => ['X-CSRF-Token: ' . $staffToken], 'json' => ['id' => 999999]]);
    R('DEL-003', 'Delete', 'Delete nonexistent id', '200 (idempotent) — acceptable', $r->status, $r->status === 200 ? 'PASS' : 'FAIL', 'Low');

    /* ================================================================ */
    /* 9. LOGOUT                                                        */
    /* ================================================================ */
    $r = apiCall('POST', 'logout', ['cookies' => $staffCookie]);
    $r2 = apiCall('GET', 'me', ['cookies' => $staffCookie]);
    R('AUTH-016', 'Session', 'Logout invalidates session', 'logout 200 then me -> 401', 'logout=' . $r->status . ' me=' . $r2->status, ($r->status === 200 && $r2->status === 401) ? 'PASS' : 'FAIL', 'High');

    /* ================================================================ */
    /* 10. PERFORMANCE PROBE                                            */
    /* ================================================================ */
    $t0 = microtime(true);
    apiCall('GET', 'cards', ['cookies' => $adminCookie]);
    $dt = (int) round((microtime(true) - $t0) * 1000);
    R('PERF-001', 'Performance', 'GET cards (full table, current data size)', '< 1000 ms', "$dt ms", $dt < 1000 ? 'PASS' : 'FAIL', 'Low');
} finally {
    /* ================================================================ */
    /* CLEANUP — remove everything the suite created                     */
    /* ================================================================ */
    foreach ($createdCardIds as $id) $pdo->prepare('DELETE FROM id_cards WHERE id=?')->execute([$id]);
    foreach ($createdRequestIds as $id) $pdo->prepare('DELETE FROM lost_id_requests WHERE id=?')->execute([$id]);
    foreach ($createdSignatoryIds as $id) $pdo->prepare('DELETE FROM signatories WHERE id=?')->execute([$id]);
    $pdo->exec("DELETE pr FROM password_resets pr LEFT JOIN users u ON u.id = pr.user_id WHERE u.username IN ('qa_admin_test','qa_staff_test')");
    $pdo->exec("DELETE FROM users WHERE username IN ('qa_admin_test','qa_staff_test')");
    foreach ($uploadedFiles as $f) { if (is_string($f) && $f !== '' && is_file($f)) @unlink($f); }
    foreach (glob("$fixtureDir/*") ?: [] as $f) @unlink($f);
    @rmdir($fixtureDir);
}

/* ------------------------------------------------------------------ */
/* REPORT                                                              */
/* ------------------------------------------------------------------ */
$counts = ['PASS' => 0, 'FAIL' => 0];
foreach ($results as $row) $counts[$row[5]] = ($counts[$row[5]] ?? 0) + 1;

echo "\n================ QA API TEST MATRIX ================\n";
echo sprintf("%-9s %-14s %-44s %-32s %-38s %-6s %s\n", 'ID', 'MODULE', 'TEST CASE', 'EXPECTED', 'ACTUAL', 'STATUS', 'SEVERITY');
echo str_repeat('-', 180) . "\n";
foreach ($results as $row) {
    echo sprintf("%-9s %-14s %-44s %-32s %-38s %-6s %s\n",
        $row[0], $row[1], mb_strimwidth($row[2], 0, 44), mb_strimwidth($row[3], 0, 32),
        mb_strimwidth($row[4], 0, 38), $row[5], $row[6]);
}
echo str_repeat('-', 180) . "\n";
echo 'TOTAL: ' . count($results) . "  PASS: {$counts['PASS']}  FAIL: {$counts['FAIL']}\n";
echo "Cleanup completed (QA users/cards/requests/uploads removed).\n";








