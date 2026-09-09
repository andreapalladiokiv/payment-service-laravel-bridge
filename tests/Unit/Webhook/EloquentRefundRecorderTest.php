<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentAuthorized;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentCharged;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregateRepositoryInterface;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundFailed;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundProcessed;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\Request\RefundRequest;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Laravel\Webhook\Service\Command\CreateRefund;
use Techork\PaymentService\Laravel\Webhook\Service\EloquentRefundRecorder;
use Techork\PaymentService\Laravel\Webhook\Service\Port\ExternallyCompletedRefundPort;

/**
 * The refund half of webhook ingestion had no coverage at all — recorder, port and command
 * together, not one line executed.
 *
 * That is the same shape as the defect this package shipped days ago: its sibling,
 * EloquentPaymentIntentRecorder, kept calling `new` on a port that had grown a required
 * constructor, which is an ArgumentCountError on the only path the class exists for, and a
 * green suite said nothing because nothing ran it. This file exists so the same change to
 * ExternallyCompletedRefundPort cannot pass unnoticed, and it drives real aggregates rather
 * than asserting against doubles it configured itself.
 */
function refundRecorderInstrument(): PaymentInstrument
{
    static $instance = null;

    return $instance ??= new Token(
        TokenId::fromString('01961f5a-0000-7000-8000-0000000000d1'),
        new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test'),
            new Cvc,
        ),
        ExpiresAt::fromString(new DateTimeImmutable('+1 hour')->format(DateTimeInterface::ATOM)),
    );
}

function refundRecorderMoney(int $minor = 1000): Money
{
    return new Money($minor, new Currency('USD'));
}

function refundRecorderCustomer(): Customer
{
    return laravelSuiteCustomer(address: new BillingAddress(
        line: '123 Main St',
        city: 'NYC',
        country: new Country('US'),
        postalCode: '10001',
    ));
}

/**
 * A charged intent: the only state the domain lets a refund open from.
 */
function refundRecorderChargedEvent(?Money $amount = null): PaymentIntentCharged
{
    return new PaymentIntentCharged(
        $amount ?? refundRecorderMoney(),
        refundRecorderInstrument(),
        CaptureMethod::Immediate,
        refundRecorderCustomer(),
        [],
        new MerchantDescriptor('ACME STORE'),
        '',
    );
}

function refundRecorderAuthorizedEvent(): PaymentIntentAuthorized
{
    return new PaymentIntentAuthorized(
        refundRecorderMoney(),
        refundRecorderInstrument(),
        CaptureMethod::Automatic,
        refundRecorderCustomer(),
        [],
        new MerchantDescriptor('ACME STORE'),
        '',
    );
}

/**
 * @param  list<object>  $events  the intent's history; empty means an unknown intent
 */
