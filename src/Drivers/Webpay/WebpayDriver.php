<?php

namespace Arca\PaymentGateways\Drivers\Webpay;

use Arca\PaymentGateways\Contracts\SupportsRefunds;
use Arca\PaymentGateways\Drivers\AbstractDriver;
use Arca\PaymentGateways\Enums\Currency;
use Arca\PaymentGateways\Enums\PaymentStatus;
use Arca\PaymentGateways\Exceptions\GatewayException;
use Arca\PaymentGateways\Exceptions\PaymentVerificationException;
use Arca\PaymentGateways\Models\Payment;
use Arca\PaymentGateways\ValueObjects\ChargeIntent;
use Arca\PaymentGateways\ValueObjects\ChargeResult;
use Arca\PaymentGateways\ValueObjects\Money;
use Arca\PaymentGateways\ValueObjects\RefundResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Throwable;
use Transbank\Contracts\RequestService;
use Transbank\Webpay\Options;
use Transbank\Webpay\WebpayPlus;
use Transbank\Webpay\WebpayPlus\Exceptions\TransactionCommitException;
use Transbank\Webpay\WebpayPlus\Responses\TransactionCommitResponse;
use Transbank\Webpay\WebpayPlus\Responses\TransactionStatusResponse;
use Transbank\Webpay\WebpayPlus\Transaction;

/**
 * Transbank Webpay Plus (CLP). Needs transbank/transbank-sdk ^5.0.
 */
class WebpayDriver extends AbstractDriver implements SupportsRefunds
{
    protected ?RequestService $requestService = null;

    public function name(): string
    {
        return 'webpay';
    }

    /**
     * Webpay's buy order: up to 26 characters, unique per commerce code.
     */
    public function reference(Payment $payment): string
    {
        return (string) $payment->id;
    }

    /**
     * Replaces the SDK's HTTP layer (tests).
     */
    public function usingRequestService(RequestService $requestService): static
    {
        $this->requestService = $requestService;

        return $this;
    }

    public function charge(Payment $payment, ChargeIntent $intent): ChargeResult
    {
        if ($intent->money->currency !== Currency::CLP) {
            throw new GatewayException(
                message: "Webpay only charges CLP, payment [{$payment->uuid}] is in {$intent->money->currency->value}.",
                gateway: $this->name(),
            );
        }

        try {
            $response = $this->transaction()->create(
                $this->reference($payment),
                str_replace('-', '', $payment->uuid),
                $intent->money->amount,
                $intent->urls->returnUrl,
            );
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Create transaction');
        }

        return new ChargeResult(
            status: PaymentStatus::Pending,
            externalId: (string) $response->getToken(),
            redirectUrl: $response->getUrl().'?token_ws='.$response->getToken(),
        );
    }

    /**
     * Webpay posts or redirects the buyer back with one of four parameter sets:
     * token_ws (paid or rejected), TBK_TOKEN (aborted), TBK_ORDEN_COMPRA alone (timeout)
     * or all of them (error in the payment form).
     */
    public function complete(Payment $payment, Request $request): ChargeResult
    {
        $tokenWs = (string) $request->input('token_ws', '');
        $tbkToken = (string) $request->input('TBK_TOKEN', '');
        $buyOrder = (string) $request->input('TBK_ORDEN_COMPRA', '');

        foreach ([$tokenWs, $tbkToken] as $token) {
            if ($token !== '' && $token !== $payment->external_id) {
                throw new PaymentVerificationException(
                    message: "Webpay token does not belong to payment [{$payment->uuid}].",
                    gateway: $this->name(),
                );
            }
        }

        if ($buyOrder !== '' && $buyOrder !== $this->reference($payment)) {
            throw new PaymentVerificationException(
                message: "Webpay buy order [{$buyOrder}] does not belong to payment [{$payment->uuid}].",
                gateway: $this->name(),
            );
        }

        if ($tokenWs !== '' && $tbkToken === '') {
            return $this->commit($payment);
        }

        $reason = match (true) {
            $tokenWs !== '' => 'error',
            $tbkToken !== '' => 'aborted',
            $buyOrder !== '' => 'timeout',
            default => null,
        };

        if ($reason === null) {
            return $this->query($payment);
        }

        return new ChargeResult(
            status: PaymentStatus::Failed,
            message: __("payment-gateways::webpay.flow.{$reason}"),
        );
    }

    public function query(Payment $payment): ChargeResult
    {
        try {
            $response = $this->transaction()->status((string) $payment->external_id);
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Transaction status');
        }

        return $this->mapResponse($response);
    }

