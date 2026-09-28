<?php
/* QA suite: CSV / Excel Student Import.
 * Covers: auth gate, template download, CSV parsing, validation
 * (required fields, formats, within-file duplicates, DB duplicates),
 * preview counts, commit (transaction), and ID generation.
 * Usage: php backend/tests/qa_import_test.php [base_url]
 */
$BASE = $argv[1] ?? 'http://localhost/lake-shore-id-editor/backend/api.php';
$fail = 0; $pass = 0; $total = 0;
$csrfToken = '';
function R($id, $label, $expect, $actual, $ok, $sev = 'High') {
  global $fail, $pass, $total; $total++;
  if ($ok) $pass++; else $fail++;
  printf("%-9s %-34s %-44s %-40s %-4s %s\n", $id, $label, $expect, $actual, $ok ? 'PASS' : 'FAIL', $sev);
}
function apiCall($method, $action, $opts = []) {
  global $BASE, $csrfToken;
  $url = $BASE . '?action=' . $action;
  if (!empty($opts['query'])) $url .= '&' . http_build_query($opts['query']);
  $ch = curl_init($url);
  $headers = $opts['headers'] ?? [];
  $headers[] = 'User-Agent: QAImportSuite/1.0';
  if (isset($opts['json'])) $headers[] = 'Content-Type: application/json';
  if ($csrfToken !== '' && $method === 'POST') $headers[] = 'X-CSRF-Token: ' . $csrfToken;
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_HEADER         => true,
    CURLOPT_TIMEOUT        => 30,
  ]);
  if (isset($opts['json'])) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
  if (!empty($opts['cookies'])) curl_setopt($ch, CURLOPT_COOKIE, $opts['cookies']);
  if (isset($opts['file'])) {
    $postFields = $opts['file'];
    if (isset($opts['extra'])) foreach ($opts['extra'] as $k => $v) $postFields[$k] = $v;
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
  }
  $raw = (string) curl_exec($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $headerBlock = substr($raw, 0, $hsize);
  $body = substr($raw, $hsize);
  preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $headerBlock, $m);
  preg_match('/^Content-Type:\s*([^\r\n]+)/mi', $headerBlock, $ct);
  return (object) [
    'status' => $status,
    'json'   => json_decode($body, true) ?: [],
    'headers' => $m[1] ?? [],
    'content_type' => trim($ct[1] ?? ''),
    'body' => $body,
  ];
}
function sessionCookie($jar) { $last = ''; foreach ($jar as $c) if (strpos($c, 'LSC_ID_SESSION') === 0) $last = $c; return $last; }

require __DIR__ . '/../config.php';
$pdo = db();

/* ===== cleanup previous QA runs ===== */
$staleIds = $pdo->query("SELECT id FROM id_cards WHERE student_name LIKE 'QAImp%'")->fetchAll(PDO::FETCH_COLUMN);
if ($staleIds) {
  $in = implode(',', array_map('intval', $staleIds));
  $pdo->exec("DELETE FROM audit_logs WHERE entity_type='id_card' AND entity_id IN ($in)");
  $pdo->exec("DELETE FROM print_history WHERE card_id IN ($in)");
  $pdo->exec("DELETE FROM id_cards WHERE id IN ($in)");
}
$pdo->exec("DELETE FROM users WHERE username IN ('qa_import_staff')");
$pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_import_staff',?,'QA Import Staff','staff',1)")->execute([password_hash('QaImp#2026', PASSWORD_DEFAULT)]);

/* ===== login ===== */
$login = apiCall('POST', 'login', ['json' => ['username' => 'qa_import_staff', 'password' => 'QaImp#2026']]);
$cookie = sessionCookie($login->headers);
$csrfToken = $login->json['csrf_token'] ?? '';
R('AUTH-1', 'Staff login', '200 success', $login->status . ' ' . ($login->json['success'] ?? ''), $login->status === 200 && !empty($login->json['success']));
R('AUTH-1b', 'CSRF token returned', 'token', strlen($csrfToken) > 0 ? 'yes' : 'no', strlen($csrfToken) > 0);

/* ===== 1. Auth gate ===== */
$anon = apiCall('GET', 'importTemplate');
R('AUTH-2', 'Template download requires login', '401 unauthorized', $anon->status, $anon->status === 401, 'Critical');

