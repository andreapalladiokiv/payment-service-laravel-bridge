<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Override;
use Techork\PaymentService\Common\Contract\CustomerIdentifier;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreateOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreatePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Role\PlacesPayments;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see CreatePort} backed by {@see PlacesPayments} (Omnipay-style
 * router). Selects `charge()` for Immediate captureMethod, `authorize()`
 * otherwise. Persists the gateway reference so subsequent capture / cancel /
 * refund can locate the transaction. Translates a non-success result into
 * {@see GatewayDeclinedException} so the aggregate records `PaymentIntentFailed`.
 */
final readonly class CreateAdapter implements CreatePort
{
    /**
     * @param  ?CustomerIdentifier  $customerId  Whose payment this is.
     *
     * On the adapter for the reason the gateway id is: the host knows both when it decides how to
     * route this payment, and neither is a fact the payment intent reads. `CreateRequest` and
     * `CreatePaymentIntentCommand` are deliberately untouched — the command already carries a
     * `gatewayId()` that `CreateRequest` does not, for exactly this reason, and adding a method to
     * an interface every host implements to carry a value the aggregate ignores would be the
     * wrong trade.
     */
    public function __construct(
        private PlacesPayments $gateway,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayId $gatewayId,
        private ?CustomerIdentifier $customerId = null,
    ) {}

    #[Override]
    public function create(CreateRequest $request): CreateOutcome
    {
        $clientUniqueId = $request->paymentIntentId->toString();

        // Before the acquirer, not after. A gateway's idempotency key is not replay
        // protection — Stripe's lasts a day, ConnexPay's thirty minutes — so a job retried
        // later, or a command replayed, places a second authorization and holds a
        // cardholder's money twice. Holding a reference for this payment means the call was
        // already made, and that fact does not expire.
        $placed = $this->transactionRepository->findForPaymentIntent($clientUniqueId);
        if ($placed !== null && $placed !== '') {
            throw PaymentAlreadyPlaced::withReference($clientUniqueId, $placed);
        }

        $command = new PlacementCommand(
            gatewayId: $this->gatewayId,
            instrument: $request->instrument,
            amount: $request->amount,
            clientUniqueId: $clientUniqueId,
            billingAddress: $request->billingAddress,
            threeDS: $request->challengeResult instanceof ThreeDSResult ? $request->challengeResult : null,
            initiation: $request->initiation,
            customerId: $this->customerId,
        );

        // The capture method stays a choice of operation rather than a field on the command,
        // because the two are separately refusable: Paynet charges on its hosted page and has no
        // authorization step to offer.
        $result = $request->captureMethod === CaptureMethod::Immediate
            ? $this->gateway->charge($command)
            : $this->gateway->authorize($command);

        if ($result->reference !== null) {
            $this->transactionRepository->saveForPaymentIntent($this->gatewayId, $clientUniqueId, $result->reference, $result->metadata);
        }

        if ($result->challenge !== null) {
            return new CreateOutcome(challenge: $result->challenge);
        }

        if (!$result->success) {
            throw new GatewayDeclinedException($result->message ?? 'Gateway declined the transaction');
        }

        return new CreateOutcome(convertedAmount: $result->convertedAmount);
    }
}
