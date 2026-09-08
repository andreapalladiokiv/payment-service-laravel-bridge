<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Override;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\GatewayFactory;

/**
 * GatewayFactory wired with Laravel-side defaults.
 *
 * After the parent factory hydrates the gateway from per-tenant credentials,
 * this override applies infrastructure parameters (environment, …) sourced
 * from `services.{gateway_name}` in app config. Those values are NOT part
 * of credentials — they are derived from APP_ENV (production build →
 * production gateway, otherwise sandbox) and are applied uniformly across
 * all tenants. Anything per-tenant in credentials JSON is overridden so a
 * stored `environment=production` can never leak into a dev build.
 */
final class LaravelGatewayFactory extends GatewayFactory
{
    public function __construct(
        CustomerRepository $repository,
        private readonly ConfigRepository $config,
    ) {
        parent::__construct($repository);
    }

    #[Override]
    public function createForCredential(GatewayCredential $credential): Gateway
    {
        $gateway = parent::createForCredential($credential);

        /** @var array<string, mixed> $defaults */
        $defaults = (array) $this->config->get("services.{$credential->getGatewayName()}", []);
        $applied = false;
        foreach ($defaults as $key => $value) {
            if ($value !== null) {
                $gateway->setParameter($key, $value);
                $applied = true;
            }
        }

        // parent::createForCredential already invoked initialize() with the
        // credentials, which froze any environment-dependent state (e.g.
        // ConnexPay's HTTP clients bake the base URL from environment at
        // initialize() time). Re-initialize with the merged parameter set so
        // services.{gateway} overrides actually take effect on those clients.
        if ($applied) {
            $gateway->initialize($gateway->getParameters());
        }

        return $gateway;
    }
}
