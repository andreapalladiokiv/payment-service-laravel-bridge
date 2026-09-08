<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CancelRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\Request\RefundRequest;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Port\CancelAdapter;
use Techork\PaymentService\Laravel\Port\CaptureAdapter;
use Techork\PaymentService\Laravel\Port\RefundAdapter;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Role\CapturesPayments;
use Techork\PaymentService\Gateway\Role\CancelsPayments;
use Techork\PaymentService\Gateway\Role\RefundsPayments;
use Techork\PaymentService\Gateway\Role\PlacesPayments;

/**
 * Reading the acquirer's reference and writing it now live in the same layer. The
 * gateway used to read it itself while the ports wrote it, which split one identity's
 * lifecycle in two and — worse — let the same missing row mean different things per
 * operation: cancel returned a failed result, capture and refund threw.
 */
function referenceRepo(?string $reference): GatewayTransactionRepository
{
    $repo = Mockery::mock(GatewayTransactionRepository::class);
    $repo->shouldReceive('findForPaymentIntent')->andReturn($reference);
    $repo->shouldReceive('saveForPaymentIntent')->zeroOrMoreTimes();
    $repo->shouldReceive('saveForRefund')->zeroOrMoreTimes();

    return $repo;
}

/**
 * Capture has its own unreachable double now: it depends on {@see CapturesPayments}, one method
 * instead of the whole gateway, which is the point of the split and also why it can no longer
 * share this one.
 */
function unreachableCaptureGateway(): CapturesPayments
{
    $gateway = Mockery::mock(CapturesPayments::class);
    $gateway->shouldReceive('capture')->never();

    return $gateway;
}

function unreachableCancelGateway(): CancelsPayments
{
    $gateway = Mockery::mock(CancelsPayments::class);
    $gateway->shouldReceive('cancel')->never();

    return $gateway;
}

function unreachableRefundGateway(): RefundsPayments
{
    $gateway = Mockery::mock(RefundsPayments::class);
    $gateway->shouldReceive('refund')->never();
    $gateway->shouldReceive('retryRefund')->never();

    return $gateway;
}

function unreachableGateway(): PlacesPayments
{
    $gateway = Mockery::mock(PlacesPayments::class);
    $gateway->shouldReceive('authorize')->never();
    $gateway->shouldReceive('charge')->never();

    return $gateway;
}

it('hands the resolved reference to the gateway, which no longer looks it up', function () {
    $seen = null;

    $gateway = Mockery::mock(CapturesPayments::class);
    $gateway->shouldReceive('capture')->once()->andReturnUsing(function (CaptureCommand $command) use (&$seen) {
        $seen = $command->transactionReference;

        return GatewayResult::succeeded('cap_1');
    });

    new CaptureAdapter($gateway, referenceRepo('auth_ref'), GatewayId::generate())
        ->capture(new CaptureRequest(
            PaymentIntentId::generate(),
            new Money(100, new Currency('USD')),
            new Money(100, new Currency('USD')),
            Mockery::mock(PaymentInstrument::class),
        ));

    expect($seen)->toBe('auth_ref');
});

it('refuses a capture with no recorded reference instead of asking the acquirer', function () {
    $port = new CaptureAdapter(unreachableCaptureGateway(), referenceRepo(null), GatewayId::generate());

    expect(fn () => $port->capture(new CaptureRequest(
            PaymentIntentId::generate(),
            new Money(100, new Currency('USD')),
            new Money(100, new Currency('USD')),
            Mockery::mock(PaymentInstrument::class),
        )))
        ->toThrow(RuntimeException::class, 'No gateway transaction reference recorded');
});

it('refuses a refund with no recorded reference instead of recording RefundFailed', function () {
    $port = new RefundAdapter(unreachableRefundGateway(), referenceRepo(null), GatewayId::generate());

    // Not GatewayDeclinedException: that is what the aggregate turns into RefundFailed,
    // and nobody declined anything here.
    expect(fn () => $port->refund(new RefundRequest(
        paymentIntentId: PaymentIntentId::generate(),
        refundId: RefundId::generate(),
        amount: new Money(100, new Currency('USD')),
    )))
        ->toThrow(RuntimeException::class, 'No gateway transaction reference recorded')
        ->not->toThrow(GatewayDeclinedException::class);
});

it('stops reporting a missing reference as an issuer refusing a cancellation', function () {
    // The behaviour this refactor changes. Before, the gateway answered a missing row
    // with GatewayResult::failed(), the port turned that into GatewayDeclinedException,
    // and the aggregate recorded a failure — telling operators an issuer said no to a
    // cancellation it never received.
    $port = new CancelAdapter(unreachableCancelGateway(), referenceRepo(null), GatewayId::generate());

    $thrown = null;

    try {
        $port->cancel(new CancelRequest(PaymentIntentId::generate()));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown)->not->toBeInstanceOf(GatewayDeclinedException::class)
        ->and($thrown->getMessage())->toContain('No gateway transaction reference recorded');
});

it('forwards the hold and the instrument, without which ConnexPay takes the full amount', function () {
    // The two arguments the gateway roles::capture has always accepted and this
    // port never passed. ConnexPayGateway::capture reads them to notice that a partial
    // capture was asked for and to build the void-and-resell its provider requires;
    // its own docblock says that without them "the full hold would be captured
    // silently". The branch had tests. Nothing reached it.
    $seen = null;

    $gateway = Mockery::mock(CapturesPayments::class);
    $gateway->shouldReceive('capture')->once()->andReturnUsing(function (CaptureCommand $command) use (&$seen) {
        $seen = $command;

        return GatewayResult::succeeded('cap_1');
    });

    $authorized = new Money(1000, new Currency('USD'));
    $partial = new Money(300, new Currency('USD'));
    $instrument = Mockery::mock(PaymentInstrument::class);

    new CaptureAdapter($gateway, referenceRepo('auth_ref'), GatewayId::generate())
        ->capture(new CaptureRequest(PaymentIntentId::generate(), $partial, $authorized, $instrument));

    // Named fields on one object, where this used to index into six positional arguments and the
    // comment above it had to say which was which.
    expect($seen->amount)->toBe($partial)
        ->and($seen->authorizedAmount)->toBe($authorized)
        ->and($seen->instrument)->toBe($instrument);
});
