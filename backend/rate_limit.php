<?php
/*
|--------------------------------------------------------------------------
| Public portal rate limiting
|--------------------------------------------------------------------------
| Protects the unauthenticated endpoints of api.php from spam, abuse
| and automated submissions:
|
|   - studentSaveCard      (public ID creation form)
|   - studentUploadPhoto   (public photo upload)
|   - lostIdRequest        (public lost-ID reprint request)
|   - publicCaptcha        (human-verification challenges)
|
| Policy (values live in config.php, see the comment block there):
|   5 requests / 10 minutes / IP address per action by default.  As
|   soon as the limit is exceeded the request is REJECTED with
|   HTTP 429 BEFORE any processing, with a Retry-After header and a
|   plain-language message.  Authenticated staff endpoints are not
|   throttled — they already require a session.
|
| How the counting works
|   One row per (bucket, IP, window) in rate_limit_counters, bumped
|   with a single atomic statement:
|       INSERT ... ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits+1)
|   so parallel requests from the same IP can never race past the
|   limit, and the new counter value is read back through
|   PDO::lastInsertId() without an extra round trip.  Buckets are per
|   action, so a student who uploads a photo and saves the form does
|   not consume an unrelated action's quota, while a spammer is still
|   capped per endpoint.
|
| Client IP
|   X-Forwarded-For / X-Real-IP are honoured ONLY when the immediate
|   peer is listed in RATE_LIMIT_TRUSTED_PROXY_IPS.  Trusting those
|   headers unconditionally would let anyone forge a header and get a
|   fresh bucket per request.  IPv6 addresses are reduced to their
|   /64 prefix so a rotating temporary address cannot reset a counter.
|
| Human verification (repeated or suspicious traffic)
|   - honeypot: public forms carry a hidden field ("website"); a
|     non-empty value marks an automated submission.
|   - captcha:  a self-hosted SVG challenge served by ?action=
|     publicCaptcha.  No third-party service, no API key and no GD
|     extension needed.  A blocked client can solve it once per window
|     to unlock RATE_LIMIT_CAPTCHA_GRANT_ATTEMPTS extra attempts,
|     which is what keeps a shared network (one public IP for a whole
|     school lab) usable without weakening the anonymous limit.
|
| Failure behaviour
|   If the counters cannot be read or written the request is ALLOWED
|   (fail-open) and the reason is written to the PHP error log: a
|   database hiccup must never take the ID portal offline.
|
|   Nothing about storage, tables, file paths, versions or the server
|   is ever disclosed to a blocked client — only the retry time and
|   (when it helps) the captcha prompt.
*/

require_once __DIR__ . '/config.php';

/* ------------------------------------------------------------------ */
/* Configuration accessors (defaults mirror config.php)                */
/* ------------------------------------------------------------------ */

function rlEnabled(): bool {
  return defined('RATE_LIMIT_ENABLED') ? (bool)constant('RATE_LIMIT_ENABLED') : true;
}
function rlMaxAttempts(): int {
  $v = defined('RATE_LIMIT_MAX_ATTEMPTS') ? (int)constant('RATE_LIMIT_MAX_ATTEMPTS') : 5;
  return $v > 0 ? $v : 5;
}
function rlWindowSeconds(): int {
  $v = defined('RATE_LIMIT_WINDOW_SECONDS') ? (int)constant('RATE_LIMIT_WINDOW_SECONDS') : 600;
  return $v >= 10 ? $v : 600;
}
function rlHoneypotField(): string {
  return defined('RATE_LIMIT_HONEYPOT_FIELD') ? trim((string)constant('RATE_LIMIT_HONEYPOT_FIELD')) : 'website';
}
function rlCaptchaEnabled(): bool {
  return defined('RATE_LIMIT_CAPTCHA_ENABLED') ? (bool)constant('RATE_LIMIT_CAPTCHA_ENABLED') : true;
}
function rlCaptchaGrantAttempts(): int {
  $v = defined('RATE_LIMIT_CAPTCHA_GRANT_ATTEMPTS') ? (int)constant('RATE_LIMIT_CAPTCHA_GRANT_ATTEMPTS') : 5;
  return $v > 0 ? $v : 5;
}
function rlCaptchaIssueLimit(): int {
  $v = defined('RATE_LIMIT_CAPTCHA_ISSUE_LIMIT') ? (int)constant('RATE_LIMIT_CAPTCHA_ISSUE_LIMIT') : 10;
  return $v > 0 ? $v : 10;
}
function rlCaptchaTtl(): int {
  $v = defined('RATE_LIMIT_CAPTCHA_TTL') ? (int)constant('RATE_LIMIT_CAPTCHA_TTL') : 300;
  return $v >= 30 ? $v : 300;
}
function rlCaptchaMaxAttempts(): int {
  $v = defined('RATE_LIMIT_CAPTCHA_MAX_ATTEMPTS') ? (int)constant('RATE_LIMIT_CAPTCHA_MAX_ATTEMPTS') : 4;
  return $v > 0 ? $v : 4;
}
function rlGcProbability(): int {
  return defined('RATE_LIMIT_GC_PROBABILITY') ? (int)constant('RATE_LIMIT_GC_PROBABILITY') : 10;
}

