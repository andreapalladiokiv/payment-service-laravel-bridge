<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Repository;

use Override;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Common\ValueObject\UuidValueObject;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Laravel\Models\GatewayReference;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * @implements PaymentInstrumentVisitor<UuidValueObject|null>
 */
final readonly class EloquentGatewayInstrumentRepository implements GatewayInstrumentRepository, PaymentInstrumentVisitor
{
    public function __construct(private string $modelClass = GatewayReference::class)
    {
    }

    #[Override]
    public function find(GatewayId $gatewayId, PaymentInstrument $instrument): ?string
    {
        $id = $this->resolveId($instrument);

        if ($id === null) {
            return null;
        }

        return $this->modelClass::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', $instrument::type())
            ->where('referenceable_id', $id)
            ->value('reference');
    }

    #[Override]
    public function saveReference(GatewayId $gatewayId, PaymentInstrument $instrument, string $reference): void
    {
        $id = $this->resolveId($instrument);

        // Refused, where find() legitimately answers null. A raw card or cash has no identity
        // of its own — identity arrives with tokenisation — so a reference stored against one
        // could never be read back: find() resolves the same instrument to null and would
        // return nothing. Accepting the write would make it look kept. Without this the null
        // reached the upsert and surfaced as the driver's NOT NULL violation, which names a
        // column rather than the caller's mistake.
        if ($id === null) {
            throw new RuntimeException("A {$instrument::type()} carries no identity to store a gateway reference against; tokenise it first.");
        }

        $this->modelClass::query()->upsert(
            [
                'gateway_id' => $gatewayId->toString(),
                'referenceable_type' => $instrument::type(),
                'referenceable_id' => $id,
                'reference' => $reference,
                'failure_reason' => null,
            ],
            ['gateway_id', 'referenceable_type', 'referenceable_id'],
            ['reference', 'failure_reason'],
        );
    }

    #[Override]
    public function saveFailure(GatewayId $gatewayId, PaymentInstrument $instrument, string $reason): void
    {
        $id = $this->resolveId($instrument);

        // Refused, where find() legitimately answers null. A raw card or cash has no identity
        // of its own — identity arrives with tokenisation — so a reference stored against one
        // could never be read back: find() resolves the same instrument to null and would
        // return nothing. Accepting the write would make it look kept. Without this the null
        // reached the upsert and surfaced as the driver's NOT NULL violation, which names a
        // column rather than the caller's mistake.
        if ($id === null) {
            throw new RuntimeException("A {$instrument::type()} carries no identity to store a gateway reference against; tokenise it first.");
        }

        GatewayReference::query()->upsert(
            [
                'gateway_id' => $gatewayId->toString(),
                'referenceable_type' => $instrument::type(),
                'referenceable_id' => $id,
                'failure_reason' => $reason,
            ],
            ['gateway_id', 'referenceable_type', 'referenceable_id'],
            ['failure_reason'],
        );
    }

    private function resolveId(PaymentInstrument $instrument): ?UuidValueObject
    {
        return $instrument->accept($this);
    }

    #[Override]
    public function visitCreditCard(CreditCard $card): null
    {
        return null;
    }

    #[Override]
    public function visitCash(Cash $cash): null
    {
        return null;
    }

    #[Override]
    public function visitToken(Token $token): TokenId
    {
        return $token->id;
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): PaymentMethodId
    {
        return $paymentMethod->id;
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new RuntimeException('Hosted-payment instruments are not supported in this context.');
    }
}
