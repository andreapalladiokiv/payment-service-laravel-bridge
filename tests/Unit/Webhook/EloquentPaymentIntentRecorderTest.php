<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectResult;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentAuthorized;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentCancelled;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentCaptured;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentFailed;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentRequiresAction;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregateRepositoryInterface;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\ConfirmChallengeRequest;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Laravel\Webhook\Service\EloquentPaymentIntentRecorder;
use Techork\PaymentService\Laravel\Webhook\Service\Port\ExternallyCompletedConfirmChallengePort;

/**
 * The recorder had no tests at all, and the gap cost something concrete: when
 * {@see ExternallyCompletedConfirmChallengePort} gained a constructor that carries the
 * announced evidence, all three construction sites here kept calling `new` with no
 * arguments. That is an `ArgumentCountError` on every webhook that resolves a pending
 * challenge — the whole reason this class exists — and a green suite said nothing,
 * because nothing executed a single line of it.
 *
 * So these tests are deliberately shallow and broad rather than deep: they drive each
 * entry point end to end over a real aggregate, which is what catches a signature drifting
 * away from its callers. The transitions themselves are covered where they belong, in
 * PaymentIntentAggregateTest.
 *
 * The aggregate is reconstituted from events rather than mocked; it is final, and a test
 * double for it would assert against the recorder's idea of the domain instead of the
 * domain.
 */
function recorderInstrument(): PaymentInstrument
{
    static $instance = null;

    return $instance ??= new Token(
        TokenId::fromString('01961f5a-0000-7000-8000-0000000000f1'),
        new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test'),
            new Cvc,
        ),
        ExpiresAt::fromString(new DateTimeImmutable('+1 hour')->format(DateTimeInterface::ATOM)),
    );
}

function recorderAmount(): Money
{
    return new Money(1000, new Currency('USD'));
}

/**
 * The payer these events record, address included.
 *
 * `recorderCustomer()` is what this used to be, and the rename is the change: the events
 * carry a whole {@see \Techork\PaymentService\Common\ValueObject\Customer} where they carried
 * an address that happened to hold the payer's name.
 */
function recorderCustomer(): Customer
{
    return laravelSuiteCustomer(address: new BillingAddress(
        line: '123 Main St',
        city: 'NYC',
        country: new Country('US'),
        postalCode: '10001',
    ));
}

/**
 * An intent parked on a hosted redirect — the state every webhook-driven resolution
 * starts from.
 */
function recorderRequiresActionEvent(CaptureMethod $captureMethod = CaptureMethod::Automatic): PaymentIntentRequiresAction
{
    return new PaymentIntentRequiresAction(
        recorderAmount(),
        recorderInstrument(),
        $captureMethod,
        recorderCustomer(),
        [],
        new MerchantDescriptor('ACME STORE'),
        '',
        new RedirectChallenge('gw-txn-1', 'https://hosted.example/checkout', []),
    );
}

function recorderAuthorizedEvent(CaptureMethod $captureMethod = CaptureMethod::Automatic): PaymentIntentAuthorized
{
    return new PaymentIntentAuthorized(
        recorderAmount(),
        recorderInstrument(),
        $captureMethod,
        recorderCustomer(),
        [],
        new MerchantDescriptor('ACME STORE'),
        '',
    );
}

/**
 * @param  list<object>  $events  the intent's history; empty means an unknown intent,
 *                                which reconstitutes at version 0
 */
function recorderPaymentIntentRepository(array $events): PaymentIntentAggregateRepositoryInterface
{
    return new class($events) implements PaymentIntentAggregateRepositoryInterface
    {
        public int $persistCount = 0;

        /** @var list<object> */
        public array $recorded = [];

        /** @param list<object> $events */
        public function __construct(private readonly array $events) {}

        public function retrieve(PaymentIntentId $aggregateRootId): PaymentIntentAggregate
        {
            $events = $this->events;

            return PaymentIntentAggregate::reconstituteFromEvents(
                $aggregateRootId,
                // EventSauce takes the aggregate's version from the generator's RETURN
                // value, not from how many events it yielded. Without the return the
                // aggregate reconstitutes at version 0, which the recorder reads as
                // "no such intent" — every case would pass as NotFound.
                (static function () use ($events) {
                    yield from $events;

                    return count($events);
                })(),
            );
        }

        public function persist(PaymentIntentAggregate $aggregateRoot): void
        {
            $this->persistCount++;

            foreach ($aggregateRoot->releaseEvents() as $event) {
                $this->recorded[] = $event;
            }
        }
    };
}

