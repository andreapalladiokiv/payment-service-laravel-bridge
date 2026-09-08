<?php

declare(strict_types=1);

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway as GatewayContract;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Laravel\LaravelGatewayFactory;
use Techork\PaymentService\Laravel\Repository\EloquentCustomerRepository;

/**
 * The only thing this factory adds to its parent is a merge, and the merge is a security
 * boundary: `services.{gateway_name}` — derived from APP_ENV, identical for every tenant —
 * has to beat whatever the tenant's credentials JSON says, so a stored
 * `environment=production` cannot open a live gateway from a dev build.
 *
 * Pinning the parameter bag alone would not be enough, and that is why this file exists.
 * Providers bake environment-dependent state at initialize() time — ConnexPay builds both
 * of its HTTP clients, base URL included, inside its initialize() override — and the
 * parent factory has already called initialize() with the credentials by the time the
 * defaults are applied. Set a parameter afterwards and the bag says "sandbox" while the
 * client that was already built keeps talking to production. So every assertion below
 * checks the value the gateway BAKED, not only the value it stores.
 *
 * {@see LaravelGatewayFactoryProbeGateway} exists to make that baking observable: it is
 * shaped after ConnexPayGateway (default parameters, `set*` accessors, an initialize()
 * override that derives a base URL) rather than mocked, because a double would answer for
 * the merge and say nothing about the freeze that motivated the re-initialisation. It lives
 * here rather than importing a provider package, which the Laravel bridge does not depend on.
 *
 * Deliberately written against the factory's OBSERVABLE result — final parameters, final
 * baked URL, gateway identity — and not against how many times initialize() is called or
 * in what order the defaults are applied. Replacing the per-key `setParameter` loop plus
 * re-initialise with a single `initialize()` of an explicit merge has to keep every
 * assertion here true.
 */
final class LaravelGatewayFactoryProbeGateway extends AbstractGateway implements GatewayContract
{
    /**
     * Derived inside initialize() from the environment parameter, exactly as ConnexPay's
     * clients derive their base URL — the state a later setParameter() cannot reach.
     */
    public string $bakedBaseUrl = '';

    public ?CustomerRepository $attachedRepository = null;

    public function getName(): string
    {
        return 'probe_provider';
    }

    /** @return array<string, string> */
    public function getDefaultParameters(): array
    {
        return ['username' => '', 'environment' => 'sandbox', 'deviceGuid' => ''];
    }

    public function getEnvironment(): string
    {
        return $this->getParameter('environment') ?? 'sandbox';
    }

    public function setEnvironment(string $value): static
    {
        return $this->setParameter('environment', $value);
    }

    public function getUsername(): string
    {
        return $this->getParameter('username') ?? '';
    }

    public function setUsername(string $value): static
    {
        return $this->setParameter('username', $value);
    }

    public function getDeviceGuid(): string
    {
        return $this->getParameter('deviceGuid') ?? '';
    }

    public function setDeviceGuid(string $value): static
    {
        return $this->setParameter('deviceGuid', $value);
    }

    public function initialize(array $parameters = []): static
    {
        parent::initialize($parameters);

        $this->bakedBaseUrl = $this->getEnvironment() === 'production'
            ? 'https://live.probe.test'
            : 'https://sandbox.probe.test';

        return $this;
    }

    public function setCustomerRepository(CustomerRepository $repository): void
    {
        $this->attachedRepository = $repository;
    }

    public function createPaymentMethod(array $options = []): RequestInterface
    {
        throw new BadMethodCallException('The factory never reaches a request.');
    }

    public function void(array $options = []): RequestInterface
    {
        throw new BadMethodCallException('The factory never reaches a request.');
    }

    public function issueVirtualCard(array $options = []): RequestInterface
    {
        throw new BadMethodCallException('The factory never reaches a request.');
    }

    public function terminateVirtualCard(array $options = []): RequestInterface
    {
        throw new BadMethodCallException('The factory never reaches a request.');
    }

    public function retryRefund(array $options = []): RequestInterface
    {
        throw new BadMethodCallException('The factory never reaches a request.');
    }

    public function updateVirtualCard(array $options = []): RequestInterface
    {
        throw new BadMethodCallException('The factory never reaches a request.');
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
    $factory = new LaravelGatewayFactory(new EloquentCustomerRepository, new ConfigRepository(['services' => $config]));
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

it('still holds the customer repository the parent attached after re-initialising', function () {
    // The parent sets the repository between its initialize() and ours. It is a property
    // rather than a parameter, so a re-initialise must leave it standing; providers call it
    // during tokenization and a null there fails only at that point, far from here.
    $repository = new EloquentCustomerRepository;
    $factory = new LaravelGatewayFactory(
        $repository,
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
    $app->bind(CustomerRepository::class, EloquentCustomerRepository::class);

    expect($app->make(LaravelGatewayFactory::class))->toBeInstanceOf(LaravelGatewayFactory::class);
});
