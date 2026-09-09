<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;
use Techork\PaymentService\Common\ValueObject\State;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutCancelled;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutCreated;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentImported;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Laravel\EventSourcing\Serialization\SymfonyPayloadSerializer;
use Techork\PaymentService\Laravel\Serializer\PayloadSerializerFactory;
use Techork\PaymentService\Tests\Support\EventStreamPiiStore;

/**
 * The adapter between EventSauce's payload contract and the Symfony normalizer chain.
 *
 * Four lines of code with an outsized consequence: it normalizes the event **object** rather
 * than calling the event's own `toPayload()`, even though every event in this codebase
 * implements `SerializablePayload`. So the shape on disk is the *property* shape produced by
 * `PropertyNormalizer` walking the whole graph, and the `toPayload()` methods — snake_case,
 * scalar-flattened, carefully written — do not participate in the event stream at all. That is
 * exactly how a value object whose stored shape differs from its property shape
 * ({@see State}) made every event carrying a US address impossible to read back.
 *
 * These tests therefore pin the shape as well as the round-trip. The shape is the contract:
 * rows already written follow it, so a renamed private property on any value object reachable
 * from an event is a breaking change to history, and an assertion on the literal payload is
 * the only place that shows up before a replay does.
 */
function payloadSerializerFor(?EventStreamPiiStore $store = null): SymfonyPayloadSerializer
{
    // The production chain, from the factory the service provider uses. Assembling one here
    // instead is what let a fixed serializer go on being described as broken.
    return new SymfonyPayloadSerializer(PayloadSerializerFactory::make($store ?? new EventStreamPiiStore));
}

function payloadSerializerImportedIntent(?State $state = null): PaymentIntentImported
{
    return new PaymentIntentImported(
        new Money(12345, new Currency('USD')),
        PaymentIntentStatus::Charged,
        HostedPayment::unknown(),
        CaptureMethod::Immediate,
        laravelSuiteCustomer(address: new BillingAddress(
            line: '1 Analytical Way',
            city: 'Juneau',
            country: new Country('US'),
            postalCode: '99801',
            state: $state ?? new State('AK', new Country('US')),
        )),
        new MerchantDescriptor('EXAMPLE STORE'),
        'Imported from a settlement file',
    );
}

it('writes the property shape of the event, not the shape its toPayload returns', function () {
    $event = new CheckoutCreated(
        new Money(5000, new Currency('USD')),
        'A checkout worth serializing',
        'https://merchant.example/return',
        null,
        ['order_ref' => 'ORD-1'],
    );

    // camelCase keys and a nested `{amount, currency: {code}}` for the Money are the tell:
    // `CheckoutCreated::toPayload()` would have produced `callback_url` and flattened the
    // money into `amount` + `currency` scalars. Neither is what the stream holds.
    expect(payloadSerializerFor()->serializePayload($event))->toBe([
        'amount' => ['amount' => '5000', 'currency' => ['code' => 'USD']],
        'description' => 'A checkout worth serializing',
        'callbackUrl' => 'https://merchant.example/return',
        'expiresAt' => null,
        'metadata' => ['order_ref' => 'ORD-1'],
        'plan' => null,
    ]);
});

it('rebuilds the event graph from its own payload', function () {
    $serializer = payloadSerializerFor();
    $event = new CheckoutCreated(
        new Money(5000, new Currency('USD')),
        'A checkout worth serializing',
        'https://merchant.example/return',
        new DateTimeImmutable('2026-03-04T05:06:07+00:00'),
        ['order_ref' => 'ORD-1'],
    );

    $rebuilt = $serializer->unserializePayload(CheckoutCreated::class, $serializer->serializePayload($event));

    // The date matters on its own: `DateTimeNormalizer` sits in the chain ahead of the
    // property walk, and without it an expiry would come back as a bag of Symfony's
    // date properties, i.e. an expired checkout that no longer knows it expired.
    expect($rebuilt)->toBeInstanceOf(CheckoutCreated::class)
        ->and($rebuilt)->toEqual($event)
        ->and($rebuilt->expiresAt?->format(DATE_ATOM))->toBe('2026-03-04T05:06:07+00:00');
});

