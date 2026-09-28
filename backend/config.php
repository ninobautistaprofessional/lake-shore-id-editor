<?php
// Change these values for your XAMPP/production MySQL server.
define('DB_HOST', 'localhost');
define('DB_NAME', 'lake_shore_id_system');
define('DB_USER', 'root');
define('DB_PASS', '');

/*
|--------------------------------------------------------------------------
| Rate limiting (public portal / unauthenticated endpoints)
|--------------------------------------------------------------------------
| Protect the public self-service endpoints — studentSaveCard,
| studentUploadPhoto and lostIdRequest — from spam, abuse and
| automated submissions.  See backend/rate_limit.php for details.
|
| Default policy: 5 requests / 10 minutes / IP address.  Every
| additional request is rejected with HTTP 429 before it is
| processed.  Authenticated (staff) endpoints are NOT throttled.
|
| Shared networks: the whole school can sit behind a single public
| IP, so a blocked client may solve a self-hosted captcha once per
| window to unlock a few extra attempts.  Raise MAX_ATTEMPTS above
| if the portal is used from a large NAT / free Wi-Fi network.
*/
if (!defined('RATE_LIMIT_ENABLED')) define('RATE_LIMIT_ENABLED', true);
/* Requests allowed per window, per IP, per action. */
if (!defined('RATE_LIMIT_MAX_ATTEMPTS')) define('RATE_LIMIT_MAX_ATTEMPTS', 5);
/* Window length in seconds (600 = 10 minutes). */
if (!defined('RATE_LIMIT_WINDOW_SECONDS')) define('RATE_LIMIT_WINDOW_SECONDS', 600);

/* Bot trap: public forms carry a hidden field with this name.  A
 * non-empty value marks an automated submission.  Set to '' to
 * disable the honeypot (e.g. if a browser extension autofills it). */
if (!defined('RATE_LIMIT_HONEYPOT_FIELD')) define('RATE_LIMIT_HONEYPOT_FIELD', 'website');

/* Human verification for blocked / suspicious traffic. */
if (!defined('RATE_LIMIT_CAPTCHA_ENABLED')) define('RATE_LIMIT_CAPTCHA_ENABLED', true);
/* Extra attempts granted once per window after a solved captcha. */
if (!defined('RATE_LIMIT_CAPTCHA_GRANT_ATTEMPTS')) define('RATE_LIMIT_CAPTCHA_GRANT_ATTEMPTS', 5);
/* How many captcha challenges one IP may request per window. */
if (!defined('RATE_LIMIT_CAPTCHA_ISSUE_LIMIT')) define('RATE_LIMIT_CAPTCHA_ISSUE_LIMIT', 10);
/* Challenge lifetime and maximum wrong answers per challenge. */
if (!defined('RATE_LIMIT_CAPTCHA_TTL')) define('RATE_LIMIT_CAPTCHA_TTL', 300);
if (!defined('RATE_LIMIT_CAPTCHA_MAX_ATTEMPTS')) define('RATE_LIMIT_CAPTCHA_MAX_ATTEMPTS', 4);
/* HMAC secret for captcha answers. Leave '' to use a random secret
 * that is generated once and stored in system_settings. */
if (!defined('RATE_LIMIT_SECRET')) define('RATE_LIMIT_SECRET', '');

/* Only trust X-Forwarded-For / X-Real-IP when the request comes from
 * one of these proxy IPs. Leave empty (the default) when Apache/PHP
 * receives the real client address directly — trusting the header
 * unconditionally would let anyone bypass the limiter. */
if (!defined('RATE_LIMIT_TRUSTED_PROXY_IPS')) define('RATE_LIMIT_TRUSTED_PROXY_IPS', []);

/* 1-in-N requests also remove expired rows (1 = always). */
if (!defined('RATE_LIMIT_GC_PROBABILITY')) define('RATE_LIMIT_GC_PROBABILITY', 10);

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    }
    return $pdo;
}
