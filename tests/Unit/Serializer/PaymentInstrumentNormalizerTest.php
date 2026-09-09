<?php

declare(strict_types=1);

use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Mapping\Loader\LoaderChain;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Symfony\Component\Serializer\Serializer;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Address;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\State;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Laravel\Serializer\PayloadSerializerFactory;
use Techork\PaymentService\Laravel\Serializer\PaymentInstrumentNormalizer;
use Techork\PaymentService\Laravel\Serializer\StateNormalizer;
use Techork\PaymentService\Laravel\Serializer\PhoneNumberNormalizer;
use Techork\PaymentService\Laravel\Serializer\PiiAttributeLoader;
use Techork\PaymentService\Laravel\Serializer\PiiAwareObjectNormalizer;
use Techork\PaymentService\Laravel\Serializer\UuidNormalizer;
use Techork\PaymentService\Laravel\Shredding\PiiStore;
use Techork\PaymentService\Common\ValueObject\AttachedPaymentMethod;

/**
 * Records what the PII pipeline wrote so the instrument tests can prove the
 * visitor really delegates to it (a hash landed in the payload) and can then
 * shred a key to check the stub path.
 */
final class InstrumentNormalizerTestStore implements PiiStore
{
    /** @var array<string, string> */
    public array $byHash = [];

    public function store(string $plaintext): string
    {
        $hash = hash('sha256', $plaintext);
        $this->byHash[$hash] = $plaintext;

        return $hash;
    }

    public function retrieve(string $hash): ?string
    {
        return $this->byHash[$hash] ?? null;
    }

    public function forget(string $hash): void
    {
        unset($this->byHash[$hash]);
    }
}

/**
 * The chain `GatewayServiceProvider` registers, restricted to the normalizers an
 * instrument can reach. `PropertyNormalizer` (not `ObjectNormalizer`) and the
 * `ReflectionExtractor` are both copied from the provider deliberately: the card
 * VOs keep their state private, and without the extractor the inner normalizer
 * never learns a private property's declared type and so never delegates it back
 * to this chain.
 *
 * `$instrumentFirst: false` reproduces the ordering mistake the provider's comment
 * warns about — `PiiAwareObjectNormalizer` supports every object and every existing
 * type, so ahead of the resolver it wins both lookups.
 */
function instrumentNormalizerSerializer(?PiiStore $store = null, bool $instrumentFirst = true): Serializer
{
    // The default is the production chain itself, asked of the factory the provider uses, so
    // a normalizer added there cannot be missing from what these tests exercise. Rebuilding
    // it by hand is what let a fixed serializer go on being described as broken here.
    if ($instrumentFirst) {
        return PayloadSerializerFactory::make($store ?? new InstrumentNormalizerTestStore);
    }

    // Hand-assembled only for the mis-ordering the factory deliberately cannot express.
    $piiAware = instrumentNormalizerPiiAware($store);

    return new Serializer([
        new UuidNormalizer,
        new PhoneNumberNormalizer,
        new StateNormalizer,
        new BackedEnumNormalizer,
        new DateTimeNormalizer,
        new ArrayDenormalizer,
        $piiAware,
        new PaymentInstrumentNormalizer($piiAware),
    ]);
}

function instrumentNormalizerPiiAware(?PiiStore $store = null): PiiAwareObjectNormalizer
{
    $metadataFactory = new ClassMetadataFactory(
        new LoaderChain([new AttributeLoader, new PiiAttributeLoader]),
    );

    return new PiiAwareObjectNormalizer(
        new PropertyNormalizer(
            classMetadataFactory: $metadataFactory,
            propertyTypeExtractor: new ReflectionExtractor,
        ),
        $store ?? new InstrumentNormalizerTestStore,
        $metadataFactory,
    );
}

/**
 * A card with every optional field populated except the AVS address' state, which
 * the chain cannot round-trip today — see the characterization test at the bottom
 * of this file for why it is left out here.
 */
function instrumentNormalizerCard(?State $state = null): CreditCard
{
    return new CreditCard(
        number: new Number('411111', '1111', CardBrand::Visa),
        expiration: Expiration::fromMonthAndYear(11, 2031),
        holder: new Holder('JOHN Q PUBLIC'),
        cvc: new Cvc,
        address: new Address(
            city: 'Anchorage',
            country: new Country('US'),
            postalCode: '99501',
            line: '1 Main St',
            lineExtra: 'Apt 4',
            state: $state,
        ),
        addressLineCheck: CheckResult::Pass,
        postalCodeCheck: CheckResult::Pass,
        cvcCheck: CheckResult::Unavailable,
    );
}

