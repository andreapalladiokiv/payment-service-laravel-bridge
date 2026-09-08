<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Service\Port;

use Money\Money;
use Override;
use RuntimeException;
use Techork\PaymentService\Domain\PaymentIntent\Port\ConfirmChallengeOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\ConfirmChallengePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\ConfirmChallengeRequest;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see ConfirmChallengePort} for the flow where the authentication resolved on the
 * gateway's side and we are merely told — a webhook, or a poll. The gateway raised the
 * challenge against a payment it had already opened and settled it once the cardholder
 * was through, so this port places nothing.
 *
 * It still has to bring what it was told. It used to return an empty outcome, asserting
 * that the gateway had settled the payment while carrying no evidence of it, and the
 * reference the announcement named was persisted by the caller afterwards, outside any
 * port — the same split that once had the gateway reading references while the ports
 * wrote them. Now the announced data arrives in the constructor and the port hands it
 * over when the domain asks, persisting the reference itself, before the aggregate
 * records anything, exactly as
 * {@see \Techork\PaymentService\Laravel\Port\CreateAdapter} does inline.
 *
 * Its counterpart is
 * {@see \Techork\PaymentService\Laravel\Port\OmnipayConfirmChallengePort}, for the
 * challenge WE raised: same handing over of what is known, plus the call that actually
 * places the payment, because on that path nobody has placed it yet.
 *
 * `$convertedAmount` stays optional and is usually absent — an announcement need not
 * carry the FX figure, and it should be passed only when the payload really does.
 */
final readonly class ExternallyCompletedConfirmChallengePort implements ConfirmChallengePort
{
    private function __construct(
        private ?GatewayTransactionRepository $transactionRepository,
        private ?GatewayId $gatewayId,
        private ?string $gatewayReference,
        private ?Money $convertedAmount,
    ) {}

    /**
     * The announcement said the payment settled, and named it. The only shape that can
     * actually confirm anything.
     */
    public static function announcing(
        GatewayTransactionRepository $transactionRepository,
        GatewayId $gatewayId,
        string $gatewayReference,
        ?Money $convertedAmount = null,
    ): self {
        return new self($transactionRepository, $gatewayId, $gatewayReference, $convertedAmount);
    }

    /**
     * For the path that reports a REFUSAL. It carries no gateway data because it has
     * none — {@see \Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate::confirmChallenge}
     * records the failure and returns before any port is reached, so this instance
     * exists only to satisfy a parameter the flow does not use.
     *
     * Stated rather than faked. Constructing the announcing shape with an empty
     * reference would put a lie one refactor away from becoming true; this one says what
     * it is, and throws if the flow ever does reach it.
     */
    public static function reportingRefusalOnly(): self
    {
        return new self(null, null, null, null);
    }

    #[Override]
    public function confirm(ConfirmChallengeRequest $request): ConfirmChallengeOutcome
    {
        // An announcement that a payment settled but does not name it is not a
        // confirmation of anything: nothing could capture, cancel or refund it
        // afterwards, and the aggregate would be recording a charge on hearsay. Loud
        // here beats a stream that says the money moved. The refusal-only shape lands
        // here too, which is the point — it should never be asked to confirm.
        $reference = $this->gatewayReference;

        if ($this->transactionRepository === null || $this->gatewayId === null || $reference === null || $reference === '') {
            throw new RuntimeException(
                'A completed-elsewhere challenge was confirmed without a gateway reference for payment intent '
                ."'{$request->paymentIntentId->toString()}'.",
            );
        }

        // The gateway settled it, so there is nothing to call — but there is something
        // to keep. Without this the announcement would have been the only place the
        // reference ever appeared.
        $this->transactionRepository->saveForPaymentIntent(
            $this->gatewayId,
            $request->paymentIntentId->toString(),
            $reference,
        );

        return ConfirmChallengeOutcome::placed($this->convertedAmount);
    }
}
