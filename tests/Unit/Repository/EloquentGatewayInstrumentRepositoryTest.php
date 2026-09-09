<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
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
use Techork\PaymentService\Laravel\Repository\EloquentGatewayInstrumentRepository;
use Techork\PaymentService\Common\ValueObject\AttachedPaymentMethod;
use Techork\PaymentService\Common\ValueObject\CustomerId;

/**
 * The instrument half of `gateway_references`, at zero coverage until now: every provider
 * package that tokenizes a card writes through this class, and nothing executed a line of
 * it. Its sibling {@see EloquentGatewayTransactionRepositoryTest} exists because one
 * load-bearing rule — merge, not replace — could only be seen against a real database. The
 * equivalents here are the ones this file pins:
 *
 *  - a second write for the same instrument UPDATES the row rather than adding one, which is
 *    what the composite unique key buys and what `upsert`'s update list decides;
 *  - which columns that update list touches, because it differs between `saveReference` and
 *    `saveFailure` and therefore decides whether a retry clears a stale decline reason;
 *  - that two instruments carrying the same UUID under different morph types, or the same
 *    instrument under two gateways, never see each other's reference.
 *
 * Booted through Illuminate's Capsule for the same reason as the precedent: one in-memory
 * SQLite connection and the real repository, no new dev dependency, nothing mocked. The
 * schema is created only when missing and the tables are emptied on every test, because the
 * Capsule is global and several test files write to these tables in one process.
 */
function bootInstrumentReferenceSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // Mirrors create_gateway_references_table + add_metadata_to_gateway_references. The
    // gateway_id foreign key is left off — this exercises one repository, not the schema's
    // referential integrity — but the composite unique key is kept, because the upserts
    // under test resolve their conflict target against exactly that key.
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

    Capsule::table('gateway_references')->delete();
}

function instrumentTestCard(): CreditCard
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
function instrumentTestToken(?string $id = null): Token
{
    return new Token(
        TokenId::fromString($id ?? Uuid::uuid4()->toString()),
        instrumentTestCard(),
        ExpiresAt::fromString(new DateTimeImmutable('+1 hour')->format(DateTimeInterface::ATOM)),
    );
}

function instrumentTestPaymentMethod(?string $id = null): PaymentMethod
{
    return new PaymentMethod(
        PaymentMethodId::fromString($id ?? Uuid::uuid4()->toString()),
        instrumentTestCard(),
    );
}

/**
 * The same credential with a customer attached, which is the form a payment now carries: a bare
 * `PaymentMethod` names no payer and the gateways decline it.
 */
function instrumentTestAttached(PaymentMethod $paymentMethod, ?string $customerId = null): AttachedPaymentMethod
{
    return new AttachedPaymentMethod(
        laravelSuiteCustomer(id: CustomerId::fromString($customerId ?? '01920000-0000-7000-8000-00000000cafe')),
        $paymentMethod,
    );
}

/** @return array<string, mixed>|null */
function instrumentReferenceRow(string $referenceableId): ?array
{
    $row = Capsule::table('gateway_references')->where('referenceable_id', $referenceableId)->first();

    return $row === null ? null : (array) $row;
}

beforeEach(function () {
    bootInstrumentReferenceSchema();

    $this->repo = new EloquentGatewayInstrumentRepository;
    $this->gatewayId = GatewayId::generate();
});

it('stores a token reference and reads it back', function () {
    $token = instrumentTestToken();

    expect($this->repo->find($this->gatewayId, $token))->toBeNull();

    $this->repo->saveReference($this->gatewayId, $token, 'tok_abc');

    expect($this->repo->find($this->gatewayId, $token))->toBe('tok_abc');
});

it('updates the existing row when the same instrument is tokenized again', function () {
    // The whole point of the composite unique key: re-tokenizing (a provider re-vaulting a
    // card, or a retry after a timeout whose first attempt did land) must move the reference
    // on the one row, not accumulate rows that would then make `find` answer arbitrarily.
    $token = instrumentTestToken();

    $this->repo->saveReference($this->gatewayId, $token, 'tok_first');
    $this->repo->saveReference($this->gatewayId, $token, 'tok_second');

    expect($this->repo->find($this->gatewayId, $token))->toBe('tok_second')
        ->and(Capsule::table('gateway_references')->count())->toBe(1);
});

it('does not let one gateway read another gateway\'s reference for the same instrument', function () {
    // A token vaulted at Nuvei has a reference that means nothing at ConnexPay. Sending one
    // gateway's reference to another is a hard decline at best, so the scoping is not a
    // tidiness concern — it is why the same instrument can be vaulted at several providers.
    $token = instrumentTestToken();
    $otherGateway = GatewayId::generate();

    $this->repo->saveReference($this->gatewayId, $token, 'tok_here');

    expect($this->repo->find($otherGateway, $token))->toBeNull()
        ->and($this->repo->find($this->gatewayId, $token))->toBe('tok_here');
});

