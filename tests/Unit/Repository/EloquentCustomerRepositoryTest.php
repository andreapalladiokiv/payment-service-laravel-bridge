<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Repository\EloquentCustomerRepository;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayInstrumentRepository;

/**
 * `EloquentCustomerRepository` is the only class in this package that writes through a pivot,
 * and it was at zero coverage. Everything interesting about it lives in the database rather
 * than in the code: a `firstOrCreate` guarded by a composite unique key, and a `sync` chosen
 * over `syncWithoutDetaching` because the pivot's UNIQUE `gateway_reference_id` makes the
 * link one-to-one. That decision carries a comment in the source explaining what the wrong
 * choice would do — a stale row plus a second INSERT that violates the constraint — and a
 * comment is all that held it up. This file holds it up instead.
 *
 * The other rule pinned here is the ordering the contract states in prose: the instrument's
 * `gateway_reference` must already exist, and `saveAndAttach` silently does nothing when it
 * does not. Silent no-ops are exactly the behaviour a green suite cannot see, and providers
 * call this immediately after tokenization, so the two writes' order is load-bearing.
 *
 * Same harness as {@see EloquentGatewayTransactionRepositoryTest}: one global in-memory
 * SQLite Capsule, real repositories, nothing mocked, no new dev dependency. The schema is
 * created only when absent and every table this file uses is emptied per test, because the
 * Capsule is shared with the sibling database-backed tests in this process.
 */
function bootGatewayCustomerSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // Mirrors create_gateway_references_table + add_metadata_to_gateway_references.
    if (! Capsule::schema()->hasTable('gateway_references')) {
        Capsule::schema()->create('gateway_references', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('gateway_id');
            $table->string('referenceable_type');
            $table->uuid('referenceable_id');
            $table->string('reference')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['gateway_id', 'referenceable_type', 'referenceable_id']);
        });
    }

    // Mirrors create_gateway_customers_table. Foreign keys are left off — this exercises the
    // repository, not referential integrity — but both unique keys are kept, because they are
    // what `firstOrCreate` and `sync` are written around and the point of several tests below.
    if (! Capsule::schema()->hasTable('gateway_customers')) {
        Capsule::schema()->create('gateway_customers', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('gateway_id');
            $table->string('customer_reference');
            $table->timestamps();

            $table->unique(['gateway_id', 'customer_reference']);
        });
    }

    if (! Capsule::schema()->hasTable('gateway_reference_customer')) {
        Capsule::schema()->create('gateway_reference_customer', function ($table) {
            $table->uuid('gateway_reference_id');
            $table->uuid('gateway_customer_id');

            $table->unique('gateway_reference_id');
        });
    }

    Capsule::table('gateway_reference_customer')->delete();
    Capsule::table('gateway_customers')->delete();
    Capsule::table('gateway_references')->delete();
}

