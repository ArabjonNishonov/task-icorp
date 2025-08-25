<?php
declare(strict_types=1);

// ------------------------------
// Detect CLI vs Web
// ------------------------------
$isCli = (PHP_SAPI === 'cli');

// ------------------------------
// Input reading
// ------------------------------
function readInput(array $argv, bool $isCli): array {
    $defaults = [
        'endpoint' => 'https://test.icorp.uz/private/interview.php',
        'msg'      => 'hello-from-client',
        'uri'      => '/private/next', // placeholder; will be overridden if API provides URI
        'timeout'  => 15,
        'verbose'  => false,
    ];

    if ($isCli) {
        $opts = getopt('', ['endpoint::', 'msg::', 'uri::', 'timeout::', 'verbose']);
        $params = array_filter([
            'endpoint' => $opts['endpoint'] ?? null,
            'msg'      => $opts['msg'] ?? null,
            'uri'      => $opts['uri'] ?? null,
            'timeout'  => isset($opts['timeout']) ? (int)$opts['timeout'] : null,
            'verbose'  => isset($opts['verbose']),
        ], static fn($v) => $v !== null);
    } else {
        $params = array_filter([
            'endpoint' => $_GET['endpoint'] ?? $_POST['endpoint'] ?? null,
            'msg'      => $_GET['msg'] ?? $_POST['msg'] ?? null,
            'uri'      => $_GET['uri'] ?? $_POST['uri'] ?? null,
            'timeout'  => isset($_GET['timeout']) ? (int)$_GET['timeout'] : (isset($_POST['timeout']) ? (int)$_POST['timeout'] : null),
            'verbose'  => isset($_GET['verbose']) || isset($_POST['verbose']),
        ], static fn($v) => $v !== null);
    }

    return array_merge($defaults, $params);
}

// ------------------------------
// HTTP helpers
// ------------------------------
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
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($verbose) {
        fwrite(STDERR, "POST $url\nPayload: $json\nHTTP $status\n\n");
    }

    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL error: $err");
    }

    curl_close($ch);

    return ['status' => $status, 'body' => $body];
}

function httpGet(string $url, int $timeout, bool $verbose = false): array {
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($verbose) {
        fwrite(STDERR, "GET $url\nHTTP $status\n\n");
    }

    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL error: $err");
    }

    curl_close($ch);

    return ['status' => $status, 'body' => $body];
}

// ------------------------------
// Utilities
// ------------------------------
function failIfBadStatus(array $resp, string $label): void {
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException("$label failed. HTTP status: {$resp['status']}. Body: " . substr($resp['body'], 0, 400));
    }
}

function decodeJson(string $s): ?array {
    $data = json_decode($s, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
}

function buildAbsolute(string $uri, string $base): string {
    if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
        return $uri;
    }
    return rtrim(dirname($base), '/') . '/' . ltrim($uri, '/');
}

// ------------------------------
// Main flow
// ------------------------------
try {
    $cfg = readInput($argv ?? [], $isCli);

    // 1) First POST
    $firstPayload = ['msg' => $cfg['msg'], 'uri' => $cfg['uri']];
    $first = httpPostJson($cfg['endpoint'], $firstPayload, (int)$cfg['timeout'], (bool)$cfg['verbose']);
    failIfBadStatus($first, 'First POST');

    $firstJson = decodeJson($first['body']);
    if ($firstJson === null) {
        throw new RuntimeException('First response is not valid JSON. Body: ' . substr($first['body'], 0, 400));
    }

    $part1 = $firstJson['part1'] ?? $firstJson['code_part1'] ?? $firstJson['first'] ?? $firstJson['code1'] ?? null;
    if ($part1 === null) {
        throw new RuntimeException('Cannot find first code part in response.');
    }

    $nextUri = $firstJson['uri'] ?? $firstJson['next'] ?? $cfg['uri'];
    $nextUrl = buildAbsolute((string)$nextUri, $cfg['endpoint']);

    // 2) Second GET
    $second = httpGet($nextUrl, (int)$cfg['timeout'], (bool)$cfg['verbose']);
    failIfBadStatus($second, 'Second GET');

    $secondJson = decodeJson($second['body']);
    if ($secondJson !== null) {
        $part2 = $secondJson['part2'] ?? $secondJson['code_part2'] ?? $secondJson['second'] ?? $secondJson['code2'] ?? null;
        if ($part2 === null) {
            $part2 = $secondJson['code'] ?? $secondJson['data'] ?? null;
        }
    } else {
        $part2 = trim($second['body']);
    }

    if (!is_string($part2) || $part2 === '') {
        throw new RuntimeException('Cannot determine second code part from the designated URI response.');
    }

    // 3) Combine and POST back
    $combined = (string)$part1 . (string)$part2;
    $final = httpPostJson($cfg['endpoint'], ['code' => $combined], (int)$cfg['timeout'], (bool)$cfg['verbose']);
    failIfBadStatus($final, 'Final POST');

    $finalJson = decodeJson($final['body']);
    if ($finalJson !== null) {
        $message = $finalJson['message'] ?? $finalJson['result'] ?? $finalJson['msg'] ?? json_encode($finalJson, JSON_UNESCAPED_UNICODE);
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