function refundRecorderIntents(array $events): PaymentIntentAggregateRepositoryInterface
{
    return new class($events) implements PaymentIntentAggregateRepositoryInterface
    {
        public int $persistCount = 0;

        /** @var list<object> */
        public array $recorded = [];

        /** @param list<object> $events */
        public function __construct(private array $events) {}

        public function retrieve(PaymentIntentId $aggregateRootId): PaymentIntentAggregate
        {
            $events = $this->events;

            return PaymentIntentAggregate::reconstituteFromEvents(
                $aggregateRootId,
                // EventSauce reads the version off the generator's RETURN value, not off how
                // many events it yielded; without it the aggregate arrives at version 0 and
                // the recorder reads that as "no such intent".
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

function refundRecorderTransactions(): GatewayTransactionRepository
{
    return new class implements GatewayTransactionRepository
    {
        /** @var list<array{refund: string, reference: string}> */
        public array $savedRefunds = [];

        public function findForPaymentIntent(string $paymentIntentId): ?string
        {
            return null;
        }

        public function saveForPaymentIntent(GatewayId $gatewayId, string $paymentIntentId, string $reference, array $metadata = []): void {}

        public function findMetadataForPaymentIntent(string $paymentIntentId): array
        {
            return [];
        }

        public function findForRefund(string $refundId): ?string
        {
            return null;
        }

        public function saveForRefund(GatewayId $gatewayId, string $refundId, string $reference): void
        {
            $this->savedRefunds[] = ['refund' => $refundId, 'reference' => $reference];
        }
    };
}

/**
 * @param  string|null  $knownRefundId  what the resolver already holds for the reference;
 *                                      non-null makes the webhook a duplicate
 */
function refundRecorderResolver(?string $knownRefundId = null): TransactionIdResolver
{
    return new class($knownRefundId) implements TransactionIdResolver
    {
        public function __construct(private ?string $knownRefundId) {}

        public function resolvePaymentIntent(GatewayId $gatewayId, string $reference): ?string
        {
            return null;
        }

        public function resolveRefund(GatewayId $gatewayId, string $reference): ?string
        {
            return $this->knownRefundId;
        }
    };
}

beforeEach(function () {
    $this->intentId = '01961f5a-0000-7000-8000-0000000000b1';
    $this->gatewayId = GatewayId::generate();
});

it('records a refund the gateway processed, and keeps the reference that identifies it', function () {
    $intents = refundRecorderIntents([refundRecorderChargedEvent()]);
    $transactions = refundRecorderTransactions();

    $outcome = new EloquentRefundRecorder($intents, $transactions, refundRecorderResolver())
        ->onRefundProcessed($this->gatewayId, $this->intentId, 'rf_123', refundRecorderMoney(400));

    expect($outcome)->toBe(RecorderOutcome::Applied)
        ->and($intents->recorded)->toHaveCount(1)
        ->and($intents->recorded[0])->toBeInstanceOf(RefundProcessed::class)
        ->and($intents->recorded[0]->amount->getAmount())->toBe('400');

    // The reference is what makes the NEXT delivery of this webhook a detected duplicate,
    // so it is stored against the refund id the aggregate just minted.
    expect($transactions->savedRefunds)->toHaveCount(1)
        ->and($transactions->savedRefunds[0]['reference'])->toBe('rf_123')
        ->and($transactions->savedRefunds[0]['refund'])->toBe($intents->recorded[0]->refundId->toString());
});

it('records a refund the gateway declined, and still keeps the reference', function () {
    $intents = refundRecorderIntents([refundRecorderChargedEvent()]);
    $transactions = refundRecorderTransactions();

    $outcome = new EloquentRefundRecorder($intents, $transactions, refundRecorderResolver())
        ->onRefundFailed($this->gatewayId, $this->intentId, 'rf_456', refundRecorderMoney(400), 'issuer unavailable');

    expect($outcome)->toBe(RecorderOutcome::Applied)
        ->and($intents->recorded[0])->toBeInstanceOf(RefundFailed::class)
        ->and($intents->recorded[0]->reason)->toBe('issuer unavailable')
        // Deliberate, and worth pinning: a failed attempt is still an attempt the gateway
        // named. Without the reference a redelivery would open a second RefundFailed on the
        // stream instead of resolving to this one.
        ->and($transactions->savedRefunds[0]['reference'])->toBe('rf_456');
});

it('skips a webhook whose reference already resolves to a refund', function () {
    // The idempotency gate. It answers before the aggregate is touched, so a redelivery
    // cannot mint a second refund id for the same gateway reference.
    $intents = refundRecorderIntents([refundRecorderChargedEvent()]);
    $transactions = refundRecorderTransactions();

    $outcome = new EloquentRefundRecorder($intents, $transactions, refundRecorderResolver('01961f5a-0000-7000-8000-0000000000c9'))
        ->onRefundProcessed($this->gatewayId, $this->intentId, 'rf_123', refundRecorderMoney(400));

    expect($outcome)->toBe(RecorderOutcome::Skipped)
        ->and($intents->persistCount)->toBe(0)
        ->and($transactions->savedRefunds)->toBe([]);
});

it('reports NotFound for an intent with no history', function () {
    $intents = refundRecorderIntents([]);
    $transactions = refundRecorderTransactions();
    $recorder = new EloquentRefundRecorder($intents, $transactions, refundRecorderResolver());

    expect($recorder->onRefundProcessed($this->gatewayId, $this->intentId, 'rf_1', refundRecorderMoney()))->toBe(RecorderOutcome::NotFound)
        ->and($recorder->onRefundFailed($this->gatewayId, $this->intentId, 'rf_2', refundRecorderMoney(), 'nope'))->toBe(RecorderOutcome::NotFound)
        ->and($intents->persistCount)->toBe(0)
        ->and($transactions->savedRefunds)->toBe([]);
});

it('skips a refund the aggregate refuses because the intent was never charged', function () {
    // An authorized intent holds no captured funds, so there is nothing to return. The
    // gateway may have acted anyway; the stream does not follow it.
    $intents = refundRecorderIntents([refundRecorderAuthorizedEvent()]);
    $transactions = refundRecorderTransactions();

    $outcome = new EloquentRefundRecorder($intents, $transactions, refundRecorderResolver())
        ->onRefundProcessed($this->gatewayId, $this->intentId, 'rf_789', refundRecorderMoney(400));

    expect($outcome)->toBe(RecorderOutcome::Skipped)
        ->and($intents->persistCount)->toBe(0)
        // Nothing was recorded, so nothing may carry a reference — the same rule the payment
        // intent recorder follows on a refused transition.
        ->and($transactions->savedRefunds)->toBe([]);
});

it('skips a refund larger than what the intent captured', function () {
    $intents = refundRecorderIntents([refundRecorderChargedEvent(refundRecorderMoney(1000))]);
    $transactions = refundRecorderTransactions();

    $outcome = new EloquentRefundRecorder($intents, $transactions, refundRecorderResolver())
        ->onRefundProcessed($this->gatewayId, $this->intentId, 'rf_over', refundRecorderMoney(1500));

    expect($outcome)->toBe(RecorderOutcome::Skipped)
        ->and($intents->persistCount)->toBe(0)
        ->and($transactions->savedRefunds)->toBe([]);
});

it('skips a refund in a currency the intent was not charged in', function () {
    $intents = refundRecorderIntents([refundRecorderChargedEvent()]);

    $outcome = new EloquentRefundRecorder($intents, refundRecorderTransactions(), refundRecorderResolver())
        ->onRefundProcessed($this->gatewayId, $this->intentId, 'rf_eur', new Money(400, new Currency('EUR')));

    expect($outcome)->toBe(RecorderOutcome::Skipped)
        ->and($intents->persistCount)->toBe(0);
});

// ──────────────────────────────────────────────
//  The port, which replays a decision already taken
// ──────────────────────────────────────────────

function refundRecorderRequest(): RefundRequest
{
    return new RefundRequest(
        paymentIntentId: PaymentIntentId::fromString('01961f5a-0000-7000-8000-0000000000b1'),
        refundId: RefundId::generate(),
        amount: refundRecorderMoney(400),
        retryInstrument: null,
    );
}

it('returns quietly when the gateway processed the refund', function () {
    // Void return IS the signal: the aggregate reads it as success and records
    // RefundProcessed. There is nothing to call, because the money already moved.
    expect(ExternallyCompletedRefundPort::successful()->refund(refundRecorderRequest()))->toBeNull();
});

it('throws the decline the aggregate turns into RefundFailed', function () {
    expect(fn () => ExternallyCompletedRefundPort::declined('issuer unavailable')->refund(refundRecorderRequest()))
        ->toThrow(GatewayDeclinedException::class, 'issuer unavailable');
});

it('carries no retry instrument, because a webhook refund has no second card', function () {
    // Pins a documented decision rather than an accident: refunds that arrive by webhook
    // originate at the gateway, against the original transaction, so there is no
    // alternative payment source to redirect them onto.
    $command = new CreateRefund(RefundId::generate(), refundRecorderMoney(400));

    expect($command->retryInstrument())->toBeNull()
        ->and($command->amount()->getAmount())->toBe('400');
});
