<?php
/* QA suite: Print History feature.
 * Covers: original printing, reprint reason validation, history rows,
 * legacy print_count handling, released-terminal guard, admin-only read
 * endpoint, filters (search / user / type / date), monthly + per-staff
 * report totals and the "staff printed student ID" audit events.
 * Usage: php backend/tests/qa_print_history_test.php [base_url]
 */
$BASE = $argv[1] ?? 'http://localhost/lake-shore-id-editor/backend/api.php';
$fail = 0; $pass = 0; $total = 0;
function R($id, $label, $expect, $actual, $ok, $sev = 'High') {
  global $fail, $pass, $total; $total++;
  if ($ok) $pass++; else $fail++;
  printf("%-9s %-30s %-46s %-42s %-4s %s\n", $id, $label, $expect, $actual, $ok ? 'PASS' : 'FAIL', $sev);
}
function apiCall($method, $action, $opts = []) {
  global $BASE, $pdo;
  /* Public submissions are rate limited; throttling itself is covered by
     qa_rate_limit_test.php, so clear the per-IP window here. */
  if (in_array($action, ['studentSaveCard', 'studentUploadPhoto', 'lostIdRequest'], true)) {
    $pdo->exec("DELETE FROM rate_limit_counters WHERE bucket LIKE 'public:%'");
  }
  $url = $BASE . '?action=' . $action;
  if (!empty($opts['query'])) $url .= '&' . http_build_query($opts['query']);
  $ch = curl_init($url);
  $headers = $opts['headers'] ?? [];
  $headers[] = 'User-Agent: QAPrintHistorySuite/1.0';
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
$staleIds = $pdo->query("SELECT id FROM id_cards WHERE student_name LIKE 'QAPrint%'")->fetchAll(PDO::FETCH_COLUMN);
if ($staleIds) {
  $in = implode(',', array_map('intval', $staleIds));
  $pdo->exec("DELETE FROM audit_logs WHERE entity_type='id_card' AND entity_id IN ($in)");
  $pdo->exec("DELETE FROM print_history WHERE card_id IN ($in)");
  $pdo->exec("DELETE FROM id_cards WHERE id IN ($in)");
}
$pdo->exec("DELETE FROM users WHERE username IN ('qa_print_admin','qa_print_staff')");
$pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_print_staff',?,'QA Print Staff','staff',1)")->execute([password_hash('QaPrint#2026', PASSWORD_DEFAULT)]);
$pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_print_admin',?,'QA Print Admin','admin',1)")->execute([password_hash('QaPrint#Admin1', PASSWORD_DEFAULT)]);
$staffId = (int)$pdo->query("SELECT id FROM users WHERE username='qa_print_staff'")->fetchColumn();
$adminId = (int)$pdo->query("SELECT id FROM users WHERE username='qa_print_admin'")->fetchColumn();

/* ===== bootstrap sessions ===== */
$r = apiCall('POST', 'login', ['json' => ['username' => 'qa_print_staff', 'password' => 'QaPrint#2026']]);
$staffCookie = sessionCookie($r->headers);
$staff = ['cookies' => $staffCookie, 'headers' => ['X-CSRF-Token: ' . ($r->json['csrf_token'] ?? '')]];
$r = apiCall('POST', 'login', ['json' => ['username' => 'qa_print_admin', 'password' => 'QaPrint#Admin1']]);
$adminCookie = sessionCookie($r->headers);
$admin = ['cookies' => $adminCookie, 'headers' => ['X-CSRF-Token: ' . ($r->json['csrf_token'] ?? '')]];
R('PH-001', 'staff + admin login', 'both sessions + csrf', 'staff=' . ($staffCookie ? 'ok' : 'no') . ' admin=' . ($adminCookie ? 'ok' : 'no'), (bool)$staffCookie && (bool)$adminCookie, 'High');

/* ===== test card via the public self-service path ===== */
$r = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => 'QAPrint Juan Dela Cruz', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'student_number' => 'QA-PRINT-0001', 'academic_year' => '2026-2027', 'school_year' => '2026-2027']]);
$cardId = (int)($r->json['id'] ?? 0);
R('PH-002', 'test card created', 'id > 0 via studentSaveCard', 'id=' . $cardId . ' status=' . $r->status, $cardId > 0, 'High');

/* ===== 1. original print (no reason needed) ===== */
$r = $cardId ? apiCall('POST', 'printCard', $staff + ['json' => ['id' => $cardId]]) : null;
$row = $cardId ? $pdo->query("SELECT * FROM print_history WHERE card_id=$cardId ORDER BY id DESC LIMIT 1")->fetch() : false;
$card = $cardId ? $pdo->query("SELECT * FROM id_cards WHERE id=$cardId")->fetch() : false;
R('PH-003', 'original print recorded', 'type=original reason="New Student" count=1',
  $row ? ('type=' . $row['print_type'] . ' reason=' . $row['reason'] . ' count=' . $card['print_count']) : 'MISSING',
  $r && $r->status === 200 && $row && $row['print_type'] === 'original' && $row['reason'] === 'New Student' && (int)$card['print_count'] === 1 && $card['status'] === 'printed' && $card['printed_at'] !== null, 'High');
