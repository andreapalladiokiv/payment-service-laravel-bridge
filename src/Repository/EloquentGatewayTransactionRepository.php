<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Repository;

use Override;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Laravel\Models\GatewayReference;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Backs {@see GatewayTransactionRepository} via the polymorphic
 * {@see GatewayReference} table with morph types `payment_intent` and `refund`.
 *
 * Semantics: one row per (gateway, aggregate) — writes overwrite on transition
 * (e.g. PaymentIntent auth-ref → charge-ref on capture).
 */
final readonly class EloquentGatewayTransactionRepository implements GatewayTransactionRepository
{
    public const string TYPE_PAYMENT_INTENT = 'payment_intent';

    public const string TYPE_REFUND = 'refund';

    public function __construct(private string $modelClass = GatewayReference::class)
    {
    }

    #[Override]
    public function findForPaymentIntent(string $paymentIntentId): ?string
    {
        return $this->find(self::TYPE_PAYMENT_INTENT, $paymentIntentId);
    }

    #[Override]
    public function saveForPaymentIntent(GatewayId $gatewayId, string $paymentIntentId, string $reference, array $metadata = []): void
    {
        $this->save($gatewayId, self::TYPE_PAYMENT_INTENT, $paymentIntentId, $reference, $metadata);
    }

    #[Override]
    public function findMetadataForPaymentIntent(string $paymentIntentId): array
    {
        return $this->findMetadata(self::TYPE_PAYMENT_INTENT, $paymentIntentId);
    }

    #[Override]
    public function findForRefund(string $refundId): ?string
    {
        return $this->find(self::TYPE_REFUND, $refundId);
    }

    #[Override]
    public function saveForRefund(GatewayId $gatewayId, string $refundId, string $reference): void
    {
        $this->save($gatewayId, self::TYPE_REFUND, $refundId, $reference);
    }

    private function find(string $referenceableType, string $referenceableId): ?string
    {
        return $this->modelClass::query()
            ->where('referenceable_type', $referenceableType)
            ->where('referenceable_id', $referenceableId)
            ->value('reference');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function save(GatewayId $gatewayId, string $referenceableType, string $referenceableId, string $reference, array $metadata = []): void
    {
        // MERGED, not replaced. An empty array means "no signal", not "erase" —
        // overwrite-on-transition applies to the reference only — and replacing the
        // whole bag honoured that just as long as the later response happened to
        // carry nothing. The moment a capture returns metadata of its own (ConnexPay
        // returns its incoming transaction code there) everything the authorization
        // recorded was dropped, which is the opposite of what the rule above says.
        // Same-key writes still win, so a value the later response does repeat is
        // updated rather than pinned.
        $existing = $metadata === [] ? [] : $this->findMetadata($referenceableType, $referenceableId);

        $this->modelClass::unguarded(fn () => $this->modelClass::query()->updateOrCreate(
            [
                'gateway_id' => $gatewayId->toString(),
                'referenceable_type' => $referenceableType,
                'referenceable_id' => $referenceableId,
            ],
            [
                'reference' => $reference,
                'failure_reason' => null,
                ...($metadata === [] ? [] : ['metadata' => [...$existing, ...$metadata]]),
            ],
        ));
    }

    /** @return array<string, mixed> */
    private function findMetadata(string $referenceableType, string $referenceableId): array
    {
        $metadata = $this->modelClass::query()
            ->where('referenceable_type', $referenceableType)
            ->where('referenceable_id', $referenceableId)
            ->value('metadata');

        return $metadata ?: [];
    }
}