function instrumentNormalizerBillingAddress(?State $state = null): BillingAddress
{
    return new BillingAddress(
        line: '1 Main St',
        city: 'Anchorage',
        country: new Country('US'),
        postalCode: '99501',
        lineExtra: 'Apt 4',
        state: $state,
    );
}

// ─────────────────────────────────────────────────────────
//  Round trip — every PaymentInstrument the repo supports
// ─────────────────────────────────────────────────────────

it('round-trips a fully populated CreditCard', function () {
    $serializer = instrumentNormalizerSerializer();
    $original = instrumentNormalizerCard();

    $rebuilt = $serializer->denormalize($serializer->normalize($original), PaymentInstrument::class);

    expect($rebuilt)->toBeInstanceOf(CreditCard::class)
        ->and($rebuilt)->toEqual($original);
});

it('round-trips a CreditCard with no address and default check results', function () {
    // The minimum a card can be: nullable address absent, every AVS/CVC check left
    // at its Unchecked default. A null address must come back null rather than as an
    // Address of empty strings.
    $serializer = instrumentNormalizerSerializer();
    $original = new CreditCard(
        new Number('555555', '4444', CardBrand::Mastercard),
        Expiration::fromMonthAndYear(1, 2030),
        new Holder('JANE ROE'),
        new Cvc,
    );

    $rebuilt = $serializer->denormalize($serializer->normalize($original), PaymentInstrument::class);

    expect($rebuilt)->toEqual($original)
        ->and($rebuilt->address)->toBeNull()
        ->and($rebuilt->cvcCheck)->toBe(CheckResult::Unchecked);
});

it('round-trips Cash, which has no state at all', function () {
    // A stateless instrument normalizes to nothing but its discriminator; the
    // denormalize side has to cope with a payload whose only key is `type`, which
    // is not a property of the class.
    $serializer = instrumentNormalizerSerializer();

    $payload = $serializer->normalize(new Cash);
    $rebuilt = $serializer->denormalize($payload, PaymentInstrument::class);

    expect($payload)->toBe(['type' => 'cash'])
        ->and($rebuilt)->toBeInstanceOf(Cash::class)
        ->and($rebuilt)->toEqual(new Cash);
});

it('round-trips a HostedPayment', function () {
    $serializer = instrumentNormalizerSerializer();
    $original = new HostedPayment(
        successUrl: 'https://merchant.test/return/ok',
        cancelUrl: 'https://merchant.test/return/cancel',
    );

    expect($serializer->denormalize($serializer->normalize($original), PaymentInstrument::class))
        ->toEqual($original);
});

it('round-trips the unknown() HostedPayment used for imported intents', function () {
    // Blank URLs are the honest marker for an intent we did not open; they must not
    // be normalized away, because isValid() reporting false is the point.
    $serializer = instrumentNormalizerSerializer();

    $rebuilt = $serializer->denormalize(
        $serializer->normalize(HostedPayment::unknown()),
        PaymentInstrument::class,
    );

    expect($rebuilt)->toEqual(HostedPayment::unknown())
        ->and($rebuilt->isValid())->toBeFalse();
});

it('round-trips a Token wrapping a CreditCard through the nested interface slot', function () {
    // The reason the normalizer exists. `Token::$instrument` is typed as the bare
    // interface, so the inner reflection-based normalizer cannot rebuild it: the
    // resolver has to be re-entered for the nested value.
    $serializer = instrumentNormalizerSerializer();
    $original = new Token(
        id: TokenId::fromString('0192b1d0-8f2a-7c3e-9a1b-2c3d4e5f6071'),
        instrument: instrumentNormalizerCard(),
        expiresAt: ExpiresAt::fromString('2031-11-30T23:59:59+00:00'),
    );

    $rebuilt = $serializer->denormalize($serializer->normalize($original), PaymentInstrument::class);

    expect($rebuilt)->toBeInstanceOf(Token::class)
        ->and($rebuilt->instrument)->toBeInstanceOf(CreditCard::class)
        ->and($rebuilt)->toEqual($original);
});

