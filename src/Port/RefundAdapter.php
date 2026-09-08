<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use Override;
use Techork\PaymentService\Common\Contract\CustomerIdentifier;
use RuntimeException;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\RefundPort;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\Request\RefundRequest;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Role\RefundsPayments;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * {@see RefundPort} backed by {@see RefundsPayments}. Issues a refund against the parent payment
 * intent's gateway transaction and persists the refund's gateway reference. Gateway refusal
 * becomes {@see GatewayDeclinedException} so the aggregate records `RefundFailed`.
 *
 * The two-step retry lives here now, not in the gateway layer. When the original card refuses the
 * money and the caller supplied somewhere else to send it, a second attempt goes out on the
 * provider's own primitive for that — ConnexPay returns to a retry card, Nuvei pays out, Stripe
 * has nothing and says so. Sequencing those two calls is a decision about this refund, made by
 * the same layer that was handed the alternative instrument; the gateway layer's job is to answer
 * each call, not to decide there should be a second one.
 */
final readonly class RefundAdapter implements RefundPort
{
    /**
     * @param  ?CustomerIdentifier  $customerId  Whose money is being returned. On the adapter for
     *   the reason {@see CreateAdapter} gives, and it is the retry that needs it: "refund to a
     *   different card" is a payout at Nuvei, and a payout names the user it pays.
     */
    public function __construct(
        private RefundsPayments $gateway,
        private GatewayTransactionRepository $transactionRepository,
        private GatewayId $gatewayId,
        private ?CustomerIdentifier $customerId = null,
    ) {}

    #[Override]
    public function refund(RefundRequest $request): void
    {
        $paymentIntentId = $request->paymentIntentId->toString();
        $refundId = $request->refundId->toString();

        $transactionReference = $this->transactionRepository->findForPaymentIntent($paymentIntentId)
            ?? throw new RuntimeException("No gateway transaction reference recorded for payment intent '$paymentIntentId'.");

        $command = new RefundCommand(
            gatewayId: $this->gatewayId,
            transactionReference: $transactionReference,
            amount: $request->amount,
            clientUniqueId: $refundId,
            retryInstrument: $request->retryInstrument,
            customerId: $this->customerId,
        );

        $result = $this->gateway->refund($command);

        // Step two only when the first was refused AND somewhere else to send the money was
        // named. A provider without the primitive refuses in its own way: unmarked, and the
        // failure below is what the merchant reads; marked, and it propagates, because asking an
        // issuing-only gateway to redirect a refund is a misroute rather than a declined refund.
        if (! $result->success && $request->retryInstrument !== null) {
            $result = $this->gateway->retryRefund($command);
        }

        $this->record($refundId, $result);
    }

    private function record(string $refundId, GatewayResult $result): void
    {
        if ($result->reference !== null) {
            $this->transactionRepository->saveForRefund($this->gatewayId, $refundId, $result->reference);
        }

        if (! $result->success) {
            throw new GatewayDeclinedException($result->message ?? 'Gateway declined the refund');
        }
    }
}