it('keeps a token and a payment method apart even when they carry the same uuid', function () {
    // Nothing stops a TokenId and a PaymentMethodId from holding the same UUID — they are
    // minted by different aggregates. Only `referenceable_type` separates them, so if the
    // morph type were dropped from either the write or the read, one instrument would answer
    // with the other's gateway reference.
    $sharedUuid = Uuid::uuid4()->toString();

    $this->repo->saveReference($this->gatewayId, instrumentTestToken($sharedUuid), 'tok_ref');
    $this->repo->saveReference($this->gatewayId, instrumentTestPaymentMethod($sharedUuid), 'pm_ref');

    expect($this->repo->find($this->gatewayId, instrumentTestToken($sharedUuid)))->toBe('tok_ref')
        ->and($this->repo->find($this->gatewayId, instrumentTestPaymentMethod($sharedUuid)))->toBe('pm_ref')
        ->and(Capsule::table('gateway_references')->count())->toBe(2);
});

it('records a failure without inventing a reference for it', function () {
    // A declined tokenization has to leave a trace — the reason is what a support answer is
    // built from — but it must not leave anything a later charge could mistake for a usable
    // vault reference, so `find` still says no.
    $token = instrumentTestToken();

    $this->repo->saveFailure($this->gatewayId, $token, 'issuer declined');

    expect($this->repo->find($this->gatewayId, $token))->toBeNull()
        ->and(instrumentReferenceRow($token->id->toString()))
        ->toMatchArray(['reference' => null, 'failure_reason' => 'issuer declined']);
});

it('clears the failure reason when a retry finally succeeds', function () {
    // `saveReference` lists `failure_reason` among the columns its upsert updates, and writes
    // null into it. Without that the row would keep claiming a decline it has recovered from,
    // and anything reporting on stored-credential failures would count it forever.
    $token = instrumentTestToken();

    $this->repo->saveFailure($this->gatewayId, $token, 'issuer declined');
    $this->repo->saveReference($this->gatewayId, $token, 'tok_retry');

    expect(instrumentReferenceRow($token->id->toString()))
        ->toMatchArray(['reference' => 'tok_retry', 'failure_reason' => null])
        ->and(Capsule::table('gateway_references')->count())->toBe(1);
});

it('keeps a working reference when a later attempt fails', function () {
    // The mirror of the case above, and deliberately NOT symmetric: `saveFailure` updates
    // `failure_reason` only. A vault reference stays valid whatever a later call does, so a
    // transient failure recorded against the instrument must not blank the reference and
    // strand a card that is still chargeable.
    $token = instrumentTestToken();

    $this->repo->saveReference($this->gatewayId, $token, 'tok_good');
    $this->repo->saveFailure($this->gatewayId, $token, 'gateway timeout');

    expect($this->repo->find($this->gatewayId, $token))->toBe('tok_good')
        ->and(instrumentReferenceRow($token->id->toString()))
        ->toMatchArray(['reference' => 'tok_good', 'failure_reason' => 'gateway timeout']);
});

it('answers with nothing for instruments that have no identity to key a row by', function () {
    // A raw card and cash are values, not records: there is no id to store a reference
    // against, so the visitor returns null and `find` short-circuits before it queries.
    // Pinned because the alternative — querying on a null id — would match any row whose
    // referenceable_id happened to be null and hand out someone else's reference.
    expect($this->repo->find($this->gatewayId, instrumentTestCard()))->toBeNull()
        ->and($this->repo->find($this->gatewayId, new Cash))->toBeNull();
});

it('refuses a hosted-payment instrument outright rather than pretending to store it', function () {
    // A hosted instrument means the card data lives on the gateway's page and was never ours
    // to vault, so there is no reference this table could ever hold for it. The refusal is
    // typed and loud on purpose: masking it as "nothing found" would let a caller proceed as
    // if the instrument were simply unvaulted and retry forever.
    $hosted = new HostedPayment('https://shop.test/ok', 'https://shop.test/cancel');

    expect(fn () => $this->repo->find($this->gatewayId, $hosted))
        ->toThrow(RuntimeException::class, 'Hosted-payment instruments are not supported in this context.')
        ->and(fn () => $this->repo->saveReference($this->gatewayId, $hosted, 'ref'))
        ->toThrow(RuntimeException::class)
        ->and(fn () => $this->repo->saveFailure($this->gatewayId, $hosted, 'reason'))
        ->toThrow(RuntimeException::class);
});

