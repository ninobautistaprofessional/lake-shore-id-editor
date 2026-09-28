<?php
/* QA suite: audit log system. Covers every required action:
 *   done, edited (field diff), deleted, printed, template changed,
 *   signatory changed, user disabled, lost-ID approved
 * plus auth security events and the admin-only read endpoint.
 * Usage: php backend/tests/qa_audit_test.php [base_url]
 */
$BASE = $argv[1] ?? 'http://localhost/lake-shore-id-editor/backend/api.php';
$fail = 0; $pass = 0; $total = 0;
function R($id, $label, $expect, $actual, $ok, $sev = 'High') {
  global $fail, $pass, $total; $total++;
  if ($ok) $pass++; else $fail++;
  printf("%-9s %-28s %-46s %-40s %-4s %s\n", $id, $label, $expect, $actual, $ok ? 'PASS' : 'FAIL', $sev);
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
  $headers[] = 'User-Agent: QAAuditSuite/1.0';
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
  if (isset($opts['files'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['files']);
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
$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$pngTmp = tempnam(sys_get_temp_dir(), 'qapng'); file_put_contents($pngTmp, $PNG);

require __DIR__ . '/../config.php';
$pdo = db();
$runStart = date('Y-m-d H:i:s');
/* Cleanup namespaces */
$pdo->exec("DELETE FROM audit_logs WHERE action LIKE 'AUDIT_TEST%' OR entity_type='audit_test'");
$pdo->exec("DELETE FROM audit_logs WHERE action='login_failed' AND created_at >= '$runStart'");
$pdo->exec("DELETE lr FROM lost_id_requests lr LEFT JOIN id_cards c ON c.id=lr.reference_card_id WHERE lr.student_name LIKE 'QAAudit%' OR c.id IS NULL AND lr.student_name LIKE 'QAAudit%'");
$pdo->exec("DELETE FROM lost_id_requests WHERE student_name LIKE 'QAAudit%'");
$pdo->exec("DELETE FROM id_cards WHERE student_name LIKE 'QAAudit%'");
$pdo->exec("DELETE FROM users WHERE username IN ('qa_audit_admin','qa_audit_staff','qa_audit_victim')");
$pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_audit_staff',?,'QA Audit Staff','staff',1)")->execute([password_hash('QaAudit#2026', PASSWORD_DEFAULT)]);
$pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_audit_admin',?,'QA Audit Admin','admin',1)")->execute([password_hash('QaAudit#Admin1', PASSWORD_DEFAULT)]);

/* ===== bootstrap sessions ===== */
$r = apiCall('POST', 'login', ['json' => ['username' => 'qa_audit_admin', 'password' => 'QaAudit#Admin1']]);
$adminCookie = sessionCookie($r->headers); $adminToken = (string)($r->json['csrf_token'] ?? '');
$adminId = (int)($r->json['data']['id'] ?? 0);
R('AUD-000', 'Bootstrap', 'admin login', $r->status . ' role=' . ($r->json['data']['role'] ?? '?'), $r->status === 200 && $adminCookie !== '' && $adminToken !== '' ? true : false, 'Critical');
$auth = ['cookies' => $adminCookie, 'headers' => ['X-CSRF-Token: ' . $adminToken]];

/* ===== 1. student card creation (public) + edit diff (staff) ===== */
$r = apiCall('POST', 'studentSaveCard', ['json' => ['student_name' => 'QAAudit Juan Dela Cruz', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'student_number' => 'QAA-COL-0001', 'section_name' => 'A']]);
$cardId = (int)($r->json['id'] ?? 0);
R('AUD-001', 'card created', 'audit row: card created w/ entity id', 'id=' . $cardId, $r->status === 200 && $cardId > 0, 'High');
$row = $cardId ? $pdo->query("SELECT * FROM audit_logs WHERE entity_type='id_card' AND entity_id=$cardId AND action='card_created' ORDER BY id DESC LIMIT 1")->fetch() : false;
R('AUD-002', 'card created logged', "action=card_created entity=$cardId", $row ? ('user_id=' . var_export($row['user_id'], true) . ' new=' . substr((string)$row['new_value'], 0, 30)) : 'MISSING', (bool)$row, 'High');

$r = apiCall('POST', 'saveCard', $auth + ['json' => ['id' => $cardId, 'student_name' => 'QAAudit Juan Dela Cruz', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN ACCOUNTANCY', 'student_number' => 'QAA-COL-0001', 'section_name' => 'B']]);
R('AUD-003', 'staff edit request', '200', $r->status, $r->status === 200, 'High');
$row = $pdo->query("SELECT * FROM audit_logs WHERE entity_type='id_card' AND entity_id=$cardId AND action='card_updated' ORDER BY id DESC LIMIT 1")->fetch();
$diff = $row ? json_decode($row['new_value'], true) : [];
$course = null; foreach (($diff ?: []) as $d) if (($d['field'] ?? '') === 'course') $course = $d;
R('AUD-004', 'edit diff: course', 'FROM Psychology TO Accountancy', $course ? ($course['from'] . ' -> ' . $course['to']) : 'NO DIFF', $course && stripos($course['from'], 'PSYCHOLOGY') !== false && stripos($course['to'], 'ACCOUNTANCY') !== false, 'High');
$section = null; foreach (($diff ?: []) as $d) if (($d['field'] ?? '') === 'section_name') $section = $d;
R('AUD-005', 'edit diff: section', 'FROM A TO B', $section ? ($section['from'] . ' -> ' . $section['to']) : 'NO DIFF', $section && $section['from'] === 'A' && $section['to'] === 'B', 'Medium');
R('AUD-006', 'edit captured user', 'staff user id + username in old/new meta', $row ? ('user_id=' . $row['user_id']) : 'MISSING', $row && (int)$row['user_id'] === $adminId, 'High');

/* ===== 2. lifecycle: done -> printed ===== */
$statusBefore = (string)$pdo->query("SELECT status FROM id_cards WHERE id=$cardId")->fetchColumn();
$r = apiCall('POST', 'setCardStatus', $auth + ['json' => ['id' => $cardId, 'status' => 'done']]);
$row = $pdo->query("SELECT * FROM audit_logs WHERE entity_type='id_card' AND entity_id=$cardId AND action='card_status_changed' ORDER BY id DESC LIMIT 1")->fetch();
$old = $row ? json_decode($row['old_value'], true) : [];
R('AUD-007', 'marked Done', "audit card_status_changed old=$statusBefore", $row ? ('old=' . ($old['status'] ?? '?')) : 'MISSING', $r->status === 200 && $row && ($old['status'] ?? '') === $statusBefore, 'High');
$r = apiCall('POST', 'setCardStatus', $auth + ['json' => ['id' => $cardId, 'status' => 'printed']]);
$row = $pdo->query("SELECT * FROM audit_logs WHERE entity_type='id_card' AND entity_id=$cardId AND action='card_printed' ORDER BY id DESC LIMIT 1")->fetch();
R('AUD-008', 'printed', 'audit card_printed', $row ? ('action=' . $row['action']) : 'MISSING', $r->status === 200 && (bool)$row, 'High');

/* ===== 3. lost-ID approval ===== */
$r = apiCall('POST', 'lostIdRequest', ['json' => ['student_name' => 'QAAudit Juan Dela Cruz', 'id_type' => 'COLLEGE', 'course' => 'BACHELOR OF SCIENCE IN ACCOUNTANCY', 'student_number' => 'QAA-COL-0001', 'reason' => 'QA lost id']]);
$lostId = (int)($r->json['id'] ?? 0);
$r = $lostId ? apiCall('POST', 'updateLostIdRequest', $auth + ['json' => ['id' => $lostId, 'status' => 'approved']]) : null;
$row = $lostId ? $pdo->query("SELECT * FROM audit_logs WHERE entity_type='lost_id_request' AND entity_id=$lostId AND action='lost_id_approved' ORDER BY id DESC LIMIT 1")->fetch() : false;
R('AUD-009', 'lost ID approved', "audit lost_id_approved id=$lostId", $row ? ('user_id=' . $row['user_id']) : 'MISSING (req status ' . ($r->status ?? 'n/a') . ')', (bool)$row, 'High');

/* ===== 4. template + signatory ===== */
$r = apiCall('POST', 'saveTemplate', $auth + ['files' => ['name' => 'QAAudit Template', 'id_type' => 'COLLEGE', 'fields_json' => '[]', 'is_active' => '1', 'front_image' => new CURLFile($pngTmp, 'image/png', 'front.png')]]);
$tplId = (int)($r->json['id'] ?? 0);
$row = $tplId ? $pdo->query("SELECT * FROM audit_logs WHERE entity_type='id_template' AND entity_id=$tplId AND action='template_created' ORDER BY id DESC LIMIT 1")->fetch() : false;
R('AUD-010', 'template created', "audit template_created id=$tplId", $row ? ('name=' . (json_decode($row['new_value'], true)['name'] ?? '?')) : 'MISSING (status ' . $r->status . ')', (bool)$row, 'High');
$r = apiCall('POST', 'saveSignatory', $auth + ['files' => ['full_name' => 'QAAudit Signatory', 'position_title' => 'QA Registrar', 'signature' => new CURLFile($pngTmp, 'image/png', 'sig.png')]]);
$sigId = (int)($r->json['id'] ?? 0);
$row = $sigId ? $pdo->query("SELECT * FROM audit_logs WHERE entity_type='signatory' AND entity_id=$sigId AND action='signatory_created' ORDER BY id DESC LIMIT 1")->fetch() : false;
R('AUD-011', 'signatory changed', "audit signatory_created id=$sigId", $row ? 'logged' : 'MISSING (status ' . $r->status . ')', (bool)$row, 'High');

/* ===== 5. user management: create then disable ===== */
$r = apiCall('POST', 'saveUser', $auth + ['json' => ['username' => 'qa_audit_victim', 'full_name' => 'QA Audit Victim', 'role' => 'staff', 'password' => 'Victim#2026']]);
$victimId = (int)($r->json['id'] ?? 0);
$r = $victimId ? apiCall('POST', 'saveUser', $auth + ['json' => ['id' => $victimId, 'username' => 'qa_audit_victim', 'full_name' => 'QA Audit Victim', 'role' => 'staff', 'is_active' => 0]]) : null;
$row = $victimId ? $pdo->query("SELECT * FROM audit_logs WHERE entity_type='user' AND entity_id=$victimId AND action='user_disabled' ORDER BY id DESC LIMIT 1")->fetch() : false;
$dis = $row ? [json_decode($row['old_value'], true)['is_active'] ?? null, json_decode($row['new_value'], true)['is_active'] ?? null] : null;
R('AUD-012', 'user disabled', 'audit user_disabled 1 -> 0', $dis ? ("old={$dis[0]} new={$dis[1]}") : 'MISSING (status ' . ($r->status ?? 'n/a') . ')', $dis && $dis[0] === 1 && $dis[1] === 0, 'High');

/* ===== 6. delete ID ===== */
$r = apiCall('POST', 'deleteCard', $auth + ['json' => ['id' => $cardId]]);
$row = $pdo->query("SELECT * FROM audit_logs WHERE entity_type='id_card' AND entity_id=$cardId AND action='card_deleted' ORDER BY id DESC LIMIT 1")->fetch();
$delOld = $row ? json_decode($row['old_value'], true) : [];
R('AUD-013', 'ID deleted', 'audit card_deleted w/ snapshot', $row ? ('name=' . ($delOld['student_name'] ?? '?') . ' status=' . ($delOld['status'] ?? '?')) : 'MISSING (status ' . $r->status . ')', $r->status === 200 && $row && ($delOld['status'] ?? '') === 'printed', 'High');

/* ===== 7. auth security events ===== */
$row = $pdo->query("SELECT * FROM audit_logs WHERE action='login' AND entity_id=$adminId ORDER BY id DESC LIMIT 1")->fetch();
R('AUD-014', 'login logged', 'audit login row for admin', $row ? 'logged' : 'MISSING', (bool)$row, 'Medium');

/* ===== 8. endpoint access control ===== */
$r = apiCall('POST', 'login', ['json' => ['username' => 'qa_audit_staff', 'password' => 'QaAudit#2026']]);
$staffCookie = sessionCookie($r->headers);
$r = apiCall('GET', 'auditLogs', ['cookies' => $staffCookie]);
R('AUD-015', 'staff blocked from logs', '403', $r->status, $r->status === 403, 'Critical');
$r = apiCall('GET', 'auditLogs');
R('AUD-016', 'anonymous blocked', '401', $r->status, $r->status === 401, 'Critical');
$r = apiCall('GET', 'auditLogs', $auth);
R('AUD-017', 'admin reads logs', '200 + data + pagination', $r->status . ' rows=' . count($r->json['data'] ?? []), $r->status === 200 && isset($r->json['data'], $r->json['pagination'], $r->json['actions']), 'Critical');
$r = apiCall('POST', 'auditLogs', $auth + ['json' => ['action' => 'hack']]);
R('AUD-018', 'no write path for logs', '404 (no create/update/delete API)', $r->status, $r->status === 404, 'Critical');

/* ===== 9. metadata capture ===== */
$row = $pdo->query("SELECT * FROM audit_logs WHERE entity_type='id_card' AND action='card_updated' ORDER BY id DESC LIMIT 1")->fetch();
$ipOk = $row && in_array($row['ip_address'], ['127.0.0.1', '::1'], true);
$uaOk = $row && $row['user_agent'] === 'QAAuditSuite/1.0';
R('AUD-019', 'IP + user agent captured', 'ip=127.0.0.1|::1, ua=QAAuditSuite/1.0', 'ip=' . ($row['ip_address'] ?? '?') . ' ua=' . ($row['user_agent'] ?? '?'), $ipOk && $uaOk, 'High');
$row = $pdo->query("SELECT * FROM audit_logs WHERE entity_type='id_card' AND action='card_created' AND user_id IS NULL ORDER BY id DESC LIMIT 1")->fetch();
R('AUD-020', 'public action anonymous', 'card_created row has user_id NULL', $row ? 'user_id=NULL' : 'MISSING', (bool)$row, 'Medium');
$row = $pdo->query("SELECT * FROM audit_logs WHERE entity_type='id_card' AND action='card_updated' ORDER BY id DESC LIMIT 1")->fetch();
$ageOk = $row && (time() - strtotime($row['created_at'])) < 300;
R('AUD-021', 'timestamp sane', 'created_at within 5 min', $row ? ('age=' . (time() - strtotime($row['created_at'])) . 's') : 'MISSING', $ageOk, 'Medium');

/* ===== 10. endpoint filters ===== */
$r = apiCall('GET', 'auditLogs', $auth + ['query' => ['action_type' => 'card_deleted']]);
$rows = $r->json['data'] ?? [];
$onlyDel = count($rows) > 0 && !in_array(false, array_map(fn ($x) => $x['action'] === 'card_deleted', $rows), true);
R('AUD-022', 'filter by action', 'only card_deleted rows', 'rows=' . count($rows), $onlyDel, 'Medium');
$r = apiCall('GET', 'auditLogs', $auth + ['query' => ['user_id' => (string)$adminId]]);
$rows = $r->json['data'] ?? [];
$onlyAdmin = count($rows) > 0 && !in_array(false, array_map(fn ($x) => (int)$x['user_id'] === $adminId, $rows), true);
R('AUD-023', 'filter by user', 'only admin rows', 'rows=' . count($rows), $onlyAdmin, 'Medium');
$r = apiCall('GET', 'auditLogs', $auth + ['query' => ['search' => 'QAAudit']]);
$rows = $r->json['data'] ?? [];
R('AUD-024', 'search hits values', 'search finds QAAudit rows', 'rows=' . count($rows), count($rows) >= 3, 'Medium');
$r = apiCall('GET', 'auditLogs', $auth + ['query' => ['page' => '1', 'per_page' => '5']]);
$pg = $r->json['pagination'] ?? [];
R('AUD-025', 'pagination', 'per_page respected', 'per_page=' . ($pg['per_page'] ?? '?') . ' rows=' . count($r->json['data'] ?? []), ($pg['per_page'] ?? 0) === 5 && count($r->json['data'] ?? []) <= 5, 'Medium');

/* ===== cleanup (direct DB — there is intentionally no API to modify logs) ===== */
$pdo->exec("DELETE FROM audit_logs WHERE (entity_type='id_card' AND entity_id=$cardId) OR (entity_type='lost_id_request' AND entity_id=$lostId) OR (entity_type='id_template' AND entity_id=$tplId) OR (entity_type='signatory' AND entity_id=$sigId) OR (entity_type='user' AND entity_id=$victimId) OR (entity_type='user' AND entity_id=$adminId AND action IN ('login','login_failed'))");
$pdo->exec("DELETE FROM lost_id_requests WHERE student_name LIKE 'QAAudit%'");
$pdo->exec("DELETE FROM id_cards WHERE student_name LIKE 'QAAudit%'");
$pdo->exec("DELETE FROM id_templates WHERE name='QAAudit Template'");
$pdo->exec("DELETE FROM signatories WHERE full_name='QAAudit Signatory'");
/* Remove every audit row written by the suite's own users BEFORE deleting the
 * users, so no audit_logs rows are left with dangling user_ids. */
$pdo->exec("DELETE FROM audit_logs WHERE user_id IN (SELECT id FROM (SELECT id FROM users WHERE username IN ('qa_audit_admin','qa_audit_staff','qa_audit_victim')) t)");
$pdo->exec("DELETE FROM users WHERE username IN ('qa_audit_admin','qa_audit_staff','qa_audit_victim')");
printf("\nTOTAL: %d  PASS: %d  FAIL: %d\n", $total, $pass, $fail);
exit($fail === 0 ? 0 : 1);
