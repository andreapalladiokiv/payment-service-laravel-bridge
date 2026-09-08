<?php

declare(strict_types=1);

use EventSauce\EventSourcing\Header;
use EventSauce\EventSourcing\Message;
use EventSauce\EventSourcing\MessageDispatcher;
use EventSauce\EventSourcing\MessageRepository;
use EventSauce\EventSourcing\Snapshotting\SnapshotRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Log\Context\Repository as ContextRepository;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\State;
use Techork\PaymentService\Domain\Checkout\CheckoutAggregate;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutCancelled;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutCreated;
use Techork\PaymentService\Domain\Checkout\ValueObject\CheckoutId;
use Techork\PaymentService\Domain\Customer\CustomerAggregate;
use Techork\PaymentService\Domain\Customer\CustomerStatus;
use Techork\PaymentService\Domain\Customer\Event\CustomerRegistered;
use Techork\PaymentService\Domain\Customer\ValueObject\CustomerId;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentImported;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Domain\Subscription\Event\SubscriptionCreated;
use Techork\PaymentService\Domain\Subscription\SubscriptionAggregate;
use Techork\PaymentService\Domain\Subscription\SubscriptionStatus;
use Techork\PaymentService\Domain\Subscription\ValueObject\BillingInterval;
use Techork\PaymentService\Domain\Subscription\ValueObject\BillingPeriod;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionPlan;
use Techork\PaymentService\Laravel\EventSourcing\Decorators\GatewayIdMessageDecorator;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\CheckoutAggregateRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\CustomerAggregateRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\IlluminateMessageRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\IlluminateSnapshotRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\PaymentIntentAggregateRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\SubscriptionAggregateRepository;
use Techork\PaymentService\Tests\Support\EventStreamDatabase;

/**
 * The four aggregate repositories, over the real message and snapshot repositories.
 *
 * Each of them is the same handful of lines four times: an `EventSourcedAggregateRootRepository`
 * for the write path, plus a `ConstructingAggregateRootRepositoryWithSnapshotting` built beside
 * it for the read path. Wiring duplicated per aggregate is wiring that can be wrong for one
 * aggregate and right for the others, so every assertion below is made for all four rather
 * than for whichever one was convenient — a decorator handed to the parent but not to the
 * inner repository, or a snapshot repository connected for `PaymentIntent` and forgotten for
 * `Subscription`, is invisible until it is a stream that will not replay.
 *
 * These run against SQLite and the production normalizer chain rather than against test
 * doubles, because the thing worth knowing is not that the classes call each other — it is
 * that an aggregate written through one comes back out of the other as itself.
 */

/**
 * @return array{0: ConnectionInterface, 1: MessageRepository, 2: SnapshotRepository}
 */
function aggregateRepoWiring(): array
{
    $connection = EventStreamDatabase::connection();

    return [
        $connection,
        new IlluminateMessageRepository($connection, 'stored_events', EventStreamDatabase::messageSerializer()),
        new IlluminateSnapshotRepository($connection),
    ];
}

function aggregateRepoCustomerRegistered(): CustomerRegistered
{
    return new CustomerRegistered(
        // An email, so the payload that crosses the serializer carries a `#[Pii]` field: this
        // stream is the one whose whole payload is a person.
        new CustomerIdentity('Ada', 'Lovelace', new Email('ada@example.test')),
    );
}

function aggregateRepoCheckoutCreated(): CheckoutCreated
{
    return new CheckoutCreated(
        new Money(5000, new Currency('USD')),
        'A checkout worth replaying',
        'https://merchant.example/return',
        null,
        ['order_ref' => 'ORD-1'],
    );
}

/**
 * An imported intent billed to a US address, which is the shape that used to be unreplayable:
 * `State` stores a country while its constructor takes a `Country`, so before
 * {@see \Techork\PaymentService\Laravel\Serializer\StateNormalizer} joined the chain every
 * event carrying one died on the way back with a missing-constructor-argument error.
 */
function aggregateRepoImportedIntent(): PaymentIntentImported
{
    return new PaymentIntentImported(
        new Money(12345, new Currency('USD')),
        PaymentIntentStatus::Charged,
        HostedPayment::unknown(),
        CaptureMethod::Immediate,
        new BillingAddress(
            firstName: 'Ada',
            lastName: 'Lovelace',
            line: '1 Analytical Way',
            city: 'Juneau',
            country: new Country('US'),
            postalCode: '99801',
            state: new State('AK', new Country('US')),
        ),
        new MerchantDescriptor('EXAMPLE STORE'),
        'Imported from a settlement file',
    );
}

