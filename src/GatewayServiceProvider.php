<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel;

use EventSauce\EventSourcing\DefaultHeadersDecorator;
use EventSauce\EventSourcing\MessageDecorator;
use EventSauce\EventSourcing\MessageDecoratorChain;
use EventSauce\EventSourcing\MessageDispatcher;
use EventSauce\EventSourcing\MessageDispatcherChain;
use EventSauce\EventSourcing\MessageRepository;
use EventSauce\EventSourcing\Serialization\ConstructingMessageSerializer;
use EventSauce\EventSourcing\Snapshotting\SnapshotRepository;
use EventSauce\EventSourcing\SynchronousMessageDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Validation\Factory;
use Illuminate\Validation\InvokableValidationRule;
use Omnipay\Omnipay;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Serializer\Serializer;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Domain\Checkout\CheckoutAggregateRepositoryInterface;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregateRepositoryInterface;
use Techork\PaymentService\Domain\Subscription\SubscriptionAggregateRepositoryInterface;
use Techork\PaymentService\Firewall\Dsl\FactSchema;
use Techork\PaymentService\Firewall\Dsl\RuleCompiler;
use Techork\PaymentService\Firewall\Dsl\RuleEvaluator;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface;
use Techork\PaymentService\Gateway\Contract\VirtualCardReferenceRepository;
use Techork\PaymentService\Gateway\GatewayFactory;
use Techork\PaymentService\Laravel\Encryption\EncrypterAwareInterface;
use Techork\PaymentService\Laravel\Encryption\LaravelEncrypter;
use Techork\PaymentService\Laravel\EventSourcing\Consumers\LaravelMessageConsumer;
use Techork\PaymentService\Laravel\EventSourcing\Decorators\GatewayIdMessageDecorator;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\CheckoutAggregateRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\IlluminateMessageRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\IlluminateSnapshotRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\PaymentIntentAggregateRepository;
use Techork\PaymentService\Laravel\EventSourcing\Repositories\SubscriptionAggregateRepository;
use Techork\PaymentService\Laravel\EventSourcing\Serialization\SymfonyPayloadSerializer;
use Techork\PaymentService\Laravel\Logger\Sanitizer\ByPropertyNameSanitizer;
use Techork\PaymentService\Laravel\Logger\SanitizerInterface;
use Techork\PaymentService\Laravel\Logger\Sanitizer\CardNumberSanitizer;
use Techork\PaymentService\Laravel\Logger\Sanitizer\EmailSanitizer;
use Techork\PaymentService\Laravel\Logger\Sanitizer\PhoneNumberSanitizer;
use Techork\PaymentService\Laravel\Logger\SanitizingLogger;
use Techork\PaymentService\Laravel\Repository\EloquentCustomerRepository;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayCredentialRepository;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayInstrumentRepository;
use Techork\PaymentService\Laravel\Repository\EloquentGatewayTransactionRepository;
use Techork\PaymentService\Laravel\Repository\EloquentVirtualCardReferenceRepository;
use Techork\PaymentService\Laravel\Rules\Country;
use Techork\PaymentService\Laravel\Rules\Currency;
use Techork\PaymentService\Laravel\Rules\Duration;
use Techork\PaymentService\Laravel\Rules\Phone;
use Techork\PaymentService\Laravel\Rules\State;
use Techork\PaymentService\Laravel\Serializer\PayloadSerializerFactory;
use Techork\PaymentService\Laravel\Shredding\EloquentPiiStore;
use Techork\PaymentService\Laravel\Shredding\PiiStore;
use Techork\PaymentService\Gateway\Logger\GatewayLoggerInterface;
use Techork\PaymentService\Gateway\PaymentGatewayRouter;

