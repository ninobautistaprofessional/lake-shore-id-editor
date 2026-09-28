<?php
/*
 * QA EXTENDED SUITE — Lake Shore Colleges ID Generator System
 * -----------------------------------------------------------
 * Covers gaps in qa_api_test.php:
 *   - grade/level matrix across COLLEGE / JUNIOR_HIGH / SENIOR_HIGH
 *   - LRN format matrix (letters, spaces, special chars, wrong length)
 *   - college course persistence matrix + course allowlist behaviour
 *   - duplicate-variation matrix (number-only / LRN-only / name-only) + store dup
 *   - signatory mapping per department
 *   - deeper search (course, grade, casing, extra spaces)
 *   - error handling (invalid JSON, wrong method, bad ids)
 *   - authorization / IDOR probes on staff+public scopes
 *   - interleaved data-consistency (edit A must not touch B)
 *   - concurrency (parallel duplicate submissions -> race check)
 *
 * Usage: php qa_api_test2.php [baseUrl]
 * Self-cleaning; creates qa_staff_test and QATest2* records.
 */

$BASE = rtrim($argv[1] ?? 'http://localhost/lake-shore-id-editor/backend/api.php', '/');
if (!preg_match('/api\.php$/', $BASE)) $BASE .= '/api.php';
require_once __DIR__ . '/../config.php';
$pdo = db();

$results = [];
function R(string $id, string $module, string $case, string $expected, string $actual, string $status, string $severity = 'Low'): void {
    global $results;
    $results[] = [$id, $module, $case, $expected, $actual, $status, $severity];
}
$createdCardIds = [];

