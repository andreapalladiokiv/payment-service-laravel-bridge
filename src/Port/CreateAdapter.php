<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Override;
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
     * No customer here, unlike the gateway id beside it. The payment intent carries its own now —
     * `CreateRequest` holds a {@see \Techork\PaymentService\Common\ValueObject\Customer}, so
     * the payer travels with the payment instead of being injected alongside it.
     *
     * This adapter used to take one, on the argument that the host knows who is paying when it
     * decides how to route the payment and the aggregate ignores the answer. The aggregate does
     * not ignore it any more: the address it always recorded was carrying the payer's name and
     * email for want of anywhere else to put them, so naming the customer on the intent replaced
     * a fact it was already holding rather than adding one. Two sources for the same value is the
     * thing to avoid — an injected customer and a recorded one disagreeing is invisible until an
     * acquirer attributes a payment to the wrong person.
     */
    public function __construct(
        private PlacesPayments $gateway,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayId $gatewayId,
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
            threeDS: $request->challengeResult instanceof ThreeDSResult ? $request->challengeResult : null,
            initiation: $request->initiation,
            customer: $request->customer,
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
