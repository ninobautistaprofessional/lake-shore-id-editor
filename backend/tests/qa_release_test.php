<?php
/* ============================================================
 * QA: Released ID Status (PRINTED -> RELEASED)
 * Requires Apache+MySQL running. Usage:
 *   php backend/tests/qa_release_test.php [BASE_URL]
 * ============================================================ */
error_reporting(E_ALL & ~E_DEPRECATED);
$BASE = $argv[1] ?? 'http://localhost/lake-shore-id-editor/backend/api.php';
$results = [];
function R(string $id, string $module, string $case, string $expect, string $actual, string $status, string $sev = 'High'): void {
    global $results; $results[] = [$id, $module, $case, $expect, $actual, $status, $sev];
    printf("%-9s %-14s %-52s %-38s %-42s %-5s %s\n", $id, $module, $case, $expect, $actual, $status, $sev);
}
/* --- curl-based API helper with cookie jar --- */
function apiCall(string $method, string $action, array $opts = []): object {
    global $BASE, $pdo;
    /* Public submissions are rate limited; throttling itself is covered
       by qa_rate_limit_test.php, so clear the per-IP window here. */
    if (in_array($action, ['studentSaveCard', 'studentUploadPhoto', 'lostIdRequest'], true)) {
        $pdo->exec("DELETE FROM rate_limit_counters WHERE bucket LIKE 'public:%'");
    }
    $url = $BASE . '?action=' . urlencode($action);
    if (!empty($opts['query'])) $url .= '&' . http_build_query($opts['query']);
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    $post = null;
    if (isset($opts['json'])) { $post = json_encode($opts['json']); $headers[] = 'Content-Type: application/json'; }
    elseif (isset($opts['form'])) { $post = http_build_query($opts['form']); }
    $captured = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'QARlsSuite/1.0',
        CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$captured) { $captured[] = trim($h); return strlen($h); },
    ]);
    if (!empty($opts['cookie'])) curl_setopt($ch, CURLOPT_COOKIE, $opts['cookie']);
    $raw = (string) curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $j = json_decode($raw, true);
    return (object) ['status' => $st, 'json' => is_array($j) ? $j : [], 'raw' => $raw, 'headers' => $captured];
}
/* Uses the LAST LSC_ID_SESSION cookie: session_regenerate_id(true) on login
 * invalidates the first one PHP sets, so the final value is the live session. */
