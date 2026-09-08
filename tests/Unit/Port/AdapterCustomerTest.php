<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Domain\Customer\ValueObject\CustomerId;
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
 * that. The value has to be asserted where a caller supplies it: here, at the adapter that turns a
 * domain request into a gateway command.
 *
 * The adapter is also WHY these live here rather than in the domain. The host knows who is paying
 * when it decides how to route the payment, exactly as it knows which gateway; neither is a fact
 * the payment intent reads, so `CreateRequest` and `CreatePaymentIntentCommand` are deliberately
 * untouched — adding a method to an interface every host implements, to carry a value the
 * aggregate ignores, would be the wrong trade.
 */
function adapterCustomerTransactions(): GatewayTransactionRepository
{
    $repo = Mockery::mock(GatewayTransactionRepository::class);
    $repo->shouldReceive('findForPaymentIntent')->andReturn(null);
    $repo->shouldReceive('findMetadataForPaymentIntent')->andReturn([]);
    $repo->shouldReceive('saveForPaymentIntent')->zeroOrMoreTimes();

    return $repo;
}

function adapterCustomerCreateRequest(CaptureMethod $captureMethod = CaptureMethod::Manual): CreateRequest
{
    return new CreateRequest(
        paymentIntentId: PaymentIntentId::generate(),
        amount: new Money(1000, new Currency('USD')),
        instrument: Mockery::mock(PaymentInstrument::class),
        captureMethod: $captureMethod,
        billingAddress: new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
        initiation: PaymentInitiation::CardholderInitiated,
    );
}

it('puts the customer the host named onto the placement it builds', function (CaptureMethod $captureMethod, string $operation) {
    $customerId = CustomerId::generate();
    $seen = null;

    $gateway = Mockery::mock(PlacesPayments::class);
    $gateway->shouldReceive($operation)->andReturnUsing(function (PlacementCommand $command) use (&$seen): AuthorizationResult {
        $seen = $command->customerId;

        return AuthorizationResult::succeeded('ref_1');
    });

    new CreateAdapter($gateway, adapterCustomerTransactions(), GatewayId::generate(), $customerId)
        ->create(adapterCustomerCreateRequest($captureMethod));

    expect($seen)->toBe($customerId);
})->with([
    // Both operations, because the adapter picks between them by capture method and a customer
    // dropped on one branch only is a whole class of payment reaching the acquirer anonymous.
    'authorize' => [CaptureMethod::Manual, 'authorize'],
    'charge' => [CaptureMethod::Immediate, 'charge'],
]);

/**
 * A payment that names nobody stays nameless, all the way down. One-off charges belong to people
 * we have no record of, and the alternative — a default reaching for whatever identity is nearby —
 * is the behaviour being removed.
 */
it('leaves the placement without a customer when the host named none', function () {
    $seen = 'untouched';

    $gateway = Mockery::mock(PlacesPayments::class);
    $gateway->shouldReceive('authorize')->andReturnUsing(function (PlacementCommand $command) use (&$seen): AuthorizationResult {
        $seen = $command->customerId;

        return AuthorizationResult::succeeded('ref_1');
    });

    new CreateAdapter($gateway, adapterCustomerTransactions(), GatewayId::generate())
        ->create(adapterCustomerCreateRequest());

    expect($seen)->toBeNull();
});

/**
 * Capture too, and it is a different question from the authorization's: ConnexPay accepts
 * `CustomerID` on Capture and not on Void or Return, so the field has to survive the second leg
 * of a two-step payment.
 */
it('puts the customer onto the capture it builds', function () {
    $customerId = CustomerId::generate();
    $seen = null;

    $repo = Mockery::mock(GatewayTransactionRepository::class);
    $repo->shouldReceive('findForPaymentIntent')->andReturn('auth_ref');
    $repo->shouldReceive('saveForPaymentIntent')->zeroOrMoreTimes();

    $gateway = Mockery::mock(CapturesPayments::class);
    $gateway->shouldReceive('capture')->andReturnUsing(function (CaptureCommand $command) use (&$seen): GatewayResult {
        $seen = $command->customerId;

        return GatewayResult::succeeded('cap_1');
    });

    new CaptureAdapter($gateway, $repo, GatewayId::generate(), $customerId)
        ->capture(new CaptureRequest(
            paymentIntentId: PaymentIntentId::generate(),
            amount: new Money(1000, new Currency('USD')),
            authorizedAmount: new Money(1000, new Currency('USD')),
            instrument: Mockery::mock(PaymentInstrument::class),
        ));

    expect($seen)->toBe($customerId);
});

/**
 * And a renewal, where it matters most. Nuvei renews through a `userPaymentOptionId`, which exists
 * only under the `userTokenId` it was stored against — so a renewal that names no customer cannot
 * reach the stored instrument it exists to charge. `authorizeRebilling` had no customer at all and
 * put none in its options, while routing through the same provider call that reads the key.
 */
it('puts the customer onto the renewal it builds', function () {
    $customerId = CustomerId::generate();
    $seen = null;

    $gateway = Mockery::mock(PlacesRebillingPayments::class);
    $gateway->shouldReceive('authorizeRebilling')->andReturnUsing(function (RebillingCommand $command) use (&$seen): AuthorizationResult {
        $seen = $command->customerId;

        return AuthorizationResult::succeeded('ref_1');
    });

    new RebillingCreateAdapter($gateway, adapterCustomerTransactions(), GatewayId::generate(), null, $customerId)
        ->create(adapterCustomerCreateRequest());

    expect($seen)->toBe($customerId);
});