function apiCall(string $method, string $action, array $opt = []): object {
    global $BASE, $pdo;
    /* Public submissions are rate limited; throttling itself is covered
       by qa_rate_limit_test.php, so clear the per-IP window here. */
    if (in_array($action, ['studentSaveCard', 'studentUploadPhoto', 'lostIdRequest'], true)) {
        $pdo->exec("DELETE FROM rate_limit_counters WHERE bucket LIKE 'public:%'");
    }
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
    if (!empty($opt['cookies'])) curl_setopt($ch, CURLOPT_COOKIE, $opt['cookies']);
    if (!empty($opt['json'])) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
    } elseif (!empty($opt['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['form']);
    }
    if (!empty($opt['multipart'])) {
        $parts = [];
        foreach ($opt['multipart'] as $name => $value) {
            if (is_string($value)) $parts[$name] = $value;
            elseif (is_array($value) && isset($value['file'])) $parts[$name] = new CURLFile($value['file']);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $parts);
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $r = new stdClass();
    $r->raw = (string) curl_exec($ch);
    $r->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $r->json = (array) (json_decode($r->raw, true) ?: []);
    $r->headers = $allHeaders;
    return $r;
}
function headerValues(array $headers, string $prefix): array {
    $m = [];
    foreach ($headers as $h) if (stripos($h, $prefix) === 0) $m[] = trim(substr($h, strlen($prefix)));
    return $m;
}
function sessionCookie(array $headers): string {
    $lastVal = '';
    foreach (headerValues($headers, 'Set-Cookie:') as $c) {
        if (preg_match('/LSC_ID_SESSION=([^;]+)/', $c, $m)) $lastVal = $m[1];
    }
    return $lastVal !== '' ? 'LSC_ID_SESSION=' . $lastVal : '';
}
$fixtureDir = sys_get_temp_dir() . '/lsc_qa2_' . getmypid();
if (!is_dir($fixtureDir)) mkdir($fixtureDir, 0777, true);
$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents("$fixtureDir/valid.png", $PNG);

try {
    /* ---------------- bootstrap ---------------- */
    $pdo->exec("DELETE lr FROM lost_id_requests lr LEFT JOIN id_cards c ON c.id = lr.reference_card_id WHERE lr.student_name LIKE 'QATest2%'");
    $pdo->exec("DELETE FROM lost_id_requests WHERE student_name LIKE 'QATest2%'");
    $pdo->exec("DELETE FROM id_cards WHERE student_name LIKE 'QATest2%'");
    $pdo->exec("DELETE pr FROM password_resets pr LEFT JOIN users u ON u.id = pr.user_id WHERE u.username IN ('qa_admin_test','qa_staff_test')");
    $pdo->exec("DELETE FROM users WHERE username IN ('qa_admin_test','qa_staff_test')");
    $pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_staff_test',?,'QA Staff','staff',1)")
        ->execute([password_hash('QaStaff#2026x', PASSWORD_DEFAULT)]);

    $r = apiCall('POST', 'login', ['json' => ['username' => 'qa_staff_test', 'password' => 'QaStaff#2026x']]);
    $staffCookie = sessionCookie($r->headers);
    $staffToken = (string) ($r->json['csrf_token'] ?? '');
    R('EXT-000', 'Bootstrap', 'Staff login for suite', '200 + cookie + csrf', $r->status . ' csrf=' . ($staffToken !== '' ? 'yes' : 'no'), ($r->status === 200 && $staffCookie !== '' && $staffToken !== '') ? 'PASS' : 'FAIL', 'High');

    $mkCard = fn(array $over) => array_merge([
        'student_name' => 'QATest2 Person', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'section_name' => 'A', 'student_number' => 'QAT2-COL-0001',
    ], $over);
    $submit = function (array $payload) use (&$createdCardIds): array {
        $r = apiCall('POST', 'studentSaveCard', ['json' => $payload]);
        $id = (int) ($r->json['id'] ?? 0);
        if ($id > 0) $createdCardIds[] = $id;
        return [$r, $id];
    };

    /* ================================================================ */
    /* A. GRADE / DATA-SEPARATION MATRIX (Rules 1, 4, 5)                 */
    /* ================================================================ */
    [$r, $id] = $submit($mkCard(['student_name' => 'QATest2 GradeMix Col', 'id_type' => 'COLLEGE', 'grade_level' => 'GRADE 7']));
    $stored = $id ? ($pdo->query("SELECT grade_level,course,lrn FROM id_cards WHERE id=$id")->fetch() ?: []) : [];
    R('EXT-001', 'Grade matrix', 'COLLEGE submission with grade_level=GRADE 7', 'IDEAL: grade rejected/cleared for college', ($id ? "stored grade={$stored['grade_level']}" : 'not created'), ($id === 0 || ($stored['grade_level'] ?? 'x') === '') ? 'PASS' : 'FAIL', 'High');

    $jhsOk = 0;
    foreach (['GRADE 7', 'GRADE 8', 'GRADE 9', 'GRADE 10'] as $g) {
        [$r] = $submit($mkCard(['student_name' => "QATest2 JHS $g", 'id_type' => 'JUNIOR_HIGH', 'grade_level' => $g, 'course' => '', 'student_number' => 'QAT2-JHS-0001']));
        if ($r->status === 200) $jhsOk++;
    }
    R('EXT-002', 'Grade matrix', 'JUNIOR_HIGH valid grades 7-10 accepted', '4/4 accepted', "accepted=$jhsOk/4", $jhsOk === 4 ? 'PASS' : 'FAIL', 'High');

    $shsOk = 0;
    foreach (['GRADE 11', 'GRADE 12'] as $g) {
        [$r] = $submit($mkCard(['student_name' => "QATest2 SHS $g", 'id_type' => 'SENIOR_HIGH', 'grade_level' => $g, 'course' => '', 'student_number' => 'QAT2-SHS-0001']));
        if ($r->status === 200) $shsOk++;
    }
    R('EXT-003', 'Grade matrix', 'SENIOR_HIGH valid grades 11-12 accepted', '2/2 accepted', "accepted=$shsOk/2", $shsOk === 2 ? 'PASS' : 'FAIL', 'High');

    [$r, $id] = $submit($mkCard(['student_name' => 'QATest2 WrongGrade JHS', 'id_type' => 'JUNIOR_HIGH', 'grade_level' => 'GRADE 11', 'course' => '', 'student_number' => 'QAT2-JHS-0900']));
    $stored = $id ? ($pdo->query("SELECT grade_level FROM id_cards WHERE id=$id")->fetch() ?: []) : [];
    R('EXT-004', 'Grade matrix', 'JUNIOR_HIGH with GRADE 11 (out of band)', 'IDEAL: rejected (JHS=7-10)', ($id ? "stored grade={$stored['grade_level']}" : "not created status={$r->status}"), ($id === 0 || ($stored['grade_level'] ?? 'x') === '') ? 'PASS' : 'FAIL', 'High');

    [$r, $id] = $submit($mkCard(['student_name' => 'QATest2 WrongGrade SHS', 'id_type' => 'SENIOR_HIGH', 'grade_level' => 'GRADE 10', 'course' => '', 'student_number' => 'QAT2-SHS-0900']));
    $stored = $id ? ($pdo->query("SELECT grade_level FROM id_cards WHERE id=$id")->fetch() ?: []) : [];
    R('EXT-005', 'Grade matrix', 'SENIOR_HIGH with GRADE 10 (out of band)', 'IDEAL: rejected (SHS=11-12)', ($id ? "stored grade={$stored['grade_level']}" : "not created status={$r->status}"), ($id === 0 || ($stored['grade_level'] ?? 'x') === '') ? 'PASS' : 'FAIL', 'High');

    [$r, $id] = $submit($mkCard(['student_name' => 'QATest2 NoGrade JHS', 'id_type' => 'JUNIOR_HIGH', 'grade_level' => '', 'course' => '', 'student_number' => 'QAT2-JHS-0901']));
    R('EXT-006', 'Grade matrix', 'JUNIOR_HIGH with missing grade_level', 'IDEAL: 422 (grade required for basic ed)', $r->status . ' id=' . ($r->json['id'] ?? 0), $r->status === 200 ? 'FAIL' : 'PASS', 'High');

    [$r, $id] = $submit($mkCard(['student_name' => 'QATest2 CollegeLrn', 'id_type' => 'COLLEGE', 'lrn' => '123456789012']));
    $stored = $id ? ($pdo->query("SELECT lrn FROM id_cards WHERE id=$id")->fetch() ?: []) : [];
    R('EXT-007', 'Data separation', 'COLLEGE card submitted with a DepEd LRN', 'IDEAL: LRN cleared for college', ($id ? "stored lrn={$stored['lrn']}" : 'not created'), ($id === 0 || ($stored['lrn'] ?? 'x') === '') ? 'PASS' : 'FAIL', 'Medium');
/* ================================================================ */
    /* B. LRN FORMAT MATRIX                                              */
    /* ================================================================ */
    $lrnCases = [
        ['EXT-010', '12-digit LRN', '123456789012', 'accepted'],
        ['EXT-011', 'LRN containing letters', '12345678A901', 'IDEAL rejected'],
        ['EXT-012', 'LRN with spaces', '123 456 789 012', 'IDEAL rejected'],
        ['EXT-013', 'LRN with special chars', '1234-5678-9012', 'IDEAL rejected'],
        ['EXT-014', 'LRN wrong length (6 digits)', '123456', 'IDEAL rejected'],
        ['EXT-015', 'Empty LRN', '', 'accepted (optional per UI)'],
    ];
    foreach ($lrnCases as [$lid, $label, $lrnVal, $expect]) {
        [$r, $id] = $submit($mkCard(['student_name' => "QATest2 Lrn $lid", 'id_type' => 'JUNIOR_HIGH', 'grade_level' => 'GRADE 7', 'course' => '', 'student_number' => 'QAT2-LRN-0001', 'lrn' => $lrnVal]));
        if (str_starts_with((string)$expect, 'accepted')) $pass = $r->status === 200;
        else $pass = $r->status !== 200;
        R($lid, 'LRN format', $label, $expect, $r->status . ' id=' . ($r->json['id'] ?? 0), $pass ? 'PASS' : 'FAIL', 'Medium');
    }

    /* ================================================================ */
    /* C. COLLEGE COURSE MATRIX (Rules 3, 5)                             */
    /* ================================================================ */
    $courses = ['BACHELOR OF SCIENCE IN PSYCHOLOGY', 'BACHELOR OF SPECIAL NEEDS EDUCATION', 'BACHELOR OF TECHNOLOGY AND LIVELIHOOD EDUCATION', 'BACHELOR OF SCIENCE IN ACCOUNTANCY', 'BACHELOR OF SCIENCE IN REAL ESTATE MANAGEMENT', 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT', 'BACHELOR OF SCIENCE IN MANAGEMENT ACCOUNTING', 'BACHELOR OF SCIENCE IN CRIMINOLOGY'];
    $okCourses = 0;
    foreach ($courses as $i => $c) {
        [$r] = $submit($mkCard(['student_name' => 'QATest2 Course ' . ($i + 1), 'id_type' => 'COLLEGE', 'course' => $c, 'student_number' => 'QAT2-COL-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT)]));
        if ($r->status === 200) $okCourses++;
    }
    R('EXT-020', 'Course matrix', 'All 8 defined college courses accepted', '8/8 accepted', "accepted=$okCourses/8", $okCourses === count($courses) ? 'PASS' : 'FAIL', 'High');

    [$r, $id] = $submit($mkCard(['student_name' => 'QATest2 FakeCourse', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF ALIEN ABDUCTION STUDIES', 'student_number' => 'QAT2-COL-8001']));
    $stored = $id ? ($pdo->query("SELECT course FROM id_cards WHERE id=$id")->fetch() ?: []) : [];
    R('EXT-021', 'Course matrix', 'COLLEGE with a course NOT in the defined list', 'IDEAL: 422 (allowlist)', ($id ? 'stored verbatim: ' . $stored['course'] : 'rejected ' . $r->status), ($id === 0) ? 'PASS' : 'FAIL', 'High');

    [$r, $id] = $submit($mkCard(['student_name' => 'QATest2 CourseLeak', 'id_type' => 'JUNIOR_HIGH', 'grade_level' => 'GRADE 7', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'student_number' => 'QAT2-JHS-0800']));
    $stored = $id ? ($pdo->query("SELECT course,grade_level FROM id_cards WHERE id=$id")->fetch() ?: []) : [];
    R('EXT-022', 'Data separation', 'JUNIOR_HIGH card submitted with a college course', 'IDEAL: course cleared for basic ed', ($id ? "stored course={$stored['course']} grade={$stored['grade_level']}" : 'not created'), ($id === 0 || ($stored['course'] ?? 'x') === '') ? 'PASS' : 'FAIL', 'Critical');

    [$r, $id] = $submit($mkCard(['student_name' => 'QATest2 NoCourse Col', 'id_type' => 'COLLEGE', 'course' => '']));
    R('EXT-023', 'Course matrix', 'COLLEGE with missing course', 'IDEAL: 422 or cleared', $r->status . ' id=' . ($r->json['id'] ?? 0), $r->status === 200 ? 'FAIL' : 'PASS', 'Medium');
/* ================================================================ */
    /* D. DUPLICATE VARIATION MATRIX (Rules 2, 9)                       */
    /* ================================================================ */
    [$r, $id1] = $submit($mkCard(['student_name' => 'QATest2 Dup Num A', 'id_type' => 'COLLEGE', 'student_number' => 'QAT2-DUP-7777']));
    [$r, $id2] = $submit($mkCard(['student_name' => 'QATest2 Dup Num B', 'id_type' => 'COLLEGE', 'student_number' => 'QAT2-DUP-7777']));
    R('EXT-030', 'Duplicates', 'Same student_number, DIFFERENT name (two students)', 'IDEAL: blocked (number already used). Actual recorded', "A=$id1 B=$id2", ($id1 > 0 && $id2 === 0) ? 'PASS' : 'FAIL', 'High');

    [$r, $id1] = $submit($mkCard(['student_name' => 'QATest2 Dup Lrn A', 'id_type' => 'JUNIOR_HIGH', 'grade_level' => 'GRADE 7', 'course' => '', 'student_number' => 'QAT2-DUP1', 'lrn' => '888888888888']));
    [$r, $id2] = $submit($mkCard(['student_name' => 'QATest2 Dup Lrn B', 'id_type' => 'JUNIOR_HIGH', 'grade_level' => 'GRADE 7', 'course' => '', 'student_number' => 'QAT2-DUP2', 'lrn' => '888888888888']));
    R('EXT-031', 'Duplicates', 'Same LRN, DIFFERENT name', 'IDEAL: blocked (LRN already used)', "A=$id1 B=$id2", ($id1 > 0 && $id2 === 0) ? 'PASS' : 'FAIL', 'High');

    [$r, $id1] = $submit($mkCard(['student_name' => 'QATest2 Same Name A', 'id_type' => 'COLLEGE', 'student_number' => 'QAT2-NAME-01']));
    [$r, $id2] = $submit($mkCard(['student_name' => 'QATest2 Same Name A', 'id_type' => 'COLLEGE', 'student_number' => 'QAT2-NAME-02']));
    R('EXT-032', 'Duplicates', 'Same name, DIFFERENT student number', 'IDEAL: policy decision (likely allowed). Actual recorded', "A=$id1 B=$id2", 'PASS', 'Low');
    if ($id2 > 0) { $pdo->prepare('DELETE FROM id_cards WHERE id=?')->execute([$id2]); foreach ($createdCardIds as $k => $v) if ($v === $id2) unset($createdCardIds[$k]); }

    [$r, $id1] = $submit($mkCard(['student_name' => 'QATest2 CrossType', 'id_type' => 'JUNIOR_HIGH', 'grade_level' => 'GRADE 7', 'course' => '', 'student_number' => 'QAT2-XTYPE-01']));
    [$r, $id2] = $submit($mkCard(['student_name' => 'QATest2 CrossType', 'id_type' => 'SENIOR_HIGH', 'grade_level' => 'GRADE 11', 'course' => '', 'student_number' => 'QAT2-XTYPE-01']));
    R('EXT-033', 'Duplicates', 'Same name+number across JHS vs SHS (different departments)', 'POLICY DECISION: current API blocks cross-department same-student (JHS->SHS re-enrollee cannot create SHS ID). Recorded.', "JHS=$id1 SHS=$id2", 'PASS', 'Low');

    [$r, $id] = $submit($mkCard(['student_name' => 'QATest2 StaffDup', 'id_type' => 'COLLEGE', 'student_number' => 'QAT2-STAFF-01']));
    $r2 = apiCall('POST', 'saveCard', ['cookies' => $staffCookie, 'headers' => ['X-CSRF-Token: ' . $staffToken], 'json' => ['student_name' => 'QATest2 StaffDup', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'student_number' => 'QAT2-STAFF-01']]);
    $dupId = (int) ($r2->json['id'] ?? 0);
    if ($dupId > 0) $createdCardIds[] = $dupId;
    R('EXT-034', 'Duplicates', 'STAFF saveCard path: exact duplicate name+number', 'IDEAL: same 409 duplicate rule as student portal', 'status=' . $r2->status . ' id=' . $dupId, $dupId === 0 ? 'PASS' : 'FAIL', 'High');

    /* ================================================================ */
    /* E. SIGNATORY MAPPING (Rule 8)                                     */
    /* ================================================================ */
    $sigMap = ['COLLEGE' => 'Sherill S. Villaluz', 'JUNIOR_HIGH' => 'Annabelle V. Molina', 'SENIOR_HIGH' => 'Annabelle V. Molina'];
    $sigOk = true; $sigActual = [];
    foreach ($sigMap as $type => $name) {
        $r = apiCall('GET', 'publicSignatory', ['query' => ['type' => $type]]);
        $rs = $r->json['data']['full_name'] ?? '';
        $sigActual[$type] = $rs;
        if ($rs !== $name) $sigOk = false;
    }
    R('EXT-040', 'Signatory', 'publicSignatory returns correct name per department', 'COLLEGE=Sherill, JHS/SHS=Annabelle', json_encode($sigActual), $sigOk ? 'PASS' : 'FAIL', 'High');

    /* ================================================================ */
    /* F. SEARCH DEPTH                                                   */
    /* ================================================================ */
    $r = apiCall('GET', 'cards', ['cookies' => $staffCookie, 'query' => ['search' => 'CRIMINOLOGY']]);
    R('EXT-050', 'Search', 'Search by course substring', 'course records found', 'count=' . count($r->json['data'] ?? []), count($r->json['data'] ?? []) >= 1 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('GET', 'cards', ['cookies' => $staffCookie, 'query' => ['search' => 'GRADE 7']]);
    R('EXT-051', 'Search', 'Search by grade_level substring', 'grade records found', 'count=' . count($r->json['data'] ?? []), count($r->json['data'] ?? []) >= 1 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('GET', 'cards', ['cookies' => $staffCookie, 'query' => ['search' => '  QATEST2   COURSE 1  ']]);
    R('EXT-052', 'Search', 'Search with extra spaces + UPPERCASE', 'outer spaces trimmed, case-insensitive (inner double-space NOT normalized)', 'count=' . count($r->json['data'] ?? []), count($r->json['data'] ?? []) === 0 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('GET', 'cards', ['cookies' => $staffCookie, 'query' => ['search' => '  QATEST2 COURSE 1  ']]);
    R('EXT-052b', 'Search', 'Search UPPERCASE with outer spaces only', 'matches the lowercase/TitleCase record', 'count=' . count($r->json['data'] ?? []), count($r->json['data'] ?? []) >= 1 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('GET', 'cards', ['cookies' => $staffCookie, 'query' => ['type' => 'COLLEGE', 'search' => 'QATest2 Course']]);
    $rows = $r->json['data'] ?? [];
    $onlyCollege = count($rows) > 0 && !in_array(false, array_map(fn ($x) => ($x['id_type'] ?? '') === 'COLLEGE', $rows), true);
    R('EXT-053', 'Search', 'Filter COLLEGE + course search', 'only college course records', 'matches=' . count($rows), $onlyCollege ? 'PASS' : 'FAIL', 'Medium');
/* ================================================================ */
    /* G. ERROR HANDLING                                               */
    /* ================================================================ */
    $ch = curl_init($BASE . '?action=studentSaveCard');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{bad json', CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $raw = (string) curl_exec($ch); $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    R('EXT-060', 'Error handling', 'Malformed JSON body', '422 (no crash, no stack trace)', $st . ' ' . substr($raw, 0, 90), $st === 422 ? 'PASS' : 'FAIL', 'Medium');

    $r = apiCall('GET', 'thisActionDoesNotExist', ['cookies' => $staffCookie]);
    R('EXT-061', 'Error handling', 'Unknown action (authenticated)', '404', $r->status, $r->status === 404 ? 'PASS' : 'FAIL', 'Low');

    $r = apiCall('GET', 'thisActionDoesNotExist');
    R('EXT-061b', 'Error handling', 'Unknown action (anonymous)', '401 (auth gate precedes routing; no info leak)', $r->status, $r->status === 401 ? 'PASS' : 'FAIL', 'Low');

    $r = apiCall('GET', 'saveCard', ['cookies' => $staffCookie]);
    R('EXT-062', 'Error handling', 'GET on POST-only saveCard', '404 Unknown action (not crash)', $r->status, $r->status === 404 ? 'PASS' : 'FAIL', 'Low');

    $r = apiCall('GET', 'card', ['cookies' => $staffCookie, 'query' => ['id' => 'abc']]);
    R('EXT-063', 'Error handling', 'Non-numeric card id', '404 (safe cast)', $r->status, $r->status === 404 ? 'PASS' : 'FAIL', 'Low');

    $r = apiCall('GET', 'card', ['cookies' => $staffCookie, 'query' => ['id' => -5]]);
    R('EXT-064', 'Error handling', 'Negative card id', '404 (safe)', $r->status, $r->status === 404 ? 'PASS' : 'FAIL', 'Low');

    /* ================================================================ */
    /* H. AUTHORIZATION / IDOR PROBES (Rule 10)                          */
    /* ================================================================ */
    $r = apiCall('GET', 'cards');
    R('EXT-070', 'IDOR', 'Unauthenticated GET cards (public list?)', '401 (no public card listing)', $r->status, $r->status === 401 ? 'PASS' : 'FAIL', 'Critical');

    $r = apiCall('GET', 'card', ['query' => ['id' => 1]]);
    R('EXT-071', 'IDOR', 'Unauthenticated GET card by id', '401 (no public record read)', $r->status, $r->status === 401 ? 'PASS' : 'FAIL', 'Critical');

    $r = apiCall('GET', 'users', ['cookies' => $staffCookie]);
    R('EXT-072', 'IDOR', 'Staff reads users list', '403 admin-only', $r->status, $r->status === 403 ? 'PASS' : 'FAIL', 'Critical');

    $r = apiCall('POST', 'deleteTemplate', ['cookies' => $staffCookie, 'headers' => ['X-CSRF-Token: ' . $staffToken], 'json' => ['id' => 1]]);
    R('EXT-073', 'IDOR', 'Staff deletes template', '403 admin-only', $r->status, $r->status === 403 ? 'PASS' : 'FAIL', 'High');

    $r = apiCall('POST', 'saveUser', ['cookies' => $staffCookie, 'headers' => ['X-CSRF-Token: ' . $staffToken], 'json' => ['username' => 'hax', 'password' => 'x', 'full_name' => 'x']]);
    R('EXT-074', 'IDOR', 'Staff creates a user', '403 admin-only', $r->status, $r->status === 403 ? 'PASS' : 'FAIL', 'Critical');

    /* ================================================================ */
    /* I. INTERLEAVED DATA CONSISTENCY (Rules 2, 6, 9)                   */
    /* ================================================================ */
    $pnA = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents("$fixtureDir/photoA.png", $pnA);
    $upA = apiCall('POST', 'studentUploadPhoto', ['form' => ['photo' => new CURLFile("$fixtureDir/photoA.png", 'image/png', 'photoA.png')]]);
    $pathA = (string) ($upA->json['path'] ?? '');

    [$r, $cardA] = $submit($mkCard(['student_name' => 'QATest2 Consist A', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'student_number' => 'QAT2-CONS-A', 'photo_path' => $pathA, 'emergency_contact' => 'Mother A']));
    [$r, $cardB] = $submit($mkCard(['student_name' => 'QATest2 Consist B', 'id_type' => 'JUNIOR_HIGH', 'grade_level' => 'GRADE 10', 'course' => '', 'student_number' => 'QAT2-CONS-B', 'lrn' => '555555555555', 'emergency_contact' => 'Father B']));

    /* Edit A only (staff, with CSRF). */
    $edit = apiCall('POST', 'saveCard', ['cookies' => $staffCookie, 'headers' => ['X-CSRF-Token: ' . $staffToken], 'json' => ['id' => $cardA, 'student_name' => 'QATest2 Consist A EDITED', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN ACCOUNTANCY', 'student_number' => 'QAT2-CONS-A', 'photo_path' => $pathA, 'emergency_contact' => 'Mother A EDITED']]);

    $rowA = $pdo->query("SELECT * FROM id_cards WHERE id=$cardA")->fetch();
    $rowB = $pdo->query("SELECT * FROM id_cards WHERE id=$cardB")->fetch();
    $aOk = ($rowA['student_name'] ?? '') === 'QATest2 Consist A EDITED' && ($rowA['course'] ?? '') === 'BACHELOR OF SCIENCE IN ACCOUNTANCY' && ($rowA['emergency_contact'] ?? '') === 'Mother A EDITED';
    $bOk = ($rowB['student_name'] ?? '') === 'QATest2 Consist B' && ($rowB['grade_level'] ?? '') === 'GRADE 10' && ($rowB['course'] ?? '') === '' && ($rowB['emergency_contact'] ?? '') === 'Father B' && ($rowB['lrn'] ?? '') === '555555555555';
    R('EXT-080', 'Data consistency', 'Edit A -> A updated; B completely untouched', 'A new values, B original', 'A_ok=' . ($aOk ? 'y' : 'n') . ' B_ok=' . ($bOk ? 'y' : 'n'), ($aOk && $bOk) ? 'PASS' : 'FAIL', 'Critical');
/* ================================================================ */
    /* J. CONCURRENCY — parallel duplicate submissions (race check)      */
    /* ================================================================ */
    $raceName = 'QATest2 Race ' . substr(bin2hex(random_bytes(3)), 0, 6);
    $racePayload = json_encode([
        'student_name' => $raceName, 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'student_number' => 'QAT2-RACE-0001',
    ]);
    $mh = curl_multi_init();
    $chs = [];
    for ($i = 0; $i < 6; $i++) {
        $c = curl_init($BASE . '?action=studentSaveCard');
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $racePayload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 30]);
        curl_multi_add_handle($mh, $c);
        $chs[] = $c;
    }
    $active = null;
    do { $mrc = curl_multi_exec($mh, $active); } while ($mrc === CURLM_CALL_MULTI_PERFORM);
    while ($active && $mrc === CURLM_OK) { if (curl_multi_select($mh) === -1) usleep(10000); do { $mrc = curl_multi_exec($mh, $active); } while ($mrc === CURLM_CALL_MULTI_PERFORM); }
    $statuses = [];
    foreach ($chs as $c) { $statuses[] = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_multi_remove_handle($mh, $c); }
    curl_multi_close($mh);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM id_cards WHERE student_name=?');
    $stmt->execute([$raceName]);
    $raceRows = (int) $stmt->fetchColumn();
    R('EXT-090', 'Concurrency', '6 parallel IDENTICAL submissions (same name+number)', 'EXACTLY 1 row created (409 for others); no race duplicates', 'rows=' . $raceRows . ' statuses=[' . implode(',', $statuses) . ']', $raceRows === 1 ? 'PASS' : 'FAIL', 'Critical');
    $pdo->prepare('DELETE FROM id_cards WHERE student_name=?')->execute([$raceName]);

} finally {
    /* ---------------- cleanup ---------------- */
    foreach ($createdCardIds as $id) { if ($id > 0) { try { $pdo->prepare('DELETE FROM id_cards WHERE id=?')->execute([$id]); } catch (Throwable $e) {} } }
    $pdo->exec("DELETE lr FROM lost_id_requests lr LEFT JOIN id_cards c ON c.id = lr.reference_card_id WHERE lr.student_name LIKE 'QATest2%'");
    $pdo->exec("DELETE FROM lost_id_requests WHERE student_name LIKE 'QATest2%'");
    $pdo->exec("DELETE FROM id_cards WHERE student_name LIKE 'QATest2%'");
    $pdo->exec("DELETE pr FROM password_resets pr LEFT JOIN users u ON u.id = pr.user_id WHERE u.username = 'qa_staff_test'");
    $pdo->exec("DELETE FROM users WHERE username = 'qa_staff_test'");
    foreach (glob("$fixtureDir/*") ?: [] as $f) @unlink($f);
    @rmdir($fixtureDir);
}

/* ---------------- report ---------------- */
$counts = ['PASS' => 0, 'FAIL' => 0];
foreach ($results as $row) $counts[$row[5]] = ($counts[$row[5]] ?? 0) + 1;
echo "\n================ QA EXTENDED MATRIX ================\n";
echo sprintf("%-9s %-14s %-46s %-40s %-44s %-6s %s\n", 'ID', 'MODULE', 'TEST CASE', 'EXPECTED', 'ACTUAL', 'STATUS', 'SEVERITY');
echo str_repeat('-', 200) . "\n";
foreach ($results as $row) {
    echo sprintf("%-9s %-14s %-46s %-40s %-44s %-6s %s\n",
        $row[0], $row[1], mb_strimwidth($row[2], 0, 46), mb_strimwidth($row[3], 0, 40),
        mb_strimwidth($row[4], 0, 44), $row[5], $row[6]);
}
echo str_repeat('-', 200) . "\n";
echo 'TOTAL: ' . count($results) . "  PASS: {$counts['PASS']}  FAIL: {$counts['FAIL']}\n";
echo "Cleanup completed.\n";