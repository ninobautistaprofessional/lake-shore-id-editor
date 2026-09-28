<?php
/* QA suite: Dashboard Analytics (dashboardStats endpoint).
 * Covers: auth gate (401 anonymous / 200 staff), SQL-aggregated summary
 * (total/printed/released), per-department rows (COLLEGE/JUNIOR_HIGH/SENIOR_HIGH),
 * monthly generated + printed series, lost-IDs and reprint counters, and the
 * date_from/date_to/id_type/status filters — each validated against direct
 * SQL ground truth on the same connection.
 * Usage: php backend/tests/qa_dashboard_test.php [base_url]
 */
$BASE = $argv[1] ?? 'http://localhost/lake-shore-id-editor/backend/api.php';
$fail = 0; $pass = 0; $total = 0;
function R($id, $label, $expect, $actual, $ok, $sev = 'High') {
  global $fail, $pass, $total; $total++;
  if ($ok) $pass++; else $fail++;
  printf("%-9s %-34s %-44s %-40s %-4s %s\n", $id, $label, $expect, $actual, $ok ? 'PASS' : 'FAIL', $sev);
}
function apiCall($method, $action, $opts = []) {
  global $BASE;
  $url = $BASE . '?action=' . $action;
  if (!empty($opts['query'])) $url .= '&' . http_build_query($opts['query']);
  $ch = curl_init($url);
  $headers = $opts['headers'] ?? [];
  $headers[] = 'User-Agent: QADashboardSuite/1.0';
  if (isset($opts['json'])) { $headers[] = 'Content-Type: application/json'; }
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_HEADER         => true,
    CURLOPT_TIMEOUT        => 30,
  ]);
  if (isset($opts['json'])) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
  if (!empty($opts['cookies'])) curl_setopt($ch, CURLOPT_COOKIE, $opts['cookies']);
  $raw = (string) curl_exec($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $headerBlock = substr($raw, 0, $hsize);
  $body = substr($raw, $hsize);
  preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $headerBlock, $m);
  return (object) [
    'status' => $status,
    'json'   => json_decode($body, true) ?: [],
    'headers' => $m[1] ?? [],
  ];
}
function sessionCookie($jar) { $last = ''; foreach ($jar as $c) if (strpos($c, 'LSC_ID_SESSION') === 0) $last = $c; return $last; }

require __DIR__ . '/../config.php';
$pdo = db();
/* ===== cleanup previous QA runs ===== */
$staleIds = $pdo->query("SELECT id FROM id_cards WHERE student_name LIKE 'QADash%'")->fetchAll(PDO::FETCH_COLUMN);
if ($staleIds) {
  $in = implode(',', array_map('intval', $staleIds));
  $pdo->exec("DELETE FROM audit_logs WHERE entity_type='id_card' AND entity_id IN ($in)");
  $pdo->exec("DELETE FROM print_history WHERE card_id IN ($in)");
  $pdo->exec("DELETE FROM id_cards WHERE id IN ($in)");
}
$pdo->exec("DELETE FROM lost_id_requests WHERE student_name LIKE 'QADash%'");
$pdo->exec("DELETE FROM users WHERE username IN ('qa_dash_staff')");
$pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_dash_staff',?,'QA Dash Staff','staff',1)")->execute([password_hash('QaDash#2026', PASSWORD_DEFAULT)]);

/* ===== QA dataset: cards across departments / statuses / months ===== */
$cards = [
  /* [name, type, created_at, status, print_count, printed_at] */
  ['QADash Alice College',   'COLLEGE',     '2026-06-15 10:00:00', 'released', 1, '2026-07-12 09:00:00'],
  ['QADash Bob Jhs',         'JUNIOR_HIGH', '2026-07-10 10:00:00', 'printed',  1, '2026-07-20 09:00:00'],
  ['QADash Cara Shs',        'SENIOR_HIGH', '2026-08-05 10:00:00', 'created',  0, null],
  ['QADash Dan College',     'COLLEGE',     '2026-08-25 10:00:00', 'done',     0, null],
  ['QADash Eve College',     'COLLEGE',     '2026-09-01 10:00:00', 'printed',  2, '2026-09-02 09:00:00'],
];
$cardIds = [];
$ins = $pdo->prepare("INSERT INTO id_cards(student_name,id_type,course,grade_level,academic_year,school_year,status,print_count,printed_at) VALUES(?,?,?,?,?,?,?,?,?)");
foreach ($cards as $c) {
  $course = $c[1] === 'COLLEGE' ? 'BACHELOR OF SCIENCE IN PSYCHOLOGY' : '';
  $grade  = $c[1] === 'JUNIOR_HIGH' ? 'GRADE 7' : ($c[1] === 'SENIOR_HIGH' ? 'GRADE 11' : '');
  $ins->execute([$c[0], $c[1], $course, $grade, '2026-2027', '2026-2027', $c[3], $c[4], $c[5]]);
  $id = (int)$pdo->lastInsertId();
  $cardIds[$c[0]] = $id;
  $pdo->prepare("UPDATE id_cards SET created_at=? WHERE id=?")->execute([$c[2], $id]);
}
/* print_history: 1 original (Jul) + 2 reprints (Aug Lost, Sep Damaged) */
$ph = $pdo->prepare("INSERT INTO print_history(card_id,user_id,print_type,reason,printed_at) VALUES(?,?,?,?,?)");
$ph->execute([$cardIds['QADash Alice College'], null, 'original', '', '2026-07-12 09:00:00']);
$ph->execute([$cardIds['QADash Eve College'],   null, 'reprint',  'Lost ID - reported by student', '2026-08-20 09:00:00']);
$ph->execute([$cardIds['QADash Eve College'],   null, 'reprint',  'Damaged card', '2026-09-02 09:30:00']);
/* lost_id_requests: one Aug (COLLEGE) + one Sep (JUNIOR_HIGH) */
$pdo->prepare("INSERT INTO lost_id_requests(student_name,id_type,created_at) VALUES('QADash Alice College','COLLEGE','2026-08-21 09:00:00')")->execute();
$pdo->prepare("INSERT INTO lost_id_requests(student_name,id_type,created_at) VALUES('QADash Bob Jhs','JUNIOR_HIGH','2026-09-03 09:00:00')")->execute();

