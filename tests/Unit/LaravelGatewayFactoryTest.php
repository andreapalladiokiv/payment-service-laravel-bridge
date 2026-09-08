<?php

declare(strict_types=1);

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\RegisterCustomerCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Gateway\Contract\Gateway as GatewayContract;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\LaravelGatewayFactory;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayCustomerRepository;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;

/**
 * The only thing this factory adds to its parent is a merge, and the merge is a security
 * boundary: `services.{gateway_name}` — derived from APP_ENV, identical for every tenant — has to
 * beat whatever the tenant's credentials JSON says, so a stored `environment=production` cannot
 * open a live gateway from a dev build.
 *
 * Pinning the merged settings alone would not be enough, and that is why this file still exists.
 * Providers derive state from the environment when they are configured — ConnexPay builds both of
 * its HTTP clients, base URL included — so what matters is the value the gateway BAKED, not the
 * one it stored.
 *
 * What is gone with the parameter bag: the merge used to happen AFTER the gateway had been
 * initialised from credentials, so anything baked from the tenant's environment was stale and had
 * to be rebuilt by re-initialising. The settings are merged before `configure()` is called, once,
 * and there is no window in which a client and its configuration disagree.
 */
final class LaravelGatewayFactoryProbeGateway implements GatewayContract
{
    /** Derived in configure(), exactly as ConnexPay's clients derive their base URL. */
    public string $bakedBaseUrl = '';

    public ?GatewayCustomerRepository $attachedRepository = null;

    public ?GatewayInfrastructure $attachedInfrastructure = null;

    public function getName(): string
    {
        return 'probe_provider';
    }

    public function configure(GatewayInfrastructure $infrastructure): void
    {
        $this->attachedInfrastructure = $infrastructure;
        $this->attachedRepository = $infrastructure->customers;

        $this->bakedBaseUrl = $infrastructure->stringSetting('environment', 'sandbox') === 'production'
            ? 'https://live.probe.test'
            : 'https://sandbox.probe.test';
    }

    public function getEnvironment(): string
    {
        return $this->attachedInfrastructure?->stringSetting('environment', 'sandbox') ?? 'sandbox';
    }

    public function getUsername(): string
    {
        return $this->attachedInfrastructure?->stringSetting('username') ?? '';
    }

    public function getDeviceGuid(): string
    {
        return $this->attachedInfrastructure?->stringSetting('deviceGuid') ?? '';
    }

    public function authorize(PlacementCommand $command): AuthorizationResult
    {
        throw new BadMethodCallException('The factory never reaches an operation.');
    }

    public function charge(PlacementCommand $command): AuthorizationResult
    {
        throw new BadMethodCallException('The factory never reaches an operation.');
    }

    public function authorizeRebilling(RebillingCommand $command): AuthorizationResult
    {
        throw new BadMethodCallException('The factory never reaches an operation.');
    }

    public function capture(CaptureCommand $command): GatewayResult
    {
        throw new BadMethodCallException('The factory never reaches an operation.');
    }

    public function cancel(CancelCommand $command): GatewayResult
    {
        throw new BadMethodCallException('The factory never reaches an operation.');
    }

    public function refund(RefundCommand $command): GatewayResult
    {
        throw new BadMethodCallException('The factory never reaches an operation.');
    }

    public function retryRefund(RefundCommand $command): GatewayResult
    {
        throw new BadMethodCallException('The factory never reaches an operation.');
    }

    public function tokenize(VaultCommand $command): RegistrationResult
    {
        throw new BadMethodCallException('The factory never reaches an operation.');
    }

    public function registerPaymentMethod(VaultCommand $command): RegistrationResult
    {
        throw new BadMethodCallException('The factory never reaches an operation.');
    }

    public function registerCustomer(RegisterCustomerCommand $command): RegistrationResult
    {
        throw new BadMethodCallException('The factory never reaches an operation.');
    }

    public function issueVirtualCard(IssueCardCommand $command): VirtualCardResult
    {
        throw new BadMethodCallException('The factory never reaches an operation.');
    }

    public function updateVirtualCard(UpdateCardCommand $command): VirtualCardResult
    {
        throw new BadMethodCallException('The factory never reaches an operation.');
    }

    public function terminateVirtualCard(TerminateCardCommand $command): GatewayResult
    {
        throw new BadMethodCallException('The factory never reaches an operation.');
    }
}

/**
 * Stands in for a row of the `gateways` table without needing one: the factory reads
 * exactly three things off a credential, and the Eloquent model would drag an encrypter
 * and a schema behind it for no gain here.
 */
final class LaravelGatewayFactoryProbeCredential implements GatewayCredential
{
    private readonly GatewayId $id;

    /** @param array<string, string> $credentials */
    public function __construct(
        private readonly array $credentials,
        private readonly string $gatewayName = 'probe_provider',
        ?GatewayId $id = null,
    ) {
        $this->id = $id ?? GatewayId::generate();
    }