it('stores timestamps to the second, dropping any finer precision the event carried', function () {
    $serializer = payloadSerializerFor();
    $event = new CheckoutCreated(
        new Money(5000, new Currency('USD')),
        'A checkout with a sub-second expiry',
        null,
        new DateTimeImmutable('2026-03-04T05:06:07.891011+00:00'),
    );

    $payload = $serializer->serializePayload($event);
    $rebuilt = $serializer->unserializePayload(CheckoutCreated::class, $payload);

    // `DateTimeNormalizer` defaults to `DateTimeInterface::RFC3339`, which has no fractional
    // part, so microseconds do not reach the stream and an event does not come back equal to
    // the one that was recorded.
    //
    // Worth stating plainly, because the event disagrees: `CheckoutCreated::toPayload()`
    // formats `expires_at` as `Y-m-d\TH:i:s.uP` — microseconds, deliberately — and so do
    // `SubscriptionActivated` and `SubscriptionRenewed` for their period bounds. The stream
    // never calls `toPayload()`, so that intent has no effect on what is stored. Left as it
    // is because nothing in the domain resolves finer than a second (an expiry is compared
    // against wall-clock now; billing periods are days and months), but the mismatch is real
    // and this is where it is visible rather than inferred.
    expect($payload['expiresAt'])->toBe('2026-03-04T05:06:07+00:00')
        ->and($rebuilt->expiresAt?->format('u'))->toBe('000000')
        ->and($rebuilt)->not->toEqual($event);
});

it('round-trips an event whose address carries a state', function () {
    $serializer = payloadSerializerFor();
    $event = payloadSerializerImportedIntent();

    $payload = $serializer->serializePayload($event);

    // The two-key shape, not the property shape. `State` declares `private ?string $country`
    // while its constructor takes a `?Country`, so the payload PropertyNormalizer used to
    // write could not be fed back through the constructor — that mismatch, on the ordinary US
    // address, is what made these events unreplayable.
    expect($payload['customer']['billingAddress']['state'])->toBe(['state' => 'AK', 'country' => 'US']);

    $rebuilt = $serializer->unserializePayload(PaymentIntentImported::class, $payload);

    expect($rebuilt)->toBeInstanceOf(PaymentIntentImported::class)
        ->and((string) $rebuilt->customer->billingAddress->state)->toBe('AK')
        // The country is stored beside the code because the code alone means nothing: without
        // it the name would come back as `AK` where it used to be `ALASKA`.
        ->and($rebuilt->customer->billingAddress->state?->getCountry())->toBe('US')
        ->and($rebuilt->customer->billingAddress->state?->getName())->toBe('ALASKA')
        ->and($rebuilt)->toEqual($event);
});

