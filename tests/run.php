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

echo "\n{$tests} assertions passed.\n";
