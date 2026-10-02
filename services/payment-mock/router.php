<?php

declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_SERVER['REQUEST_URI'] !== '/payments') {
    http_response_code(404);
    echo json_encode(['message' => 'Not found']);

    return;
}

$key = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));

if ($key === '') {
    http_response_code(422);
    echo json_encode(['message' => 'Idempotency-Key is required']);

    return;
}

$storePath = '/tmp/ticketrush-payment-mock.json';
$readStore = static function () use ($storePath): array {
    if (! is_file($storePath)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($storePath), true);

    return is_array($decoded) ? $decoded : [];
};
$writeSuccess = static function (array $response) use ($storePath, $key): void {
    $handle = fopen($storePath, 'c+');

    if ($handle === false) {
        return;
    }

    try {
        flock($handle, LOCK_EX);
        rewind($handle);
        $decoded = json_decode(stream_get_contents($handle) ?: '{}', true);
        $store = is_array($decoded) ? $decoded : [];
        $store[$key] ??= $response;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($store, JSON_THROW_ON_ERROR));
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
};

$existing = $readStore()[$key] ?? null;

if (is_array($existing)) {
    echo json_encode($existing);

    return;
}

$minLatency = (int) (getenv('PAYMENT_LATENCY_MIN_MS') ?: 50);
$maxLatency = (int) (getenv('PAYMENT_LATENCY_MAX_MS') ?: 150);
$errorRate = (float) (getenv('PAYMENT_ERROR_RATE') ?: 0.02);
$timeoutRate = (float) (getenv('PAYMENT_TIMEOUT_RATE') ?: 0.02);
$rateLimitRate = (float) (getenv('PAYMENT_RATE_LIMIT_RATE') ?: 0.01);
$timeoutMs = (int) (getenv('PAYMENT_TIMEOUT_MS') ?: 3000);

$roll = random_int(1, 1_000_000) / 1_000_000;
$success = [
    'status' => 'paid',
    'payment_reference' => 'pay_'.substr(hash('sha256', $key), 0, 24),
];

if ($roll < $timeoutRate) {
    // The provider completes the operation, but the response arrives after the
    // client timeout. A retry with the same key returns this stored result.
    $writeSuccess($success);
    usleep($timeoutMs * 1000);
    echo json_encode($success);

    return;
}

usleep(random_int($minLatency, max($minLatency, $maxLatency)) * 1000);

if ($roll < $timeoutRate + $rateLimitRate) {
    http_response_code(429);
    header('Retry-After: 1');
    echo json_encode(['status' => 'rate_limited']);

    return;
}

if ($roll < $timeoutRate + $rateLimitRate + $errorRate) {
    http_response_code(500);
    echo json_encode(['status' => 'failed']);

    return;
}

$writeSuccess($success);
echo json_encode($success);