R('PH-004', 'history links staff user', 'user_id=' . $staffId, $row ? ('user_id=' . $row['user_id']) : 'MISSING', $row && (int)$row['user_id'] === $staffId, 'High');
/* ===== 2. reprint requires a reason ===== */
$r = apiCall('POST', 'printCard', $staff + ['json' => ['id' => $cardId]]);
R('PH-005', 'reprint w/o reason rejected', 'HTTP 422', 'HTTP ' . $r->status, $r->status === 422, 'High');
$r = apiCall('POST', 'printCard', $staff + ['json' => ['id' => $cardId, 'reason' => str_repeat('x', 300)]]);
R('PH-006', 'reason >255 chars rejected', 'HTTP 422', 'HTTP ' . $r->status, $r->status === 422, 'Medium');

/* ===== 3. reprint with reason ===== */
$r = apiCall('POST', 'printCard', $staff + ['json' => ['id' => $cardId, 'reason' => 'Damaged']]);
$reprintRow = $pdo->query("SELECT * FROM print_history WHERE card_id=$cardId ORDER BY id DESC LIMIT 1")->fetch();
$card = $pdo->query("SELECT * FROM id_cards WHERE id=$cardId")->fetch();
R('PH-007', 'reprint with reason recorded', 'type=reprint reason=Damaged count=2',
  $reprintRow ? ('type=' . $reprintRow['print_type'] . ' reason=' . $reprintRow['reason'] . ' count=' . $card['print_count']) : 'MISSING',
  $r->status === 200 && $reprintRow && $reprintRow['print_type'] === 'reprint' && $reprintRow['reason'] === 'Damaged' && (int)$card['print_count'] === 2, 'High');

/* ===== 4. legacy card (print_count>0, no history) is a reprint ===== */
$pdo->prepare("INSERT INTO id_cards(student_name,id_type,course,status,printed_at,print_count,created_at,updated_at) VALUES('QAPrint Legacy Card','COLLEGE','BACHELOR OF SCIENCE IN PSYCHOLOGY','printed',NOW(),2,NOW(),NOW())")->execute();
$legacyId = (int)$pdo->lastInsertId();
$r = apiCall('POST', 'printCard', $admin + ['json' => ['id' => $legacyId]]);
R('PH-008', 'legacy print_count -> reprint guard', 'HTTP 422 (reason required)', 'HTTP ' . $r->status, $r->status === 422, 'High');
$r = apiCall('POST', 'printCard', $admin + ['json' => ['id' => $legacyId, 'reason' => 'Lost']]);
$legacyRow = $pdo->query("SELECT * FROM print_history WHERE card_id=$legacyId ORDER BY id DESC LIMIT 1")->fetch();
$legacyCard = $pdo->query("SELECT * FROM id_cards WHERE id=$legacyId")->fetch();
R('PH-009', 'legacy reprint + counter kept in sync', 'type=reprint count=3',
  $legacyRow ? ('type=' . $legacyRow['print_type'] . ' count=' . $legacyCard['print_count']) : 'MISSING',
  $r->status === 200 && $legacyRow && $legacyRow['print_type'] === 'reprint' && (int)$legacyCard['print_count'] === 3 && (int)$legacyRow['user_id'] === $adminId, 'High');

/* ===== 5. released IDs are terminal ===== */
$r = apiCall('POST', 'releaseCard', $staff + ['json' => ['id' => $cardId, 'release_notes' => 'QA release', 'student_received' => 1]]);
$relStatus = $r->status;
$r = apiCall('POST', 'printCard', $staff + ['json' => ['id' => $cardId, 'reason' => 'Damaged']]);
R('PH-010', 'released ID cannot be printed', 'release 200 then print 409', 'release=' . $relStatus . ' print=' . $r->status, $relStatus === 200 && $r->status === 409, 'High');

