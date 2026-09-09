<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Serializer;

use InvalidArgumentException;
use Override;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use function sprintf;

/**
 * Resolve `PaymentInstrument` to a concrete class on both sides of the
 * serializer round-trip so {@see PiiAwareObjectNormalizer} can attach
 * per-class PII metadata. Without this, attempting to denormalize the
 * bare interface yields an empty PII attribute set — every hash stays a
 * hash and the inner reflection-based normalizer can't reconstitute
 * typed sub-VOs like `Holder`.
 *
 * Direction handling:
 *  - normalize: drive concrete-class selection through the matching
 *    {@see PaymentInstrumentVisitor} (same hierarchy used elsewhere to
 *    dispatch on instrument type).
 *  - denormalize: read the `type` discriminator written on the way out
 *    (each implementation already publishes one via `::type()`).
 *
 * In both directions the actual conversion is delegated to the wrapped
 * normalizer, so the PII pipeline keeps working on the concrete class.
 *
 * @implements PaymentInstrumentVisitor<array<string, mixed>>
 */
final class PaymentInstrumentNormalizer implements DenormalizerInterface, NormalizerInterface, PaymentInstrumentVisitor
{
    private ?string $format = null;

    /** @var array<string, mixed> */
    private array $context = [];

    public function __construct(private readonly PiiAwareObjectNormalizer $delegate) {}

    #[Override]
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $this->format = $format;
        $this->context = $context;

        return $data->accept($this);
    }

    #[Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof PaymentInstrument;
    }

    #[Override]
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): PaymentInstrument
    {
        $concreteType = match ($data['type'] ?? null) {
            CreditCard::type() => CreditCard::class,
            Cash::type() => Cash::class,
            Token::type() => Token::class,
            PaymentMethod::type() => PaymentMethod::class,
            HostedPayment::type() => HostedPayment::class,
            default => throw new InvalidArgumentException(sprintf(
                'Cannot denormalize PaymentInstrument: missing or unknown "type" key (%s).',
                var_export($data['type'] ?? null, true),
            )),
        };

        return $this->delegate->denormalize($data, $concreteType, $format, $context);
    }

    #[Override]
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_a($type, PaymentInstrument::class, true);
    }

    /**
     * @inheritDoc
     *
     * @return array<class-string, bool>
     */
    #[Override]
    public function getSupportedTypes(?string $format): array
    {
        return [PaymentInstrument::class => true];
    }

    #[Override]
    public function visitCreditCard(CreditCard $card): array
    {
        return $this->serialize($card);
    }

    #[Override]
    public function visitCash(Cash $cash): array
    {
        return $this->serialize($cash);
    }

    #[Override]
    public function visitToken(Token $token): array
    {
        return $this->serialize($token);
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): array
    {
        return $this->serialize($paymentMethod);
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): array
    {
        return $this->serialize($hosted);
    }

    /**
     * @param PaymentInstrument $instrument
     * @return array<string, mixed>
     * @throws ExceptionInterface
     */
    private function serialize(PaymentInstrument $instrument): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->delegate->normalize($instrument, $this->format, $this->context);
        $data['type'] = $instrument::type();

        return $data;
    }
}
