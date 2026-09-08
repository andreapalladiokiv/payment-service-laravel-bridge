<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Override;
use RuntimeException;
use Techork\PaymentService\Domain\PaymentIntent\Port\CaptureOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CapturePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see CapturePort} backed by {@see PaymentGatewayInterface}. Captures an existing
 * authorization at the gateway and persists the capture reference; gateway refusal
 * becomes {@see GatewayDeclinedException}.
 *
 * Resolving the acquirer's reference happens here, next to persisting it. The gateway
 * used to look it up itself, which split one identity's lifecycle across two layers
 * and left the same missing-row condition meaning different things per operation —
 * {@see \Techork\PaymentService\Gateway\PaymentGatewayRouter::cancel} turned it into
 * a failed result, i.e. into an acquirer decline for a payment the acquirer was never
 * asked about.
 */
final readonly class OmnipayCapturePort implements CapturePort
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayId $gatewayId,
    ) {}

    #[Override]
    public function capture(CaptureRequest $request): CaptureOutcome
    {
        $paymentIntentId = $request->paymentIntentId->toString();

        $transactionReference = $this->transactionRepository->findForPaymentIntent($paymentIntentId)
            // Our own bookkeeping, not the issuer's answer: there is nothing to
            // capture because we never recorded what to capture. Never a decline.
            ?? throw new RuntimeException("No gateway transaction reference recorded for payment intent '$paymentIntentId'.");

        $result = $this->gateway->capture(
            $this->gatewayId,
            $transactionReference,
            $request->amount,
            "$paymentIntentId:capture",
            $request->authorizedAmount,
            $request->instrument,
        );

        if ($result->reference !== null) {
            $this->transactionRepository->saveForPaymentIntent($this->gatewayId, $paymentIntentId, $result->reference, $result->metadata);
        }

        if (!$result->success) {
            throw new GatewayDeclinedException($result->message ?? 'Gateway declined the capture');
        }

        return new CaptureOutcome($result->convertedAmount);
    }
}