/* ------------------------------------------------------------------ */
/* Client IP resolution                                                */
/* ------------------------------------------------------------------ */

function rlTrustedProxies(): array {
  $list = defined('RATE_LIMIT_TRUSTED_PROXY_IPS') ? (array)constant('RATE_LIMIT_TRUSTED_PROXY_IPS') : [];
  $out = [];
  foreach ($list as $ip) { $ip = trim((string)$ip); if ($ip !== '') $out[] = $ip; }
  return $out;
}

/* Normalise an address for use as a counter key. IPv6 is reduced to
 * its /64 network prefix (privacy extensions / SLAAC rotate the host
 * part constantly, which would otherwise hand out a fresh bucket per
 * request). Anything that is not a valid IP is rejected. */
function rlNormaliseIp(string $ip): string {
  $ip = trim($ip);
  if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) return '';
  if (strpos($ip, ':') === false) return $ip;
  $packed = @inet_pton($ip);
  if ($packed === false || strlen($packed) !== 16) return $ip;
  /* inet_ntop() only accepts 4 or 16 bytes, so the /64 prefix is padded
   * with a zero host part before it is formatted. */
  $network = @inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));
  return is_string($network) && $network !== '' ? $network.'/64' : $ip;
}

/* The address the limiter counts against, or '' when the server did
 * not provide a usable one (the caller then fails open). */
function rlClientIp(): string {
  $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
  $proxies = rlTrustedProxies();
  if ($remote !== '' && $proxies !== [] && in_array($remote, $proxies, true)) {
    foreach (explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $candidate) {
      $normalised = rlNormaliseIp($candidate);
      if ($normalised !== '') return $normalised;
    }
    $normalised = rlNormaliseIp((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($normalised !== '') return $normalised;
  }
  return rlNormaliseIp($remote);
}

/* Bucket name for an action — public endpoints are namespaced so they
 * can never collide with a staff-side bucket. */
function rlBucket(string $action): string {
  return 'public:'.preg_replace('/[^A-Za-z0-9_-]/', '', $action);
}

/* ------------------------------------------------------------------ */
/* Response helpers                                                    */
/* ------------------------------------------------------------------ */

/* Emit a JSON response and stop. Kept local (instead of reusing
 * api.php's response()) so this file stays usable on its own. */
function rlRespond(int $status, array $payload, array $headers = []): void {
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $name => $value) header($name.': '.$value);
  }
  http_response_code($status);
  echo json_encode($payload);
  exit;
}

/* Standard rate-limit headers so well behaved clients can back off on
 * their own. They expose the public policy only (limit, remaining
 * requests, reset time) — never anything about the server internals. */
function rlSendHeaders(int $limit, int $remaining, int $reset): void {
  if (headers_sent()) return;
  header('X-RateLimit-Limit: '.$limit);
  header('X-RateLimit-Remaining: '.max(0, $remaining));
  header('X-RateLimit-Reset: '.$reset);
}

