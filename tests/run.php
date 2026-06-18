<?php

/**
 * Dependency-free test runner (no PHPUnit install needed): every assertion
 * throws on failure; exit code 0 means green. Run: php tests/run.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/SendByteException.php';
require __DIR__ . '/../src/SendByte.php';

use SendByte\SendByte;
use SendByte\SendByteException;

$tests = 0;

function check(bool $condition, string $label): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "ok: {$label}\n";
}

/** @return array{0: callable, 1: \stdClass} Object holder so the call log survives the return. */
function fakeTransport(int $status, array $body): array
{
    $log = new \stdClass();
    $log->calls = [];
    $transport = function (string $method, string $url, array $headers, ?string $payload) use ($log, $status, $body): array {
        $log->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $payload !== null ? json_decode($payload, true) : null,
        ];
        return [$status, json_encode($body)];
    };
    return [$transport, $log];
}

/**
 * A transport that returns a scripted list of [status, text, headers?] tuples,
 * one per call. Raw text lets tests exercise non-JSON bodies.
 *
 * @param list<array{0: int, 1: string, 2?: array<string, string>}> $responses
 * @return array{0: callable, 1: \stdClass}
 */
function sequenceTransport(array $responses): array
{
    $log = new \stdClass();
    $log->calls = [];
    $transport = function (string $method, string $url, array $headers, ?string $payload) use ($log, $responses): array {
        $log->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $payload !== null ? json_decode($payload, true) : null,
        ];
        $i = min(count($log->calls) - 1, count($responses) - 1);
        return $responses[$i];
    };
    return [$transport, $log];
}

/* ---- construction ---- */

try {
    new SendByte('pk_live_nope');
    check(false, 'malformed key should throw');
} catch (SendByteException $e) {
    check($e->errorCode === 'invalid_api_key', 'rejects malformed API keys');
}

/* ---- send ---- */

[$transport, $log] = fakeTransport(201, ['id' => 'em_1', 'status' => 'queued', 'sandbox' => true]);
$client = new SendByte('sk_test_abc', 'https://api.test', $transport);
$email = $client->sendEmail([
    'from' => 'a@b.ng',
    'to' => 'c@d.ng',
    'subject' => 'Hi',
    'html' => '<p>x</p>',
    'idempotency_key' => 'order-1',
]);
check($email['id'] === 'em_1', 'send returns the created email');
check($log->calls[0]['url'] === 'https://api.test/v1/emails', 'send hits /v1/emails');
check(in_array('authorization: Bearer sk_test_abc', $log->calls[0]['headers'], true), 'send carries bearer auth');
check($log->calls[0]['body']['idempotency_key'] === 'order-1', 'send passes idempotency key');

/* ---- list query ---- */

[$transport, $log] = fakeTransport(200, ['data' => [], 'has_more' => false]);
$client = new SendByte('sk_test_abc', 'https://api.test', $transport);
$client->listEmails(['limit' => 5, 'status' => 'delivered']);
check($log->calls[0]['url'] === 'https://api.test/v1/emails?limit=5&status=delivered', 'list builds query string');

/* ---- error mapping ---- */

[$transport, $unused] = fakeTransport(403, [
    'error' => [
        'code' => 'domain_not_verified',
        'message' => 'paylink.ng is not verified.',
        'docs_url' => 'https://docs.sendbyte.africa/errors/domain_not_verified',
    ],
]);
$client = new SendByte('sk_live_abc', 'https://api.test', $transport);
try {
    $client->sendEmail(['from' => 'a@paylink.ng', 'to' => 'c@d.ng', 'subject' => 'x', 'text' => 'x']);
    check(false, 'API error should throw');
} catch (SendByteException $e) {
    check($e->errorCode === 'domain_not_verified', 'maps error code');
    check($e->status === 403, 'maps status');
    check(str_contains((string) $e->docsUrl, '/errors/domain_not_verified'), 'maps docs url');
}

/* ---- domains and webhooks surfaces ---- */

[$transport, $log] = fakeTransport(200, ['data' => []]);
$client = new SendByte('sk_test_abc', 'https://api.test', $transport);
$client->listDomains();
$client->listWebhooks();
check($log->calls[0]['url'] === 'https://api.test/v1/domains', 'domains surface');
check($log->calls[1]['url'] === 'https://api.test/v1/webhooks', 'webhooks surface');

/* ---- webhook signatures ---- */

$secret = 'whsec_secret';
$now = 1750000000;
$body = '{"type":"email.delivered"}';
$sign = fn (int $t): string => "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", $secret);