it('round-trips a PaymentMethod wrapping a CreditCard and a full BillingAddress', function () {
    // Two levels of the concern at once: a nested interface-typed instrument plus a
    // billing address whose every identifying field is `#[Pii]`.
    $serializer = instrumentNormalizerSerializer();
    $original = new PaymentMethod(
        id: PaymentMethodId::fromString('0192b1d0-8f2a-7c3e-9a1b-2c3d4e5f6072'),
        instrument: instrumentNormalizerCard(),
    );

    $rebuilt = $serializer->denormalize($serializer->normalize($original), PaymentInstrument::class);

    expect($rebuilt)->toBeInstanceOf(PaymentMethod::class)
        ->and($rebuilt)->toEqual($original);
});

it('round-trips a Token wrapping Cash, the degenerate nested case', function () {
    // Nested instrument with no state: the inner payload is just its discriminator,
    // which still has to be enough to pick the class back out.
    $serializer = instrumentNormalizerSerializer();
    $original = new Token(
        TokenId::fromString('0192b1d0-8f2a-7c3e-9a1b-2c3d4e5f6073'),
        new Cash,
        ExpiresAt::fromString('2031-11-30T23:59:59+00:00'),
    );

    expect($serializer->denormalize($serializer->normalize($original), PaymentInstrument::class))
        ->toEqual($original);
});

// ─────────────────────────────────────────────────────────
//  Wire format: the discriminator comes from ::type()
// ─────────────────────────────────────────────────────────

it('stamps each instrument with the discriminator its class publishes', function () {
    // These tokens are already in stored rows, and the normalizer reads them back
    // from the same `::type()` accessors — pinning the literals keeps a rename from
    // silently orphaning history.
    $serializer = instrumentNormalizerSerializer();

    expect($serializer->normalize(instrumentNormalizerCard())['type'])->toBe('card')
        ->and($serializer->normalize(new Cash)['type'])->toBe('cash')
        ->and($serializer->normalize(new HostedPayment('https://a.test', 'https://b.test'))['type'])->toBe('hosted')
        ->and(CreditCard::type())->toBe('card')
        ->and(Token::type())->toBe('token')
        ->and(PaymentMethod::type())->toBe('payment_method');
});

it('stamps the nested instrument with its own discriminator, not the wrapper\'s', function () {
    $payload = instrumentNormalizerSerializer()->normalize(new Token(
        TokenId::generate(),
        new HostedPayment('https://a.test', 'https://b.test'),
        ExpiresAt::fromString('2031-11-30T23:59:59+00:00'),
    ));

    expect($payload['type'])->toBe('token')
        ->and($payload['instrument']['type'])->toBe('hosted');
});

// ─────────────────────────────────────────────────────────
//  The PII pipeline is reached through the visitor
// ─────────────────────────────────────────────────────────

it('sends the cardholder name through the PII store instead of the payload', function () {
    // Proves the visitor delegates to PiiAwareObjectNormalizer rather than
    // normalizing on its own — the class docblock's whole justification.
    $store = new InstrumentNormalizerTestStore;
    $payload = instrumentNormalizerSerializer($store)->normalize(instrumentNormalizerCard());

    expect($payload['holder'])->toBeString()
        ->and($payload['holder'])->not->toContain('JOHN')
        ->and($store->byHash)->toHaveKey($payload['holder']);
});

it('substitutes the holder stub when the PII key has been shredded', function () {
    // Post-erasure replay: the card must still resolve, with the redaction sentinel
    // in place of the name, rather than failing to denormalize.
    $store = new InstrumentNormalizerTestStore;
    $serializer = instrumentNormalizerSerializer($store);
    $payload = $serializer->normalize(instrumentNormalizerCard());

    $store->forget($payload['holder']);
    $rebuilt = $serializer->denormalize($payload, PaymentInstrument::class);

    expect((string) $rebuilt->holder)->toBe(ShreddingStubs::NAME)
        ->and($rebuilt->number->last4)->toBe('1111');
});

