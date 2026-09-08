<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Override;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\GatewayFactory;

/**
 * {@see GatewayFactory} with the deployment's own settings.
 *
 * `services.{gateway_name}` is derived from APP_ENV — production build, production gateway;
 * otherwise sandbox — and is identical for every tenant, so it overrides whatever the tenant's
 * credentials JSON says. The parent merges it over the credential row before configuring the
 * driver, which is the whole of what this class adds.
 *
 * It used to add more: a per-key `setParameter` loop after construction, then a second
 * `initialize()` to make providers that had already baked state rebuild it. Both are gone with the
 * parameter bag.
 */
final class LaravelGatewayFactory extends GatewayFactory
{
    public function __construct(
        GatewayCustomerRepository $customers,
        DecryptInterface $decrypter,
        GatewayInstrumentRepository $instruments,
        private readonly ConfigRepository $config,
    ) {
        parent::__construct($customers, $decrypter, $instruments);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function settingsFor(string $gatewayName): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = (array) $this->config->get("services.{$gatewayName}", []);

        // A null default states nothing; letting it through would blank a credential the tenant
        // did store.
        return array_filter($defaults, static fn (mixed $value): bool => $value !== null);
    }
}
