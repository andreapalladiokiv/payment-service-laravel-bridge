<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Port\CreateAdapter;
use Techork\PaymentService\Laravel\Port\PaymentAlreadyPlaced;
use Techork\PaymentService\Gateway\Role\PlacesPayments;

function replayCreateRequest(PaymentIntentId $id): CreateRequest
{
    return new CreateRequest(
        paymentIntentId: $id,
        amount: new Money(5000, new Currency('USD')),
        instrument: Mockery::mock(PaymentInstrument::class),
        captureMethod: CaptureMethod::Manual,
        billingAddress: new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );
}

/**
 * A gateway's idempotency key is not replay protection. Stripe's lasts a day, ConnexPay's
 * thirty minutes, and a job retried after that — or a stream replayed — authorizes again:
 * a second hold on a cardholder's card for a payment already placed.
 *
 * The durable answer is ours, not theirs. We already know the gateway reference for this
 * payment; holding one means the call has been made, and making it again cannot be right
 * whatever the clock says.
 */
it('does not reach the acquirer for a payment it has already placed', function () {
    $gateway = Mockery::mock(PlacesPayments::class);
    $gateway->shouldNotReceive('authorize');
    $gateway->shouldNotReceive('charge');

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturn('cxp-guid-already-placed');
    $txRepo->shouldNotReceive('saveForPaymentIntent');

    $port = new CreateAdapter($gateway, $txRepo, GatewayId::generate());

    $port->create(replayCreateRequest(PaymentIntentId::generate()));
})->throws(PaymentAlreadyPlaced::class);

/**
 * It refuses rather than reporting the original outcome, because it does not know what the
 * original outcome was: a stored reference says a transaction exists, not that it was
 * approved. Answering "authorized" from the presence of an id is the mistake this package
 * has already made once, in Stripe's `isSuccessful()`.
 */
it('says which payment it is refusing to place twice', function () {
    $id = PaymentIntentId::generate();

    $gateway = Mockery::mock(PlacesPayments::class);
    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturn('cxp-guid');

    $port = new CreateAdapter($gateway, $txRepo, GatewayId::generate());

    try {
        $port->create(replayCreateRequest($id));
        $thrown = null;
    } catch (PaymentAlreadyPlaced $e) {
        $thrown = $e;
    }

    expect($thrown?->getMessage())->toContain($id->toString())
        ->and($thrown?->getMessage())->toContain('cxp-guid');
});

it('places a payment it has never seen', function () {
    $gateway = Mockery::mock(PlacesPayments::class);
    $gateway->shouldReceive('authorize')->once()->andReturn(
        Techork\PaymentService\Gateway\Contract\AuthorizationResult::succeeded('ref_new'),
    );

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturnNull();
    $txRepo->shouldReceive('saveForPaymentIntent')->once();

    $port = new CreateAdapter($gateway, $txRepo, GatewayId::generate());

    expect($port->create(replayCreateRequest(PaymentIntentId::generate()))->challenge)->toBeNull();
});