it('refuses to store a reference for an instrument with no identity, and writes nothing', function () {
    // The seam the reads guard and the writes did not. `find()` answers null for an
    // identity-less instrument, which is a legitimate answer; a write is not, because the row
    // could never be read back through the same instrument. It used to arrive as the driver's
    // NOT NULL violation, naming a column instead of the caller's mistake — now the refusal
    // says what to do about it, and still leaves no half-formed row.
    expect(fn () => $this->repo->saveReference($this->gatewayId, instrumentTestCard(), 'tok_x'))
        ->toThrow(RuntimeException::class, 'carries no identity')
        ->and(fn () => $this->repo->saveFailure($this->gatewayId, new Cash, 'declined'))
        ->toThrow(RuntimeException::class, 'tokenise it first')
        ->and(Capsule::table('gateway_references')->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────
//  One credential, one key — whichever form it arrives in
//
//  A row is addressed by (gateway_id, referenceable_type, referenceable_id), and the two halves
//  used to come from two different views of the same instrument: the id through `resolveId()`,
//  which is a visitor and unwrapped a pairing, and the type through `$instrument::type()`, which
//  did not. So one stored card was addressed as ('payment_method', <id>) or
//  ('attached_payment_method', <the same id>) depending on how it was passed — the id half being
//  identical is what made it a collision of meaning rather than two unrelated rows.
//
//  It was reachable on the ordinary path, in the direction that matters: registration writes the
//  bare form, a payment carries the attached one, so the reference was invisible exactly when it
//  was needed and the payment failed as "not registered" — a key mismatch reading as a refusal.
// ─────────────────────────────────────────────────────────

it('finds a reference saved in the bare form when asked in the attached form', function () {
    $paymentMethod = instrumentTestPaymentMethod();

    $this->repo->saveReference($this->gatewayId, $paymentMethod, 'pm_ref');

    expect($this->repo->find($this->gatewayId, instrumentTestAttached($paymentMethod)))->toBe('pm_ref');
});

it('finds a reference saved in the attached form when asked in the bare form', function () {
    $paymentMethod = instrumentTestPaymentMethod();

    $this->repo->saveReference($this->gatewayId, instrumentTestAttached($paymentMethod), 'pm_ref');

    expect($this->repo->find($this->gatewayId, $paymentMethod))->toBe('pm_ref');
});

it('writes one row whichever form the same credential arrives in', function () {
    $paymentMethod = instrumentTestPaymentMethod();

    $this->repo->saveReference($this->gatewayId, $paymentMethod, 'pm_first');
    $this->repo->saveReference($this->gatewayId, instrumentTestAttached($paymentMethod), 'pm_second');

    expect(Capsule::table('gateway_references')->count())->toBe(1)
        ->and(instrumentReferenceRow($paymentMethod->id->toString())['reference'])->toBe('pm_second')
        ->and(instrumentReferenceRow($paymentMethod->id->toString())['referenceable_type'])->toBe('payment_method');
});

/**
 * The property `visitAttachedPaymentMethod()`'s docblock claims: a reference belongs to the
 * instrument, not to whoever holds it, because a card can be attached and re-attached while its
 * provider-side reference stays what it was. Asserted rather than described, because the code
 * three lines below that docblock used to defeat it.
 */
it('keys a reference on the credential, not on the customer holding it', function () {
    $paymentMethod = instrumentTestPaymentMethod();

    $this->repo->saveReference(
        $this->gatewayId,
        instrumentTestAttached($paymentMethod, '01920000-0000-7000-8000-00000000aaaa'),
        'pm_ref',
    );

    $reattached = instrumentTestAttached($paymentMethod, '01920000-0000-7000-8000-00000000bbbb');

    expect($this->repo->find($this->gatewayId, $reattached))->toBe('pm_ref')
        ->and(Capsule::table('gateway_references')->count())->toBe(1);
});

it('records a failure in the attached form that is readable in the bare form', function () {
    $paymentMethod = instrumentTestPaymentMethod();

    $this->repo->saveFailure($this->gatewayId, instrumentTestAttached($paymentMethod), 'card declined');

    $row = instrumentReferenceRow($paymentMethod->id->toString());

    expect($row['failure_reason'])->toBe('card declined')
        ->and($row['referenceable_type'])->toBe('payment_method')
        ->and(Capsule::table('gateway_references')->count())->toBe(1);
});

/**
 * And the unwrapping stops at the pairing. A `Token` and a `PaymentMethod` sharing a UUID stay
 * apart — that is what the type half of the key is for — so collapsing the attached form onto the
 * bare one must not collapse anything else with it.
 */
it('still keeps different instrument kinds apart after unwrapping', function () {
    $shared = Uuid::uuid4()->toString();

    $this->repo->saveReference($this->gatewayId, instrumentTestToken($shared), 'tok_ref');
    $this->repo->saveReference($this->gatewayId, instrumentTestAttached(instrumentTestPaymentMethod($shared)), 'pm_ref');

    expect($this->repo->find($this->gatewayId, instrumentTestToken($shared)))->toBe('tok_ref')
        ->and($this->repo->find($this->gatewayId, instrumentTestPaymentMethod($shared)))->toBe('pm_ref')
        ->and(Capsule::table('gateway_references')->count())->toBe(2);
});
