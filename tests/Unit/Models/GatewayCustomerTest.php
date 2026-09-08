<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Models\Gateway;
use Techork\PaymentService\Laravel\Models\GatewayCustomer;
use Techork\PaymentService\Laravel\Models\GatewayReference;

/**
 * `GatewayCustomer` is the row that remembers a provider-side customer, and both of its
 * declarations carry consequences that only a database can show.
 *
 * `$guarded = ['*']` is total: it does not filter a payload, it refuses one, which is why
 * {@see \Techork\PaymentService\Laravel\Repository\EloquentCustomerRepository} wraps its
 * write in `unguarded()`. Pinned from this side because a reader could take the guard for a
 * safety net that quietly drops unexpected keys, remove the `unguarded()` wrapper as
 * redundant, and break customer creation for every provider at once.
 *
 * The `gateway_id` cast is the other half. It makes the attribute a {@see GatewayId}, and
 * that object is what the `belongsTo` binds into its query and what the repository's
 * `where()` clauses compare against — so the relation is exercised here rather than trusted.
 *
 * Shares the in-memory SQLite Capsule with the other database-backed tests and uses the real
 * models throughout; `Model::encryptUsing` is set because reaching a Gateway row through the
 * relation decrypts its credentials.
 */
function gatewayCustomerModelSchema(): void
{
    if (Model::getConnectionResolver() === null) {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    Model::encryptUsing(new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));

    // Mirrors create_gateways_table.
    if (! Capsule::schema()->hasTable('gateways')) {
        Capsule::schema()->create('gateways', function ($table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('gateway_name');
            $table->text('credentials');
            $table->timestamps();
        });
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

    // Mirrors create_gateway_customers_table, both tables, unique keys included: they are
    // what the model's relations are written around. Foreign keys are left off.
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
    Capsule::table('gateways')->delete();
}

function gatewayCustomerGatewayRow(string $gatewayName = 'ConnexPay'): Gateway
{
    return Gateway::query()->create([
        'name' => 'Primary',
        'gateway_name' => $gatewayName,
        'credentials' => ['username' => 'u'],
    ]);
}

beforeEach(fn () => gatewayCustomerModelSchema());

it('refuses a mass-assigned payload outright, which is why its repository unguards', function () {
    // `$guarded = ['*']` means totally guarded, and Eloquent's answer to that is an
    // exception, not a silent drop. Nothing writes this row without `unguarded()`.
    $gateway = gatewayCustomerGatewayRow();

    expect(fn () => GatewayCustomer::query()->create([
        'gateway_id' => $gateway->getKey(),
        'customer_reference' => 'cus_123',
    ]))->toThrow(MassAssignmentException::class);

    // And the way the repository does it, which has to keep working.
    $customer = GatewayCustomer::unguarded(fn () => GatewayCustomer::query()->create([
        'gateway_id' => $gateway->getKey(),
        'customer_reference' => 'cus_123',
    ]));

    expect($customer->customer_reference)->toBe('cus_123')
        ->and(Capsule::table('gateway_customers')->count())->toBe(1);
});

it('reads its gateway id back as a value object and accepts one on the way in', function () {
    // Both directions of the cast: the repository writes the id as a string, provider code
    // hands it a GatewayId, and a comparison against `$customer->gateway_id` only holds if
    // what comes back is the value object the property annotation promises.
    $gateway = gatewayCustomerGatewayRow();

    $fromString = GatewayCustomer::query()->forceCreate([
        'gateway_id' => $gateway->getKey(),
        'customer_reference' => 'cus_string',
    ]);
    $fromObject = GatewayCustomer::query()->forceCreate([
        'gateway_id' => $gateway->getId(),
        'customer_reference' => 'cus_object',
    ]);

    expect($fromString->fresh()->gateway_id)->toBeInstanceOf(GatewayId::class)
        ->and($fromString->fresh()->gateway_id->toString())->toBe($gateway->getKey())
        ->and($fromObject->fresh()->gateway_id->toString())->toBe($gateway->getKey())
        // HasUuids owns the key; nothing supplies it.
        ->and($fromString->getKey())->toBeString()
        ->and($fromString->getKey())->not->toBe($fromObject->getKey());
});

it('belongs to the gateway whose id it holds as a value object', function () {
    // The relation binds `gateway_id` — a GatewayId, not a string — into its where clause.
    // Pinned because the cast and the belongsTo were written apart from each other, and a
    // relation that cannot bind its own key fails only where it is used.
    $gateway = gatewayCustomerGatewayRow('Nuvei');
    $customer = GatewayCustomer::query()->forceCreate([
        'gateway_id' => $gateway->getKey(),
        'customer_reference' => 'cus_123',
    ]);

    $resolved = $customer->gateway()->first();

    expect($resolved)->toBeInstanceOf(Gateway::class)
        ->and($resolved->getKey())->toBe($gateway->getKey())
        ->and($resolved->getGatewayName())->toBe('Nuvei');
});

it('reaches its instrument references through the pivot, and only its own', function () {
    // The inverse of GatewayReference::customers(): given a provider-side customer, which
    // vaulted instruments belong to it. Both custom pivot column names have to be right, and
    // a wrong one reads as an empty set rather than an error — so the second customer's
    // reference is here to prove the set is filtered and not simply empty.
    $gateway = gatewayCustomerGatewayRow();
    $mine = GatewayCustomer::query()->forceCreate(['gateway_id' => $gateway->getKey(), 'customer_reference' => 'cus_mine']);
    $theirs = GatewayCustomer::query()->forceCreate(['gateway_id' => $gateway->getKey(), 'customer_reference' => 'cus_theirs']);

    foreach ([[$mine, 'tok_mine'], [$theirs, 'tok_theirs']] as [$customer, $reference]) {
        $row = GatewayReference::query()->forceCreate([
            'gateway_id' => $gateway->getKey(),
            'referenceable_type' => 'token',
            'referenceable_id' => Uuid::uuid4()->toString(),
            'reference' => $reference,
        ]);
        $row->customers()->attach($customer->getKey());
    }

    expect($mine->references()->pluck('reference')->all())->toBe(['tok_mine'])
        ->and($theirs->references()->pluck('reference')->all())->toBe(['tok_theirs']);
});