/* ===== ground truth helpers ===== */
$q1 = function ($sql) use ($pdo) { return (int)$pdo->query($sql)->fetchColumn(); };
$gtTotal    = $q1('SELECT COUNT(*) FROM id_cards');
$gtPrinted  = $q1('SELECT COUNT(*) FROM id_cards WHERE print_count>0');
$gtReleased = $q1("SELECT COUNT(*) FROM id_cards WHERE status='released'");
$gtReprints = $q1("SELECT COUNT(*) FROM print_history WHERE print_type='reprint'");
$gtLost     = $q1('SELECT COUNT(*) FROM lost_id_requests');
$gtPhAll    = $q1('SELECT COUNT(*) FROM print_history');
/* ===== bootstrap session ===== */
$r = apiCall('POST', 'login', ['json' => ['username' => 'qa_dash_staff', 'password' => 'QaDash#2026']]);
$cookie = sessionCookie($r->headers);
R('DA-001', 'staff login', 'session cookie', $cookie ? 'ok' : 'none', (bool)$cookie, 'High');
$staff = ['cookies' => $cookie];

/* ===== 1. auth gate ===== */
$r = apiCall('GET', 'dashboardStats');
R('DA-002', 'anonymous blocked', '401', (string)$r->status, $r->status === 401, 'High');
$r = apiCall('GET', 'dashboardStats', $staff);
$d = $r->json['data'] ?? [];
R('DA-003', 'staff can read stats', '200 + success', "status={$r->status} success=" . ($r->json['success'] ? 'true' : 'false'), $r->status === 200 && !empty($r->json['success']), 'High');

/* ===== 2. summary aggregation vs SQL ===== */
R('DA-004', 'summary.total', (string)$gtTotal, (string)($d['summary']['total'] ?? -1), (int)($d['summary']['total'] ?? -1) === $gtTotal, 'High');
R('DA-005', 'summary.printed', (string)$gtPrinted, (string)($d['summary']['printed'] ?? -1), (int)($d['summary']['printed'] ?? -1) === $gtPrinted, 'High');
R('DA-006', 'summary.released', (string)$gtReleased, (string)($d['summary']['released'] ?? -1), (int)($d['summary']['released'] ?? -1) === $gtReleased, 'High');

/* ===== 3. per-department rows ===== */
$byType = [];
foreach (($d['by_type'] ?? []) as $row) $byType[$row['id_type']] = $row;
$okType = true; $typeDetail = [];
foreach (['COLLEGE', 'JUNIOR_HIGH', 'SENIOR_HIGH'] as $t) {
  $gt = $q1("SELECT COUNT(*) FROM id_cards WHERE id_type='$t'");
  $got = (int)($byType[$t]['total'] ?? -1);
  $typeDetail[] = "$t=$got/$gt";
  if ($got !== $gt) $okType = false;
}
R('DA-007', 'by_type totals match SQL', 'all equal', implode(' ', $typeDetail), $okType, 'High');
$sumByType = array_sum(array_map(fn($x) => (int)$x['total'], $d['by_type'] ?? []));
R('DA-008', 'by_type sums to total', (string)$gtTotal, (string)$sumByType, $sumByType === $gtTotal, 'Medium');

/* ===== 4. monthly series ===== */
$genSum = array_sum($d['monthly']['generated'] ?? []);
$genJun = (int)($d['monthly']['generated']['2026-06'] ?? -1);
$gtJun  = $q1("SELECT COUNT(*) FROM id_cards WHERE created_at LIKE '2026-06%'");
R('DA-009', 'monthly.generated sums to total', (string)$gtTotal, (string)$genSum, $genSum === $gtTotal, 'High');
R('DA-010', 'monthly.generated June count', (string)$gtJun, (string)$genJun, $genJun === $gtJun, 'High');
$prnSum = array_sum($d['monthly']['printed'] ?? []);
R('DA-011', 'monthly.printed sums to print rows', (string)$gtPhAll, (string)$prnSum, $prnSum === $gtPhAll, 'High');