    public function getId(): GatewayId
    {
        return $this->id;
    }

    public function getGatewayName(): string
    {
        return $this->gatewayName;
    }

    /** @return array<string, string> */
    public function getCredentials(): array
    {
        return $this->credentials;
    }
}

/**
 * A factory holding the probe gateway under the name the service provider would register
 * it as — `replace()` keys the registry by `(new $class)->getName()`, and that key is what
 * the `services.{gateway_name}` lookup is spelled with.
 *
 * @param  array<string, mixed>  $config  the `services` array as app config would hold it
 * @param  list<string>  $names  extra registry names for the same probe class, for the
 *   case where two tenants run different providers out of one build
 */
function laravelGatewayFactoryUnderTest(array $config, array $names = []): LaravelGatewayFactory
{
    $registry = ['probe_provider' => LaravelGatewayFactoryProbeGateway::class];

    foreach ($names as $name) {
        $registry[$name] = LaravelGatewayFactoryProbeGateway::class;
    }

    // The real repository, not a double: the factory hands it to the gateway and nothing
    // here calls it, so there is no behaviour to fake and an identity worth asserting.
    $factory = new LaravelGatewayFactory(
        new EloquentGatewayCustomerRepository,
        Mockery::mock(DecryptInterface::class),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        new ConfigRepository(['services' => $config]),
    );
    $factory->replace($registry);

    return $factory;
}

it('overrides a stored environment and re-bakes the state initialize froze', function () {
    // The headline invariant. Credentials say production, the build says sandbox, and the
    // build must win — twice over: in the parameter bag AND in whatever the gateway built
    // out of that parameter while the credentials were still in force. A gateway that
    // reports sandbox while its HTTP client points at production is the failure this
    // guards, and it is invisible to anything that only reads getParameter().
    $factory = laravelGatewayFactoryUnderTest(['probe_provider' => ['environment' => 'sandbox']]);

    $gateway = $factory->createForCredential(new LaravelGatewayFactoryProbeCredential([
        'username' => 'tenant-user',
        'environment' => 'production',
    ]));

    expect($gateway)->toBeInstanceOf(LaravelGatewayFactoryProbeGateway::class)
        ->and($gateway->getEnvironment())->toBe('sandbox')
        ->and($gateway->bakedBaseUrl)->toBe('https://sandbox.probe.test');
});

it('keeps the credential parameters the config defaults say nothing about', function () {
    // The merge is a merge, not a replacement. `services.{gateway}` carries infrastructure
    // only; the per-tenant secrets live in credentials, and re-initialising with the
    // defaults alone would send every request out unauthenticated.
    $factory = laravelGatewayFactoryUnderTest(['probe_provider' => ['environment' => 'production']]);

    $gateway = $factory->createForCredential(new LaravelGatewayFactoryProbeCredential([
        'username' => 'tenant-user',
        'device_guid' => 'device-1',
    ]));

    expect($gateway->getUsername())->toBe('tenant-user')
        ->and($gateway->getDeviceGuid())->toBe('device-1')
        ->and($gateway->getEnvironment())->toBe('production')
        // Credentials survived the re-initialisation and the override still took, so the
        // baked URL is the config's — both halves of the merge in one value.
        ->and($gateway->bakedBaseUrl)->toBe('https://live.probe.test');
});

it('ignores a config key whose value is null rather than blanking the credential', function () {
    // A half-filled `services` entry is the normal case: config files list every key and
    // read them from env vars, so an unset var arrives as null. Null means "nothing to
    // say", not "clear it" — treating it as a value would wipe a tenant's own setting and,
    // for `environment`, hand the gateway an empty string where a URL is derived from it.
    $factory = laravelGatewayFactoryUnderTest([
        'probe_provider' => ['environment' => null, 'username' => null],
    ]);

    $gateway = $factory->createForCredential(new LaravelGatewayFactoryProbeCredential([
        'username' => 'tenant-user',
        'environment' => 'production',
    ]));

    expect($gateway->getEnvironment())->toBe('production')
        ->and($gateway->getUsername())->toBe('tenant-user')
        ->and($gateway->bakedBaseUrl)->toBe('https://live.probe.test');
});

it('leaves a credential-initialised gateway alone when the service has no config at all', function () {
    // No `services.{gateway_name}` entry — a provider deployed without infrastructure
    // config — must not disturb what the credentials established, including the state the
    // parent's initialize() already baked from them.
    $factory = laravelGatewayFactoryUnderTest(['some_other_provider' => ['environment' => 'sandbox']]);

    $gateway = $factory->createForCredential(new LaravelGatewayFactoryProbeCredential([
        'username' => 'tenant-user',
        'environment' => 'production',
    ]));

    expect($gateway->getEnvironment())->toBe('production')
        ->and($gateway->getUsername())->toBe('tenant-user')
        ->and($gateway->bakedBaseUrl)->toBe('https://live.probe.test');
});

