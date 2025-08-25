<?php

/**
* Test API Interaction — Single-file PHP implementation
*
* Requirements covered:
* - POST JSON to https://test.icorp.uz/private/interview.php with fields `msg` and `uri`
* - Receive first code part (and possibly a next URI)
* - GET the designated URI to retrieve the second code part
* - Concatenate both parts and POST back to the same endpoint with field `code`
* - Print final message to console or web output
*
* Usage (CLI):
* php test_api_task.php \
* --msg="Hello from candidate" \
* --uri="/private/some/path" \
* [--endpoint="https://test.icorp.uz/private/interview.php"] \
* [--timeout=15] [--verbose]
*
* Usage (Web):
* Place this file under a web server (PHP 7.4+). Provide query params:
* ?msg=Hello&uri=/private/some/path&endpoint=...&verbose=1
*
* Notes:
* - If the first response returns a `uri`/`next` field, it overrides the provided --uri.
* - Second response can be JSON (fields like `part2`/`code`) or plain text; both are handled.
*
* Author: <your-name>
* License: MIT
*/


declare(strict_types=1);


// ------------------------------
// Small utility: detect CLI vs Web
// ------------------------------

$isCli = (PHP_SAPI === 'cli');


function readInput(array $argv, bool $isCli): array {
    $defaults = [
    'endpoint' => 'https://test.icorp.uz/private/interview.php',
    'msg' => 'hello-from-client',
    'uri' => '/private/next', // placeholder; will be overridden if API provides a URI
    'timeout' => 15,
    'verbose' => false,
    ];


if ($isCli) {
    $opts = getopt('', ['endpoint::', 'msg::', 'uri::', 'timeout::', 'verbose']);
    $params = array_filter([
    'endpoint' => $opts['endpoint'] ?? null,
    'msg' => $opts['msg'] ?? null,
    'uri' => $opts['uri'] ?? null,
    'timeout' => isset($opts['timeout']) ? (int)$opts['timeout'] : null,
    'verbose' => isset($opts['verbose']),
    ], static fn($v) => $v !== null);
} else {
    $params = array_filter([
    'endpoint' => $_GET['endpoint'] ?? $_POST['endpoint'] ?? null,
    'msg' => $_GET['msg'] ?? $_POST['msg'] ?? null,
    'uri' => $_GET['uri'] ?? $_POST['uri'] ?? null,
    'timeout' => isset($_GET['timeout']) ? (int)$_GET['timeout'] : (isset($_POST['timeout']) ? (int)$_POST['timeout'] : null),
    'verbose' => isset($_GET['verbose']) || isset($_POST['verbose']),
    ], static fn($v) => $v !== null);
}


return array_merge($defaults, $params);
}


function httpPostJson(string $url, array $payload, int $timeout, bool $verbose = false): array {
$ch = curl_init($url);
if ($ch === false) {
    throw new RuntimeException('Failed to initialize cURL.');
}


$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    throw new InvalidArgumentException('Failed to encode JSON payload: ' . json_last_error_msg());
}


$headers = [
'Content-Type: application/json',
'Accept: application/json, text/plain, */*',
];


curl_setopt_array($ch, [
CURLOPT_POST => true,
CURLOPT_POSTFIELDS => $json,
CURLOPT_HTTPHEADER => $headers,
CURLOPT_RETURNTRANSFER => true,
CURLOPT_FOLLOWLOCATION => true,
CURLOPT_MAXREDIRS => 5,
CURLOPT_CONNECTTIMEOUT => $timeout,
CURLOPT_TIMEOUT => $timeout,
]);


$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);


if ($verbose) {
    fwrite(STDERR, "POST $url\nPayload: $json\nHTTP $status\n\n");
}


if ($body === false) {
$err = curl_error($ch);
curl_close($ch);
}

try {
$cfg = readInput($argv ?? [], $isCli);
// 1) First POST: send msg + uri
$firstPayload = [
'msg' => $cfg['msg'],
'uri' => $cfg['uri'],
];
$first = httpPostJson($cfg['endpoint'], $firstPayload, (int)$cfg['timeout'], (bool)$cfg['verbose']);
failIfBadStatus($first, 'First POST');


$firstJson = decodeJson($first['body']);
if ($firstJson === null) {
    throw new RuntimeException('First response is not valid JSON. Body: ' . substr($first['body'], 0, 400));
}


// Extract part1 and next URI if present (support multiple key names just in case)
$part1 = $firstJson['part1'] ?? $firstJson['code_part1'] ?? $firstJson['first'] ?? $firstJson['code1'] ?? null;
if ($part1 === null) {
throw new RuntimeException('Cannot find first code part in response (expected keys: part1/code_part1/first/code1).');
}


$nextUri = $firstJson['uri'] ?? $firstJson['next'] ?? $cfg['uri'];
$nextUrl = buildAbsolute((string)$nextUri, $cfg['endpoint']);


// 2) Second request: GET designated URI
$second = httpGet($nextUrl, (int)$cfg['timeout'], (bool)$cfg['verbose']);
failIfBadStatus($second, 'Second GET');


$secondJson = decodeJson($second['body']);
if ($secondJson !== null) {
$part2 = $secondJson['part2'] ?? $secondJson['code_part2'] ?? $secondJson['second'] ?? $secondJson['code2'] ?? null;
if ($part2 === null) {
// If JSON but without explicit key, try common keys
$part2 = $secondJson['code'] ?? $secondJson['data'] ?? null;
}
} else {
// Plain text fallback
$part2 = trim($second['body']);
}


if (!is_string($part2) || $part2 === '') {
throw new RuntimeException('Cannot determine second code part from the designated URI response.');
}


// 3) Combine and POST back as `code`
$combined = (string)$part1 . (string)$part2;


$final = httpPostJson($cfg['endpoint'], ['code' => $combined], (int)$cfg['timeout'], (bool)$cfg['verbose']);
failIfBadStatus($final, 'Final POST');


$finalJson = decodeJson($final['body']);
$message = null;
if ($finalJson !== null) {
$message = $finalJson['message'] ?? $finalJson['result'] ?? $finalJson['msg'] ?? null;
if ($message === null) {
// If no dedicated message field, stringify the JSON
$message = json_encode($finalJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
} else {
$message = trim($final['body']);
}


if ($isCli) {
fwrite(STDOUT, "\n✅ Final message:\n" . $message . "\n");
} else {
header('Content-Type: text/plain; charset=UTF-8');
echo "Final message:\n" . $message;
}


} catch (Throwable $e) {
    $out = 'Error: ' . $e->getMessage();
    if ($isCli) {
    fwrite(STDERR, "\n❌ $out\n");
    exit(1);
    } else {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $out;
    }
}
}
