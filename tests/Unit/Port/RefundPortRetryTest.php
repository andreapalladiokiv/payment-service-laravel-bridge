<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\Request\RefundRequest;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\Role\RefundsPayments;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\Port\RefundAdapter;

/**
 * The two-step refund, which used to live inside the gateway stack::refund` and now lives
 * where the alternative instrument comes from. What is asserted is the sequencing, not the
 * provider call: whether there is a second primitive at all, and what its absence means, is the
 * gateway's business and is pinned in its own package.
 */
function retryRefundRepo(): GatewayTransactionRepository
{
    $repo = Mockery::mock(GatewayTransactionRepository::class);
    $repo->shouldReceive('findForPaymentIntent')->andReturn('sale_ref');
    $repo->shouldReceive('saveForRefund')->zeroOrMoreTimes();

    return $repo;
}

function retryRefundRequest(?PaymentInstrument $retryInstrument): RefundRequest
{
    return new RefundRequest(
        paymentIntentId: PaymentIntentId::generate(),
        refundId: RefundId::generate(),
        amount: new Money(1500, new Currency('USD')),
        retryInstrument: $retryInstrument,
    );
}

it('does not reach for another card when the original refund succeeded', function () {
    $gateway = Mockery::mock(RefundsPayments::class);
    $gateway->shouldReceive('refund')->once()->andReturn(GatewayResult::succeeded('ref_1'));
    $gateway->shouldReceive('retryRefund')->never();

    new RefundAdapter($gateway, retryRefundRepo(), GatewayId::generate())
        ->refund(retryRefundRequest(Mockery::mock(PaymentInstrument::class)));
});

/**
 * No alternative instrument means the caller named nowhere else to send the money, so a refused
 * refund is the answer rather than the first half of one.
 */
it('does not reach for another card when none was named', function () {
    $gateway = Mockery::mock(RefundsPayments::class);
    $gateway->shouldReceive('refund')->once()->andReturn(GatewayResult::failed('Original card closed'));
    $gateway->shouldReceive('retryRefund')->never();

    expect(fn () => new RefundAdapter($gateway, retryRefundRepo(), GatewayId::generate())
        ->refund(retryRefundRequest(null)))
        ->toThrow(GatewayDeclinedException::class, 'Original card closed');
});

it('sends the money to the alternative card once the original one refuses it', function () {
    $seen = null;

    $gateway = Mockery::mock(RefundsPayments::class);
    $gateway->shouldReceive('refund')->once()->andReturn(GatewayResult::failed('Original card closed'));
    $gateway->shouldReceive('retryRefund')->once()->andReturnUsing(function (RefundCommand $command) use (&$seen) {
        $seen = $command;

        return GatewayResult::succeeded('credit_ref');
    });

    $instrument = Mockery::mock(PaymentInstrument::class);

    new RefundAdapter($gateway, retryRefundRepo(), GatewayId::generate())
        ->refund(retryRefundRequest($instrument));

    expect($seen->retryInstrument)->toBe($instrument)
        ->and($seen->transactionReference)->toBe('sale_ref');
});

/**
 * A provider whose refusal is unmarked has folded it into a failed result by the time it gets
 * here, and that failure is what the merchant reads — Stripe, which refunds fine but cannot
 * redirect one. A marked refusal is a misroute and propagates instead of being reported as a
 * second decline.
 */
it('reports an unmarked refusal of the retry as the refund failing', function () {
    $gateway = Mockery::mock(RefundsPayments::class);
    $gateway->shouldReceive('refund')->andReturn(GatewayResult::failed('Original card closed'));
    $gateway->shouldReceive('retryRefund')->andReturn(GatewayResult::failed('Stripe does not support refunding to an alternative card'));

    expect(fn () => new RefundAdapter($gateway, retryRefundRepo(), GatewayId::generate())
        ->refund(retryRefundRequest(Mockery::mock(PaymentInstrument::class))))
        ->toThrow(GatewayDeclinedException::class, 'alternative card');
});

it('lets a marked refusal of the retry propagate rather than reporting a decline', function () {
    $gateway = Mockery::mock(RefundsPayments::class);
    $gateway->shouldReceive('refund')->andReturn(GatewayResult::failed('Original card closed'));
    $gateway->shouldReceive('retryRefund')->andThrow(
        UnsupportedOperation::forGateway('revolut', 'retryRefund', 'it acquires nothing'),
    );

    expect(fn () => new RefundAdapter($gateway, retryRefundRepo(), GatewayId::generate())
        ->refund(retryRefundRequest(Mockery::mock(PaymentInstrument::class))))
        ->toThrow(UnsupportedOperation::class);
});