function recorderTransactionRepository(): GatewayTransactionRepository
{
    return new class implements GatewayTransactionRepository
    {
        /** @var list<array{payment_intent: string, reference: string}> */
        public array $saved = [];

        public function findForPaymentIntent(string $paymentIntentId): ?string
        {
            return null;
        }

        public function saveForPaymentIntent(GatewayId $gatewayId, string $paymentIntentId, string $reference, array $metadata = []): void
        {
            $this->saved[] = ['payment_intent' => $paymentIntentId, 'reference' => $reference];
        }

        public function findMetadataForPaymentIntent(string $paymentIntentId): array
        {
            return [];
        }

        public function findForRefund(string $refundId): ?string
        {
            return null;
        }

        public function saveForRefund(GatewayId $gatewayId, string $refundId, string $reference): void {}
    };
}

beforeEach(function () {
    $this->intentId = '01961f5a-0000-7000-8000-0000000000aa';
    $this->gatewayId = GatewayId::generate();
});

it('confirms a challenge the gateway announced as settled, keeping the reference once', function () {
    $intents = recorderPaymentIntentRepository([recorderRequiresActionEvent()]);
    $transactions = recorderTransactionRepository();

    $outcome = new EloquentPaymentIntentRecorder($intents, $transactions)
        ->onGatewaySuccess($this->gatewayId, $this->intentId, 'gw-ref-1', recorderAmount());

    expect($outcome)->toBe(RecorderOutcome::Applied)
        ->and($intents->recorded)->toHaveCount(1)
        ->and($intents->recorded[0])->toBeInstanceOf(PaymentIntentAuthorized::class);

    // Once, by the port, and not a second time by the recorder: the write is idempotent,
    // so a duplicate would never fail — it would just quietly outlive the reason it was
    // there, which is how the split between gateway and ports grew in the first place.
    expect($transactions->saved)->toBe([['payment_intent' => $this->intentId, 'reference' => 'gw-ref-1']]);
});

it('confirms a bare authorization announcement', function () {
    $intents = recorderPaymentIntentRepository([recorderRequiresActionEvent()]);
    $transactions = recorderTransactionRepository();

    $outcome = new EloquentPaymentIntentRecorder($intents, $transactions)
        ->onGatewayAuthorization($this->gatewayId, $this->intentId, 'gw-ref-2');

    expect($outcome)->toBe(RecorderOutcome::Applied)
        ->and($intents->recorded[0])->toBeInstanceOf(PaymentIntentAuthorized::class)
        ->and($transactions->saved)->toBe([['payment_intent' => $this->intentId, 'reference' => 'gw-ref-2']]);
});

it('records a capture the gateway made on its own, and keeps that reference itself', function () {
    // The capture port replays a state change and stores nothing, so unlike the confirm
    // path this branch is the one that still has to save.
    $intents = recorderPaymentIntentRepository([recorderAuthorizedEvent()]);
    $transactions = recorderTransactionRepository();

    $outcome = new EloquentPaymentIntentRecorder($intents, $transactions)
        ->onGatewaySuccess($this->gatewayId, $this->intentId, 'gw-ref-3', recorderAmount());

    expect($outcome)->toBe(RecorderOutcome::Applied)
        ->and($intents->recorded[0])->toBeInstanceOf(PaymentIntentCaptured::class)
        ->and($transactions->saved)->toBe([['payment_intent' => $this->intentId, 'reference' => 'gw-ref-3']]);
});

it('records a refused challenge without any gateway data to carry', function () {
    $intents = recorderPaymentIntentRepository([recorderRequiresActionEvent()]);
    $transactions = recorderTransactionRepository();

    $outcome = new EloquentPaymentIntentRecorder($intents, $transactions)
        ->onGatewayFailure($this->intentId, 'issuer declined');

    expect($outcome)->toBe(RecorderOutcome::Applied)
        ->and($intents->recorded[0])->toBeInstanceOf(PaymentIntentFailed::class)
        // Nothing settled, so there is no reference to record — and the refusal-only port
        // was never asked to confirm anything.
        ->and($transactions->saved)->toBe([]);
});

it('reports NotFound for an intent with no history', function () {
    $intents = recorderPaymentIntentRepository([]);
    $recorder = new EloquentPaymentIntentRecorder($intents, recorderTransactionRepository());

    expect($recorder->onGatewaySuccess($this->gatewayId, $this->intentId, 'gw-ref', recorderAmount()))->toBe(RecorderOutcome::NotFound)
        ->and($recorder->onGatewayAuthorization($this->gatewayId, $this->intentId, 'gw-ref'))->toBe(RecorderOutcome::NotFound)
        ->and($recorder->onGatewayFailure($this->intentId, 'whatever'))->toBe(RecorderOutcome::NotFound)
        ->and($recorder->onGatewayCancellation($this->intentId))->toBe(RecorderOutcome::NotFound)
        ->and($intents->persistCount)->toBe(0);
});

