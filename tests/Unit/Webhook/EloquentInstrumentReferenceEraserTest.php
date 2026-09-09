<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayInstrumentRepository;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayTransactionRepository;
use Techork\PaymentService\Laravel\Webhook\Service\EloquentInstrumentReferenceEraser;

/**
 * The default eraser, at zero coverage until now, and the only DELETE the webhook layer
 * performs. A `payment_method.detached`-style delivery says the gateway has forgotten a
 * vault entry; this class forgets the linkage on our side while leaving the local
 * PaymentMethod record alone.
 *
 * A delete keyed on a provider-supplied string is worth pinning for the same reason
 * {@see \Techork\PaymentService\Laravel\Webhook\Service\EloquentTransactionIdResolver} was:
 * the three WHERE clauses are the whole of the safety, and every one of them separates rows
 * that can legitimately carry the same `reference` string. Too wide a match here does not
 * fail — it silently unlinks a payment intent from its authorization, or another merchant's
 * vault token, and the next payment against it opens against nothing.
 *
 * The rows are written through the real repositories rather than by hand, so what is deleted
 * is what the tokenisation path actually stores. Same Capsule harness as the repository
 * tests: one in-memory SQLite connection, nothing mocked, no new dev dependency.
 */
function bootInstrumentEraserSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // Mirrors create_gateway_references_table + add_metadata_to_gateway_references. The
    // gateway_id foreign key is left off — this exercises one delete, not the schema's
    // referential integrity — while the composite unique key stays, because the repositories
    // used to seed the rows resolve their upsert conflict target against exactly that key.
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

function eraserPaymentMethod(?PaymentMethodId $id = null): PaymentMethod
{
    return new PaymentMethod(
        $id ?? PaymentMethodId::generate(),
        new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test User'),
            new Cvc,
        ),
    );
}

function eraserToken(): Token
{
    return new Token(
        TokenId::generate(),
        new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test User'),
            new Cvc,
        ),
        ExpiresAt::fromString(new DateTimeImmutable('+1 hour')->format(DateTimeInterface::ATOM)),
    );
}

beforeEach(function () {
    bootInstrumentEraserSchema();

    $this->eraser = new EloquentInstrumentReferenceEraser;
    $this->instruments = new EloquentGatewayInstrumentRepository;
    $this->transactions = new EloquentGatewayTransactionRepository;
    $this->gatewayId = GatewayId::generate();
});

it('forgets the gateway linkage of a payment method it holds', function () {
    $paymentMethod = eraserPaymentMethod();
    $this->instruments->saveReference($this->gatewayId, $paymentMethod, 'pm_ref_1');

    expect($this->eraser->forgetPaymentMethodReference($this->gatewayId, 'pm_ref_1'))->toBeTrue()
        // The linkage is gone, so the next payment re-tokenises rather than presenting a
        // vault entry the gateway has already dropped.
        ->and($this->instruments->find($this->gatewayId, $paymentMethod))->toBeNull();
});

it('reports nothing erased for a reference it never held', function () {
    // The handler's ack depends on this boolean. A detach for a card we never stored is not
    // an error — the gateway is telling us about something we already do not have — and
    // false is how the recorder distinguishes that from work actually done.
    expect($this->eraser->forgetPaymentMethodReference($this->gatewayId, 'pm_never_seen'))->toBeFalse();
});

it('does not erase another gateway\'s identical reference', function () {
    // Vault references are only unique within one gateway account, and this string arrives
    // from a delivery attributed to one tenant. Unscoped, a detach at one provider would
    // unlink a live card at another.
    $otherGateway = GatewayId::generate();
    $ours = eraserPaymentMethod();
    $theirs = eraserPaymentMethod();
    $this->instruments->saveReference($this->gatewayId, $ours, 'pm_shared');
    $this->instruments->saveReference($otherGateway, $theirs, 'pm_shared');

    expect($this->eraser->forgetPaymentMethodReference($this->gatewayId, 'pm_shared'))->toBeTrue()
        ->and($this->instruments->find($otherGateway, $theirs))->toBe('pm_shared');
});

it('does not erase a transaction that happens to carry the same reference', function () {
    // The same table holds payment intents and refunds, whose references come from the same
    // provider and can collide with a vault entry's as strings. Erasing one would detach a
    // payment from its authorization — and with it the stored-credential anchor a rebilling
    // payment needs — over a webhook about a card.
    $paymentIntentId = Uuid::uuid4()->toString();
    $paymentMethod = eraserPaymentMethod();
    $this->transactions->saveForPaymentIntent($this->gatewayId, $paymentIntentId, 'AMBIGUOUS-1');
    $this->instruments->saveReference($this->gatewayId, $paymentMethod, 'AMBIGUOUS-1');

    expect($this->eraser->forgetPaymentMethodReference($this->gatewayId, 'AMBIGUOUS-1'))->toBeTrue()
        ->and($this->transactions->findForPaymentIntent($paymentIntentId))->toBe('AMBIGUOUS-1')
        ->and($this->instruments->find($this->gatewayId, $paymentMethod))->toBeNull();
});

it('does not erase a token that happens to carry the same reference', function () {
    // A single-use token and a stored payment method are different morph types with the same
    // shape of reference; only `referenceable_type` keeps them apart.
    $token = eraserToken();
    $this->instruments->saveReference($this->gatewayId, $token, 'AMBIGUOUS-2');
    $this->instruments->saveReference($this->gatewayId, eraserPaymentMethod(), 'AMBIGUOUS-2');

    expect($this->eraser->forgetPaymentMethodReference($this->gatewayId, 'AMBIGUOUS-2'))->toBeTrue()
        ->and($this->instruments->find($this->gatewayId, $token))->toBe('AMBIGUOUS-2');
});

it('leaves the same gateway\'s other payment methods alone', function () {
    // The delete is keyed on the reference, not on the tenant: a merchant's remaining cards
    // must survive a detach of one of them.
    $detached = eraserPaymentMethod();
    $kept = eraserPaymentMethod();
    $this->instruments->saveReference($this->gatewayId, $detached, 'pm_gone');
    $this->instruments->saveReference($this->gatewayId, $kept, 'pm_kept');

    expect($this->eraser->forgetPaymentMethodReference($this->gatewayId, 'pm_gone'))->toBeTrue()
        ->and($this->instruments->find($this->gatewayId, $kept))->toBe('pm_kept')
        ->and($this->instruments->find($this->gatewayId, $detached))->toBeNull();
});
