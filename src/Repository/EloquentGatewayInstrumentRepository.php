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
use Techork\PaymentService\Common\ValueObject\AttachedPaymentMethod;
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
        $instrument = $this->addressable($instrument);
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
        $instrument = $this->addressable($instrument);
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
        $instrument = $this->addressable($instrument);
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

    /**
     * The instrument a row is actually keyed on, unwrapped once so both halves of the key agree.
     *
     * A row is addressed by `(gateway_id, referenceable_type, referenceable_id)`, and the two
     * halves used to be read off two different views of the same instrument: the id through
     * {@see resolveId()}, which is a visitor and unwraps a pairing, and the type through
     * `$instrument::type()`, which does not. So one stored credential was addressed as
     * `('payment_method', <id>)` or `('attached_payment_method', <the same id>)` depending on
     * which form the caller happened to hold — and the id half being identical is what made that
     * a collision of meaning rather than two unrelated rows.
     *
     * It was reachable on the ordinary path and in the direction that hurts: registration writes
     * the bare form, while a payment carries the attached one because the gateways decline an
     * instrument that names no payer. The reference was therefore invisible at exactly the moment
     * it was needed, and the payment failed as "not registered" — a key mismatch reading as a
     * gateway's refusal. `saveFailure()` wrote the reason against a key nothing would read, and
     * the upsert's conflict target includes the type, so the duplicate was written silently.
     *
     * Unwrapping HERE rather than teaching the visitor a `resolveType()` is the smaller fix and
     * the stronger one: `type()` is static and cannot be dispatched through a visit, so the
     * alternative leaves two methods that have to agree. One view in, both halves derived from
     * it, and "a reference is keyed on the credential" holds by construction.
     *
     * Which customer holds the credential is a separate map — `GatewayCustomerRepository` — for
     * the reason {@see visitAttachedPaymentMethod()} gives: a card can be attached and
     * re-attached while its provider-side reference stays what it was.
     */
    private function addressable(PaymentInstrument $instrument): PaymentInstrument
    {
        return $instrument instanceof AttachedPaymentMethod
            ? $instrument->paymentMethod
            : $instrument;
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

    /**
     * The same id: a reference belongs to the instrument, not to whoever holds it.
     *
     * Which customer a card is attached to is a separate map — `GatewayCustomerRepository` —
     * because one instrument can be attached and re-attached while its provider-side reference
     * stays what it was.
     *
     * Unreachable from this class's three public methods, which run {@see addressable()} first
     * and so never dispatch a pairing. Kept because the visitor contract requires the case and
     * because the answer is right: it is the same rule, stated where a reader looking for the
     * unwrapping expects to find it. What it could not do alone is fix the key — the type half
     * comes from a static `type()` that no visit passes through, which is the whole reason
     * `addressable()` exists.
     */
    #[Override]
    public function visitAttachedPaymentMethod(AttachedPaymentMethod $attached): PaymentMethodId
    {
        return $attached->paymentMethod->id;
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new RuntimeException('Hosted-payment instruments are not supported in this context.');
    }
}
