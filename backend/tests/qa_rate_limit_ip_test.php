<?php
/*
 * QA RATE LIMIT — CLIENT IP RESOLUTION (offline unit test)
 * ---------------------------------------------------------
 * The limiter counts per client address, so the resolution rules are
 * security critical and are therefore tested on their own (no server
 * or database needed):
 *
 *   - X-Forwarded-For is IGNORED unless the request came from a proxy
 *     listed in RATE_LIMIT_TRUSTED_PROXY_IPS, otherwise anyone could
 *     forge the header and get a fresh bucket on every request
 *   - the first valid entry of the chain wins and invalid values are
 *     skipped
 *   - IPv6 is reduced to its /64 prefix so rotating host addresses
 *     (privacy extensions, SLAAC) cannot reset a counter
 *   - garbage is rejected instead of being used as a key
 *
 * Usage:  php qa_rate_limit_ip_test.php
 */

require_once __DIR__ . '/../rate_limit.php';

$results = [];
function R(string $id, string $case, string $expected, string $actual, bool $pass): void {
    global $results;
    $results[] = [$id, $case, $expected, $actual, $pass ? 'PASS' : 'FAIL'];
}

$cases = [
    ['IP-001', 'Plain IPv4 request is used as-is',
     ['REMOTE_ADDR' => '203.0.113.10'], [], '203.0.113.10'],
    ['IP-002', 'IPv6 request is reduced to its /64 network',
     ['REMOTE_ADDR' => '2001:db8:1:2:3:4:5:6'], [], '2001:db8:1:2::/64'],
    ['IP-003', 'Two IPv6 hosts of the same /64 share one bucket',
     ['REMOTE_ADDR' => '2001:db8:1:2:aaaa:bbbb:cccc:dddd'], [], '2001:db8:1:2::/64'],
    ['IP-004', 'Different /64 networks stay separate',
     ['REMOTE_ADDR' => '2001:db8:1:3::99'], [], '2001:db8:1:3::/64'],
    ['IP-005', 'Forged X-Forwarded-For is ignored (no trusted proxy)',
     ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], [], '203.0.113.10'],
    ['IP-006', 'Forged X-Real-IP is ignored (no trusted proxy)',
     ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_REAL_IP' => '1.2.3.4'], [], '203.0.113.10'],
    ['IP-007', 'X-Forwarded-For is honoured behind a trusted proxy',
     ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.1'], ['127.0.0.1'], '198.51.100.7'],
    ['IP-008', 'Invalid entries in the chain are skipped',
     ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip, 198.51.100.9'], ['127.0.0.1'], '198.51.100.9'],
    ['IP-009', 'A trusted proxy without the header falls back to the peer',
     ['REMOTE_ADDR' => '10.1.1.1'], ['10.1.1.1'], '10.1.1.1'],
    ['IP-010', 'A chain with nothing usable falls back to the proxy (never fails open)',
     ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'garbage'], ['127.0.0.1'], '127.0.0.1'],
    ['IP-011', 'A missing/garbage REMOTE_ADDR is rejected (fails open)',
     ['REMOTE_ADDR' => 'localhost'], [], ''],
    ['IP-012', 'IPv4-mapped IPv6 is treated as IPv6',
     ['REMOTE_ADDR' => '::ffff:203.0.113.11'], [], '::/64'],
];

/* Execute every case in a clean process: the trusted-proxy list is a
 * configuration constant, so it cannot be changed at runtime. The case
 * is handed over as base64 JSON through argv to stay safe from Windows
 * command-line quoting. */
$runner = tempnam(sys_get_temp_dir(), 'rlip') . '.php';
/* The constant has to be defined BEFORE config.php is loaded, because
 * config.php only defines it when it is not set yet. */
file_put_contents($runner, "<?php\n"
    . "\$case = json_decode(base64_decode(\$argv[1]), true);\n"
    . "if (!empty(\$case['proxies'])) define('RATE_LIMIT_TRUSTED_PROXY_IPS', \$case['proxies']);\n"
    . 'require ' . var_export(__DIR__ . '/../rate_limit.php', true) . ";\n"
    . "\$_SERVER = \$case['server'];\n"
    . 'echo rlClientIp();' . "\n");

foreach ($cases as [$id, $case, $server, $proxies, $expected]) {
    $payload = base64_encode(json_encode(['server' => $server, 'proxies' => $proxies]));
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($payload) . ' 2>&1', $out);
    $actual = trim(implode('', $out));
    R($id, $case, $expected === '' ? '(rejected)' : $expected, $actual === '' ? '(rejected)' : $actual, $actual === $expected);
}
@unlink($runner);

echo "\n============ QA RATE LIMIT — CLIENT IP RESOLUTION ============\n";
echo sprintf("%-8s %-56s %-24s %-24s %s\n", 'ID', 'TEST CASE', 'EXPECTED', 'ACTUAL', 'STATUS');
echo str_repeat('-', 130) . "\n";
foreach ($results as [$id, $case, $expected, $actual, $status]) {
    echo sprintf("%-8s %-56s %-24s %-24s %s\n", $id, mb_strimwidth($case, 0, 56), $expected, $actual, $status);
}
echo str_repeat('-', 130) . "\n";
$pass = count(array_filter($results, fn($r) => $r[4] === 'PASS'));
echo 'TOTAL: ' . count($results) . "  PASS: $pass  FAIL: " . (count($results) - $pass) . "\n";