it('applies a snake_case config key through the setter that owns it', function () {
    // Config keys are snake_case, gateway parameters are camelCase, and only the provider's
    // `set*` accessor knows the mapping. Pinned because the value has to arrive where the
    // gateway's own getters look for it — the raw key sitting in the parameter bag under
    // its config spelling would read as applied while the request built nothing from it.
    $factory = laravelGatewayFactoryUnderTest([
        'probe_provider' => ['device_guid' => 'infra-device'],
    ]);

    $gateway = $factory->createForCredential(new LaravelGatewayFactoryProbeCredential([
        'device_guid' => 'tenant-device',
    ]));

    expect($gateway->getDeviceGuid())->toBe('infra-device');
});

it('gives each gateway name its own services entry', function () {
    // One build serves every tenant and every provider, so the lookup is keyed by the
    // credential's gateway_name. A shared or first-wins lookup would let one provider's
    // environment configure another's — the same leak as a stored credential winning,
    // arriving from the other side.
    $factory = laravelGatewayFactoryUnderTest([
        'probe_provider' => ['environment' => 'sandbox'],
        'other_provider' => ['environment' => 'production'],
    ], ['other_provider']);

    $sandboxSide = $factory->createForCredential(new LaravelGatewayFactoryProbeCredential([], 'probe_provider'));
    $productionSide = $factory->createForCredential(new LaravelGatewayFactoryProbeCredential([], 'other_provider'));

    expect($sandboxSide->bakedBaseUrl)->toBe('https://sandbox.probe.test')
        ->and($productionSide->bakedBaseUrl)->toBe('https://live.probe.test');
});

it('hands the gateway-customer map down to the driver it builds', function () {
    // The map reaches a driver as part of `GatewayInfrastructure`, once, and this is the only
    // route: the per-gateway `setCustomerRepository()` it used to arrive through is gone, and so
    // is the null it left a driver holding until something called it. What this pins is that the
    // factory passes the same instance it was constructed with — a driver given a different one,
    // or none, resolves every customer to null and every payment reaches the provider anonymous,
    // which fails far from here.
    //
    // The comment this replaced described the parent setting the repository between two
    // `initialize()` calls, and Omnipay's parameter bag went with `bf8ebfd`. There is one
    // configuration step now, so there is no window in which a driver and its configuration
    // disagree.
    $repository = new EloquentGatewayCustomerRepository;
    $factory = new LaravelGatewayFactory(
        $repository,
        Mockery::mock(DecryptInterface::class),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        new ConfigRepository(['services' => ['probe_provider' => ['environment' => 'sandbox']]]),
    );
    $factory->replace(['probe_provider' => LaravelGatewayFactoryProbeGateway::class]);

    $gateway = $factory->createForCredential(new LaravelGatewayFactoryProbeCredential(['environment' => 'production']));

    expect($gateway->attachedRepository)->toBe($repository);
});

it('reuses the cached gateway for a repeated credential without losing the merged parameters', function () {
    // The parent caches per credential id and this override runs again on every hit, so the
    // second call re-applies the defaults to an already-merged gateway. That repetition must
    // be a no-op: same instance, same tenant credentials, same overridden environment.
    $factory = laravelGatewayFactoryUnderTest(['probe_provider' => ['environment' => 'sandbox']]);
    $credential = new LaravelGatewayFactoryProbeCredential([
        'username' => 'tenant-user',
        'environment' => 'production',
    ]);

    $first = $factory->createForCredential($credential);
    $second = $factory->createForCredential($credential);

    expect($second)->toBe($first)
        ->and($second->getUsername())->toBe('tenant-user')
        ->and($second->getEnvironment())->toBe('sandbox')
        ->and($second->bakedBaseUrl)->toBe('https://sandbox.probe.test');
});

it('is resolvable by the container, which is how the service provider builds it', function () {
    // The defect class this package pays for most: a constructor the container cannot read
    // is approved by static analysis and invisible to a green suite, and no payment opens.
    // `ConfigRepository` is an interface aliased to the `config` instance, so resolving it
    // proves the alias is what the parameter names.
    $app = new Application(sys_get_temp_dir());
    $app->instance('config', new ConfigRepository(['services' => []]));
    $app->bind(GatewayCustomerRepository::class, EloquentGatewayCustomerRepository::class);
    // The repository is bound to the concrete the service provider names. The decrypter is not:
    // `LaravelEncrypter` wants the framework's `encrypter` service, which a bare Application has
    // no reason to boot. What is under test is that the container can READ the constructor, and a
    // bound interface answers that either way.
    $app->bind(GatewayInstrumentRepository::class, EloquentGatewayInstrumentRepository::class);
    $app->instance(DecryptInterface::class, Mockery::mock(DecryptInterface::class));

    expect($app->make(LaravelGatewayFactory::class))->toBeInstanceOf(LaravelGatewayFactory::class);
});
