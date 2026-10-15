<?php

namespace Arca\PaymentGateways\Support\Transbank;

use Arca\PaymentGateways\Support\Journal;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Transbank\Contracts\RequestService;
use Transbank\Utils\HttpClientRequestService;
use Transbank\Utils\TransbankApiRequest;
use Transbank\Webpay\Exceptions\WebpayRequestException;
use Transbank\Webpay\Options;

/**
 * Wraps the Transbank SDK's HTTP layer to record each call in the journal. The API key travels
 * in headers, which are not recorded.
 */
class RecordingRequestService implements RequestService
{
    public function __construct(
        protected Journal $journal,
        protected ?RequestService $inner = null,
    ) {
        $this->inner ??= new HttpClientRequestService;
    }

    public function request(string $method, string $endpoint, array $payload, Options $options): array
    {
        $url = rtrim($options->getApiBaseUrl(), '/').'/'.ltrim($endpoint, '/');

        try {
            $response = $this->inner->request($method, $endpoint, $payload, $options);
        } catch (WebpayRequestException $e) {
            $this->journal->record($method, $url, $e->getHttpCode() ?: null, $payload ?: null, $e->getTransbankErrorMessage(), $e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            $this->journal->record($method, $url, null, $payload ?: null, null, $e->getMessage());

            throw $e;
        }

        $this->journal->record($method, $url, 200, $payload ?: null, $response);

        return $response;
    }

    public function getLastResponse(): ?ResponseInterface
    {
        return $this->inner?->getLastResponse();
    }

    public function getLastRequest(): ?TransbankApiRequest
    {
        return $this->inner?->getLastRequest();
    }
}