it('shreds the attached customer independently of the card', function () {
    // The two PII sets live on different classes reached through different code paths; erasing
    // one must not disturb the other.
    //
    // The customer's identity in place of the payment method's own address, which is where the
    // payer's name used to be. That is the point of the test rather than an adjustment to it: the
    // name and the cardholder were two `#[Pii]` sets on one instrument, and they are now two sets
    // on two objects a payment carries — still independently erasable, still reached differently.
    $store = new InstrumentNormalizerTestStore;
    $serializer = instrumentNormalizerSerializer($store);
    $payload = $serializer->normalize(new AttachedPaymentMethod(
        laravelSuiteCustomer(firstName: 'John', lastName: 'Public'),
        new PaymentMethod(PaymentMethodId::generate(), instrumentNormalizerCard()),
    ));

    $store->forget($payload['customer']['identity']['firstName']);
    $rebuilt = $serializer->denormalize($payload, PaymentInstrument::class);

    expect($rebuilt->customer->identity->firstName)->toBe(ShreddingStubs::NAME)
        ->and($rebuilt->customer->identity->lastName)->toBe('Public')
        ->and((string) $rebuilt->paymentMethod->instrument->holder)->toBe('JOHN Q PUBLIC');
});

// ─────────────────────────────────────────────────────────
//  Denormalize failure modes
// ─────────────────────────────────────────────────────────

it('refuses to denormalize an instrument payload with no type key', function () {
    expect(fn () => instrumentNormalizerSerializer()->denormalize(
        ['last4' => '1111'],
        PaymentInstrument::class,
    ))->toThrow(InvalidArgumentException::class, 'missing or unknown "type" key (NULL)');
});

it('refuses to denormalize an instrument payload whose type is unknown', function () {
    // A discriminator from a package that is not installed, or a typo in a
    // hand-written row: both have to fail loudly rather than resolve to a default.
    expect(fn () => instrumentNormalizerSerializer()->denormalize(
        ['type' => 'bank_account'],
        PaymentInstrument::class,
    ))->toThrow(InvalidArgumentException::class, "missing or unknown \"type\" key ('bank_account')");
});

// ─────────────────────────────────────────────────────────
//  supports* — what decides whether we are consulted
// ─────────────────────────────────────────────────────────

it('claims normalization for payment instruments only', function () {
    // A Challenge is an unrelated interface handled by its own resolver in the same
    // chain; claiming it here would send it through the wrong visitor.
    $normalizer = new PaymentInstrumentNormalizer(instrumentNormalizerPiiAware());

    expect($normalizer->supportsNormalization(new Cash))->toBeTrue()
        ->and($normalizer->supportsNormalization(instrumentNormalizerCard()))->toBeTrue()
        ->and($normalizer->supportsNormalization(new ThreeDSChallenge('txn-1', 'https://acs.test/step')))->toBeFalse()
        ->and($normalizer->supportsNormalization(instrumentNormalizerBillingAddress()))->toBeFalse()
        ->and($normalizer->supportsNormalization(['type' => 'card']))->toBeFalse()
        ->and($normalizer->supportsNormalization(null))->toBeFalse();
});