/* ===== 2. Template download ===== */
$tpl = apiCall('GET', 'importTemplate', ['cookies' => $cookie]);
R('TPL-1', 'Template download (staff)', '200 xlsx', $tpl->status, $tpl->status === 200);
R('TPL-2', 'Template content-type', 'spreadsheetml', $tpl->content_type, str_contains($tpl->content_type, 'spreadsheetml'));
R('TPL-3', 'Template body is a ZIP', 'PK', substr($tpl->body ?? '', 0, 2), substr($tpl->body ?? '', 0, 2) === 'PK');
/* Upload the downloaded template back through the API: proves the pure-PHP
 * XLSX parser works under Apache even when the zip extension is not loaded. */
$tplUp = uploadCsv('template_roundtrip.xlsx', $tpl->body ?? '', $cookie, '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
$tc = $tplUp->json['data']['counts'] ?? [];
R('TPL-4', 'Template re-upload parsed', '200 success', $tplUp->status . ' ' . ($tplUp->json['success'] ?? ''), $tplUp->status === 200 && !empty($tplUp->json['success']));
R('TPL-5', 'Template round-trip total 4', '4 total', ($tc['total'] ?? '?') . ' total', ($tc['total'] ?? 0) === 4);
R('TPL-6', 'Template round-trip valid 4', '4 valid', ($tc['valid'] ?? '?') . ' valid', ($tc['valid'] ?? 0) === 4);

/* ===== 3. Build a CSV for upload ===== */
function buildCsv($rows) {
  $fp = fopen('php://temp', 'r+');
  foreach ($rows as $r) fputcsv($fp, $r, ',', '"', '\\');
  rewind($fp);
  $content = stream_get_contents($fp);
  fclose($fp);
  return $content;
}
function uploadCsv($filename, $content, $cookie, $ext = '.csv', $mime = 'text/csv') {
  global $csrfToken;
  $tmp = tempnam(sys_get_temp_dir(), 'qaimp') . $ext;
  file_put_contents($tmp, $content);
  $cfile = new CURLFile($tmp, $mime, $filename);
  $postFields = ['file' => $cfile];
  if ($csrfToken !== '') $postFields['csrf_token'] = $csrfToken;
  $r = apiCall('POST', 'importUpload', ['file' => $postFields, 'cookies' => $cookie]);
  @unlink($tmp);
  return $r;
}

/* ----- 3a. Valid CSV ----- */
$csvRows = [
  ['Student Name', 'Student Number', 'LRN', 'Grade Level', 'Section', 'Address Line 1', 'Emergency Contact', 'Contact Number'],
  ['QAImp One', 'QA-0001', '136501010001', 'Grade 7', 'Sampaguita', 'Purok 1', 'Contact One', '09171234567'],
  ['QAImp Two', 'QA-0002', '', 'Grade 11', 'Molave', 'Purok 2', 'Contact Two', '09181234567'],
  ['QAImp Three', 'QA-0003', '136501010003', 'Grade 12', 'Rosal', 'Purok 3', 'Contact Three', '09191234567'],
];
$csvContent = buildCsv($csvRows);
$upload = uploadCsv('test_valid.csv', $csvContent, $cookie);
R('CSV-1', 'CSV upload success', '200 success', $upload->status . ' ' . ($upload->json['success'] ?? ''), $upload->status === 200 && !empty($upload->json['success']));
$counts = $upload->json['data']['counts'] ?? [];
R('CSV-2', 'CSV valid count = 3', '3 valid', ($counts['valid'] ?? '?') . ' valid', ($counts['valid'] ?? 0) === 3);
R('CSV-3', 'CSV total count = 3', '3 total', ($counts['total'] ?? '?') . ' total', ($counts['total'] ?? 0) === 3);

/* ----- 3b. CSV with invalid rows ----- */
$csvInvalid = [
  ['Student Name', 'Student Number', 'LRN', 'Grade Level', 'Section'],
  ['QAImp Four', 'QA-0004', '', 'Grade 7', 'Sampaguita'],
  ['', 'QA-0005', '', 'Grade 7', 'Sampaguita'],
  ['QAImp Six', '', '', 'Grade 7', 'Sampaguita'],
  ['QAImp Seven', 'QA-0007', '', 'Grade 99', 'Sampaguita'],
];
$upload2 = uploadCsv('test_invalid.csv', buildCsv($csvInvalid), $cookie);
$counts2 = $upload2->json['data']['counts'] ?? [];
R('VAL-1', 'Invalid CSV: 1 valid', '1 valid', ($counts2['valid'] ?? '?') . ' valid', ($counts2['valid'] ?? 0) === 1);
R('VAL-2', 'Invalid CSV: 3 invalid', '3 invalid', ($counts2['invalid'] ?? '?') . ' invalid', ($counts2['invalid'] ?? 0) === 3);

/* ===== 4. Within-file duplicate detection ===== */
$csvDup = [
  ['Student Name', 'Student Number', 'LRN', 'Grade Level', 'Section'],
  ['QAImp Eight', 'QA-DUP1', '136501010010', 'Grade 7', 'A'],
  ['QAImp Nine', 'QA-DUP1', '', 'Grade 7', 'B'],
  ['QAImp Ten', 'QA-DUP2', '136501010010', 'Grade 7', 'C'],
];
$upload3 = uploadCsv('test_dup.csv', buildCsv($csvDup), $cookie);
$counts3 = $upload3->json['data']['counts'] ?? [];
R('DUP-1', 'Within-file dup: 1 valid', '1 valid', ($counts3['valid'] ?? '?') . ' valid', ($counts3['valid'] ?? 0) === 1);
R('DUP-2', 'Within-file dup: 2 duplicate', '2 duplicate', ($counts3['duplicate'] ?? '?') . ' duplicate', ($counts3['duplicate'] ?? 0) === 2);

/* ===== 5. Commit valid rows ===== */
$validRows = $upload->json['data']['rows'] ?? [];
$commit = apiCall('POST', 'importCommit', ['json' => ['rows' => $validRows], 'cookies' => $cookie, 'headers' => ['Content-Type: application/json']]);
R('COM-1', 'Commit success', '200 success', $commit->status . ' ' . ($commit->json['success'] ?? ''), $commit->status === 200 && !empty($commit->json['success']));
R('COM-2', 'Commit imported = 3', '3 imported', ($commit->json['imported'] ?? '?') . ' imported', ($commit->json['imported'] ?? 0) === 3);
$importedIds = $commit->json['ids'] ?? [];
R('COM-3', 'Commit returns 3 ids', '3 ids', count($importedIds) . ' ids', count($importedIds) === 3);

/* ===== 6. DB duplicate detection (re-upload same data) ===== */
$upload4 = uploadCsv('test_db_dup.csv', $csvContent, $cookie);
$counts4 = $upload4->json['data']['counts'] ?? [];
R('DBDUP-1', 'DB dup: 0 valid', '0 valid', ($counts4['valid'] ?? '?') . ' valid', ($counts4['valid'] ?? 0) === 0);
R('DBDUP-2', 'DB dup: 3 duplicate', '3 duplicate', ($counts4['duplicate'] ?? '?') . ' duplicate', ($counts4['duplicate'] ?? 0) === 3);

/* ===== 7. Card records created ===== */
$cardCheck = apiCall('GET', 'card', ['query' => ['id' => $importedIds[0]], 'cookies' => $cookie]);
R('CARD-1', 'Card record created', 'found', $cardCheck->json['success'] ?? 'missing', !empty($cardCheck->json['success']));
R('CARD-2', 'Card name matches', 'QAImp One', $cardCheck->json['data']['student_name'] ?? 'n/a', ($cardCheck->json['data']['student_name'] ?? '') === 'QAImp One');
R('CARD-3', 'Card status created', 'created', $cardCheck->json['data']['status'] ?? 'n/a', ($cardCheck->json['data']['status'] ?? '') === 'created');

/* ===== 8. Empty commit rejected ===== */
$emptyCommit = apiCall('POST', 'importCommit', ['json' => ['rows' => []], 'cookies' => $cookie, 'headers' => ['Content-Type: application/json']]);
R('EMPTY-1', 'Empty commit rejected', '422', $emptyCommit->status, $emptyCommit->status === 422);

/* ===== cleanup ===== */
if ($importedIds) {
  $in = implode(',', array_map('intval', $importedIds));
  $pdo->exec("DELETE FROM audit_logs WHERE entity_type='id_card' AND entity_id IN ($in)");
  $pdo->exec("DELETE FROM id_cards WHERE id IN ($in)");
}
$pdo->exec("DELETE FROM users WHERE username = 'qa_import_staff'");

echo "\n===== RESULT =====\n";
echo "Total: $total | Pass: $pass | Fail: $fail\n";
exit($fail > 0 ? 1 : 0);


