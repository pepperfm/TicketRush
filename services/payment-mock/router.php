<?php

declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_SERVER['REQUEST_URI'] !== '/payments') {
    http_response_code(404);
    echo json_encode(['message' => 'Not found']);

    return;
}

$minLatency = (int) (getenv('PAYMENT_LATENCY_MIN_MS') ?: 50);
$maxLatency = (int) (getenv('PAYMENT_LATENCY_MAX_MS') ?: 150);
$errorRate = (float) (getenv('PAYMENT_ERROR_RATE') ?: 0.02);
$timeoutRate = (float) (getenv('PAYMENT_TIMEOUT_RATE') ?: 0.02);
$rateLimitRate = (float) (getenv('PAYMENT_RATE_LIMIT_RATE') ?: 0.01);
$timeoutMs = (int) (getenv('PAYMENT_TIMEOUT_MS') ?: 3000);

$roll = random_int(1, 1_000_000) / 1_000_000;

if ($roll < $timeoutRate) {
    usleep($timeoutMs * 1000);
} else {
    usleep(random_int($minLatency, max($minLatency, $maxLatency)) * 1000);
}

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

$idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? bin2hex(random_bytes(8));

echo json_encode([
    'status' => 'paid',
    'payment_reference' => 'pay_'.$idempotencyKey,
]);