/**
 * The reconstituted state of a checkout, read through its own snapshot.
 *
 * `CheckoutAggregate` exposes no getters — its state is private and only leaves through
 * `createSnapshotState()` — so snapshotting it and reading the row back is the one way to see
 * what a rehydration produced without reaching into the object with reflection. It also means
 * these assertions go through a second real repository rather than around it.
 *
 * @return array<string, mixed>
 */
function aggregateRepoSnapshotStateOf(
    CheckoutAggregateRepository $repository,
    ConnectionInterface $connection,
    CheckoutAggregate $checkout,
): array {
    $repository->storeSnapshot($checkout);

    /** @var string $state */
    $state = $connection->table('aggregate_snapshots')
        ->where('aggregate_root_id', $checkout->aggregateRootId()->toString())
        ->value('state');

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($state, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

function aggregateRepoSubscriptionCreated(): SubscriptionCreated
{
    return new SubscriptionCreated(
        new SubscriptionPlan(new Money(2999, new Currency('USD')), new BillingInterval(1, BillingPeriod::Month)),
        PaymentMethodId::generate(),
        'https://merchant.example/subscription',
        ['tier' => 'pro'],
    );
}

it('hands back a Checkout aggregate carrying the state its events recorded', function () {
    [$connection, $messages, $snapshots] = aggregateRepoWiring();
    $repository = new CheckoutAggregateRepository($messages, $snapshots);
    $id = CheckoutId::generate();

    $repository->persistEvents($id, 1, aggregateRepoCheckoutCreated());

    $checkout = $repository->retrieve($id);

    // The concrete class, not the interface EventSauce's snapshotting repository is typed to
    // return — the whole reason `retrieve()` is overridden here is so callers get the
    // aggregate they asked for without asserting on it themselves.
    expect($checkout)->toBeInstanceOf(CheckoutAggregate::class)
        ->and($checkout->aggregateRootId()->toString())->toBe($id->toString())
        ->and($checkout->aggregateRootVersion())->toBe(1)
        ->and(aggregateRepoSnapshotStateOf($repository, $connection, $checkout))->toBe([
            'status' => 'pending',
            'amount' => '5000',
            'currency' => 'USD',
            'description' => 'A checkout worth replaying',
            'callback_url' => 'https://merchant.example/return',
            'expires_at' => null,
            'metadata' => ['order_ref' => 'ORD-1'],
            'plan' => null,
        ]);
});

it('hands back a PaymentIntent aggregate including a US billing address', function () {
    [, $messages, $snapshots] = aggregateRepoWiring();
    $repository = new PaymentIntentAggregateRepository($messages, $snapshots);
    $id = PaymentIntentId::generate();

    $repository->persistEvents($id, 1, aggregateRepoImportedIntent());

    $intent = $repository->retrieve($id);

    expect($intent)->toBeInstanceOf(PaymentIntentAggregate::class)
        ->and($intent->aggregateRootId()->toString())->toBe($id->toString())
        ->and($intent->aggregateRootVersion())->toBe(1)
        ->and($intent->status())->toBe(PaymentIntentStatus::Charged)
        ->and($intent->amount())->toEqual(new Money(12345, new Currency('USD')))
        ->and($intent->description())->toBe('Imported from a settlement file')
        ->and((string) $intent->merchantDescriptor())->toBe('EXAMPLE STORE')
        // The state, through the whole stack this time. Its own normalizer is unit-tested;
        // what is pinned here is that the chain the repository actually gets is the one with
        // that normalizer in it, so the defect cannot come back by way of the wiring.
        ->and((string) $intent->billingAddress()->state)->toBe('AK')
        ->and($intent->billingAddress()->state?->getCountry())->toBe('US')
        // PII goes to the side-store as a hash and is resolved back on read, so a name that
        // survives the round-trip is also proof the shredding pipeline ran and reversed.
        ->and($intent->billingAddress()->firstName)->toBe('Ada')
        ->and($intent->billingAddress()->city)->toBe('Juneau');
});

it('hands back a Subscription aggregate carrying its plan', function () {
    [, $messages, $snapshots] = aggregateRepoWiring();
    $repository = new SubscriptionAggregateRepository($messages, $snapshots);
    $id = SubscriptionId::generate();

    $repository->persistEvents($id, 1, aggregateRepoSubscriptionCreated());

    $subscription = $repository->retrieve($id);

    expect($subscription)->toBeInstanceOf(SubscriptionAggregate::class)
        ->and($subscription->aggregateRootId()->toString())->toBe($id->toString())
        ->and($subscription->aggregateRootVersion())->toBe(1)
        ->and($subscription->status())->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->amount())->toEqual(new Money(2999, new Currency('USD')));
});

/**
 * The Customer stream is the one whose entire payload is a person, so the assertion that matters
 * is not only that it replays — it is that the email did not land in the table in the clear.
 *
 * The `#[Pii]` markings on {@see CustomerIdentity} do their work through the serializer, which
 * normalizes the *event object* rather than calling `toPayload()` — so nothing had to register
 * this aggregate anywhere for shredding to apply, and equally nothing would have said so if the
 * chain had missed it. `PiiAwareObjectNormalizerTest` proves the mechanism on fixtures; this
 * proves it on the payload that actually carries a name.
 */
it('hands back a Customer aggregate whose identity never touched the table in the clear', function () {
    [$connection, $messages, $snapshots] = aggregateRepoWiring();
    $repository = new CustomerAggregateRepository($messages, $snapshots);
    $id = CustomerId::generate();

    $repository->persistEvents($id, 1, aggregateRepoCustomerRegistered());

    $customer = $repository->retrieve($id);
    $stored = (string) $connection->table('stored_events')->value('payload');
    /** @var array{payload: array{identity: array<string, ?string>}} $decoded */
    $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
    $written = $decoded['payload']['identity'];

    expect($customer)->toBeInstanceOf(CustomerAggregate::class)
        ->and($customer->aggregateRootId()->toString())->toBe($id->toString())
        ->and($customer->aggregateRootVersion())->toBe(1)
        ->and($customer->identity()->firstName)->toBe('Ada')
        ->and((string) $customer->identity()->email)->toBe('ada@example.test')
        ->and($customer->status())->toBe(CustomerStatus::Active);

    // Round-tripped above, and yet none of it is in the table: each field is the sha256 the
    // store hands back plaintext for, and erasure drops that row. `phone` stays null rather
    // than becoming the hash of an empty string — an absent field is not a shredded one.
    expect($stored)->not->toContain('ada@example.test')
        ->and($stored)->not->toContain('Lovelace')
        ->and($written['firstName'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($written['lastName'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($written['email'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($written['phone'])->toBeNull();
});

it('answers an id with no history with a fresh aggregate at version zero', function (string $repositoryClass, callable $newId, string $aggregateClass) {
    [, $messages, $snapshots] = aggregateRepoWiring();
    /** @var object $repository */
    $repository = new $repositoryClass($messages, $snapshots);

    $aggregate = $repository->retrieve($newId());

    // Not null and not an exception. Creating an aggregate is "retrieve, then record", so an
    // unknown id has to produce something recordable — version 0 is what makes the first
    // event land at version 1.
    expect($aggregate)->toBeInstanceOf($aggregateClass)
        ->and($aggregate->aggregateRootVersion())->toBe(0);
})->with([
    'checkout' => [CheckoutAggregateRepository::class, fn () => CheckoutId::generate(), CheckoutAggregate::class],
    'payment intent' => [PaymentIntentAggregateRepository::class, fn () => PaymentIntentId::generate(), PaymentIntentAggregate::class],
    'subscription' => [SubscriptionAggregateRepository::class, fn () => SubscriptionId::generate(), SubscriptionAggregate::class],
    'customer' => [CustomerAggregateRepository::class, fn () => CustomerId::generate(), CustomerAggregate::class],
]);

it('rebuilds from the snapshot and replays only the events after it', function () {
    [$connection, $messages, $snapshots] = aggregateRepoWiring();
    $repository = new CheckoutAggregateRepository($messages, $snapshots);
    $id = CheckoutId::generate();

    $repository->persistEvents($id, 1, aggregateRepoCheckoutCreated());
    $repository->storeSnapshot($repository->retrieve($id));
    $repository->persistEvents($id, 2, new CheckoutCancelled);

    // The opening event is deleted after being folded into the snapshot. A full replay would
    // now produce a checkout with no amount at all, so anything that comes back correct
    // proves the snapshot was used rather than merely present — which is the difference
    // between snapshotting and an expensive no-op.
    $connection->table('stored_events')->where('version', 1)->delete();

    $checkout = $repository->retrieve($id);

    expect($checkout->aggregateRootVersion())->toBe(2)
        ->and(aggregateRepoSnapshotStateOf($repository, $connection, $checkout))
        // The amount could only have come from the snapshot, and the status could only have
        // come from the event after it. A snapshot read without its later events is a stale
        // aggregate presented as current, which for a cancelled checkout means one that can
        // still be paid.
        ->toMatchArray(['amount' => '5000', 'currency' => 'USD', 'status' => 'cancelled']);
});

it('reports the snapshot version when nothing has happened since', function () {
    [, $messages, $snapshots] = aggregateRepoWiring();
    $repository = new SubscriptionAggregateRepository($messages, $snapshots);
    $id = SubscriptionId::generate();

    $repository->persistEvents($id, 1, aggregateRepoSubscriptionCreated());
    $repository->storeSnapshot($repository->retrieve($id));

    // With no events after the snapshot the message repository reports version 0, and the
    // aggregate has to keep the snapshot's version instead of adopting that 0 — an aggregate
    // that believes it is at version 0 writes its next event over the history it just read.
    $subscription = $repository->retrieve($id);

    expect($subscription->aggregateRootVersion())->toBe(1)
        ->and($subscription->status())->toBe(SubscriptionStatus::Trialing);
});

it('exposes the same aggregate through retrieveFromSnapshot as through retrieve', function () {
    [, $messages, $snapshots] = aggregateRepoWiring();
    $repository = new PaymentIntentAggregateRepository($messages, $snapshots);
    $id = PaymentIntentId::generate();

    $repository->persistEvents($id, 1, aggregateRepoImportedIntent());
    $repository->storeSnapshot($repository->retrieve($id));

    // `retrieveFromSnapshot()` is the interface method and `retrieve()` is the narrowed one;
    // they must not be two different reads, or a caller reaching for the interface gets a
    // different aggregate than one reaching for the class.
    expect($repository->retrieveFromSnapshot($id))->toEqual($repository->retrieve($id));
});

it('applies the decorator it was given to the messages it writes', function (string $repositoryClass, callable $newId, callable $newEvent) {
    [, $messages, $snapshots] = aggregateRepoWiring();

    $context = new ContextRepository(new EventDispatcher);
    $context->add('gateway_id', 'gw-nuvei-1');

    $dispatched = [];
    $dispatcher = new class($dispatched) implements MessageDispatcher
    {
        /** @param list<Message> $seen */
        public function __construct(public array &$seen) {}

        public function dispatch(Message ...$messages): void
        {
            foreach ($messages as $message) {
                $this->seen[] = $message;
            }
        }
    };

    /** @var object $repository */
    $repository = new $repositoryClass($messages, $snapshots, $dispatcher, new GatewayIdMessageDecorator($context));
    $id = $newId();

    $repository->persistEvents($id, 1, $newEvent());

    $stored = iterator_to_array($messages->retrieveAll($id));

    // Each repository builds two `EventSourcedAggregateRootRepository` instances — one for
    // writes, one nested in the snapshotting repository for reads — and has to hand the
    // decorator and dispatcher to both. Reading the header back off the *stored* message
    // rather than off the dispatched one is what catches a decorator wired to only one of
    // them: a gateway id that reaches the projector but never the stream is a payment whose
    // history cannot say which acquirer took the money.
    expect($stored[0]->header(GatewayIdMessageDecorator::GATEWAY_ID))->toBe('gw-nuvei-1')
        ->and($dispatched)->toHaveCount(1)
        ->and($dispatched[0]->header(GatewayIdMessageDecorator::GATEWAY_ID))->toBe('gw-nuvei-1');
})->with([
    'checkout' => [CheckoutAggregateRepository::class, fn () => CheckoutId::generate(), fn () => aggregateRepoCheckoutCreated()],
    'payment intent' => [PaymentIntentAggregateRepository::class, fn () => PaymentIntentId::generate(), fn () => aggregateRepoImportedIntent()],
    'subscription' => [SubscriptionAggregateRepository::class, fn () => SubscriptionId::generate(), fn () => aggregateRepoSubscriptionCreated()],
    'customer' => [CustomerAggregateRepository::class, fn () => CustomerId::generate(), fn () => aggregateRepoCustomerRegistered()],
]);

it('records the aggregate type each repository was built for', function (string $repositoryClass, callable $newId, callable $newEvent, string $expectedType) {
    [, $messages, $snapshots] = aggregateRepoWiring();
    /** @var object $repository */
    $repository = new $repositoryClass($messages, $snapshots);
    $id = $newId();

    $repository->persistEvents($id, 1, $newEvent());

    $stored = iterator_to_array($messages->retrieveAll($id));

    // The aggregate-root type header is how a consumer tells a checkout's stream from a
    // subscription's in a table that holds both, and it is derived from the class name each
    // repository was constructed with — the one thing that differs between these four files.
    expect($stored[0]->aggregateRootType())->toBe($expectedType);
})->with([
    'checkout' => [CheckoutAggregateRepository::class, fn () => CheckoutId::generate(), fn () => aggregateRepoCheckoutCreated(), 'techork.payment_service.domain.checkout.checkout_aggregate'],
    'payment intent' => [PaymentIntentAggregateRepository::class, fn () => PaymentIntentId::generate(), fn () => aggregateRepoImportedIntent(), 'techork.payment_service.domain.payment_intent.payment_intent_aggregate'],
    'subscription' => [SubscriptionAggregateRepository::class, fn () => SubscriptionId::generate(), fn () => aggregateRepoSubscriptionCreated(), 'techork.payment_service.domain.subscription.subscription_aggregate'],
    'customer' => [CustomerAggregateRepository::class, fn () => CustomerId::generate(), fn () => aggregateRepoCustomerRegistered(), 'techork.payment_service.domain.customer.customer_aggregate'],
]);

it('keeps the streams of two aggregates in the same table apart', function () {
    [$connection, $messages, $snapshots] = aggregateRepoWiring();

    $checkoutRepository = new CheckoutAggregateRepository($messages, $snapshots);
    $intentRepository = new PaymentIntentAggregateRepository($messages, $snapshots);

    $checkoutId = CheckoutId::generate();
    $intentId = PaymentIntentId::generate();

    $checkoutRepository->persistEvents($checkoutId, 1, aggregateRepoCheckoutCreated());
    $intentRepository->persistEvents($intentId, 1, aggregateRepoImportedIntent());

    // One `stored_events` table, one `aggregate_snapshots` table, no aggregate-type column in
    // either: separation rests entirely on the id, and every id here is a v4/v7 UUID. This is
    // the assertion that a shared table is safe, and it is worth having because the failure
    // would be a checkout hydrated from a payment intent's events.
    $checkoutState = aggregateRepoSnapshotStateOf($checkoutRepository, $connection, $checkoutRepository->retrieve($checkoutId));

    expect($checkoutState)->toMatchArray(['status' => 'pending', 'amount' => '5000'])
        ->and($intentRepository->retrieve($intentId)->status())->toBe(PaymentIntentStatus::Charged)
        ->and($intentRepository->retrieve($intentId)->amount())->toEqual(new Money(12345, new Currency('USD')));
});

it('carries the aggregate root id header as the aggregate own id type', function () {
    [, $messages, $snapshots] = aggregateRepoWiring();
    $repository = new SubscriptionAggregateRepository($messages, $snapshots);
    $id = SubscriptionId::generate();

    $repository->persistEvents($id, 1, aggregateRepoSubscriptionCreated());

    $stored = iterator_to_array($messages->retrieveAll($id));

    // The id type is stored beside the id so the read path can rebuild it, and consumers
    // downstream — `LaravelMessageConsumer` re-dispatches the message whole — read the id off
    // this header rather than off the payload.
    expect($stored[0]->header(Header::AGGREGATE_ROOT_ID))->toBeInstanceOf(SubscriptionId::class)
        ->and($stored[0]->aggregateRootId()?->toString())->toBe($id->toString());
});
