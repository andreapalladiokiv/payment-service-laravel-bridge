<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Override;
use RuntimeException;
use Techork\PaymentService\Domain\PaymentIntent\Port\CaptureOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CapturePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Role\CapturesPayments;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see CapturePort} backed by a gateway. Captures an existing authorization and persists the
 * capture reference; gateway refusal becomes {@see GatewayDeclinedException}.
 *
 * Resolving the acquirer's reference happens here, next to persisting it. The gateway used to
 * look it up itself, which split one identity's lifecycle across two layers and left the same
 * missing-row condition meaning different things per operation — the router turned it into a
 * failed result, i.e. into an acquirer decline for a payment the acquirer was never asked about.
 *
 * Depends on {@see CapturesPayments} rather than on the whole gateway: one method is what this
 * class uses, and one method is what a reader should have to check. What it receives is a
 * {@see \Techork\PaymentService\Gateway\Routing\RoutedGateway} already bound to `$gatewayId`,
 * which is why the id no longer appears in the call and survives here only for the bookkeeping
 * the repository needs.
 *
 * The division of labour with the layers underneath: they answer WHETHER the acquirer took the
 * money, this decides what that MEANS to a payment intent. Which is why the gateway hands back
 * a value and never throws a decline — the same failed result is a `GatewayDeclinedException`
 * here, nothing at all to a checkout, and an outcome carrying a challenge on a create.
 */
final readonly class CaptureAdapter implements CapturePort
{
    public function __construct(
        private CapturesPayments $gateway,
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

        $result = $this->gateway->capture(new CaptureCommand(
            gatewayId: $this->gatewayId,
            transactionReference: $transactionReference,
            amount: $request->amount,
            clientUniqueId: "$paymentIntentId:capture",
            authorizedAmount: $request->authorizedAmount,
            instrument: $request->instrument,
        ));

        if ($result->reference !== null) {
            $this->transactionRepository->saveForPaymentIntent($this->gatewayId, $paymentIntentId, $result->reference, $result->metadata);
        }

        if (! $result->success) {
            throw new GatewayDeclinedException($result->message ?? 'Gateway declined the capture');
        }

        return new CaptureOutcome($result->convertedAmount);
    }
}