check(SendByte::verifyWebhookSignature($secret, $sign($now), $body, 300, $now), 'accepts valid signature');
check(!SendByte::verifyWebhookSignature($secret, $sign($now), '{"type":"x"}', 300, $now), 'rejects tampered body');
check(!SendByte::verifyWebhookSignature($secret, $sign($now - 301), $body, 300, $now), 'rejects stale timestamp');
check(!SendByte::verifyWebhookSignature($secret, null, $body, 300, $now), 'rejects missing header');
check(!SendByte::verifyWebhookSignature($secret, 'garbage', $body, 300, $now), 'rejects malformed header');

/* ---- auto idempotency key ---- */

[$transport, $log] = fakeTransport(201, ['id' => 'em_1']);
$client = new SendByte('sk_test_abc', 'https://api.test', $transport);
$client->sendEmail(['from' => 'a@b.ng', 'to' => 'c@d.ng', 'subject' => 'Hi', 'text' => 'x']);
$autoKey = $log->calls[0]['body']['idempotency_key'] ?? '';
check(
    (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $autoKey),
    'auto-generates a v4 UUID idempotency key when none is supplied'
);

/* ---- retry 429 reuses the idempotency key ---- */

[$transport, $log] = sequenceTransport([
    [429, json_encode(['error' => ['code' => 'rate_limited']]), ['retry-after' => '0']],
    [201, json_encode(['id' => 'em_1'])],
]);
$client = new SendByte('sk_test_abc', 'https://api.test', $transport);
$email = $client->sendEmail(['from' => 'a@b.ng', 'to' => 'c@d.ng', 'subject' => 'Hi', 'text' => 'x']);
check($email['id'] === 'em_1', 'retries a 429 and then succeeds');
check(count($log->calls) === 2, 'retry made exactly two attempts');
check(
    $log->calls[0]['body']['idempotency_key'] === $log->calls[1]['body']['idempotency_key'],
    'reuses the same idempotency key across retries'
);

/* ---- retry 5xx then typed error ---- */

[$transport, $log] = sequenceTransport([[503, json_encode(['error' => ['code' => 'unavailable']])]]);
$client = new SendByte('sk_test_abc', 'https://api.test', $transport, 2);
try {
    $client->sendEmail(['from' => 'a@b.ng', 'to' => 'c@d.ng', 'subject' => 'x', 'text' => 'x']);
    check(false, '5xx should raise after exhausting attempts');
} catch (SendByteException $e) {
    check($e->status === 503, 'surfaces a typed error with the 5xx status');
    check(count($log->calls) === 2, '5xx retried up to max attempts');
}

/* ---- non-JSON proxy error body ---- */

[$transport, $unused] = sequenceTransport([[502, '<html><body>502 Bad Gateway</body></html>']]);
$client = new SendByte('sk_test_abc', 'https://api.test', $transport, 1);
try {
    $client->sendEmail(['from' => 'a@b.ng', 'to' => 'c@d.ng', 'subject' => 'x', 'text' => 'x']);
    check(false, 'non-JSON error body should throw');
} catch (SendByteException $e) {
    check($e->errorCode === 'request_failed', 'non-JSON error body maps to request_failed');
    check($e->status === 502, 'non-JSON error body carries the HTTP status');
}

/* ---- list_unsubscribe pass-through ---- */

[$transport, $log] = fakeTransport(201, ['id' => 'em_1']);
$client = new SendByte('sk_test_abc', 'https://api.test', $transport);
$client->sendEmail([
    'from' => 'a@b.ng',
    'to' => 'c@d.ng',
    'subject' => 'Hi',
    'text' => 'x',
    'list_unsubscribe' => ['url' => 'https://x.ng/u', 'mailto' => 'unsub@x.ng'],
]);
check(
    $log->calls[0]['body']['list_unsubscribe'] === ['url' => 'https://x.ng/u', 'mailto' => 'unsub@x.ng'],
    'list_unsubscribe passes through to the API body'
);

/* ---- live key over insecure base URL ---- */

try {
    new SendByte('sk_live_abc', 'http://api.example.com');
    check(false, 'live key over http should throw');
} catch (SendByteException $e) {
    check($e->errorCode === 'insecure_base_url', 'refuses a live key over an insecure base URL');
}
// http tolerated for localhost dev; test keys always allowed (no throw expected).
new SendByte('sk_live_abc', 'http://localhost:8080');
new SendByte('sk_test_abc', 'http://api.example.com');
check(true, 'localhost http and test keys over http are allowed');

echo "\n{$tests} assertions passed.\n";
