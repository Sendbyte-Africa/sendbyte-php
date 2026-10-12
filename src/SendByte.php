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
 * The $timeoutSeconds setting bounds only the built-in curl transport; a custom
 * transport is responsible for enforcing its own timeout.
 */
class SendByte
{
    public const VERSION = '0.3.0';
    public const SIGNATURE_HEADER = 'sendbyte-signature';
    private const DEFAULT_BASE_URL = 'https://api.sendbyte.africa';
    private const DEFAULT_MAX_ATTEMPTS = 3;
    private const DEFAULT_TIMEOUT_SECONDS = 30;
    private const MAX_BACKOFF_SECONDS = 20.0;

    private string $apiKey;
    private string $baseUrl;
    /** @var ?callable */
    private $transport;
    private int $maxAttempts;
    private int $timeoutSeconds;

    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?callable $transport = null,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS
    ) {
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
        $this->maxAttempts = max(1, $maxAttempts);
        $this->timeoutSeconds = max(1, $timeoutSeconds);

        // A live key over a non-https base URL would send the secret in cleartext.
        if (str_starts_with($apiKey, 'sk_live_') && !self::isSecureBaseUrl($this->baseUrl)) {
            throw new SendByteException(
                'insecure_base_url',
                'Refusing to send a live API key (sk_live_) over an insecure base URL. '
                    . 'Use https, or a test key for local http.',
                0
            );
        }
    }

    /** https is required; http is tolerated only for localhost/loopback dev hosts. */
    private static function isSecureBaseUrl(string $baseUrl): bool
    {
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if ($scheme === 'https') {
            return true;
        }
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
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

    /** Sent and received messages in the email's thread. Returns {thread_id, replies, data}. */
    public function getEmailConversation(string $emailId): array
    {
        return $this->request('GET', "/v1/emails/{$emailId}/conversation");
    }

    /* ---------------- Domains ---------------- */

    // --- Marketing -------------------------------------------------------
    //
    // Brands hold lists, lists hold subscribers. A subscriber's status belongs
    // to the list, not to the person, which is why every call here takes both.

    /** @param array<string, mixed> $options */
    public function createBrand(string $name, array $options = []): array
    {
        return $this->request('POST', '/v1/brands', ['name' => $name] + $options);
    }

    public function listBrands(): array
    {
        return $this->request('GET', '/v1/brands');
    }

    public function getBrand(string $brandId): array
    {
        return $this->request('GET', "/v1/brands/{$brandId}");
    }

    /** @param array<string, mixed> $options */
    public function createList(string $brandId, string $name, array $options = []): array
    {
        return $this->request('POST', "/v1/brands/{$brandId}/lists", ['name' => $name] + $options);
    }

    public function listLists(string $brandId): array
    {
        return $this->request('GET', "/v1/brands/{$brandId}/lists");
    }

    /**
     * Add someone to a list.
     *
     * On a double opt-in list they land as unconfirmed and are never mailed
     * until they confirm. Anyone who previously unsubscribed or bounced stays
     * off: the response carries skipped_reason rather than the call failing.
     *
     * @param array<string, mixed> $options
     */
    public function addSubscriber(string $brandId, string $listId, string $email, array $options = []): array
    {
        return $this->request(
            'POST',
            "/v1/brands/{$brandId}/lists/{$listId}/subscribers",
            ['email' => $email] + $options
        );
    }

    /** @param array<string, mixed> $params */
    public function listSubscribers(string $brandId, string $listId, array $params = []): array
    {
        $query = $params ? '?' . http_build_query($params) : '';
        return $this->request('GET', "/v1/brands/{$brandId}/lists/{$listId}/subscribers{$query}");
    }

    /**
     * Batch import, up to 1,000 rows per call. Reports every rejected row.
     *
     * @param list<array<string, mixed>> $subscribers
     */
    public function importSubscribers(string $brandId, string $listId, array $subscribers): array
    {
        return $this->request(
            'POST',
            "/v1/brands/{$brandId}/lists/{$listId}/import",
            ['subscribers' => $subscribers]
        );
    }

    public function setSubscriberStatus(
        string $brandId,
        string $listId,
        string $subscriberId,
        string $status
    ): array {
        return $this->request(
            'PATCH',
            "/v1/brands/{$brandId}/lists/{$listId}/subscribers/{$subscriberId}",
            ['status' => $status]
        );
    }

    /** @param array<string, mixed> $params */
    public function createCampaign(string $brandId, array $params): array
    {
        return $this->request('POST', "/v1/brands/{$brandId}/campaigns", $params);
    }

    public function listCampaigns(string $brandId): array
    {
        return $this->request('GET', "/v1/brands/{$brandId}/campaigns");
    }

    /**
     * Freeze the audience and send.
     *
     * The audience is snapshotted at this moment: somebody who joins afterwards
     * is not included, somebody who leaves before their turn is skipped, and a
     * person on two of the chosen lists receives one email.
     *
     * @param list<string> $listIds
     * @param array<string, mixed> $options
     */
    public function sendCampaign(string $brandId, string $campaignId, array $listIds, array $options = []): array
    {
        return $this->request(
            'POST',
            "/v1/brands/{$brandId}/campaigns/{$campaignId}/send",
            ['list_ids' => $listIds] + $options
        );
    }

    public function getCampaignReport(string $brandId, string $campaignId): array
    {
        return $this->request('GET', "/v1/brands/{$brandId}/campaigns/{$campaignId}/report");
    }

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

    /** Receiving settings for the project, including each domain's MX setup. */
    public function getReceiving(): array
    {
        return $this->request('GET', '/v1/receiving');
    }

    /** Turn on receiving. Received emails count toward your sending quota; spam does not. */
    public function enableReceiving(): array
    {
        return $this->request('POST', '/v1/receiving/enable', ['acknowledge_quota' => true]);
    }

    public function disableReceiving(): array
    {
        return $this->request('POST', '/v1/receiving/disable', []);
    }

    /** Send a test email to one of the project's receiving addresses. */
    public function sendReceivingTest(string $to): array
    {
        return $this->request('POST', '/v1/receiving/test', ['to' => $to]);
    }

    /** Check whether a receiving subdomain is free to use before enabling it. */
    public function getDomainReceivingPreflight(string $domainId, string $subdomain = 'inbound'): array
    {
        $query = http_build_query(['subdomain' => $subdomain]);
        return $this->request('GET', "/v1/domains/{$domainId}/receiving/preflight?{$query}");
    }

    /** Set up receiving on a domain. Returns the MX record to publish. */
    public function enableDomainReceiving(string $domainId, string $subdomain = 'inbound'): array
    {
        return $this->request('POST', "/v1/domains/{$domainId}/receiving", ['subdomain' => $subdomain]);
    }

    /** Re-check the domain's receiving MX record now. */
    public function checkDomainReceiving(string $domainId): array
    {
        return $this->request('POST', "/v1/domains/{$domainId}/receiving/check", []);
    }

    public function disableDomainReceiving(string $domainId): array
    {
        return $this->request('DELETE', "/v1/domains/{$domainId}/receiving");
    }

    /**
     * List received emails, newest first. `status` takes a comma list or an array.
     * @param array{status?: string|list<string>, domain_id?: string, search?: string, days?: int, limit?: int, before?: string} $params
     */
    public function listReceivedEmails(array $params = []): array
    {
        if (isset($params['status']) && is_array($params['status'])) {
            $params['status'] = implode(',', $params['status']);
        }
        $query = $params === [] ? '' : '?' . http_build_query($params);
        return $this->request('GET', "/v1/received{$query}");
    }

    /** A received email with its body, headers, attachments and conversation. */
    public function getReceivedEmail(string $receivedId): array
    {
        return $this->request('GET', "/v1/received/{$receivedId}");
    }

    /** Received email counts by outcome over the last $days days. */
    public function getReceivedStats(int $days = 30, ?string $domainId = null): array
    {
        $params = ['days' => $days];
        if ($domainId !== null) {
            $params['domain_id'] = $domainId;
        }
        return $this->request('GET', '/v1/received/stats?' . http_build_query($params));
    }

    /** A short-lived download URL for one attachment. */
    public function getReceivedAttachment(string $receivedId, string $attachmentId): array
    {
        return $this->request('GET', "/v1/received/{$receivedId}/attachments/{$attachmentId}");
    }

    /** A short-lived download URL for the original MIME message. */
    public function getReceivedRaw(string $receivedId): array
    {
        return $this->request('GET', "/v1/received/{$receivedId}/raw");
    }

    /**
     * Reply in the same thread. Pass text or html; retries reuse one idempotency key.
     * @param array{from?: string, text?: string, html?: string, subject?: string, reply_all?: bool, idempotency_key?: string} $params
     */
    public function replyToReceivedEmail(string $receivedId, array $params): array
    {
        $params += ['idempotency_key' => self::newIdempotencyKey()];
        return $this->request('POST', "/v1/received/{$receivedId}/reply", $params);
    }

    /** Inbound routes for a domain, or the test-address routes when $domainId is null. */
    public function listInboundRoutes(?string $domainId = null): array
    {
        $query = $domainId === null ? '' : '?' . http_build_query(['domain_id' => $domainId]);
        return $this->request('GET', "/v1/inbound-routes{$query}");
    }

    /** @param array<string, mixed> $params */
    public function createInboundRoute(array $params): array
    {
        return $this->request('POST', '/v1/inbound-routes', $params);
    }

    /** @param array<string, mixed> $params */
    public function updateInboundRoute(string $routeId, array $params): array
    {
        return $this->request('PATCH', "/v1/inbound-routes/{$routeId}", $params);
    }

    public function deleteInboundRoute(string $routeId): array
    {
        return $this->request('DELETE', "/v1/inbound-routes/{$routeId}");
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
        if ($method === 'POST' && $path === '/v1/emails' && $body !== null && !isset($body['idempotency_key'])) {
            $body['idempotency_key'] = self::newIdempotencyKey();
        }

        $headers = [
            'authorization: Bearer ' . $this->apiKey,
            'user-agent: sendbyte-php/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $headers[] = 'content-type: application/json';
            $payload = json_encode(
                $body === [] ? new \stdClass() : $body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        $url = $this->baseUrl . $path;
        $lastError = null;
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $result = $this->transport !== null
                    ? ($this->transport)($method, $url, $headers, $payload)
                    : $this->curlRequest($method, $url, $headers, $payload);
            } catch (SendByteException $networkError) {
                $lastError = $networkError;
                if ($attempt < $this->maxAttempts) {
                    self::sleep(self::backoffDelay($attempt, null));
                    continue;
                }
                throw $networkError;
            }

            $status = (int) $result[0];
            $text = (string) $result[1];
            $responseHeaders = isset($result[2]) && is_array($result[2]) ? $result[2] : [];

            if ($status >= 400) {
                if (self::isRetryableStatus($status) && $attempt < $this->maxAttempts) {
                    $lastError = self::parseError($text, $status);
                    self::sleep(self::backoffDelay($attempt, self::retryAfterHeader($responseHeaders)));
                    continue;
                }
                throw self::parseError($text, $status);
            }

            if ($text === '') {
                return [];
            }
            $data = json_decode($text, true);
            if (!is_array($data)) {
                throw new SendByteException(
                    'invalid_response',
                    "The API returned a 2xx response that was not valid JSON (status {$status}).",
                    $status
                );
            }
            return $data;
        }

        throw $lastError ?? new SendByteException(
            'request_failed',
            'Request failed after exhausting retries.',
            0
        );
    }

    /** Parse a non-2xx body into a typed exception, never failing on a non-JSON body. */
    private static function parseError(string $text, int $status): SendByteException
    {
        $data = $text === '' ? [] : json_decode($text, true);
        if (!is_array($data)) {
            // A gateway/proxy 502/504 may return HTML or plain text.
            return new SendByteException('request_failed', "Request failed with status {$status}.", $status);
        }
        $error = $data['error'] ?? [];
        return new SendByteException(
            $error['code'] ?? 'request_failed',
            $error['message'] ?? "Request failed with status {$status}",
            $status,
            $error['docs_url'] ?? null
        );
    }

    private static function isRetryableStatus(int $status): bool
    {
        return $status === 429 || ($status >= 500 && $status < 600);
    }

    /**
     * Exponential backoff with full jitter, capped, honoring Retry-After
     * (delta-seconds or an HTTP-date) when present. $attempt is 1-based. Returns
     * seconds.
     */
    private static function backoffDelay(int $attempt, ?string $retryAfter): float
    {
        $after = self::parseRetryAfter($retryAfter);
        if ($after !== null) {
            return min($after, self::MAX_BACKOFF_SECONDS);
        }
        $base = min(self::MAX_BACKOFF_SECONDS, 0.25 * (2 ** ($attempt - 1)));
        return (mt_rand() / mt_getrandmax()) * $base;
    }

    private static function parseRetryAfter(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim($value);
        if (ctype_digit($value)) {
            return (float) $value;
        }
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return max(0.0, (float) ($timestamp - time()));
        }
        return null;
    }

    /** @param array<string, string> $headers Lower-cased header map. */
    private static function retryAfterHeader(array $headers): ?string
    {
        return $headers['retry-after'] ?? null;
    }

    private static function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    /** Random RFC 4122 v4 UUID. */
    private static function newIdempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** @return array{int, string, array<string, string>} */
    private function curlRequest(string $method, string $url, array $headers, ?string $payload): array
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $name = strtolower(trim(substr($line, 0, $colon)));
                    $responseHeaders[$name] = trim(substr($line, $colon + 1));
                }
                return strlen($line);
            },
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
        return [$status, (string) $text, $responseHeaders];
    }
}