it('claims denormalization for the PaymentInstrument interface and its implementations only', function () {
    $normalizer = new PaymentInstrumentNormalizer(instrumentNormalizerPiiAware());

    expect($normalizer->supportsDenormalization([], PaymentInstrument::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization([], CreditCard::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization([], Cash::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization([], Token::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization([], PaymentMethod::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization([], HostedPayment::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization([], Holder::class))->toBeFalse()
        ->and($normalizer->supportsDenormalization([], stdClass::class))->toBeFalse()
        ->and($normalizer->supportsDenormalization([], 'Not\\A\\Class'))->toBeFalse();
});

it('advertises the PaymentInstrument interface as its supported type', function () {
    expect((new PaymentInstrumentNormalizer(instrumentNormalizerPiiAware()))->getSupportedTypes(null))
        ->toBe([PaymentInstrument::class => true]);
});

// ─────────────────────────────────────────────────────────
//  Chain position
// ─────────────────────────────────────────────────────────

it('loses the discriminator when the PII normalizer is placed first', function () {
    // PiiAwareObjectNormalizer answers true for any object and any existing type, so
    // ahead of the resolver it wins both lookups: no `type` is stamped on the way out
    // and the bare interface is handed to reflection on the way in. This is why the
    // provider lists the interface resolvers before it.
    $serializer = instrumentNormalizerSerializer(instrumentFirst: false);
    $payload = $serializer->normalize(instrumentNormalizerCard());

    expect($payload)->not->toHaveKey('type');
    expect(fn () => $serializer->denormalize($payload, PaymentInstrument::class))
        ->toThrow(NotNormalizableValueException::class);
});

it('resolves the interface that the inner normalizer cannot instantiate', function () {
    // Handed `PaymentInstrument::class` directly, the reflection-based normalizer
    // tries to instantiate the interface itself; through the resolver the same
    // payload becomes a concrete instrument.
    $payload = ['type' => 'hosted', 'successUrl' => 'https://a.test', 'cancelUrl' => 'https://b.test'];

    expect(fn () => instrumentNormalizerPiiAware()->denormalize($payload, PaymentInstrument::class))
        ->toThrow(NotNormalizableValueException::class, 'is not instantiable');

    expect(instrumentNormalizerSerializer()->denormalize($payload, PaymentInstrument::class))
        ->toEqual(new HostedPayment('https://a.test', 'https://b.test'));
});

// ─────────────────────────────────────────────────────────
//  A state in the address, which used to make the whole instrument unreadable
// ─────────────────────────────────────────────────────────

it('round-trips a card whose AVS address carries a state', function () {
    // Was a characterization test for a defect that made this impossible: `State` keeps its
    // country as `private ?string` while its constructor takes `?Country`, so the reflection
    // normalizer wrote the scalar 'US' on the way out and could not rebuild a Country from it
    // on the way in. Every stored instrument with a state failed to replay, which is the
    // ordinary US, Canadian, Australian, British, Indian or New Zealand address.
    //
    // StateNormalizer now stores the code with the country that gives it meaning. The payload
    // is asserted alongside the round trip because the stored shape is the part a future
    // change could quietly alter while the round trip still passed.
    $serializer = instrumentNormalizerSerializer();
    $original = instrumentNormalizerCard(new State('AK', new Country('US')));

    $payload = $serializer->normalize($original);
    $rebuilt = $serializer->denormalize($payload, PaymentInstrument::class);

    expect($payload['address']['state'])->toBe(['state' => 'AK', 'country' => 'US'])
        ->and($rebuilt)->toBeInstanceOf(CreditCard::class)
        ->and($rebuilt)->toEqual($original);
});

it('reads back a state written in the shape the reflection normalizer used to produce', function () {
    // The rows already in dev and stage carry `{"name":…,"country":…,"state":…}`. Denormalize
    // has to keep accepting that, or fixing the write path would strand every event written
    // before it.
    $serializer = instrumentNormalizerSerializer();
    $payload = $serializer->normalize(instrumentNormalizerCard(new State('AK', new Country('US'))));
    $payload['address']['state'] = ['name' => 'ALASKA', 'country' => 'US', 'state' => 'AK'];

    $rebuilt = $serializer->denormalize($payload, PaymentInstrument::class);

    expect($rebuilt->address?->state)->toEqual(new State('AK', new Country('US')));
});

it('round-trips an attached payment method whose billing address carries a state', function () {
    // The same path through the other address VO, kept separate because BillingAddress is the
    // one every non-imported intent carries — so this is the reach of the defect, not a
    // variation on it.
    //
    // Through an `AttachedPaymentMethod` because that is where an address reaches an instrument
    // now: a bare `PaymentMethod` holds none, and the customer inside this one is also what puts
    // a customer id on the graph, which is one more `UuidValueObject` for the chain to carry.
    $serializer = instrumentNormalizerSerializer();
    $original = new AttachedPaymentMethod(
        laravelSuiteCustomer(address: new BillingAddress(
            line: '1 Analytical Way',
            city: 'Juneau',
            country: new Country('US'),
            postalCode: '99801',
            state: new State('AK', new Country('US')),
        )),
        new PaymentMethod(PaymentMethodId::generate(), instrumentNormalizerCard()),
    );

    $payload = $serializer->normalize($original);
    $rebuilt = $serializer->denormalize($payload, PaymentInstrument::class);

    expect($payload['customer']['billingAddress']['state'])->toBe(['state' => 'AK', 'country' => 'US'])
        // `{uuid: …}`, the property shape every `UuidValueObject` in this chain is stored as —
        // `UuidNormalizer` reduces the Ramsey object inside and `PropertyNormalizer` rebuilds the
        // wrapper around it. Pinned because it is a stored contract, and because a customer id
        // briefly had a normalizer of its own that flattened it to a bare string: that was needed
        // while the slot was an interface `PropertyNormalizer` could not instantiate, and the
        // shape here is the evidence the interface is gone.
        ->and($payload['customer']['id'])->toBe(['uuid' => laravelSuiteCustomerId()->toString()])
        ->and($rebuilt)->toEqual($original);
});
