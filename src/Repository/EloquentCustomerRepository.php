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
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Laravel\Models\GatewayCustomer;
use Techork\PaymentService\Laravel\Models\GatewayReference;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * @implements PaymentInstrumentVisitor<UuidValueObject|null>
 */
final class EloquentCustomerRepository implements CustomerRepository, PaymentInstrumentVisitor
{
    #[Override]
    public function findByInstrument(GatewayId $gatewayId, PaymentInstrument $instrument): ?string
    {
        $id = $this->resolveId($instrument);

        if ($id === null) {
            return null;
        }

        return GatewayCustomer::query()
            ->join('gateway_reference_customer', 'gateway_customers.id', '=', 'gateway_reference_customer.gateway_customer_id')
            ->join('gateway_references', 'gateway_reference_customer.gateway_reference_id', '=', 'gateway_references.id')
            ->where('gateway_customers.gateway_id', $gatewayId->toString())
            ->where('gateway_references.referenceable_type', $instrument::type())
            ->where('gateway_references.referenceable_id', $id)
            ->value('gateway_customers.customer_reference');
    }

    #[Override]
    public function saveAndAttach(GatewayId $gatewayId, PaymentInstrument $instrument, string $customerReference): void
    {
        $id = $this->resolveId($instrument);

        if ($id === null) {
            return;
        }

        $reference = GatewayReference::query()
            ->where('gateway_id', $gatewayId->toString())
            ->where('referenceable_type', $instrument::type())
            ->where('referenceable_id', $id)
            ->first();

        if ($reference === null) {
            return;
        }

        $customer = GatewayCustomer::unguarded(fn () => GatewayCustomer::query()->firstOrCreate([
            'gateway_id' => $gatewayId->toString(),
            'customer_reference' => $customerReference,
        ]));

        // `sync` (not `syncWithoutDetaching`): the pivot's UNIQUE
        // `gateway_reference_id` means a reference links to exactly one
        // customer. Re-pointing it at a different customer must replace the
        // existing pivot row — `syncWithoutDetaching` would leave the stale row
        // and attempt a second INSERT, violating the unique constraint.
        $reference->customers()->sync([$customer->id]);
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
