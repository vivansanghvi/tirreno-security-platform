<?php

/**
 * Demo data seeder for a local tirreno instance (run inside the app container):
 *   docker compose -p tirreno-demo exec -T -u www-data tirreno-app php docker/seed-demo.php
 *
 * 1. Creates the operator account if none exists (through the normal /signup form).
 * 2. Sends demo events to the sensor (/sensor/) with the operator's API key.
 * 3. Runs the cron job once so users get scored by the rules.
 *
 * Login defaults: demo@example.com / DemoPassw0rd! (override with DEMO_EMAIL / DEMO_PASSWORD).
 */

declare(strict_types=1);

const BASE     = 'http://127.0.0.1';
const PRESET   = 'account_takeover';
const UA_CHROME  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const UA_FIREFOX = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0';
const UA_SAFARI  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
const UA_SCANNER = 'sqlmap/1.7.2#stable (https://sqlmap.org)';

$email    = getenv('DEMO_EMAIL') ?: 'demo@example.com';
$password = getenv('DEMO_PASSWORD') ?: 'DemoPassw0rd!';
$host     = getenv('SITE') ?: 'localhost';

// Apache starts only after the entrypoint has installed the schema, so wait for it first.
$http = curl_init(BASE . '/signup');
curl_setopt_array($http, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE     => '',                // in-memory cookie jar keeps the CSRF session
    CURLOPT_HTTPHEADER     => ['Host: ' . $host],
]);
for ($try = 0; $try < 60 && !($html = (string) curl_exec($http)); ++$try) {
    sleep(1);
}

$pdo = connect((string) getenv('DATABASE_URL'));

// 1. Operator account
if ((int) $pdo->query('SELECT COUNT(*) FROM dshb_api')->fetchColumn() === 0) { // the first signup creates the API key
    if (!preg_match('/name="token" value="([^"]+)"/', $html, $m)) {
        fail('could not read the CSRF token from /signup');
    }
    curl_setopt_array($http, [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'token' => $m[1], 'email' => $email, 'password' => $password,
            'timezone' => 'UTC', 'rules-preset' => PRESET,
        ]),
    ]);
    curl_exec($http);
    echo "Operator created: $email\n";
} else {
    echo "Operator already exists, skipping signup\n";
}
curl_close($http);

$apiKey = $pdo->query('SELECT key FROM dshb_api ORDER BY id LIMIT 1')->fetchColumn();
if (!$apiKey) {
    fail('no API key found; signup failed (check assets/logs/error.log)');
}

// 2. Events (only once, so re-running the script does not duplicate data)
if ((int) $pdo->query('SELECT COUNT(*) FROM event')->fetchColumn() > 0) {
    echo "Events already present, skipping (reset with: docker compose -p tirreno-demo down -v)\n";
    exit(0);
}
$events = buildEvents();
$sensor = curl_init(BASE . '/sensor/');
curl_setopt_array($sensor, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Api-Key: ' . $apiKey, 'Host: ' . $host],
]);
$accepted = 0;
foreach ($events as $event) {
    curl_setopt($sensor, CURLOPT_POSTFIELDS, http_build_query($event)); // form-urlencoded, not JSON
    curl_exec($sensor);
    if (curl_getinfo($sensor, CURLINFO_HTTP_CODE) === 204) {
        ++$accepted;
    }
}
curl_close($sensor);
printf("Events accepted: %d of %d\n", $accepted, count($events));

// 3. Scoring
passthru('php ' . escapeshellarg(dirname(__DIR__) . '/index.php') . ' /cron > /dev/null', $code);
echo $code === 0 ? "Cron finished, users scored\n" : "Cron exited with code $code\n";
printf("Open http://%s and log in as %s / %s\n", $host, $email, $password);