it('skips an announcement for an intent that is already resolved', function () {
    // `pi.create()` reflected the outcome inline; the webhook is a duplicate.
    $intents = recorderPaymentIntentRepository([recorderAuthorizedEvent(), new PaymentIntentCaptured(recorderAmount())]);
    $transactions = recorderTransactionRepository();

    $outcome = new EloquentPaymentIntentRecorder($intents, $transactions)
        ->onGatewayAuthorization($this->gatewayId, $this->intentId, 'gw-ref-4');

    expect($outcome)->toBe(RecorderOutcome::Skipped)
        ->and($intents->persistCount)->toBe(0)
        ->and($transactions->saved)->toBe([]);
});

it('refuses to confirm through the port kept for refusals', function () {
    // The shape exists because `onGatewayFailure` has no gateway data to give it and
    // never reaches it. If the flow ever changes so that it does, this is the throw that
    // says so rather than a payment recorded as placed on nothing.
    $port = ExternallyCompletedConfirmChallengePort::reportingRefusalOnly();

    expect(fn () => $port->confirm(new ConfirmChallengeRequest(
        PaymentIntentId::fromString($this->intentId),
        new RedirectResult('gw-ref'),
        recorderAmount(),
        recorderInstrument(),
        CaptureMethod::Automatic,
        recorderCustomer(),
    )))->toThrow(RuntimeException::class, 'without a gateway reference');
});

it('records a cancellation the gateway made on its own', function () {
    $intents = recorderPaymentIntentRepository([recorderAuthorizedEvent()]);
    $transactions = recorderTransactionRepository();

    $outcome = new EloquentPaymentIntentRecorder($intents, $transactions)
        ->onGatewayCancellation($this->intentId);

    expect($outcome)->toBe(RecorderOutcome::Applied)
        ->and($intents->recorded[0])->toBeInstanceOf(PaymentIntentCancelled::class)
        // Nothing was placed and nothing settled, so there is no reference to record.
        ->and($transactions->saved)->toBe([]);
});

it('skips a cancellation the aggregate refuses', function () {
    // Money already moved: the gateway may say it cancelled, but the event stream is the
    // source of truth and it will not record a cancellation over a capture.
    $intents = recorderPaymentIntentRepository([recorderAuthorizedEvent(), new PaymentIntentCaptured(recorderAmount())]);

    $outcome = new EloquentPaymentIntentRecorder($intents, recorderTransactionRepository())
        ->onGatewayCancellation($this->intentId);

    expect($outcome)->toBe(RecorderOutcome::Skipped)
        ->and($intents->persistCount)->toBe(0);
});

it('skips a capture the aggregate refuses', function () {
    // An immediate-capture intent has nothing to capture in a second step. The recorder
    // swallows that refusal rather than letting a webhook fail: the gateway is telling us
    // about money that the inline charge already recorded.
    $intents = recorderPaymentIntentRepository([recorderAuthorizedEvent(CaptureMethod::Immediate)]);
    $transactions = recorderTransactionRepository();

    $outcome = new EloquentPaymentIntentRecorder($intents, $transactions)
        ->onGatewaySuccess($this->gatewayId, $this->intentId, 'gw-ref-5', recorderAmount());

    expect($outcome)->toBe(RecorderOutcome::Skipped)
        ->and($intents->persistCount)->toBe(0)
        // The reference must not be written for a transition that was not recorded.
        ->and($transactions->saved)->toBe([]);
});

it('skips a success announcement for a state neither branch handles', function () {
    $intents = recorderPaymentIntentRepository([recorderAuthorizedEvent(), new PaymentIntentCaptured(recorderAmount())]);
    $transactions = recorderTransactionRepository();

    $outcome = new EloquentPaymentIntentRecorder($intents, $transactions)
        ->onGatewaySuccess($this->gatewayId, $this->intentId, 'gw-ref-6', recorderAmount());

    expect($outcome)->toBe(RecorderOutcome::Skipped)
        ->and($intents->persistCount)->toBe(0)
        ->and($transactions->saved)->toBe([]);
});

it('skips a refusal for an intent that was already resolved inline', function () {
    $intents = recorderPaymentIntentRepository([recorderAuthorizedEvent()]);

    $outcome = new EloquentPaymentIntentRecorder($intents, recorderTransactionRepository())
        ->onGatewayFailure($this->intentId, 'issuer declined');

    expect($outcome)->toBe(RecorderOutcome::Skipped)
        ->and($intents->persistCount)->toBe(0);
});