/* ===== 6. guards ===== */
$r = apiCall('POST', 'printCard', ['json' => ['id' => $legacyId, 'reason' => 'Lost']]);
R('PH-011', 'printCard requires login', 'HTTP 401', 'HTTP ' . $r->status, $r->status === 401, 'High');
$r = apiCall('POST', 'printCard', $staff + ['json' => ['id' => 999999, 'reason' => 'Lost']]);
R('PH-012', 'printCard unknown card', 'HTTP 404', 'HTTP ' . $r->status, $r->status === 404, 'Medium');
$r = apiCall('GET', 'printHistory', $staff + ['query' => ['page' => 1]]);
R('PH-013', 'printHistory is admin-only', 'HTTP 403 for staff', 'HTTP ' . $r->status, $r->status === 403, 'High');
/* ===== 7. history endpoint: rows + joins ===== */
$r = apiCall('GET', 'printHistory', $admin + ['query' => ['page' => 1, 'per_page' => 50]]);
$rows = $r->json['data'] ?? [];
$mine = array_values(array_filter($rows, fn($x) => in_array((int)$x['card_id'], [$cardId, $legacyId], true)));
$first = $mine[0] ?? [];
R('PH-014', 'history rows joined (student+staff)', 'student_name + username + type + reason',
  $first ? (substr(($first['student_name'] ?? '?') . ' / ' . ($first['username'] ?? '?') . ' / ' . ($first['print_type'] ?? '?'), 0, 44)) : 'MISSING',
  count($mine) === 3 && isset($first['student_name'], $first['username'], $first['print_type'], $first['reason']), 'High');

/* ===== 8. filters ===== */
$tot = function (array $q) use ($admin) {
  $r = apiCall('GET', 'printHistory', $admin + ['query' => $q + ['page' => 1, 'per_page' => 50]]);
  return [(int)($r->json['pagination']['total'] ?? -1), $r->json['data'] ?? [], $r->json['summary'] ?? []];
};
[$n, $data] = $tot(['search' => 'QAPrint']);
R('PH-015', 'search filter', 'total=3', 'total=' . $n, $n === 3, 'High');
[$n, $data] = $tot(['type' => 'reprint', 'search' => 'QAPrint']);
$allReprint = $n === 2 && count(array_filter($data, fn($x) => $x['print_type'] === 'reprint')) === 2;
R('PH-016', 'type=reprint filter', 'total=2 all reprint', 'total=' . $n, $allReprint, 'High');
[$n, $data] = $tot(['user_id' => (string)$staffId, 'search' => 'QAPrint']);
$allStaff = $n === 2 && count(array_filter($data, fn($x) => (int)$x['user_id'] === $staffId)) === 2;
R('PH-017', 'user filter (staff)', 'total=2 all staff', 'total=' . $n, $allStaff, 'High');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
[$n] = $tot(['date_from' => $tomorrow]);
R('PH-018', 'date_from future -> empty', 'total=0', 'total=' . $n, $n === 0, 'Medium');
[$n, , $summary] = $tot(['date_from' => date('Y-m-d')]);
R('PH-019', 'date_from today -> all 3', 'total=3', 'total=' . $n, $n === 3, 'High');

/* ===== 9. report totals ===== */
$month = $summary['month'] ?? [];
$byUser = [];
foreach (($summary['by_user'] ?? []) as $u) $byUser[(int)$u['id']] = $u;
R('PH-020', 'month totals (orig vs reprint)', 'original>=1 reprint>=2 total=sum',
  'total=' . ($month['total'] ?? '?') . ' original=' . ($month['original'] ?? '?') . ' reprint=' . ($month['reprint'] ?? '?'),
  (int)($month['original'] ?? 0) >= 1 && (int)($month['reprint'] ?? 0) >= 2 && (int)($month['total'] ?? 0) >= ((int)($month['original'] ?? 0) + (int)($month['reprint'] ?? 0)), 'High');
R('PH-021', 'per-staff totals', 'staff>=2 admin>=1',
  'staff=' . ($byUser[$staffId]['total_count'] ?? '?') . ' admin=' . ($byUser[$adminId]['total_count'] ?? '?'),
  (int)($byUser[$staffId]['total_count'] ?? 0) >= 2 && (int)($byUser[$adminId]['total_count'] ?? 0) >= 1, 'High');

/* ===== 10. audit integration: "Staff printed Student ID" ===== */
$aud = $pdo->query("SELECT * FROM audit_logs WHERE action='card_printed' AND entity_type='id_card' AND entity_id=$cardId ORDER BY id DESC LIMIT 1")->fetch();
$nv = $aud ? (json_decode($aud['new_value'], true) ?: []) : [];
R('PH-022', 'audit card_printed enriched', 'user=staff, student+type+reason in new_value',
  $aud ? ('user=' . $aud['user_id'] . ' type=' . ($nv['print_type'] ?? '?') . ' reason=' . ($nv['reason'] ?? '?')) : 'MISSING',
  $aud && (int)$aud['user_id'] === $staffId && ($nv['student_name'] ?? '') === 'QAPrint Juan Dela Cruz' && ($nv['print_type'] ?? '') === 'reprint' && ($nv['reason'] ?? '') === 'Damaged', 'High');

printf("\n%d/%d checks passed (%d failed).\n", $pass, $total, $fail);
exit($fail === 0 ? 0 : 1);


