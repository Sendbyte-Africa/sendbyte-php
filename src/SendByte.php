<?php

declare(strict_types=1);

namespace SendByte;

/**
 * SendByte PHP SDK: the email API for Africa.
 *
 *     $sendbyte = new \SendByte\SendByte('sk_test_...');
 *     $email = $sendbyte->sendEmail([
 *         'from' => 'PayLink <receipts@paylink.ng>',
 *         'to' => 'amaka@halo.ng',
 *         'subject' => 'Receipt',
 *         'html' => '<p>Thank you.</p>',
 *     ]);
 *
 * Requires ext-curl and ext-json only. A custom transport callable can be
 * injected for testing: fn(string $method, string $url, array $headers, ?string $body): array{int, string}
 */
class SendByte
{
    public const VERSION = '0.1.0';
    public const SIGNATURE_HEADER = 'sendbyte-signature';
    private const DEFAULT_BASE_URL = 'https://api.sendbyte.africa';

    private string $apiKey;
    private string $baseUrl;
    /** @var ?callable */
    private $transport;

    public function __construct(string $apiKey, string $baseUrl = self::DEFAULT_BASE_URL, ?callable $transport = null)
    {
        if (!str_starts_with($apiKey, 'sk_live_') && !str_starts_with($apiKey, 'sk_test_')) {
            throw new SendByteException(
                'invalid_api_key',
                'API key must start with sk_live_ or sk_test_. Create one in the dashboard under API keys.',
                0
            );
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport;
    }

    /* ---------------- Emails ---------------- */

    /** @param array<string, mixed> $params */
    public function sendEmail(array $params): array
    {
        return $this->request('POST', '/v1/emails', $params);
    }

    public function getEmail(string $emailId): array
    {
        return $this->request('GET', "/v1/emails/{$emailId}");
    }

    /** @param array{limit?: int, after?: string, status?: string} $params */
    public function listEmails(array $params = []): array
    {
        $query = $params === [] ? '' : '?' . http_build_query($params);
        return $this->request('GET', "/v1/emails{$query}");
    }

    /* ---------------- Domains ---------------- */

    public function createDomain(string $domain): array
    {
        return $this->request('POST', '/v1/domains', ['domain' => $domain]);
    }

    public function listDomains(): array
    {
        return $this->request('GET', '/v1/domains');
    }

    public function getDomain(string $domainId): array
    {
        return $this->request('GET', "/v1/domains/{$domainId}");
    }

    public function verifyDomain(string $domainId): array
    {
        return $this->request('POST', "/v1/domains/{$domainId}/verify");
    }

    /* ---------------- Webhooks ---------------- */

    /** @param ?list<string> $events */
    public function createWebhook(string $url, ?array $events = null): array
    {
        $body = ['url' => $url];
        if ($events !== null) {
            $body['events'] = $events;
        }
        return $this->request('POST', '/v1/webhooks', $body);
    }

    public function listWebhooks(): array
    {
        return $this->request('GET', '/v1/webhooks');
    }

    public function disableWebhook(string $endpointId): array
    {
        return $this->request('DELETE', "/v1/webhooks/{$endpointId}");
    }

    public function replayWebhookDelivery(string $deliveryId): array
    {
        return $this->request('POST', "/v1/webhooks/deliveries/{$deliveryId}/replay");
    }

    /* ---------------- Webhook signature verification ---------------- */

    /**
     * Verify a webhook request came from SendByte. Header format:
     * t=<unix seconds>,v1=<hex hmac-sha256 of "<t>.<raw body>">
     */
    public static function verifyWebhookSignature(
        string $secret,
        ?string $header,
        string $body,
        int $toleranceSeconds = 300,
        ?int $now = null
    ): bool {
        if ($header === null || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            $eq = strpos($piece, '=');
            if ($eq !== false) {
                $parts[substr($piece, 0, $eq)] = substr($piece, $eq + 1);
            }
        }
        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';
        if (!ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        $current = $now ?? time();
        if (abs($current - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
        return hash_equals($expected, $signature);
    }

    /* ---------------- Internals ---------------- */

    private function request(string $method, string $path, ?array $body = null): array
    {
        $headers = [
            'authorization: Bearer ' . $this->apiKey,
            'user-agent: sendbyte-php/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $headers[] = 'content-type: application/json';
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        [$status, $text] = $this->transport !== null
            ? ($this->transport)($method, $this->baseUrl . $path, $headers, $payload)
            : $this->curlRequest($method, $this->baseUrl . $path, $headers, $payload);

        $data = $text === '' ? [] : json_decode($text, true);
        if (!is_array($data)) {
            throw new SendByteException('invalid_response', 'The API returned a non-JSON response.', $status);
        }

        if ($status >= 400) {
            $error = $data['error'] ?? [];
            throw new SendByteException(
                $error['code'] ?? 'request_failed',
                $error['message'] ?? "Request failed with status {$status}",
                $status,
                $error['docs_url'] ?? null
            );
        }
        return $data;
    }

    /** @return array{int, string} */
    private function curlRequest(string $method, string $url, array $headers, ?string $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $text = curl_exec($ch);
        if ($text === false) {
            $message = curl_error($ch);
            curl_close($ch);
            throw new SendByteException('network_error', "Could not reach the SendByte API: {$message}", 0);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string) $text];
    }
}