/* "about 4 minutes" / "42 seconds" — the only hint a blocked client
 * receives about the policy. */
function rlHumanWait(int $seconds): string {
  if ($seconds >= 60) {
    $m = (int)ceil($seconds / 60);
    return 'about '.$m.' minute'.($m === 1 ? '' : 's');
  }
  return 'about '.$seconds.' second'.($seconds === 1 ? '' : 's');
}

/* Audit trail for blocked / rejected public traffic. auditLog() never
 * throws, and the call is skipped when audit.php is not loaded (unit
 * tests). */
function rlAudit(string $action, array $detail): void {
  if (!function_exists('auditLog')) return;
  auditLog($action, 'public_endpoint', null, $detail, null, null);
}

/* ------------------------------------------------------------------ */
/* Storage (MySQL)                                                     */
/* ------------------------------------------------------------------ */

/* Mirrors database/migrations/2026_09_28_create_rate_limits.sql so the
 * limiter also works on a database where the migration was not
 * imported yet. */
function rlSchema(): array {
  return [
    "CREATE TABLE IF NOT EXISTS rate_limit_counters (
       bucket VARCHAR(64) NOT NULL,
       ip_address VARCHAR(45) NOT NULL,
       window_start DATETIME NOT NULL,
       hits INT UNSIGNED NOT NULL DEFAULT 0,
       updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (bucket, ip_address, window_start),
       KEY idx_rl_counters_window (window_start)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS rate_limit_grants (
       id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
       bucket VARCHAR(64) NOT NULL,
       ip_address VARCHAR(45) NOT NULL,
       window_start DATETIME NOT NULL,
       extra_attempts INT UNSIGNED NOT NULL DEFAULT 0,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       UNIQUE KEY uq_rl_grant (bucket, ip_address, window_start),
       KEY idx_rl_grants_window (window_start)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS rate_limit_captchas (
       captcha_id CHAR(26) NOT NULL PRIMARY KEY,
       answer_hash CHAR(64) NOT NULL,
       ip_address VARCHAR(45) NOT NULL,
       bucket VARCHAR(64) NOT NULL DEFAULT '',
       attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
       used_at DATETIME NULL,
       expires_at DATETIME NOT NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       KEY idx_rl_captcha_expiry (expires_at),
       KEY idx_rl_captcha_ip (ip_address)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  ];
}

/* Create the tables on first use (at most once per request). */
function rlEnsureTables(): bool {
  static $ready = null;
  if ($ready !== null) return $ready;
  try {
    $pdo = db();
    foreach (rlSchema() as $sql) $pdo->exec($sql);
    $ready = true;
  } catch (Throwable $e) {
    $ready = false;
    error_log('[rate_limit] counter storage unavailable: '.$e->getMessage());
  }
  return $ready;
}

/* Fixed window boundaries: floor(now / window) * window. */
function rlWindowStart(?int $now = null, ?int $window = null): int {
  $now = $now ?? time();
  $window = $window ?? rlWindowSeconds();
  return (int)(floor($now / $window) * $window);
}
/* Window keys and expiries are always stored in UTC: the boundaries
 * come from time() (timezone independent) and gmdate() keeps the stored
 * strings stable even if the PHP timezone or its DST offset changes. */
function rlDbTime(int $ts): string { return gmdate('Y-m-d H:i:s', $ts); }

/* Extra attempts already granted to this IP for this window. */
function rlGrantLookup(string $bucket, string $ip, int $window, ?int $windowStart = null): int {
  $ws = $windowStart ?? rlWindowStart(null, $window);
  $st = db()->prepare('SELECT extra_attempts FROM rate_limit_grants WHERE bucket=? AND ip_address=? AND window_start=? LIMIT 1');
  $st->execute([$bucket, $ip, rlDbTime($ws)]);
  $row = $st->fetch();
  return $row ? (int)$row['extra_attempts'] : 0;
}

/* Grant the human-verified extension. Returns false when this window
 * already has one (the UNIQUE key turns the second attempt into a
 * no-op), so solving captchas can never lift the limit repeatedly. */
function rlGrantExtra(string $bucket, int $extra): bool {
  $ip = rlClientIp();
  if ($ip === '' || $extra <= 0) return false;
  $st = db()->prepare('INSERT IGNORE INTO rate_limit_grants(bucket,ip_address,window_start,extra_attempts) VALUES(?,?,?,?)');
  $st->execute([$bucket, $ip, rlDbTime(rlWindowStart()), $extra]);
  return $st->rowCount() === 1;
}

/* Housekeeping: drop rows that can no longer influence a decision.
 * Runs on roughly 1 in RATE_LIMIT_GC_PROBABILITY guarded requests. */
function rlGc(): void {
  $p = rlGcProbability();
  if ($p <= 0) return;
  try {
    if (random_int(1, 100) > $p) return;
    $window = rlWindowSeconds();
    $db = db();
    $db->prepare('DELETE FROM rate_limit_counters WHERE window_start < ?')->execute([rlDbTime(time() - 4 * $window)]);
    $db->prepare('DELETE FROM rate_limit_grants WHERE window_start < ?')->execute([rlDbTime(time() - 4 * $window)]);
    $db->prepare('DELETE FROM rate_limit_captchas WHERE expires_at < ?')->execute([rlDbTime(time() - 600)]);
  } catch (Throwable $e) {
    /* cleanup is best effort only */
  }
}

/* Give one attempt back when a blocked request carried a verification
 * attempt that did not work out (mistyped code, expired challenge,
 * replay).  A student must not lose the allowance they just earned
 * because of a typo.  Only reachable for requests that are ALREADY
 * over the limit, so it can never be used to get free quota. */
function rlRefundAttempt(string $bucket): void {
  $ip = rlClientIp();
  if ($ip === '') return;
  try {
    db()->prepare('UPDATE rate_limit_counters SET hits=GREATEST(hits-1,0) WHERE bucket=? AND ip_address=? AND window_start=?')
        ->execute([$bucket, $ip, rlDbTime(rlWindowStart())]);
  } catch (Throwable $e) {
    /* best effort */
  }
}

/* Consume one attempt for (bucket, IP) and report the new state.
 *
 * The counter is bumped with one atomic statement; LAST_INSERT_ID() is
 * used on both branches so PDO::lastInsertId() returns the new hit
 * count whether the row was inserted (1) or updated (previous+1).
 *
 * Returned state:
 *   ok               true when the request may proceed
 *   hits             attempts used in the current window
 *   limit            base limit for the bucket
 *   grant            extra attempts unlocked by a solved captcha
 *   effective_limit  limit + grant
 *   retry_after      seconds until the current window ends
 *   reset            unix timestamp of the end of the window
 *   error            true when the store failed (fail open)
 */
function rlConsume(string $bucket, ?int $max = null, ?int $window = null): array {
  $max = $max ?? rlMaxAttempts();
  $window = $window ?? rlWindowSeconds();
  $ws = rlWindowStart(null, $window);
  $reset = $ws + $window;
  $out = ['ok'=>true,'hits'=>0,'limit'=>$max,'grant'=>0,'effective_limit'=>$max,
          'retry_after'=>max(1, $reset - time()),'reset'=>$reset,'window_start'=>$ws,'error'=>false];
  $ip = rlClientIp();
  if ($ip === '') { $out['error'] = true; return $out; }
  if (!rlEnsureTables()) { $out['error'] = true; return $out; }
  try {
    $pdo = db();
    $pdo->prepare('INSERT INTO rate_limit_counters(bucket,ip_address,window_start,hits) VALUES(?,?,?,LAST_INSERT_ID(1))
                   ON DUPLICATE KEY UPDATE hits=LAST_INSERT_ID(hits+1)')
        ->execute([$bucket, $ip, rlDbTime($ws)]);
    $hits = (int)$pdo->lastInsertId();
    if ($hits < 1) { /* driver without a usable LAST_INSERT_ID */
      $st = $pdo->prepare('SELECT hits FROM rate_limit_counters WHERE bucket=? AND ip_address=? AND window_start=? LIMIT 1');
      $st->execute([$bucket, $ip, rlDbTime($ws)]);
      $hits = (int)$st->fetchColumn();
    }
    $grant = rlGrantLookup($bucket, $ip, $window, $ws);
    $out['hits'] = $hits;
    $out['grant'] = $grant;
    $out['effective_limit'] = $max + $grant;
    $out['ok'] = $hits <= $max + $grant;
    rlGc();
  } catch (Throwable $e) {
    error_log('[rate_limit] consume failed for '.$bucket.': '.$e->getMessage());
    $out['error'] = true;
  }
  return $out;
}

/* ------------------------------------------------------------------ */
/* Human verification: self-hosted captcha                             */
/* ------------------------------------------------------------------ */

/* HMAC secret for captcha answers. Uses RATE_LIMIT_SECRET when set,
 * otherwise a random secret generated once and kept in the existing
 * system_settings table (reused infra, no new configuration file). */
function rlSecret(): string {
  static $secret = null;
  if ($secret !== null) return $secret;
  $configured = defined('RATE_LIMIT_SECRET') ? trim((string)constant('RATE_LIMIT_SECRET')) : '';
  if ($configured !== '') { $secret = $configured; return $secret; }
  try {
    $pdo = db();
    $read = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='rate_limit_secret' LIMIT 1");
    $read->execute();
    $row = $read->fetch();
    if ($row && trim((string)$row['setting_value']) !== '') { $secret = trim((string)$row['setting_value']); return $secret; }
    $candidate = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT IGNORE INTO system_settings(setting_key,setting_value) VALUES('rate_limit_secret',?)")->execute([$candidate]);
    $read->execute();
    $row = $read->fetch();
    $secret = ($row && trim((string)$row['setting_value']) !== '') ? trim((string)$row['setting_value']) : $candidate;
  } catch (Throwable $e) {
    /* settings table unavailable: per-process secret (challenges simply
     * stop working across a restart) */
    $secret = bin2hex(random_bytes(32));
  }
  return $secret;
}

/* Ambiguous characters (0/O, 1/I/L) are left out; 32^5 combinations
 * keep brute force hopeless even with 4 wrong answers allowed. */
const RL_CAPTCHA_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

function rlCaptchaCode(): string {
  $alphabet = RL_CAPTCHA_ALPHABET;
  $max = strlen($alphabet) - 1;
  $code = '';
  for ($i = 0; $i < 5; $i++) $code .= $alphabet[random_int(0, $max)];
  return $code;
}
function rlCaptchaId(): string {
  $max = strlen(RL_CAPTCHA_ALPHABET) - 1;
  $id = '';
  for ($i = 0; $i < 26; $i++) $id .= RL_CAPTCHA_ALPHABET[random_int(0, $max)];
  return $id;
}
function rlCaptchaHash(string $code): string {
  return hash_hmac('sha256', strtoupper(trim($code)), rlSecret());
}

/* Render the challenge as a self-contained SVG: no GD extension, no
 * external service, nothing fetched by the browser. */
function rlCaptchaSvg(string $code): string {
  $w = 200; $h = 70;
  $palette = ['#173f29', '#1d6b46', '#2b4a7d', '#7a3b12', '#5a2050', '#0f5f6b'];
  $out = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" role="presentation">';
  $out .= '<rect width="'.$w.'" height="'.$h.'" rx="10" fill="#f2f6f3"/>';
  for ($i = 0; $i < 90; $i++) {
    $out .= '<circle cx="'.random_int(2, $w - 2).'" cy="'.random_int(2, $h - 2).'" r="'.random_int(1, 2).'" fill="#173f29" opacity="0.12"/>';
  }
  for ($i = 0; $i < 4; $i++) {
    $out .= '<path d="M'.random_int(0, $w).' '.random_int(0, $h).' q '.intdiv($w, 2).' '.($h + 20).' '.$w.' 0" fill="none"'
         .' stroke="'.$palette[random_int(0, count($palette) - 1)].'" stroke-width="1" opacity="0.3"/>';
  }
  $chars = str_split(strtoupper($code));
  $step = intdiv($w - 44, max(1, count($chars)));
  $x = 24;
  foreach ($chars as $char) {
    $out .= '<text x="'.$x.'" y="'.random_int(40, 52).'" font-family="Segoe UI, Arial, sans-serif"'
         .' font-size="'.random_int(28, 36).'" font-weight="700"'
         .' fill="'.$palette[random_int(0, count($palette) - 1)].'"'
         .' transform="rotate('.random_int(-22, 22).' '.$x.' 45)">'.htmlspecialchars($char, ENT_QUOTES | ENT_XML1, 'UTF-8').'</text>';
    $x += $step;
  }
  $out .= '</svg>';
  return 'data:image/svg+xml;base64,'.base64_encode($out);
}

/* Issue a challenge. Issuance has its own budget so this endpoint
 * cannot be used to hammer the database. */
function rlIssueChallenge(string $bucket = 'public:studentPortal'): array {
  if (!rlCaptchaEnabled()) return ['ok'=>false,'reason'=>'disabled'];
  $ip = rlClientIp();
  if ($ip === '' || !rlEnsureTables()) return ['ok'=>false,'reason'=>'unavailable'];
  $state = rlConsume('captcha_issue', rlCaptchaIssueLimit(), rlWindowSeconds());
  if ($state['error']) return ['ok'=>false,'reason'=>'unavailable'];
  if (!$state['ok']) return ['ok'=>false,'reason'=>'limited','retry_after'=>$state['retry_after']];
  try {
    $id = rlCaptchaId();
    $code = rlCaptchaCode();
    $ttl = rlCaptchaTtl();
    db()->prepare('INSERT INTO rate_limit_captchas(captcha_id,answer_hash,ip_address,bucket,expires_at) VALUES(?,?,?,?,?)')
        ->execute([$id, rlCaptchaHash($code), $ip, $bucket, rlDbTime(time() + $ttl)]);
    return ['ok'=>true,'id'=>$id,'image'=>rlCaptchaSvg($code),'expires_in'=>$ttl];
  } catch (Throwable $e) {
    error_log('[rate_limit] captcha issue failed: '.$e->getMessage());
    return ['ok'=>false,'reason'=>'unavailable'];
  }
}

/* Verify a submitted answer. Returns 'ok' or a reason code; the reason
 * itself is never sent to the client (only a generic captcha_error). */
function rlVerifyCaptcha(string $id, string $code, string $ip): string {
  if ($id === '' || $code === '' || $ip === '' || !rlEnsureTables()) return 'invalid';
  try {
    $pdo = db();
    $st = $pdo->prepare('SELECT captcha_id,answer_hash,ip_address,attempts,used_at,expires_at FROM rate_limit_captchas WHERE captcha_id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    /* A challenge belongs to the IP that requested it, is single use,
     * time limited and answer limited. */
    if (!$row || !hash_equals((string)$row['ip_address'], $ip)) return 'unknown';
    if ($row['used_at'] !== null) return 'used';
    if (strtotime((string)$row['expires_at'].' UTC') < time()) return 'expired';
    if ((int)$row['attempts'] >= rlCaptchaMaxAttempts()) return 'locked';
    $pdo->prepare('UPDATE rate_limit_captchas SET attempts=attempts+1 WHERE captcha_id=? AND used_at IS NULL')->execute([$id]);
    if (!hash_equals((string)$row['answer_hash'], rlCaptchaHash($code))) return 'invalid';
    $pdo->prepare('UPDATE rate_limit_captchas SET used_at=UTC_TIMESTAMP() WHERE captcha_id=? AND used_at IS NULL')->execute([$id]);
    return 'ok';
  } catch (Throwable $e) {
    error_log('[rate_limit] captcha verify failed: '.$e->getMessage());
    return 'invalid';
  }
}

/* Pull a captcha solution out of a request payload (JSON or form). */
function rlCaptchaSolution(array $payload): ?array {
  $id = trim((string)($payload['captcha_id'] ?? ''));
  $code = trim((string)($payload['captcha_code'] ?? ''));
  if ($id === '' || $code === '') return null;
  return [substr($id, 0, 32), substr($code, 0, 16)];
}

/* ------------------------------------------------------------------ */
/* Public endpoint guard                                               */
/* ------------------------------------------------------------------ */

/* Honeypot check. The public forms ship a hidden field with this name
 * that a human never sees or fills, while automated form fillers fill
 * every input they find. */
function rlHoneypotTripped(array $payload): bool {
  $field = rlHoneypotField();
  return $field !== '' && trim((string)($payload[$field] ?? '')) !== '';
}

/* Main entry point — call at the very top of a public action, BEFORE
 * any validation, upload or database write.
 *
 * $payload: the decoded request body (JSON array or $_POST) so the
 * honeypot field and a captcha solution can be read from it.
 *
 * Returns normally when the request may continue; otherwise it answers
 * 422 (bot trap) or 429 (limit exceeded) and stops the script.
 */
function rlGuard(string $action, array $payload = []): void {
  if (!rlEnabled()) return;
  $bucket = rlBucket($action);

  /* 1. Bot trap — filled hidden field. */
  if (rlHoneypotTripped($payload)) {
    rlAudit('public_bot_submission', ['action'=>$action,'bucket'=>$bucket,'reason'=>'honeypot']);
    rlRespond(422, ['success'=>false,
      'message'=>'Your submission could not be processed. Please reload the page and try again.']);
  }

  /* 2. Consume one attempt from this IP's bucket for this action. */
  $state = rlConsume($bucket);
  if ($state['error']) return;   /* store unavailable: fail open, already logged */
  rlSendHeaders($state['limit'], $state['effective_limit'] - $state['hits'], $state['reset']);
  if ($state['ok']) return;

  /* 3. Over the limit. A solved captcha unlocks a few extra attempts,
   *    at most once per window — the shared-network escape hatch. */
  $solution = rlCaptchaSolution($payload);
  $captchaError = false;
  if ($solution !== null) {
    if (rlVerifyCaptcha($solution[0], $solution[1], rlClientIp()) === 'ok') {
      $extra = rlCaptchaGrantAttempts();
      if (rlGrantExtra($bucket, $extra) && $state['hits'] <= $state['limit'] + $extra) {
        rlSendHeaders($state['limit'], $state['limit'] + $extra - $state['hits'], $state['reset']);
        rlAudit('rate_limit_captcha_unlocked', ['action'=>$action,'bucket'=>$bucket,'extra_attempts'=>$extra,'hits'=>$state['hits']]);
        return;                    /* processed normally */
      }
    } else {
      $captchaError = true;        /* wrong, expired, replayed or foreign code */
    }
  }

  /* 4. Reject. Nothing was validated, stored or uploaded. */
  if ($solution !== null) rlRefundAttempt($bucket);   /* a failed code check costs nothing */
  $seconds = max(1, (int)$state['retry_after']);
  rlAudit('rate_limited', ['action'=>$action,'bucket'=>$bucket,'limit'=>$state['limit'],
                           'hits'=>$state['hits'],'window'=>rlWindowSeconds()]);
  $out = [
    'success'=>false,
    'message'=>'Too many submissions from your network. To keep the ID system safe, only '
      .$state['limit'].' '.($state['limit'] === 1 ? 'request is' : 'requests are').' allowed every '
      .(int)(rlWindowSeconds() / 60).' minutes. Your submission was not processed — please try again in '
      .rlHumanWait($seconds).'.',
    'retry_after'=>$seconds,
    'retry_after_minutes'=>(int)ceil($seconds / 60),
  ];
  if (rlCaptchaEnabled() && $state['grant'] === 0) $out['captcha_required'] = true;
  if ($captchaError) $out['captcha_error'] = true;
  rlRespond(429, $out, ['Retry-After'=>(string)$seconds]);
}
