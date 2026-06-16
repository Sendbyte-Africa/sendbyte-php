# sendbyte/sendbyte-php

The official PHP SDK for [SendByte](https://sendbyte.africa), the email API for Africa. Laravel friendly, requires only ext-curl and ext-json.

## Install

```bash
composer require sendbyte/sendbyte-php
```

Requires PHP 8.0 or newer.

## Quickstart

```php
<?php

use SendByte\SendByte;

$sendbyte = new SendByte('sk_test_...');

$email = $sendbyte->sendEmail([
    'from' => 'PayLink <receipts@paylink.ng>',
    'to' => 'amaka@halo.ng',
    'subject' => 'Receipt for your payment',
    'html' => '<p>Thank you. Your payment was received.</p>',
]);

echo $email['id'];
```

Keys that start with `sk_test_` run in the sandbox: every endpoint behaves the same, but nothing is actually delivered. Switch to an `sk_live_` key to send for real.

## Configuration

```php
$sendbyte = new SendByte('sk_live_...', 'https://api.sendbyte.africa');
```

## API

### Emails

```php
$sendbyte->sendEmail(['from' => ..., 'to' => ..., 'subject' => ..., 'html' => ...]);
$sendbyte->getEmail('em_123');
$sendbyte->listEmails(['limit' => 20, 'status' => 'delivered']);
```

`to`, `cc`, `bcc`, and `reply_to` accept a single address or an array. Pass `idempotency_key` to make retries safe, `scheduled_at` for future sends, and `template_id` with `variables` to render a server side template.

### Domains

```php
$domain = $sendbyte->createDomain('paylink.ng');
// $domain['dns_records'] lists the SPF, DKIM, and DMARC records to publish
$sendbyte->listDomains();
$sendbyte->getDomain($domain['id']);
$sendbyte->verifyDomain($domain['id']);
```

### Webhooks

```php
$endpoint = $sendbyte->createWebhook(
    'https://example.com/hooks/sendbyte',
    ['email.delivered', 'email.bounced']
);
// $endpoint['secret'] is shown once, store it now
$sendbyte->listWebhooks();
$sendbyte->disableWebhook($endpoint['id']);
$sendbyte->replayWebhookDelivery('evt_123');
```

## Verifying webhook signatures

Every webhook request carries a `sendbyte-signature` header. Verify it against the raw request body before trusting the payload.

```php
$raw = file_get_contents('php://input');
$valid = SendByte::verifyWebhookSignature(
    $_ENV['SENDBYTE_WEBHOOK_SECRET'],
    $_SERVER['HTTP_SENDBYTE_SIGNATURE'] ?? null,
    $raw
);
if (!$valid) {
    http_response_code(401);
    exit;
}
// handle the event
```

The check rejects missing, malformed, tampered, and stale signatures (default tolerance is 300 seconds).

## Errors

Failed requests throw a `SendByteException` carrying the API error shape.

```php
use SendByte\SendByteException;

try {
    $sendbyte->sendEmail([...]);
} catch (SendByteException $e) {
    echo $e->errorCode . ' ' . $e->status . ' ' . $e->getMessage();
    echo $e->docsUrl;
}
```

## Links

- Documentation: https://docs.sendbyte.africa

## License

MIT