/* ===== 5. lost + reprint counters ===== */
R('DA-012', 'lost_ids total', (string)$gtLost, (string)($d['lost_ids'] ?? -1), (int)($d['lost_ids'] ?? -1) === $gtLost, 'High');
R('DA-013', 'reprints total', (string)$gtReprints, (string)($d['reprints'] ?? -1), (int)($d['reprints'] ?? -1) === $gtReprints, 'High');
/* ===== 6. filters ===== */
/* department filter */
$r = apiCall('GET', 'dashboardStats', $staff + ['query' => ['id_type' => 'COLLEGE']]);
$d2 = $r->json['data'] ?? [];
$gt = $q1("SELECT COUNT(*) FROM id_cards WHERE id_type='COLLEGE'");
R('DA-014', 'filter id_type=COLLEGE', (string)$gt, (string)($d2['summary']['total'] ?? -1), (int)($d2['summary']['total'] ?? -1) === $gt, 'High');
$map2 = [];
foreach (($d2['by_type'] ?? []) as $row) $map2[$row['id_type']] = (int)$row['total'];
R('DA-015', 'filter zeroes other depts', 'JHS=0 SHS=0', 'JHS=' . ($map2['JUNIOR_HIGH'] ?? '?') . ' SHS=' . ($map2['SENIOR_HIGH'] ?? '?'), ($map2['JUNIOR_HIGH'] ?? -1) === 0 && ($map2['SENIOR_HIGH'] ?? -1) === 0, 'Medium');
/* status filter */
$r = apiCall('GET', 'dashboardStats', $staff + ['query' => ['status' => 'released']]);
$d3 = $r->json['data'] ?? [];
R('DA-016', 'filter status=released', (string)$gtReleased, (string)($d3['summary']['total'] ?? -1), (int)($d3['summary']['total'] ?? -1) === $gtReleased, 'High');
/* date window filter */
$r = apiCall('GET', 'dashboardStats', $staff + ['query' => ['date_from' => '2026-08-01', 'date_to' => '2026-08-31']]);
$d4 = $r->json['data'] ?? [];
$gt = $q1("SELECT COUNT(*) FROM id_cards WHERE created_at BETWEEN '2026-08-01 00:00:00' AND '2026-08-31 23:59:59'");
R('DA-017', 'filter date window (Aug 2026)', (string)$gt, (string)($d4['summary']['total'] ?? -1), (int)($d4['summary']['total'] ?? -1) === $gt, 'High');
$gtLostAug = $q1("SELECT COUNT(*) FROM lost_id_requests WHERE created_at BETWEEN '2026-08-01 00:00:00' AND '2026-08-31 23:59:59'");
R('DA-018', 'lost_ids respects date window', (string)$gtLostAug, (string)($d4['lost_ids'] ?? -1), (int)($d4['lost_ids'] ?? -1) === $gtLostAug, 'High');
$gtRpAug = $q1("SELECT COUNT(*) FROM print_history p JOIN id_cards c ON c.id=p.card_id WHERE p.print_type='reprint' AND p.printed_at BETWEEN '2026-08-01 00:00:00' AND '2026-08-31 23:59:59'");
R('DA-019', 'reprints respects date window', (string)$gtRpAug, (string)($d4['reprints'] ?? -1), (int)($d4['reprints'] ?? -1) === $gtRpAug, 'High');
/* combined filters */
$r = apiCall('GET', 'dashboardStats', $staff + ['query' => ['id_type' => 'COLLEGE', 'status' => 'released', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31']]);
$d5 = $r->json['data'] ?? [];
$gt = $q1("SELECT COUNT(*) FROM id_cards WHERE id_type='COLLEGE' AND status='released' AND created_at BETWEEN '2026-01-01 00:00:00' AND '2026-12-31 23:59:59'");
R('DA-020', 'combined filters', (string)$gt, (string)($d5['summary']['total'] ?? -1), (int)($d5['summary']['total'] ?? -1) === $gt, 'High');
/* invalid enum values are ignored, not fatal */
$r = apiCall('GET', 'dashboardStats', $staff + ['query' => ['id_type' => 'HACKED', 'status' => 'DROP TABLE']]);
$d6 = $r->json['data'] ?? [];
R('DA-021', 'invalid filter values ignored', "200 total=$gtTotal", "status={$r->status} total=" . ($d6['summary']['total'] ?? '?'), $r->status === 200 && (int)($d6['summary']['total'] ?? -1) === $gtTotal, 'Medium');

/* ===== cleanup ===== */
$in = implode(',', array_map('intval', array_values($cardIds)));
$pdo->exec("DELETE FROM audit_logs WHERE entity_type='id_card' AND entity_id IN ($in)");
$pdo->exec("DELETE FROM print_history WHERE card_id IN ($in)");
$pdo->exec("DELETE FROM id_cards WHERE id IN ($in)");
$pdo->exec("DELETE FROM lost_id_requests WHERE student_name LIKE 'QADash%'");
$pdo->exec("DELETE FROM users WHERE username='qa_dash_staff'");

echo "\nQA Dashboard Analytics: $pass/$total passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);


