<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Override;
use RuntimeException;
use Techork\PaymentService\Domain\PaymentIntent\Port\CancelPort;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CancelRequest;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Role\CancelsPayments;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see CancelPort} backed by {@see CancelsPayments}. Voids /
 * cancels a held authorization. Gateway refusal becomes a
 * {@see GatewayDeclinedException}; the aggregate's `cancel()` catches that
 * and records a `PaymentIntentFailed`, so it never reaches the caller.
 */
final readonly class CancelAdapter implements CancelPort
{
    public function __construct(
        private CancelsPayments $gateway,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayId $gatewayId,
    ) {}

    #[Override]
    public function cancel(CancelRequest $request): void
    {
        $paymentIntentId = $request->paymentIntentId->toString();

        // Was the sharpest case of the old split: the gateway looked this up and,
        // finding nothing, returned a FAILED result — which became
        // GatewayDeclinedException and then a recorded failure, reporting that an
        // issuer refused a cancellation no issuer ever saw. A missing row of ours is
        // not an answer from anyone.
        $transactionReference = $this->transactionRepository->findForPaymentIntent($paymentIntentId)
            ?? throw new RuntimeException("No gateway transaction reference recorded for payment intent '$paymentIntentId'.");

        $result = $this->gateway->cancel(new CancelCommand(
            gatewayId: $this->gatewayId,
            transactionReference: $transactionReference,
            clientUniqueId: "$paymentIntentId:cancel",
        ));

        if (!$result->success) {
            throw new GatewayDeclinedException($result->message ?? 'Gateway declined the cancellation');
        }
    }
}