    public function refund(Payment $payment, ?Money $amount = null): RefundResult
    {
        try {
            $response = $this->transaction()->refund((string) $payment->external_id, ($amount ?? $payment->money())->amount);
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Refund');
        }

        return new RefundResult(
            status: $response->success() ? PaymentStatus::Refunded : $payment->status,
            refundId: $response->getAuthorizationCode(),
            refundedAmount: $amount ?? $payment->money(),
            message: $response->success() ? null : 'Refund '.$response->getType(),
            raw: [
                'type' => $response->getType(),
                'response_code' => $response->getResponseCode(),
                'nullified_amount' => $response->getNullifiedAmount(),
                'balance' => $response->getBalance(),
            ],
        );
    }

    /**
     * Commits the transaction. A token already committed (page refreshed, notification raced)
     * fails with an error: the status is then read instead.
     */
    protected function commit(Payment $payment): ChargeResult
    {
        try {
            $response = $this->transaction()->commit((string) $payment->external_id);
        } catch (TransactionCommitException $e) {
            $this->logWarning('Commit failed, reading status instead', ['error' => $e->getMessage()]);

            return $this->query($payment);
        } catch (Throwable $e) {
            throw $this->communicationError($e, 'Commit transaction');
        }

        return $this->mapResponse($response);
    }

    protected function mapResponse(TransactionCommitResponse|TransactionStatusResponse $response): ChargeResult
    {
        $approved = $response->getResponseCode() === 0;

        $status = match ($response->getStatus()) {
            'AUTHORIZED', 'CAPTURED', 'PARTIALLY_NULLIFIED' => $approved ? PaymentStatus::Paid : PaymentStatus::Failed,
            'NULLIFIED' => PaymentStatus::Refunded,
            'REVERSED' => PaymentStatus::Reversed,
            'INITIALIZED' => PaymentStatus::Pending,
            default => PaymentStatus::Failed,
        };

        return new ChargeResult(
            status: $status,
            authorizationCode: $response->getAuthorizationCode(),
            reference: $response->getBuyOrder(),
            amount: $response->getAmount() !== null ? Money::CLP((int) $response->getAmount()) : null,
            message: $status === PaymentStatus::Failed ? $this->responseMessage($response->getResponseCode()) : null,
            raw: [
                'status' => $response->getStatus(),
                'response_code' => $response->getResponseCode(),
                'amount' => $response->getAmount(),
                'buy_order' => $response->getBuyOrder(),
                'authorization_code' => $response->getAuthorizationCode(),
                'payment_type_code' => $response->getPaymentTypeCode(),
                'payment_type' => $this->paymentType($response->getPaymentTypeCode()),
                'installments_number' => $response->getInstallmentsNumber(),
                'installments_amount' => $response->getInstallmentsAmount(),
                'card_number' => $response->getCardNumber(),
                'accounting_date' => $response->getAccountingDate(),
                'transaction_date' => $response->getTransactionDate(),
            ],
        );
    }

    protected function responseMessage(?int $code): string
    {
        $key = "payment-gateways::webpay.response_codes.{$code}";

        return $code !== null && Lang::has($key) ? __($key) : __('payment-gateways::messages.unknown_error');
    }

    protected function paymentType(?string $code): ?string
    {
        $key = "payment-gateways::webpay.payment_types.{$code}";

        return $code !== null && Lang::has($key) ? __($key) : $code;
    }

    protected function transaction(): Transaction
    {
        if ($this->isSandbox()) {
            $options = new Options(
                (string) (($this->config['api_key'] ?? null) ?: WebpayPlus::INTEGRATION_API_KEY),
                (string) (($this->config['commerce_code'] ?? null) ?: WebpayPlus::INTEGRATION_COMMERCE_CODE),
                Options::ENVIRONMENT_INTEGRATION,
                (int) config('payment-gateways.http.timeout', 15),
            );
        } else {
            $this->requireConfig(['commerce_code', 'api_key']);

            $options = new Options(
                (string) $this->config['api_key'],
                (string) $this->config['commerce_code'],
                Options::ENVIRONMENT_PRODUCTION,
                (int) config('payment-gateways.http.timeout', 15),
            );
        }

        return new Transaction($options, $this->requestService);
    }

    protected function baseUrl(): string
    {
        return $this->isSandbox() ? Options::BASE_URL_INTEGRATION : Options::BASE_URL_PRODUCTION;
    }
}
