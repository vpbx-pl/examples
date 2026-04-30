<?php
/**
 * SMS Proxy - prosty most HTTP GET -> callapi.pl
 *
 * Wywołanie:
 *   GET /sms.php?to=48123456789&text=Hello&from=MyApp
 *
 * Numer w formacie E.164 BEZ wiodącego "+", np. 48123456789.
 *
 * Odpowiedź:
 *   "OK <sms_id>"         - SMS przyjęty przez callapi.pl
 *   "Error: <opis>"       - cokolwiek innego
 *
 * Skrypt loguje się do callapi.pl (POST /api/v1/login) używając
 * username+password z config.php i od razu wysyła SMS przez /api/v1/sms.
 * Skrypt jest przeznaczony do uruchamiania lokalnie - dostęp ograniczaj
 * przez whitelistę IP (allowed_ips) w config.php.
 */

declare(strict_types=1);

const CALLAPI_BASE = 'https://api.vpbx.pl/api/v1';

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function ok(string $smsId): void {
    http_response_code(200);
    echo "OK $smsId\n";
    exit;
}

function fail(int $status, string $description): void {
    http_response_code($status);
    echo "Error: $description\n";
    exit;
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    fail(500, 'config.php missing');
}
$config = require $configPath;

function callapiLogin(string $username, string $password): string {
    $ch = curl_init(CALLAPI_BASE . '/login');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['username' => $username, 'password' => $password]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('login curl error: ' . $err);
    }
    $decoded = json_decode((string)$resp, true);
    if (!is_array($decoded) || ($decoded['result'] ?? null) !== 'OK' || empty($decoded['token'])) {
        throw new RuntimeException("login failed (http $code)");
    }
    return (string)$decoded['token'];
}

function postSms(string $token, array $payload): array {
    $ch = curl_init(CALLAPI_BASE . '/sms');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('sms curl error: ' . $err);
    }
    $decoded = json_decode((string)$resp, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("sms invalid json (http $code)");
    }
    return [$code, $decoded];
}

// ---------------------------------------------------------------------------

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

// 1. Whitelista IP - wpisy obsługują wildcards: '192.168.1.*', '10.*.*.*'
if (!empty($config['allowed_ips'])) {
    $allowed = false;
    foreach ($config['allowed_ips'] as $pattern) {
        if (fnmatch((string)$pattern, $clientIp)) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        fail(403, 'forbidden');
    }
}

// 2. Walidacja parametrów
$to   = trim((string)($_GET['to']   ?? ''));
$text = (string)($_GET['text'] ?? '');
$from = trim((string)($_GET['from'] ?? ($config['default_from'] ?? '')));

if ($to === '' || $text === '' || $from === '') {
    fail(400, 'missing parameters: to, text, from');
}

// Numer odbiorcy: same cyfry, 6-15 znaków (E.164 bez wiodącego "+")
if (!preg_match('/^[0-9]{6,15}$/', $to)) {
    fail(400, 'invalid recipient number (E.164 digits only, no +)');
}

// Nadawca jest weryfikowany przez API callapi.pl - nie duplikujemy walidacji.

// Limit długości wiadomości
$maxLen = (int)($config['max_text_length'] ?? 700);
if (mb_strlen($text, 'UTF-8') > $maxLen) {
    fail(400, "text exceeds $maxLen chars");
}

// 3. Wysyłka
$username = (string)($config['callapi_username'] ?? '');
$password = (string)($config['callapi_password'] ?? '');
if ($username === '' || $password === '') {
    fail(500, 'callapi credentials not configured');
}

$payload = [
    'from'           => $from,
    'to'             => $to,
    'text'           => $text,
    'webhook'        => $config['webhook'] ?? 'https://example.invalid/none',
    'webhook_method' => 'POST',
];

try {
    $token = callapiLogin($username, $password);
    [$httpCode, $body] = postSms($token, $payload);
} catch (Throwable $e) {
    fail(502, $e->getMessage());
}

if ($httpCode >= 200 && $httpCode < 300 && ($body['result'] ?? null) === 'OK') {
    ok((string)($body['sms_id'] ?? ''));
}

$desc = (string)($body['error'] ?? "callapi http $httpCode");
fail(502, $desc);
