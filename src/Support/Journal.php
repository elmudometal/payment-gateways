<?php

namespace Arca\PaymentGateways\Support;

use Closure;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Records every exchange a driver has with its gateway, untouched, so it can be stored in
 * payment_logs for audits. Secrets (keys, tokens, signatures) are masked before recording.
 */
class Journal
{
    /**
     * @var list<array{method: ?string, url: ?string, status_code: ?int, request: mixed, response: mixed, response_headers: ?array<string, string>, error: ?string}>
     */
    protected array $exchanges = [];

    /**
     * @param  mixed  $request  The body sent, decoded when JSON or form data
     * @param  mixed  $response  The body received, decoded when JSON
     * @param  array<string, string>|null  $responseHeaders  Gateway trace ids live here (Paypal-Debug-Id, x-request-id)
     */
    public function record(?string $method, ?string $url, ?int $statusCode, mixed $request, mixed $response, ?string $error = null, ?array $responseHeaders = null): void
    {
        $this->exchanges[] = [
            'method' => $method,
            'url' => $url !== null ? self::redactUrl($url) : null,
            'status_code' => $statusCode,
            'request' => self::redact($request),
            'response' => self::redact($response),
            'response_headers' => $responseHeaders,
            'error' => $error,
        ];
    }

    /**
     * Returns the recorded exchanges and forgets them.
     *
     * @return list<array{method: ?string, url: ?string, status_code: ?int, request: mixed, response: mixed, response_headers: ?array<string, string>, error: ?string}>
     */
    public function pull(): array
    {
        $exchanges = $this->exchanges;
        $this->exchanges = [];

        return $exchanges;
    }

    /**
     * Guzzle middleware recording each request and its response (or failure).
     */
    public function middleware(): Closure
    {
        return fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler) {
            try {
                $promise = $handler($request, $options);
            } catch (Throwable $e) {
                // Some handlers throw instead of rejecting (connection errors in tests and stubs).
                $this->recordPsr($request, null, $e->getMessage());

                throw $e;
            }

            return $promise->then(
                function (ResponseInterface $response) use ($request) {
                    $this->recordPsr($request, $response);

                    return $response;
                },
                function (Throwable $reason) use ($request) {
                    $response = $reason instanceof RequestException ? $reason->getResponse() : null;
                    $this->recordPsr($request, $response, $reason->getMessage());

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    protected function recordPsr(RequestInterface $request, ?ResponseInterface $response, ?string $error = null): void
    {
        $this->record(
            method: $request->getMethod(),
            url: (string) $request->getUri(),
            statusCode: $response?->getStatusCode(),
            request: self::decode(self::read($request->getBody()), $request->getHeaderLine('Content-Type')),
            response: $response ? self::decode(self::read($response->getBody()), 'application/json') : null,
            error: $error,
            responseHeaders: $response ? self::headers($response) : null,
        );
    }

    /**
     * @return array<string, string>
     */
    protected static function headers(ResponseInterface $response): array
    {
        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            if (strtolower((string) $name) !== 'set-cookie') {
                $headers[(string) $name] = implode(', ', $values);
            }
        }

        return $headers;
    }

    protected static function read(StreamInterface $body): string
    {
        if (! $body->isSeekable()) {
            return '';
        }

        $content = (string) $body;
        $body->rewind();

        return $content;
    }

    protected static function decode(string $content, string $contentType): mixed
    {
        if ($content === '') {
            return null;
        }

        if (json_validate($content)) {
            return json_decode($content, true);
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($content, $form);

            return $form;
        }

        return $content;
    }

    /**
     * Masks the values of secret keys at any depth.
     */
    public static function redact(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $secrets = array_map('strtolower', (array) config('payment-gateways.logs.redact', []));

        foreach ($data as $key => $value) {
            $data[$key] = in_array(strtolower((string) $key), $secrets, true) ? '[redacted]' : self::redact($value);
        }

        return $data;
    }

    /**
     * Masks secret query string parameters (Flow sends apiKey and its signature in the URL).
     */
    public static function redactUrl(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return $url;
        }

        parse_str($query, $params);

        return str_replace('?'.$query, '?'.http_build_query((array) self::redact($params)), $url);
    }
}