function customerRepoCard(): CreditCard
{
    return new CreditCard(
        new Number('424242', '4242', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test Holder'),
        new Cvc,
    );
}

/**
 * @param  string|null  $id  pass one to make two instruments share an identity on purpose
 */
function customerRepoToken(?string $id = null): Token
{
    return new Token(
        TokenId::fromString($id ?? Uuid::uuid4()->toString()),
        customerRepoCard(),
        ExpiresAt::fromString(new DateTimeImmutable('+1 hour')->format(DateTimeInterface::ATOM)),
    );
}

function customerRepoPaymentMethod(?string $id = null): PaymentMethod
{
    return new PaymentMethod(
        PaymentMethodId::fromString($id ?? Uuid::uuid4()->toString()),
        customerRepoCard(),
        new BillingAddress(
            firstName: 'Test',
            lastName: 'User',
            line: '123 Main St',
            city: 'NYC',
            country: new Country('US'),
            postalCode: '10001',
        ),
    );
}

beforeEach(function () {
    bootGatewayCustomerSchema();

    // The real instrument repository writes the prerequisite gateway_reference rows: the two
    // classes are used together in every provider that creates a customer, and driving the
    // pivot from a hand-rolled INSERT would prove nothing about that pairing.
    $this->instruments = new EloquentGatewayInstrumentRepository;
    $this->customers = new EloquentCustomerRepository;
    $this->gatewayId = GatewayId::generate();
});

it('links a customer to a stored instrument reference and reads it back', function () {
    $token = customerRepoToken();
    $this->instruments->saveReference($this->gatewayId, $token, 'tok_abc');

    expect($this->customers->findByInstrument($this->gatewayId, $token))->toBeNull();

    $this->customers->saveAndAttach($this->gatewayId, $token, 'cus_123');

    expect($this->customers->findByInstrument($this->gatewayId, $token))->toBe('cus_123')
        ->and(Capsule::table('gateway_customers')->count())->toBe(1)
        ->and(Capsule::table('gateway_reference_customer')->count())->toBe(1);
});

it('does nothing at all when the instrument has no gateway reference yet', function () {
    // The contract says the instrument's gateway_reference must already exist, and this is
    // what "must" means in practice: a silent return, no customer row, no pivot row. Pinned
    // because a provider that creates its customer before vaulting the card gets no error to
    // learn from — the link is simply missing, and the next charge goes out without it.
    $token = customerRepoToken();

    $this->customers->saveAndAttach($this->gatewayId, $token, 'cus_orphan');

    expect(Capsule::table('gateway_customers')->count())->toBe(0)
        ->and(Capsule::table('gateway_reference_customer')->count())->toBe(0)
        ->and($this->customers->findByInstrument($this->gatewayId, $token))->toBeNull();
});

it('stays idempotent when the same customer is attached twice', function () {
    // Providers call this on every tokenization, and a retried request re-sends the same
    // customer reference. `firstOrCreate` plus the composite unique key must absorb that
    // without a duplicate customer, and `sync` without a duplicate pivot row.
    $token = customerRepoToken();
    $this->instruments->saveReference($this->gatewayId, $token, 'tok_abc');

    $this->customers->saveAndAttach($this->gatewayId, $token, 'cus_123');
    $this->customers->saveAndAttach($this->gatewayId, $token, 'cus_123');

    expect(Capsule::table('gateway_customers')->count())->toBe(1)
        ->and(Capsule::table('gateway_reference_customer')->count())->toBe(1)
        ->and($this->customers->findByInstrument($this->gatewayId, $token))->toBe('cus_123');
});

it('re-points the reference at a different customer instead of accumulating links', function () {
    // The `sync`-not-`syncWithoutDetaching` decision, made executable. The pivot's UNIQUE
    // gateway_reference_id means one reference links to exactly one customer, so moving a
    // vaulted card to another gateway-side customer has to replace the row; the alternative
    // leaves the stale link and dies on the second INSERT.
    $token = customerRepoToken();
    $this->instruments->saveReference($this->gatewayId, $token, 'tok_abc');

    $this->customers->saveAndAttach($this->gatewayId, $token, 'cus_first');
    $this->customers->saveAndAttach($this->gatewayId, $token, 'cus_second');

    expect($this->customers->findByInstrument($this->gatewayId, $token))->toBe('cus_second')
        ->and(Capsule::table('gateway_reference_customer')->count())->toBe(1)
        // The abandoned customer row survives: it still exists at the gateway, and other
        // references may point at it. Detaching a link is not deleting a customer.
        ->and(Capsule::table('gateway_customers')->count())->toBe(2);
});

it('keeps one customer reference per gateway even when the gateways reuse the string', function () {
    // Customer references are only unique inside the provider that issued them, which is why
    // the unique key is composite. Two gateways handing out the same string must produce two
    // independent rows, each resolving for its own gateway and neither for the other's.
    $token = customerRepoToken();
    $otherGateway = GatewayId::generate();

    $this->instruments->saveReference($this->gatewayId, $token, 'tok_here');
    $this->instruments->saveReference($otherGateway, $token, 'tok_there');
    $this->customers->saveAndAttach($this->gatewayId, $token, 'cus_shared');
    $this->customers->saveAndAttach($otherGateway, $token, 'cus_shared');

    expect(Capsule::table('gateway_customers')->count())->toBe(2)
        ->and(Capsule::table('gateway_reference_customer')->count())->toBe(2)
        ->and($this->customers->findByInstrument($this->gatewayId, $token))->toBe('cus_shared')
        ->and($this->customers->findByInstrument($otherGateway, $token))->toBe('cus_shared');
});

it('does not leak a customer across gateways when only one of them attached it', function () {
    // The lookup joins through the customer's own gateway_id. Without that scope a card
    // vaulted at two providers would answer with whichever customer was attached first, and a
    // charge would be sent to one gateway carrying the other's customer reference.
    $token = customerRepoToken();
    $otherGateway = GatewayId::generate();

    $this->instruments->saveReference($this->gatewayId, $token, 'tok_here');
    $this->instruments->saveReference($otherGateway, $token, 'tok_there');
    $this->customers->saveAndAttach($this->gatewayId, $token, 'cus_here');

    expect($this->customers->findByInstrument($otherGateway, $token))->toBeNull()
        ->and($this->customers->findByInstrument($this->gatewayId, $token))->toBe('cus_here');
});

it('does not let a payment method inherit the customer of a token with the same uuid', function () {
    // A TokenId and a PaymentMethodId can hold the same UUID — different aggregates mint
    // them — so the join has to match `referenceable_type` as well. If it did not, an
    // unlinked payment method would silently borrow the token's customer.
    $sharedUuid = Uuid::uuid4()->toString();

    $this->instruments->saveReference($this->gatewayId, customerRepoToken($sharedUuid), 'tok_ref');
    $this->instruments->saveReference($this->gatewayId, customerRepoPaymentMethod($sharedUuid), 'pm_ref');
    $this->customers->saveAndAttach($this->gatewayId, customerRepoToken($sharedUuid), 'cus_token');

    expect($this->customers->findByInstrument($this->gatewayId, customerRepoToken($sharedUuid)))->toBe('cus_token')
        ->and($this->customers->findByInstrument($this->gatewayId, customerRepoPaymentMethod($sharedUuid)))->toBeNull();
});

it('ignores instruments that have no identity to link a customer to', function () {
    // A raw card and cash have no stored id, so there is nothing for the pivot to reference.
    // Both sides short-circuit before touching the database rather than querying or writing
    // on a null id, which would otherwise match any row whose referenceable_id is null.
    expect($this->customers->findByInstrument($this->gatewayId, customerRepoCard()))->toBeNull()
        ->and($this->customers->findByInstrument($this->gatewayId, new Cash))->toBeNull();

    $this->customers->saveAndAttach($this->gatewayId, customerRepoCard(), 'cus_nowhere');

    expect(Capsule::table('gateway_customers')->count())->toBe(0)
        ->and(Capsule::table('gateway_reference_customer')->count())->toBe(0);
});

it('refuses a hosted-payment instrument rather than linking a customer to nothing', function () {
    // Hosted flows keep the card gateway-side, so there is no instrument row here for a
    // customer to hang off. The refusal is typed and loud, matching the instrument
    // repository: answering "no customer" would read as merely unlinked and invite a retry.
    $hosted = new HostedPayment('https://shop.test/ok', 'https://shop.test/cancel');

    expect(fn () => $this->customers->findByInstrument($this->gatewayId, $hosted))
        ->toThrow(RuntimeException::class, 'Hosted-payment instruments are not supported in this context.')
        ->and(fn () => $this->customers->saveAndAttach($this->gatewayId, $hosted, 'cus_x'))
        ->toThrow(RuntimeException::class);
});