final class GatewayServiceProvider extends PackageServiceProvider
{
    public $singletons = [
        GatewayCredentialRepository::class => EloquentGatewayCredentialRepository::class,
        CustomerRepository::class => EloquentCustomerRepository::class,
        GatewayInstrumentRepository::class => EloquentGatewayInstrumentRepository::class,
        GatewayTransactionRepository::class => EloquentGatewayTransactionRepository::class,
        VirtualCardReferenceRepository::class => EloquentVirtualCardReferenceRepository::class,
        EncryptInterface::class => LaravelEncrypter::class,
        DecryptInterface::class => LaravelEncrypter::class,
        PiiStore::class => EloquentPiiStore::class,
    ];

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package
            ->name('gateway')
            ->hasMigrations([
                'create_gateways_table',
                'create_gateway_references_table',
                'create_gateway_customers_table',
                'extend_webhook_calls',
                'add_reference_index_to_gateway_references',
                'add_metadata_to_gateway_references',
                'create_shredding_values_table',
            ]);
    }

    #[Override]
    public function bootingPackage(): void
    {
        $this->app->make(Factory::class)->extend('country', function ($attribute, $value, $parameters, $validator) {
            return InvokableValidationRule::make(new Country(...$parameters))
                ->setValidator($validator)
                ->passes($attribute, $value);
        });
        $this->app->make(Factory::class)->extend('phone', function ($attribute, $value, $parameters, $validator) {
            return InvokableValidationRule::make(new Phone(...$parameters))
                ->setValidator($validator)
                ->passes($attribute, $value);
        });
        $this->app->make(Factory::class)->extend('state', function ($attribute, $value, $parameters, $validator) {
            return InvokableValidationRule::make(new State(...$parameters))
                ->setValidator($validator)
                ->passes($attribute, $value);
        });
        $this->app->make(Factory::class)->extend('currency', function ($attribute, $value, $parameters, $validator) {
            return InvokableValidationRule::make(new Currency)
                ->setValidator($validator)
                ->passes($attribute, $value);
        });
        $this->app->make(Factory::class)->extend('duration', function ($attribute, $value, $parameters, $validator) {
            return InvokableValidationRule::make(new Duration)
                ->setValidator($validator)
                ->passes($attribute, $value);
        });
    }

    #[Override]
    public function packageRegistered(): void
    {
        $this->app->singleton(PaymentGatewayInterface::class, PaymentGatewayRouter::class);

        $this->app->singleton(GatewayLoggerInterface::class, function (Application $app) {
            // `tagged()` is annotated as a bare `iterable`, so neither its keys nor its
            // values carry a type. Unpacking it directly is unverifiable twice over: the
            // spread needs int|string keys, and nothing would notice a tag pointing at a
            // class that is not a sanitiser. Materialising it as a list supplies the keys
            // and states what the tag is expected to hold.
            /** @var list<SanitizerInterface> $sanitizers */
            $sanitizers = iterator_to_array($app->tagged('sanitizer'), false);

            return new SanitizingLogger($app->make(LoggerInterface::class), LogLevel::INFO, ...$sanitizers);
        });

        $this->app->singleton(GatewayFactory::class, function (Application $app) {
            $manifest = $app->make(PackageManifest::class);
            $factory = new LaravelGatewayFactory($app->make(CustomerRepository::class), $app->make('config'));
            $factory->replace($this->discoverGateways($manifest));
            Omnipay::setFactory($factory);

            return $factory;
        });

        // Built by a factory the serializer tests use too, so a normalizer added here cannot
        // be missing from what they exercise.
        $this->app->singleton(Serializer::class, fn (Application $app) => PayloadSerializerFactory::make(
            $app->make(PiiStore::class),
        ));

        $this->app->singleton(MessageRepository::class, fn (Application $app) => new IlluminateMessageRepository(
            $app->make(DatabaseManager::class)->connection(),
            'stored_events',
            new ConstructingMessageSerializer(
                payloadSerializer: new SymfonyPayloadSerializer($app->make(Serializer::class)),
            ),
        ));

        $this->app->singleton(SnapshotRepository::class, fn (Application $app) => new IlluminateSnapshotRepository($app->make(DatabaseManager::class)->connection()));

        $this->app->singleton(SynchronousMessageDispatcher::class, fn (Application $app) => new SynchronousMessageDispatcher($app->make(LaravelMessageConsumer::class)));

        $this->app->singleton(DefaultHeadersDecorator::class, fn () => new DefaultHeadersDecorator);

        $this->app->tag([SynchronousMessageDispatcher::class], 'es.message_dispatcher');
        $this->app->tag([DefaultHeadersDecorator::class, GatewayIdMessageDecorator::class], 'es.message_decorator');

        $this->app->singleton(ByPropertyNameSanitizer::class, function () {
            return new ByPropertyNameSanitizer('first_name', 'last_name', 'line', 'line_extra', 'holder');
        });

        $this->app->tag([CardNumberSanitizer::class, EmailSanitizer::class, PhoneNumberSanitizer::class, ByPropertyNameSanitizer::class], 'sanitizer');

        $this->app->singleton(MessageDispatcher::class, function (Application $app) {
            /** @var list<MessageDispatcher> $dispatchers */
            $dispatchers = iterator_to_array($app->tagged('es.message_dispatcher'), false);

            return new MessageDispatcherChain(...$dispatchers);
        });

        $this->app->singleton(MessageDecorator::class, function (Application $app) {
            /** @var list<MessageDecorator> $decorators */
            $decorators = iterator_to_array($app->tagged('es.message_decorator'), false);

            return new MessageDecoratorChain(...$decorators);
        });

        $this->registerAggregateRepositories();

        $this->registerFirewall($this->app->make(PackageManifest::class));

        $this->app->resolving(
            EncrypterAwareInterface::class,
            fn (EncrypterAwareInterface $instance, Application $app) => $instance->setEncrypter($app->make(EncryptInterface::class)),
        );
    }

    /**
     * The rule DSL, wired with a parse cache.
     *
     * Discovered rather than assumed: a package declares its fact vocabulary in
     * `extra.laravel.firewall`, exactly as gateways and webhook subscribers
     * declare theirs. An empty section means the firewall package is not
     * installed, and nothing is registered — the manifest answers that question
     * properly, where a `class_exists` probe would only guess at it from the
     * autoloader.
     *
     * Only what the foundation can decide is bound. A working chain also needs a
     * `FirewallRuleSource` and `EnrichmentSuppliers`, and both belong to the
     * application — it owns the rules table, their ordering, and which risk
     * providers take part. Bind those two and `PaymentIntentFirewall` resolves on
     * its own.
     *
     * THE POOL IS THE POINT. Every evaluation hands ExpressionLanguage a compiled
     * string it must lex and parse, and on a chain of any size that dwarfs the
     * cost of evaluating the result. Symfony keys the parsed tree by expression
     * text, so a pool that outlives the request turns per-request parsing into
     * per-deploy parsing — measured at roughly a twentyfold saving on a chain of
     * twenty rules.
     *
     * The pool is deliberately a private filesystem one and NOT the application's
     * configured cache store. ExpressionLanguage looks up one key per expression
     * and has no multi-get, so N rules is N round trips; against the Redis store a
     * typical app configures, that costs more per payment than the parsing it was
     * meant to save. Override `firewall.parse_cache` only with another local pool.
     *
     * @psalm-suppress TypeDoesNotContainType the Laravel plugin resolves `config()->get()`
     *   as non-null, so it reads both `??` fallbacks below as dead. This package ships no
     *   config file at all: in a consuming app those keys are normally absent, and the
     *   fallbacks — schema discovery and the default cache directory — are the mechanism,
     *   not a defensive afterthought.
     */
    private function registerFirewall(PackageManifest $manifest): void
    {
        $schema = $this->app->make('config')->get('gateway.firewall.schema')
            ?? self::discoverFactSchema($manifest);

        if ($schema === null) {
            return;
        }

        $this->app->singleton('firewall.parse_cache', fn (Application $app) => new FilesystemAdapter(
            namespace: 'firewall-rules',
            defaultLifetime: 0,
            directory: $app->make('config')->get('gateway.firewall.cache_path')
                ?? $app->storagePath('framework/cache/firewall'),
        ));

        $this->app->singleton(FactSchema::class, $schema);

        $this->app->singleton(RuleEvaluator::class, function (Application $app) {
            $schema = $app->make(FactSchema::class);

            return new RuleEvaluator(new RuleCompiler($schema), $schema, $app->make('firewall.parse_cache'));
        });
    }

    /**
     * Reads `extra.laravel.firewall` from the manifest of every installed
     * package, keeping entries that are real {@see FactSchema} classes.
     *
     * A chain has exactly one vocabulary, so two schemas is an unresolvable
     * ambiguity in configuration rather than a runtime condition — and picking
     * one silently would evaluate every rule against the wrong fact roots,
     * rejecting them at authoring time or, worse, quietly not matching. It throws
     * instead; `gateway.firewall.schema` is the way to settle it.
     *
     * @return class-string<FactSchema>|null
     */
    private static function discoverFactSchema(PackageManifest $manifest): ?string
    {
        // Deduplicated because the foundation declares the schema and so does the
        // split firewall package; the two are mutually exclusive via `replace`,
        // but a partial install should not read as an ambiguity.
        $schemas = array_values(array_unique(array_filter(
            $manifest->config('firewall'),
            static fn ($class): bool => is_string($class)
                && class_exists($class)
                && is_a($class, FactSchema::class, true),
        )));

        if (count($schemas) > 1) {
            throw new RuntimeException(sprintf(
                'Several firewall fact schemas were discovered (%s). A chain has one vocabulary; '
                .'set gateway.firewall.schema to choose which.',
                implode(', ', $schemas),
            ));
        }

        return $schemas[0] ?? null;
    }

    private function registerAggregateRepositories(): void
    {
        $repositories = [
            CheckoutAggregateRepositoryInterface::class => CheckoutAggregateRepository::class,
            PaymentIntentAggregateRepositoryInterface::class => PaymentIntentAggregateRepository::class,
            SubscriptionAggregateRepositoryInterface::class => SubscriptionAggregateRepository::class,
        ];

        foreach ($repositories as $interface => $implementation) {
            $this->app->singleton($interface, fn (Application $app) => new $implementation(
                $app->make(MessageRepository::class),
                $app->make(SnapshotRepository::class),
                $app->make(MessageDispatcher::class),
                $app->make(MessageDecorator::class),
            ));
        }
    }

    /**
     * @return array<string, class-string<Gateway>>
     */
    private static function discoverGateways(PackageManifest $manifest): array
    {
        $classes = array_filter($manifest->config('gateway'), function ($class) {
            return is_string($class) && class_exists($class) && is_a($class, Gateway::class, true);
        });
        $gateways = [];

        foreach ($classes as $class) {
            $gateways[(new $class)->getName()] = $class;
        }

        return $gateways;
    }
}
