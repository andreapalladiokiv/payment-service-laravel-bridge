<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Role\CapturesPayments;
use Techork\PaymentService\Gateway\Role\PlacesPayments;
use Techork\PaymentService\Gateway\Role\PlacesRebillingPayments;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Port\CaptureAdapter;
use Techork\PaymentService\Laravel\Port\CreateAdapter;
use Techork\PaymentService\Laravel\Port\RebillingCreateAdapter;

/**
 * Where the customer ENTERS a gateway call, which is the only place worth asserting it.
 *
 * The lesson is paid for. `ConnexPay\CaptureRequest` emitted `CustomerID` and had a passing test
 * saying so, while the interface behind it had no customer parameter at all — so nothing ever set
 * the field, it was absent in production, and the suite was green. A request-level test cannot see
 * that. The value has to be asserted where a caller supplies it: at the adapter that turns a
 * domain request into a gateway command.
 *
 * **Two entrances now, and which one is which is the subject.** A payment intent carries its own
 * customer, so create and renewal read the payer off the request — the address the intent always
 * recorded was already carrying the payer's name and email, so naming them replaced a fact the
 * aggregate held rather than adding one, and an injected customer beside a recorded one would be
 * two sources for the same value. Capture and refund still take one from the host, because their
 * requests name an amount and the payment they act on and no payer at all.
 */
function adapterCustomerTransactions(): GatewayTransactionRepository
{
    $repo = Mockery::mock(GatewayTransactionRepository::class);
    $repo->shouldReceive('findForPaymentIntent')->andReturn(null);
    $repo->shouldReceive('findMetadataForPaymentIntent')->andReturn([]);
    $repo->shouldReceive('saveForPaymentIntent')->zeroOrMoreTimes();

    return $repo;
}

function adapterCustomerCreateRequest(CaptureMethod $captureMethod = CaptureMethod::Manual, ?Customer $customer = null): CreateRequest
{
    return new CreateRequest(
        paymentIntentId: PaymentIntentId::generate(),
        amount: new Money(1000, new Currency('USD')),
        instrument: Mockery::mock(PaymentInstrument::class),
        captureMethod: $captureMethod,
        customer: $customer ?? laravelSuiteCustomer(address: new BillingAddress('1 St', 'NYC', new Country('US'), '10001')),
        initiation: PaymentInitiation::CardholderInitiated,
    );
}

it('puts the customer the intent recorded onto the placement it builds', function (CaptureMethod $captureMethod, string $operation) {
    $customer = laravelSuiteCustomer();
    $seen = null;

    $gateway = Mockery::mock(PlacesPayments::class);
    $gateway->shouldReceive($operation)->andReturnUsing(function (PlacementCommand $command) use (&$seen): AuthorizationResult {
        $seen = $command->customer;

        return AuthorizationResult::succeeded('ref_1');
    });

    new CreateAdapter($gateway, adapterCustomerTransactions(), GatewayId::generate())
        ->create(adapterCustomerCreateRequest($captureMethod, $customer));

    expect($seen)->toBe($customer);
})->with([
    // Both operations, because the adapter picks between them by capture method and a customer
    // dropped on one branch only is a whole class of payment reaching the acquirer anonymous.
    'authorize' => [CaptureMethod::Manual, 'authorize'],
    'charge' => [CaptureMethod::Immediate, 'charge'],
]);

/**
 * The intent's customer reaches the placement unchanged, rather than being rebuilt out of parts.
 *
 * What used to be asserted here is gone with the shape that made it possible: the placement had a
 * nullable customer id beside a nullable billing address, so "names nobody" was a state a payment
 * could reach and the alternative — a default reaching for whatever identity was nearby — was the
 * behaviour being removed. A payment intent carries a whole customer, so the adapter has nothing
 * to decide and nothing to default; the assertion worth having is that it forwards the object it
 * was given.
 */
it('forwards the intent customer itself, not a copy assembled from its parts', function () {
    $customer = laravelSuiteCustomer();
    $seen = null;

    $gateway = Mockery::mock(PlacesPayments::class);
    $gateway->shouldReceive('authorize')->andReturnUsing(function (PlacementCommand $command) use (&$seen): AuthorizationResult {
        $seen = $command->customer;

        return AuthorizationResult::succeeded('ref_1');
    });

    new CreateAdapter($gateway, adapterCustomerTransactions(), GatewayId::generate())
        ->create(adapterCustomerCreateRequest(customer: $customer));

    expect($seen)->toBe($customer);
});

/**
 * Capture too, and it is a different question from the authorization's: ConnexPay accepts
 * `CustomerID` on Capture and not on Void or Return, so the field has to survive the second leg
 * of a two-step payment.
 */
it('puts the customer onto the capture it builds', function () {
    $customer = laravelSuiteCustomer();
    $seen = null;

    $repo = Mockery::mock(GatewayTransactionRepository::class);
    $repo->shouldReceive('findForPaymentIntent')->andReturn('auth_ref');
    $repo->shouldReceive('saveForPaymentIntent')->zeroOrMoreTimes();

    $gateway = Mockery::mock(CapturesPayments::class);
    $gateway->shouldReceive('capture')->andReturnUsing(function (CaptureCommand $command) use (&$seen): GatewayResult {
        $seen = $command->customer;

        return GatewayResult::succeeded('cap_1');
    });

    new CaptureAdapter($gateway, $repo, GatewayId::generate(), $customer)
        ->capture(new CaptureRequest(
            paymentIntentId: PaymentIntentId::generate(),
            amount: new Money(1000, new Currency('USD')),
            authorizedAmount: new Money(1000, new Currency('USD')),
            instrument: Mockery::mock(PaymentInstrument::class),
        ));

    expect($seen)->toBe($customer);
});

/**
 * And a renewal, where it matters most. Nuvei renews through a `userPaymentOptionId`, which exists
 * only under the `userTokenId` it was stored against — so a renewal that names no customer cannot
 * reach the stored instrument it exists to charge. `authorizeRebilling` had no customer at all and
 * put none in its options, while routing through the same provider call that reads the key.
 */
it('puts the customer onto the renewal it builds', function () {
    $customer = laravelSuiteCustomer();
    $seen = null;

    $gateway = Mockery::mock(PlacesRebillingPayments::class);
    $gateway->shouldReceive('authorizeRebilling')->andReturnUsing(function (RebillingCommand $command) use (&$seen): AuthorizationResult {
        $seen = $command->customer;

        return AuthorizationResult::succeeded('ref_1');
    });

    new RebillingCreateAdapter($gateway, adapterCustomerTransactions(), GatewayId::generate(), null)
        ->create(adapterCustomerCreateRequest(customer: $customer));

    expect($seen)->toBe($customer);
});
