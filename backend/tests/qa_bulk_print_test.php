<?php
/* QA suite: Batch Printing (bulkPrint) feature.
 * Covers: auth + CSRF guards, input validation (ids array/empty/too many),
 * original batch printing (print_history rows, status + counter sync,
 * "New Student" default reason), duplicate id de-duplication, reprint
 * reason enforcement, reprint recording within a batch, released-terminal
 * skip behaviour, missing-id reporting, print_history integration and the
 * audit trail (per-ID card_printed rows + bulk_ids_printed summary with
 * user / count / included IDs).
 * Usage: php backend/tests/qa_bulk_print_test.php [base_url]
 */
$BASE = $argv[1] ?? 'http://localhost/lake-shore-id-editor/backend/api.php';
$fail = 0; $pass = 0; $total = 0;
function R($id, $label, $expect, $actual, $ok, $sev = 'High') {
  global $fail, $pass, $total; $total++;
  if ($ok) $pass++; else $fail++;
  printf("%-9s %-34s %-44s %-42s %-4s %s\n", $id, $label, $expect, $actual, $ok ? 'PASS' : 'FAIL', $sev);
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
  $headers[] = 'User-Agent: QABulkPrintSuite/1.0';
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
function qaBulkCleanup(PDO $pdo): void {
  $staleIds = $pdo->query("SELECT id FROM id_cards WHERE student_name LIKE 'QABulkPrint%'")->fetchAll(PDO::FETCH_COLUMN);
  if ($staleIds) {
    $in = implode(',', array_map('intval', $staleIds));
    $pdo->exec("DELETE FROM audit_logs WHERE (entity_type='id_card' AND entity_id IN ($in)) OR (action='bulk_ids_printed' AND new_value LIKE '%QABulkPrint%')");
    $pdo->exec("DELETE FROM print_history WHERE card_id IN ($in)");
    $pdo->exec("DELETE FROM id_cards WHERE id IN ($in)");
  }
  $pdo->exec("DELETE FROM users WHERE username IN ('qa_bulk_admin','qa_bulk_staff')");
}

require __DIR__ . '/../config.php';
$pdo = db();
/* ===== cleanup previous QA runs ===== */
qaBulkCleanup($pdo);
$pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_bulk_staff',?,'QA Bulk Staff','staff',1)")->execute([password_hash('QaBulk#2026', PASSWORD_DEFAULT)]);
$pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_bulk_admin',?,'QA Bulk Admin','admin',1)")->execute([password_hash('QaBulk#Admin1', PASSWORD_DEFAULT)]);
$staffId = (int)$pdo->query("SELECT id FROM users WHERE username='qa_bulk_staff'")->fetchColumn();
$adminId = (int)$pdo->query("SELECT id FROM users WHERE username='qa_bulk_admin'")->fetchColumn();

/* ===== bootstrap sessions ===== */
$r = apiCall('POST', 'login', ['json' => ['username' => 'qa_bulk_staff', 'password' => 'QaBulk#2026']]);
$staffCookie = sessionCookie($r->headers);
$staff = ['cookies' => $staffCookie, 'headers' => ['X-CSRF-Token: ' . ($r->json['csrf_token'] ?? '')]];
$r = apiCall('POST', 'login', ['json' => ['username' => 'qa_bulk_admin', 'password' => 'QaBulk#Admin1']]);
$adminCookie = sessionCookie($r->headers);
$admin = ['cookies' => $adminCookie, 'headers' => ['X-CSRF-Token: ' . ($r->json['csrf_token'] ?? '')]];
R('BP-001', 'staff + admin login', 'both sessions + csrf', 'staff=' . ($staffCookie ? 'ok' : 'no') . ' admin=' . ($adminCookie ? 'ok' : 'no'), (bool)$staffCookie && (bool)$adminCookie, 'High');

/* ===== test cards via the public self-service path ===== */
$cardIds = [];
for ($i = 1; $i <= 6; $i++) {
  $r = apiCall('POST', 'studentSaveCard', ['json' => [
    'student_name' => "QABulkPrint Juan Dela Cruz $i",
    'id_type' => 'COLLEGE',
    'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY',
    'student_number' => 'QA-BULK-000' . $i,
    'academic_year' => '2026-2027',
    'school_year' => '2026-2027',
  ]]);
  $cardIds[$i] = (int)($r->json['id'] ?? 0);
}
R('BP-002', 'six test cards created', 'all ids > 0', implode(',', $cardIds), count(array_filter($cardIds)) === 6, 'High');

/* ===== guards ===== */
$r = apiCall('POST', 'bulkPrint', ['json' => ['ids' => [1]]]);
R('BP-003', 'bulkPrint requires login', 'HTTP 401', 'HTTP ' . $r->status, $r->status === 401, 'High');
$r = apiCall('POST', 'bulkPrint', ['cookies' => $staffCookie, 'json' => ['ids' => [1]]]);
R('BP-004', 'bulkPrint requires CSRF token', 'HTTP 403', 'HTTP ' . $r->status, $r->status === 403, 'High');
$r = apiCall('POST', 'bulkPrint', $staff + ['json' => ['ids' => 'nope']]);
R('BP-005', 'non-array ids rejected', 'HTTP 422', 'HTTP ' . $r->status, $r->status === 422, 'Medium');
$r = apiCall('POST', 'bulkPrint', $staff + ['json' => ['ids' => []]]);
R('BP-006', 'empty ids rejected', 'HTTP 422', 'HTTP ' . $r->status, $r->status === 422, 'Medium');
$r = apiCall('POST', 'bulkPrint', $staff + ['json' => ['ids' => array_fill(0, 201, 1)]]);
R('BP-007', 'batch > 200 rejected', 'HTTP 422', 'HTTP ' . $r->status, $r->status === 422, 'Medium');
$r = apiCall('POST', 'bulkPrint', $staff + ['json' => ['ids' => [$cardIds[1]], 'reason' => str_repeat('x', 256)]]);
R('BP-008', 'reason >255 chars rejected', 'HTTP 422', 'HTTP ' . $r->status, $r->status === 422, 'Medium');

/* ===== original batch (no reason needed) ===== */
$r = apiCall('POST', 'bulkPrint', $staff + ['json' => ['ids' => [$cardIds[1], $cardIds[2], (string)$cardIds[3]]]]);
$ok = $r->status === 200 && (int)($r->json['data']['printed'] ?? 0) === 3;
R('BP-009', 'original batch prints 3 (string ids ok)', 'HTTP 200 printed=3', 'HTTP ' . $r->status . ' printed=' . ($r->json['data']['printed'] ?? '-'), $ok, 'High');
$row = $pdo->query("SELECT status, print_count, printed_at FROM id_cards WHERE id={$cardIds[1]}")->fetch();
R('BP-010', 'card status + counter synced', 'status=printed count=1', 'status=' . ($row['status'] ?? '-') . ' count=' . ($row['print_count'] ?? '-'), $row && $row['status'] === 'printed' && (int)$row['print_count'] === 1 && $row['printed_at'] !== null, 'High');
$st = $pdo->prepare('SELECT user_id, print_type, reason FROM print_history WHERE card_id=? ORDER BY id');
$st->execute([$cardIds[1]]);
$ph = $st->fetch();
R('BP-011', 'print_history row (user, type, reason)', 'staff, original, New Student', 'user=' . ($ph['user_id'] ?? '-') . ' type=' . ($ph['print_type'] ?? '-') . ' reason="' . ($ph['reason'] ?? '-') . '"', $ph && (int)$ph['user_id'] === $staffId && $ph['print_type'] === 'original' && $ph['reason'] === 'New Student', 'High');
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM print_history WHERE card_id IN ({$cardIds[1]},{$cardIds[2]},{$cardIds[3]})")->fetchColumn();
R('BP-012', '3 history rows recorded', 'count=3', 'count=' . $cnt, $cnt === 3, 'High');

/* ===== audit trail ===== */
$sum = $pdo->query("SELECT user_id, action, new_value FROM audit_logs WHERE action='bulk_ids_printed' AND new_value LIKE '%QABulkPrint%' ORDER BY id DESC LIMIT 1")->fetch();
$nv = $sum ? json_decode($sum['new_value'], true) : [];
R('BP-013', 'audit summary bulk_ids_printed', 'user, count=3, ids listed', 'user=' . ($sum['user_id'] ?? '-') . ' count=' . ($nv['count'] ?? '-') . ' ids=' . count($nv['ids'] ?? []), $sum && (int)$sum['user_id'] === $staffId && (int)($nv['count'] ?? 0) === 3 && count($nv['ids'] ?? []) === 3, 'High');
$perCard = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='card_printed' AND entity_id IN ({$cardIds[1]},{$cardIds[2]},{$cardIds[3]})")->fetchColumn();
R('BP-014', 'per-ID card_printed audit rows', 'count=3', 'count=' . $perCard, $perCard === 3, 'Medium');

/* ===== duplicate ids de-duplicated ===== */
/* card 1 was already printed once, so the batch carries the required
 * reprint reason; the duplicated ids must still be recorded only once. */
$r = apiCall('POST', 'bulkPrint', $staff + ['json' => ['ids' => [$cardIds[1], $cardIds[1], $cardIds[1]], 'reason' => 'Lost']]);
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM print_history WHERE card_id={$cardIds[1]}")->fetchColumn();
R('BP-015', 'duplicate ids de-duplicated', 'printed=1, history rows=2 total', 'HTTP ' . $r->status . ' printed=' . ($r->json['data']['printed'] ?? '-') . ' history=' . $cnt, $r->status === 200 && (int)($r->json['data']['printed'] ?? 0) === 1 && $cnt === 2, 'High');

/* ===== reprint rules inside a batch ===== */
$r = apiCall('POST', 'bulkPrint', $staff + ['json' => ['ids' => [$cardIds[1], $cardIds[4]]]]);
R('BP-016', 'batch w/ reprint, no reason -> 422', 'HTTP 422', 'HTTP ' . $r->status, $r->status === 422, 'High');
$r = apiCall('POST', 'bulkPrint', $staff + ['json' => ['ids' => [$cardIds[1], $cardIds[4]], 'reason' => 'Damaged']]);
$ph = $pdo->query("SELECT print_type, reason FROM print_history WHERE card_id={$cardIds[1]} ORDER BY id DESC LIMIT 1")->fetch();
$ph4 = $pdo->query("SELECT print_type, reason FROM print_history WHERE card_id={$cardIds[4]} ORDER BY id DESC LIMIT 1")->fetch();
$ok = $r->status === 200 && $ph && $ph4 && $ph['print_type'] === 'reprint' && $ph['reason'] === 'Damaged' && $ph4['print_type'] === 'original' && $ph4['reason'] === 'New Student';
R('BP-017', 'batch with reason: reprint+original', 'reprint/Damaged + original/New Student', "HTTP {$r->status} c1=" . ($ph['print_type'] ?? '-') . '/' . ($ph['reason'] ?? '-') . " c4=" . ($ph4['print_type'] ?? '-') . '/' . ($ph4['reason'] ?? '-'), $ok, 'High');
$cnt1 = (int)$pdo->query("SELECT print_count FROM id_cards WHERE id={$cardIds[1]}")->fetchColumn();
R('BP-018', 'reprint counter incremented', 'count=3', 'count=' . $cnt1, $cnt1 === 3, 'High');

/* ===== released IDs are skipped, others still print ===== */
apiCall('POST', 'printCard', $staff + ['json' => ['id' => $cardIds[5]]]);
apiCall('POST', 'releaseCard', $staff + ['json' => ['id' => $cardIds[5], 'release_notes' => 'QA bulk suite']]);
$before = (int)$pdo->query("SELECT print_count FROM id_cards WHERE id={$cardIds[5]}")->fetchColumn();
$r = apiCall('POST', 'bulkPrint', $staff + ['json' => ['ids' => [$cardIds[5], $cardIds[6]]]]);
$after = (int)$pdo->query("SELECT print_count FROM id_cards WHERE id={$cardIds[5]}")->fetchColumn();
$sk = $r->json['data']['skipped'] ?? [];
$ok = $r->status === 200 && (int)($r->json['data']['printed'] ?? 0) === 1 && count($sk) === 1 && (int)($sk[0]['id'] ?? 0) === $cardIds[5] && $before === 1 && $after === 1;
R('BP-019', 'released card skipped, not printed', 'printed=1 skipped=[released] count stays 1', "HTTP {$r->status} printed=" . ($r->json['data']['printed'] ?? '-') . ' skipped=' . count($sk) . " count {$before}->{$after}", $ok, 'High');

/* ===== missing ids reported ===== */
/* card 6 was printed in BP-019, so the batch needs a reprint reason. */
$r = apiCall('POST', 'bulkPrint', $staff + ['json' => ['ids' => [999999999, $cardIds[6]], 'reason' => 'Lost']]);
$miss = $r->json['data']['missing'] ?? [];
R('BP-020', 'unknown id reported as missing', 'printed=1 missing=[999999999]', "HTTP {$r->status} printed=" . ($r->json['data']['printed'] ?? '-') . ' missing=' . json_encode($miss), $r->status === 200 && (int)($r->json['data']['printed'] ?? 0) === 1 && in_array(999999999, array_map('intval', array_column($miss, 'id')), true), 'Medium');

/* ===== print history integration (admin read endpoint) ===== */
$r = apiCall('GET', 'printHistory', $admin + ['query' => ['search' => 'QABulkPrint', 'per_page' => 100]]);
$totalRows = (int)($r->json['pagination']['total'] ?? 0);
/* card1: BP-009 + BP-015 + BP-017 = 3 · card2/card3/card4 = 3
 * card5: setup printCard = 1 · card6: BP-019 + BP-020 = 2  →  9 */
R('BP-021', 'printHistory reflects bulk rows', 'total=9 via admin endpoint', 'total=' . $totalRows, $r->status === 200 && $totalRows === 9, 'High');
$r = apiCall('GET', 'printHistory', $staff + ['query' => []]);
R('BP-022', 'printHistory is admin-only', 'HTTP 403 for staff', 'HTTP ' . $r->status, $r->status === 403, 'Medium');

/* ===== audit_logs read endpoint shows the summary ===== */
$r = apiCall('GET', 'auditLogs', $admin + ['query' => ['search' => 'bulk_ids_printed', 'per_page' => 5]]);
R('BP-023', 'auditLogs exposes bulk summary', 'total>=1 (admin)', 'HTTP ' . $r->status . ' total=' . ($r->json['pagination']['total'] ?? '-'), $r->status === 200 && (int)($r->json['pagination']['total'] ?? 0) >= 1, 'Medium');

/* ===== cleanup (end) ===== */
qaBulkCleanup($pdo);

printf("\n%d/%d checks passed (%d failed).\n", $pass, $total, $fail);
exit($fail === 0 ? 0 : 1);
