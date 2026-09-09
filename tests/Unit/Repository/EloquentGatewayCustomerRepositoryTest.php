<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayCustomerRepository;

/**
 * The map that replaces `CustomerRepository`, and the difference is the key. The old one goes
 * customer → `gateway_reference_customer` → `gateway_references`, so it can only answer for a
 * customer that happens to own a stored instrument's reference: a raw-card payment resolves
 * nobody, an expiring `Token` resolves somebody, and one person with three cards is three rows
 * nothing ties together. This one is keyed on the customer, with no pivot to go through.
 *
 * Three files in this process create `gateway_customers` "only when absent", so the first to
 * run decides its shape for all of them and each mirror has to carry every column the others
 * need — `customer_id` included, even where it goes unread. A column missing from one mirror
 * fails a different file, which is a long way from the cause.
 */
function bootGatewayCustomerMapSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    // Mirrors create_gateway_customers_table + add_customer_id_to_gateway_customers. Foreign keys
    // are left off — this exercises the repository, not referential integrity — but both unique
    // keys are kept, because one is the conflict target of `saveReference`'s upsert.
    if (! Capsule::schema()->hasTable('gateway_customers')) {
        Capsule::schema()->create('gateway_customers', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('gateway_id');
            $table->uuid('customer_id')->nullable();
            $table->string('customer_reference');
            $table->timestamps();
            $table->unique(['gateway_id', 'customer_id']);
            $table->unique(['gateway_id', 'customer_reference']);
        });
    }
}
beforeEach(function () {
    bootGatewayCustomerMapSchema();
    Capsule::table('gateway_customers')->delete();
});

it('answers with the reference it was given', function () {
    $repository = new EloquentGatewayCustomerRepository;
    $gatewayId = GatewayId::generate();
    $customerId = laravelSuiteCustomerId(Uuid::uuid7()->toString());

    $repository->saveReference($gatewayId, $customerId, 'cus_stripe_1');

    expect($repository->find($gatewayId, $customerId))->toBe('cus_stripe_1');
});

it('answers nothing for a customer it has never seen', function () {
    expect(new EloquentGatewayCustomerRepository()->find(GatewayId::generate(), laravelSuiteCustomerId(Uuid::uuid7()->toString())))->toBeNull();
});

/**
 * One customer legitimately has a different reference at each gateway — that is the whole
 * reason the key is composite — so a lookup must not cross the boundary.
 */
it('keeps each gateway answer to itself', function () {
    $repository = new EloquentGatewayCustomerRepository;
    $customerId = laravelSuiteCustomerId(Uuid::uuid7()->toString());
    $stripe = GatewayId::generate();
    $nuvei = GatewayId::generate();

    $repository->saveReference($stripe, $customerId, 'cus_stripe_1');
    $repository->saveReference($nuvei, $customerId, $customerId->toString());

    expect($repository->find($stripe, $customerId))->toBe('cus_stripe_1')
        ->and($repository->find($nuvei, $customerId))->toBe($customerId->toString());
});

/**
 * Re-pointing updates the single row rather than adding a second. Two rows would leave the old
 * reference readable and whichever came back first would win.
 */
it('replaces a reference rather than accumulating one', function () {
    $repository = new EloquentGatewayCustomerRepository;
    $gatewayId = GatewayId::generate();
    $customerId = laravelSuiteCustomerId(Uuid::uuid7()->toString());

    $repository->saveReference($gatewayId, $customerId, 'cus_old');
    $repository->saveReference($gatewayId, $customerId, 'cus_new');

    expect($repository->find($gatewayId, $customerId))->toBe('cus_new')
        ->and(Capsule::table('gateway_customers')->count())->toBe(1);
});

/**
 * Legacy rows exist where the reference was written as `''`. Both gateway resolvers already say
 * in their own words what forwarding that costs: Nuvei rejects a payment referencing a stored
 * payment option, and Stripe rejects the charge with "Please include the customer". So an empty
 * string is missing, not a reference.
 */
it('treats an empty reference as none', function () {
    $gatewayId = GatewayId::generate();
    $customerId = laravelSuiteCustomerId(Uuid::uuid7()->toString());

    Capsule::table('gateway_customers')->insert([
        'id' => Uuid::uuid7()->toString(),
        'gateway_id' => $gatewayId->toString(),
        'customer_id' => $customerId->toString(),
        'customer_reference' => '',
    ]);

    expect(new EloquentGatewayCustomerRepository()->find($gatewayId, $customerId))->toBeNull();
});