function connect(string $url): PDO {
    $p = parse_url($url);
    if (!is_array($p) || !isset($p['host'], $p['user'], $p['pass'], $p['path'])) {
        fail('DATABASE_URL is missing or invalid');
    }
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'], $p['port'] ?? 5432, ltrim($p['path'], '/'));

    return new PDO($dsn, $p['user'], $p['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function fail(string $message): never {
    fwrite(STDERR, "seed-demo: $message\n");
    exit(1);
}

/** One sensor event; $time is a strtotime() expression relative to now (UTC). */
function event(string $user, string $ip, string $type, string $url, string $time, string $ua = UA_CHROME, array $extra = []): array {
    return $extra + [
        'userName'        => $user,
        'emailAddress'    => $user . '@example.com',
        'ipAddress'       => $ip,
        'eventType'       => $type,
        'url'             => $url,
        'eventTime'       => gmdate('Y-m-d H:i:s', strtotime($time)) . '.000',
        'userAgent'       => $ua,
        'httpMethod'      => $type === 'page_view' ? 'GET' : 'POST',
        'httpCode'        => '200',
        'browserLanguage' => 'en-US',
    ];
}

function buildEvents(): array {
    $e = [];

    // Normal customers: register, log in, browse, log out over the last week.
    $normal = [
        'alice' => ['198.51.100.11', UA_CHROME],
        'bob'   => ['198.51.100.12', UA_FIREFOX],
        'carol' => ['198.51.100.13', UA_SAFARI],
        'david' => ['198.51.100.14', UA_CHROME],
        'emma'  => ['198.51.100.15', UA_SAFARI],
    ];
    foreach ($normal as $user => [$ip, $ua]) {
        $e[] = event($user, $ip, 'account_registration', '/signup', '-7 days 10:00', $ua, ['firstName' => ucfirst($user), 'lastName' => 'Demo']);
        foreach (['-5 days 11:00', '-2 days 15:00', 'yesterday 17:00'] as $t) {
            $e[] = event($user, $ip, 'account_login', '/login', $t, $ua);
            foreach (['/dashboard', '/products', '/cart'] as $i => $url) {
                $e[] = event($user, $ip, 'page_view', $url, "$t +" . ($i + 1) . ' minutes', $ua);
            }
            $e[] = event($user, $ip, 'account_logout', '/logout', "$t +5 minutes", $ua);
        }
    }

    // Two accounts sharing one IP (rule I04 "Shared IP").
    foreach (['frank', 'grace'] as $user) {
        $e[] = event($user, '203.0.113.50', 'account_login', '/login', '-1 day 12:00', UA_FIREFOX);
        $e[] = event($user, '203.0.113.50', 'page_view', '/promo', '-1 day 12:02', UA_FIREFOX);
    }

    // Account takeover on "mallory": failed logins, night activity, 12 IPs,
    // several devices in one session, language switch, password change, errors.
    for ($i = 1; $i <= 12; ++$i) {
        $e[] = event('mallory', "192.0.2.$i", 'account_login_fail', '/login', sprintf('yesterday 02:%02d', $i), UA_FIREFOX, ['httpCode' => '401']);
    }
    $e[] = event('mallory', '192.0.2.99', 'account_login', '/login', 'yesterday 02:20', UA_SAFARI, ['browserLanguage' => 'ru-RU']);
    $e[] = event('mallory', '192.0.2.99', 'page_view', '/account', 'yesterday 02:21', UA_CHROME, ['browserLanguage' => 'ru-RU']);
    $e[] = event('mallory', '192.0.2.99', 'account_password_change', '/account/password', 'yesterday 02:22', UA_CHROME, ['browserLanguage' => 'ru-RU']);
    for ($i = 0; $i < 5; ++$i) {
        $e[] = event('mallory', '192.0.2.99', 'page_view', '/api/orders', sprintf('yesterday 02:%02d', 23 + $i), UA_CHROME, ['httpCode' => '500']);
    }

    // Vulnerability scanner: suspicious URLs and user agent, many 404s.
    foreach (['/wp-admin', '/.env', '/phpmyadmin', '/wp-content/plugins', '/.git/config', '/admin'] as $i => $url) {
        $e[] = event('scanner_bot', '203.0.113.200', 'page_view', $url, sprintf('-30 minutes +%d seconds', $i * 10), UA_SCANNER, ['httpCode' => '404']);
    }

    return $e;
}