function sessionCookie(array $headers): string {
    $val = '';
    foreach ($headers as $h) {
        if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/LSC_ID_SESSION=([^;]+)/', $h, $m)) $val = 'LSC_ID_SESSION=' . $m[1];
    }
    return $val;
}
require __DIR__ . '/../config.php';
$pdo = db();
$ids = []; // created card ids for cleanup
try {
    /* ---------------- bootstrap ---------------- */
    $pdo->exec("DELETE FROM audit_logs WHERE user_id IN (SELECT id FROM users WHERE username IN ('qa_rls_admin','qa_rls_staff'))");
    $pdo->exec("DELETE FROM users WHERE username IN ('qa_rls_admin','qa_rls_staff')");
    $pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_rls_admin',?,'QA RLS Admin','admin',1)")
        ->execute([password_hash('QaRls#Admin1', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO users(username,password,full_name,role,is_active) VALUES('qa_rls_staff',?,'QA RLS Staff','staff',1)")
        ->execute([password_hash('QaRls#Staff1', PASSWORD_DEFAULT)]);
    $staffId = (int) $pdo->query("SELECT id FROM users WHERE username='qa_rls_staff'")->fetchColumn();
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE username='qa_rls_admin'")->fetchColumn();

    $login = function (string $u, string $p) {
        $r = apiCall('POST', 'login', ['json' => ['username' => $u, 'password' => $p]]);
        return [(string) sessionCookie($r->headers), (string) ($r->json['csrf_token'] ?? '')];
    };
    [$staffCookie, $staffCsrf] = $login('qa_rls_staff', 'QaRls#Staff1');
    [$adminCookie, $adminCsrf] = $login('qa_rls_admin', 'QaRls#Admin1');
    R('RLS-000', 'Bootstrap', 'Staff + admin created and logged in', 'cookies + csrf tokens',
        'staff=' . ($staffCookie !== '' ? 'ok' : 'NO') . ' admin=' . ($adminCookie !== '' ? 'ok' : 'NO'),
        ($staffCookie !== '' && $staffCsrf !== '' && $adminCookie !== '' && $adminCsrf !== '') ? 'PASS' : 'FAIL');

    $mkCard = function (string $name, string $num) use (&$ids): int {
        $r = apiCall('POST', 'studentSaveCard', ['json' => [
            'student_name' => $name, 'id_type' => 'COLLEGE',
            'course' => 'BACHELOR OF SCIENCE IN PSYCHOLOGY', 'section_name' => 'A', 'student_number' => $num,
        ]]);
        $id = (int) ($r->json['id'] ?? 0);
        if ($id > 0) $ids[] = $id;
        return $id;
    };
    $row = function (int $id) use ($pdo) {
        $st = $pdo->prepare('SELECT c.*, u.username AS released_by_name FROM id_cards c LEFT JOIN users u ON u.id=c.released_by WHERE c.id=?');
        $st->execute([$id]); return $st->fetch();
    };
    $rls = function (int $cardId, string $cookie, string $csrf, array $extra = []) {
        return apiCall('POST', 'releaseCard', ['cookie' => $cookie, 'headers' => ["X-CSRF-Token: $csrf"], 'json' => array_merge(['id' => $cardId], $extra)]);
    };
    $stat = function (int $cardId, string $status, string $cookie, string $csrf) {
        return apiCall('POST', 'setCardStatus', ['cookie' => $cookie, 'headers' => ["X-CSRF-Token: $csrf"], 'json' => ['id' => $cardId, 'status' => $status]]);
    };
    /* CASE-A stays unprinted; CASE-B full lifecycle; CASE-C admin release */
    $cardA = $mkCard('QARls Unprinted One', 'QARLS-A-0001');
    $cardB = $mkCard('QARls Full Lifecycle', 'QARLS-B-0002');
    $cardC = $mkCard('QARls Admin Release', 'QARLS-C-0003');
    if (!$cardA || !$cardB || !$cardC) { R('RLS-000b', 'Bootstrap', 'Fixture cards created', '3 ids', "A=$cardA B=$cardB C=$cardC", 'FAIL'); throw new RuntimeException('fixtures failed'); }

    /* ---------- RLS-002: CREATED (never printed) cannot be released ---------- */
    $r = $rls($cardA, $staffCookie, $staffCsrf);
    $after = $row($cardA);
    R('RLS-002', 'Release guard', 'Release a CREATED (never printed) ID', '422 + row unchanged',
        $r->status . ' status=' . $after['status'],
        ($r->status === 422 && $after['status'] === 'created' && $after['released_by'] === null) ? 'PASS' : 'FAIL');
    $stat($cardA, 'done', $staffCookie, $staffCsrf);
    $r = $rls($cardA, $staffCookie, $staffCsrf, ['release_notes' => 'x']);
    $after = $row($cardA);
    R('RLS-003', 'Release guard', 'Release a DONE (approved, unprinted) ID', '422 + still done',
        $r->status . ' status=' . $after['status'],
        ($r->status === 422 && $after['status'] === 'done') ? 'PASS' : 'FAIL');
    /* ---------- RLS-005 + RLS-001: full lifecycle release by staff ---------- */
    $stat($cardB, 'done', $staffCookie, $staffCsrf);
    $stat($cardB, 'printed', $staffCookie, $staffCsrf);
    $r = $rls($cardB, $staffCookie, $staffCsrf, ['release_notes' => 'Claimed at registrar window 2', 'student_received' => true]);
    $after = $row($cardB);
    $ok = $r->status === 200 && ($after['status'] ?? '') === 'released';
    R('RLS-001', 'Release', 'PRINTED ID released by staff (with confirm in UI)', '200 + status RELEASED',
        $r->status . ' status=' . ($after['status'] ?? '?'), $ok ? 'PASS' : 'FAIL');
    R('RLS-005', 'Lifecycle', 'created->done->printed->released transitions', 'each persisted',
        'printed -> ' . ($after['status'] ?? '?'), $ok ? 'PASS' : 'FAIL');

    /* ---------- RLS-004: release info persisted correctly ---------- */
    $okInfo = ((int) $after['released_by'] === $staffId)
        && $after['released_at'] !== null
        && $after['release_notes'] === 'Claimed at registrar window 2'
        && (int) $after['student_received'] === 1
        && ($after['released_by_name'] ?? '') === 'qa_rls_staff';
    R('RLS-004', 'Release', 'released_by / released_at / notes / received saved', 'staff id, timestamp, notes, 1, name join',
        'by=' . ($after['released_by_name'] ?? '?') . ' at=' . ($after['released_at'] ?? 'NULL') . ' recv=' . $after['student_received'],
        $okInfo ? 'PASS' : 'FAIL');

    /* ---------- RLS-006: audit record for the release ---------- */
    $ast = $pdo->prepare("SELECT * FROM audit_logs WHERE action='card_released' AND entity_type='id_card' AND entity_id=? ORDER BY id DESC LIMIT 1");
    $ast->execute([$cardB]);
    $log = $ast->fetch();
    $old = $log ? (json_decode($log['old_value'], true) ?: []) : [];
    $new = $log ? (json_decode($log['new_value'], true) ?: []) : [];
    $okLog = $log && (int) $log['user_id'] === $staffId
        && ($old['status'] ?? '') === 'printed'
        && ($new['status'] ?? '') === 'released'
        && ($new['release_notes'] ?? '') === 'Claimed at registrar window 2'
        && $log['ip_address'] !== null && $log['ip_address'] !== ''
        && strpos((string) $log['user_agent'], 'QARlsSuite/1.0') !== false
        && $log['created_at'] !== null;
    R('RLS-006', 'Audit', "'Staff Released Student ID' audit complete", 'user/old/new/notes/ip/ua/time',
        $log ? ('uid=' . $log['user_id'] . ' old=' . ($old['status'] ?? '?') . ' new=' . ($new['status'] ?? '?') . ' ip=' . $log['ip_address']) : 'NO LOG ROW',
        $okLog ? 'PASS' : 'FAIL');

    /* ---------- RLS-007: no accidental re-release ---------- */
    $r = $rls($cardB, $staffCookie, $staffCsrf);
    R('RLS-007', 'Release guard', 'Release an already-RELEASED ID', '409 + data intact',
        $r->status . ' notes=' . substr((string) $row($cardB)['release_notes'], 0, 12),
        ($r->status === 409 && $row($cardB)['release_notes'] === 'Claimed at registrar window 2') ? 'PASS' : 'FAIL');

    /* ---------- RLS-008: RELEASED is terminal for status changes ---------- */
    $r = $stat($cardB, 'done', $staffCookie, $staffCsrf);
    R('RLS-008', 'Release guard', 'setCardStatus on a RELEASED ID', '409 terminal',
        $r->status . ' status=' . $row($cardB)['status'],
        ($r->status === 409 && $row($cardB)['status'] === 'released') ? 'PASS' : 'FAIL');

    /* ---------- RLS-009: permissions ---------- */
    $rAnon = apiCall('POST', 'releaseCard', ['json' => ['id' => $cardC]]);
    $rNoCsrf = apiCall('POST', 'releaseCard', ['cookie' => $staffCookie, 'json' => ['id' => $cardC]]);
    R('RLS-009', 'Permissions', 'Anonymous / missing-CSRF release attempts', '401 / 403',
        'anon=' . $rAnon->status . ' nocsrf=' . $rNoCsrf->status,
        ($rAnon->status === 401 && $rNoCsrf->status === 403) ? 'PASS' : 'FAIL');

    /* ---------- RLS-011: admin can release too ---------- */
    $stat($cardC, 'done', $adminCookie, $adminCsrf);
    $stat($cardC, 'printed', $adminCookie, $adminCsrf);
    $r = $rls($cardC, $adminCookie, $adminCsrf, ['release_notes' => 'Released by admin', 'student_received' => false]);
    $afterC = $row($cardC);
    R('RLS-011', 'Permissions', 'Admin release path (received unchecked)', '200, released_by=admin, recv=0',
        $r->status . ' by=' . ($afterC['released_by_name'] ?? '?') . ' recv=' . $afterC['student_received'],
        ($r->status === 200 && $afterC['status'] === 'released' && (int) $afterC['released_by'] === $adminId && (int) $afterC['student_received'] === 0) ? 'PASS' : 'FAIL');

    /* ---------- RLS-010: listing exposes release info ---------- */
    $r = apiCall('GET', 'cards', ['cookie' => $staffCookie, 'query' => ['search' => 'QARLS-B-0002']]);
    $b = null;
    foreach (($r->json['data'] ?? []) as $x) if (($x['student_number'] ?? '') === 'QARLS-B-0002') $b = $x;
    R('RLS-010', 'Listing', 'cards list carries released_by_name + status', 'present on released row',
        $b ? ('by=' . ($b['released_by_name'] ?? 'MISSING') . ' status=' . $b['status']) : 'NOT FOUND',
        ($b && ($b['released_by_name'] ?? '') !== '' && $b['status'] === 'released') ? 'PASS' : 'FAIL', 'Medium');

    $pass = count(array_filter($results, fn ($x) => $x[5] === 'PASS'));
    printf("\nTOTAL: %d  PASS: %d  FAIL: %d\n", count($results), $pass, count($results) - $pass);
} catch (Throwable $e) {
    printf("FATAL: %s\n", $e->getMessage());
} finally {
    /* ---------- cleanup: audit rows, cards, users ---------- */
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM audit_logs WHERE entity_type='id_card' AND entity_id IN ($in)");
        $pdo->exec("DELETE FROM id_cards WHERE id IN ($in)");
    }
    $pdo->exec("DELETE FROM audit_logs WHERE user_id IN (SELECT id FROM (SELECT id FROM users WHERE username IN ('qa_rls_admin','qa_rls_staff')) t)");
    $pdo->exec("DELETE FROM users WHERE username IN ('qa_rls_admin','qa_rls_staff')");
    echo "Cleanup completed.\n";
}