it('survives the JSON encoding the message repository stores it through', function () {
    $serializer = payloadSerializerFor();
    $event = payloadSerializerImportedIntent();

    // Normalize and denormalize agreeing with each other is not enough. The payload is written
    // to the `payload` column with `json_encode` and read back with `json_decode($row, true)`,
    // so anything the chain leaves in place that JSON cannot carry — an object, a resource, a
    // non-UTF-8 string — round-trips in memory and dies on replay.
    $throughJson = json_decode(json_encode($serializer->serializePayload($event), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($serializer->unserializePayload(PaymentIntentImported::class, $throughJson))->toEqual($event);
});

it('keeps declared PII out of the payload and resolves it back on read', function () {
    $store = new EventStreamPiiStore;
    $serializer = payloadSerializerFor($store);
    $event = payloadSerializerImportedIntent();

    $payload = $serializer->serializePayload($event);
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

    // The event stream is append-only and can never be rewritten, which is why PII is
    // substituted on the way in rather than erased later. The cardholder's name must not be
    // anywhere in the row — the whole GDPR story depends on the plaintext living only in the
    // side-store that *can* be deleted.
    expect($encoded)->not->toContain('Ada')
        ->and($encoded)->not->toContain('Lovelace')
        ->and($encoded)->not->toContain('1 Analytical Way')
        // Not PII, and needed by AVS and reporting, so it stays legible.
        ->and($encoded)->toContain('Juneau')
        ->and($store->byHash)->not->toBeEmpty();

    $rebuilt = $serializer->unserializePayload(PaymentIntentImported::class, $payload);

    expect($rebuilt->customer->identity->firstName)->toBe('Ada')
        ->and($rebuilt->customer->billingAddress->line)->toBe('1 Analytical Way');
});

it('substitutes the declared stub once the PII has been shredded', function () {
    $store = new EventStreamPiiStore;
    $serializer = payloadSerializerFor($store);

    $payload = $serializer->serializePayload(payloadSerializerImportedIntent());

    // Erasure happens in the side-store, never in the stream. What is pinned is that the event
    // stays readable afterwards: an intent whose cardholder exercised their right to erasure
    // must still replay, or the payment becomes unrefundable as a side effect.
    $store->byHash = [];

    $rebuilt = $serializer->unserializePayload(PaymentIntentImported::class, $payload);

    expect($rebuilt)->toBeInstanceOf(PaymentIntentImported::class)
        ->and($rebuilt->customer->identity->firstName)->not->toBe('Ada')
        ->and($rebuilt->customer->billingAddress->city)->toBe('Juneau');
});

it('denormalizes into the class it is handed', function () {
    $serializer = payloadSerializerFor();

    // The class name is an argument, not something read out of the payload: EventSauce
    // resolves it from the `__event_type` header through the inflector. An event with an empty
    // payload — and there are several — has nothing in it to identify itself with, so the
    // header is the only thing that decides what gets built.
    expect($serializer->serializePayload(new CheckoutCancelled))->toBe([])
        ->and($serializer->unserializePayload(CheckoutCancelled::class, []))
        ->toBeInstanceOf(CheckoutCancelled::class);
});

/**
 * A {@see CustomerIdentity} through the production chain.
 *
 * It is the densest value-object graph the chain has to carry: two strings, an {@see Email} and
 * a {@see PhoneNumber}, every one of them `#[Pii]`. The phone is the reason this is asserted
 * rather than assumed — `PhoneNumber` holds a `libphonenumber\PhoneNumber` that no constructor
 * can rebuild from its properties, which is what
 * {@see \Techork\PaymentService\Laravel\Serializer\PhoneNumberNormalizer} exists for, and
 * `State` taught this codebase that such a value object is unreplayable until something in the
 * chain knows about it. So these go through the chain the service provider builds, not a copy.
 *
 * The payload is declared here rather than being a domain event because no domain event carries
 * an identity any more — the customer is not event-sourced in this package, and the application
 * that does source one uses this chain. That makes the fixture the *only* place the phone's
 * round-trip is exercised, which is why it is kept rather than deleted with the aggregate: a
 * normalizer nothing tests is a normalizer that can be dropped from the chain unnoticed, and the
 * host's customer stream is what would find out.
 */
final readonly class PayloadSerializerIdentityPayload
{
    public function __construct(public CustomerIdentity $identity) {}
}

function payloadSerializerIdentity(): CustomerIdentity
{
    return new CustomerIdentity(
        firstName: 'Ada',
        lastName: 'Lovelace',
        email: new Email('ada@example.com'),
        phone: new PhoneNumber('+12025550123'),
    );
}

it('round-trips an identity through the production chain', function () {
    $serializer = payloadSerializerFor();
    $event = new PayloadSerializerIdentityPayload(payloadSerializerIdentity());

    // Through JSON, because that is what the `payload` column holds: a `libphonenumber`
    // object surviving in memory and dying on `json_decode` is precisely the failure mode
    // a dedicated normalizer removes.
    $throughJson = json_decode(json_encode($serializer->serializePayload($event), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($serializer->unserializePayload(PayloadSerializerIdentityPayload::class, $throughJson))->toEqual($event);
});

it('writes the identity as four hashes and nothing legible', function () {
    $store = new EventStreamPiiStore;
    $serializer = payloadSerializerFor($store);

    $payload = $serializer->serializePayload(new PayloadSerializerIdentityPayload(payloadSerializerIdentity()));
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

    // Unlike a `BillingAddress`, an identity has no non-PII field: there is no city here to
    // stay legible for AVS, so every one of the four keys must be a hash. The shape is pinned
    // because rows already written follow it — the phone in particular must be a single hash
    // rather than the nested `{number: {countryCode: …}}` its property shape would give.
    expect(array_keys($payload['identity']))->toBe(['firstName', 'lastName', 'email', 'phone'])
        ->and($payload['identity'])->each->toBeString()
        ->and($encoded)->not->toContain('Ada')
        ->and($encoded)->not->toContain('Lovelace')
        ->and($encoded)->not->toContain('ada@example.com')
        ->and($encoded)->not->toContain('2025550123')
        ->and($store->byHash)->toHaveCount(4);

    $rebuilt = $serializer->unserializePayload(PayloadSerializerIdentityPayload::class, $payload);

    expect($rebuilt->identity->firstName)->toBe('Ada')
        ->and((string) $rebuilt->identity->email)->toBe('ada@example.com');
});

it('leaves a shredded identity replayable with stubs in its place', function () {
    $store = new EventStreamPiiStore;
    $serializer = payloadSerializerFor($store);

    $payload = $serializer->serializePayload(new PayloadSerializerIdentityPayload(payloadSerializerIdentity()));

    // What erasure promises: the identity is gone and the stream still reads. If the phone's
    // stub could not be rebuilt, replaying a forgotten customer would throw and erasure would
    // take the stream with it.
    $store->byHash = [];

    $rebuilt = $serializer->unserializePayload(PayloadSerializerIdentityPayload::class, $payload);

    expect($rebuilt->identity->firstName)->toBe(ShreddingStubs::NAME)
        ->and((string) $rebuilt->identity->email)->toBe(ShreddingStubs::EMAIL)
        ->and((string) $rebuilt->identity->phone)->toBe(ShreddingStubs::PHONE);
});
