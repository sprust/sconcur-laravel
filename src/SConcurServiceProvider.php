<?php

declare(strict_types=1);

namespace SConcur\Laravel;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use SConcur\Laravel\Config\AsyncConfig;
use SConcur\Laravel\Console\ExtensionLoadCommand;
use SConcur\Laravel\Console\ExtensionStatusCommand;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\QueueManager;
use SConcur\Laravel\Console\HttpStartCommand;
use SConcur\Laravel\Console\MasterReloadCommand;
use SConcur\Laravel\Console\MasterStartCommand;
use SConcur\Laravel\Console\MasterStatusCommand;
use SConcur\Laravel\Console\MasterStopCommand;
use SConcur\Laravel\Events\AsyncDispatcher;
use SConcur\Laravel\Console\RabbitmqConsumerStartCommand;
use SConcur\Laravel\Console\RabbitmqDeclareCommand;
use SConcur\Laravel\Console\TasksRestartCommand;
use SConcur\Laravel\Console\TasksStartCommand;
use SConcur\Laravel\Console\TasksStopCommand;
use SConcur\Laravel\Console\WsStartCommand;
use SConcur\Laravel\Database\CoroutineTransactionsManager;
use SConcur\Laravel\Database\Mysql\Connection as SconcurMysqlConnection;
use SConcur\Laravel\Database\Mysql\Connector as SconcurMysqlConnector;
use SConcur\Laravel\Queue\Rabbitmq\Connector;
use SConcur\Laravel\Routing\AsyncRouter;
use SConcur\Laravel\Tasks\Control\ControlChannel;
use SConcur\Laravel\Tasks\CooperativeSleeper;
use SConcur\Laravel\Tasks\TaskPoolLogger;
use SConcur\Laravel\Tasks\TaskPoolOptions;
use SConcur\Laravel\Tasks\TaskRegistry;
use SConcur\Laravel\Translation\AsyncTranslator;
use SConcur\Laravel\View\AsyncViewFactory;
use SConcur\Laravel\Ws\Auth\SignatureVerifier;
use SConcur\Laravel\Ws\Broadcasting\SConcurBroadcaster;
use SConcur\Laravel\Ws\Bus\AmqpBroadcastBus;
use SConcur\Laravel\Ws\Bus\BroadcastBusInterface;
use SConcur\Laravel\Ws\Bus\BusSubscriber;
use SConcur\Laravel\Ws\Bus\LocalBroadcastBus;
use SConcur\Laravel\Ws\ConnectionRegistry;
use SConcur\Laravel\Ws\Presence\CachePresenceRepository;
use SConcur\Laravel\Ws\Presence\MemoryPresenceRepository;
use SConcur\Laravel\Ws\Presence\PresencePayload;
use SConcur\Laravel\Ws\Presence\PresenceRepositoryInterface;
use SConcur\Laravel\Ws\Protocol\MessageCodec;
use SConcur\Laravel\Ws\SocketIdGenerator;
use SConcur\Laravel\Ws\WsBusOptions;
use SConcur\Laravel\Ws\WsLogger;
use SConcur\Laravel\Ws\WsOptions;
use SConcur\Laravel\Ws\WsPresenceOptions;
use RuntimeException;

/**
 * Laravel service provider for the SConcur integration.
 *
 * Always: registers the artisan commands, the `sconcur_rabbitmq` queue connector, the
 * `sconcur_mysql` database driver and the coroutine-scoped transactions manager. None of
 * them depends on the process being a coroutine one — the SQL feature works synchronously
 * too, so which connection an application uses is its own choice, made in
 * config/database.php like any other.
 *
 * The config is published, not merged. Merging would leave the package's own defaults
 * standing behind the application's file, so a value the application deleted would
 * quietly come back — and the package would have to carry defaults for things only the
 * application knows, such as which queues to consume. Publish it with
 * `vendor:publish --tag=sconcur-laravel`; without it config('sconcur') is empty and the
 * commands say so rather than running on someone else's numbers.
 * The application is coroutine-scoped in every process, with nothing to turn on and
 * nothing to detect: config, events, router, translator and view are swapped for their
 * coroutine-safe adapters, and the container resolves request/session/auth/cookie per
 * coroutine. Each of them keeps its state in the coroutine context, which outside a
 * coroutine is the process root — a single store for a single caller, which is exactly
 * what the stock implementations are.
 *
 * See docs/fiber-safe-laravel-bridge.ru.md.
 */
class SConcurServiceProvider extends ServiceProvider
{
    /** Adapters that flip into per-coroutine mode once the app is booted. */
    private const array BOOT_COMPLETED_ADAPTERS = ['config', 'events', 'router', 'translator', 'view'];

    /**
     * @throws BindingResolutionException
     */
    public function register(): void
    {
        $this->commands([
            MasterStartCommand::class,
            MasterStopCommand::class,
            MasterStatusCommand::class,
            MasterReloadCommand::class,
            HttpStartCommand::class,
            RabbitmqConsumerStartCommand::class,
            RabbitmqDeclareCommand::class,
            WsStartCommand::class,
            TasksStartCommand::class,
            TasksStopCommand::class,
            TasksRestartCommand::class,
            ExtensionLoadCommand::class,
            ExtensionStatusCommand::class,
        ]);

        $this->registerQueueConnector();
        $this->registerDatabaseDriver();
        $this->registerCoroutineTransactionsManager();
        $this->registerTaskPool();
        $this->registerWsPool();
        $this->registerBroadcaster();
        $this->registerAsyncAdapters();
    }

    public function boot(): void
    {
        $this->publishes(
            paths: [
                __DIR__ . '/../config/sconcur.php' => config_path('sconcur.php'),
            ],
            groups: [
                'sconcur-laravel',
            ]
        );
    }

    /**
     * Registers the `sconcur_rabbitmq` queue driver.
     *
     * resolving() rather than resolving the manager here, so a request that never
     * touches a queue does not pay for building one.
     */
    private function registerQueueConnector(): void
    {
        $this->app->resolving('queue', static function (QueueManager $manager): void {
            $manager->addConnector('sconcur_rabbitmq', static fn(): Connector => new Connector());
        });
    }

    /**
     * Bindings of the periodic task pool.
     *
     * Everything is read from config('sconcur.tasks'), including the task list: the
     * registry resolves the classes named there out of the container, so a task is an
     * ordinary injectable service and the pool itself needs no knowledge of what it runs.
     */
    private function registerTaskPool(): void
    {
        $this->app->singleton(
            TaskPoolOptions::class,
            static fn(): TaskPoolOptions => TaskPoolOptions::fromArray((array) config('sconcur.tasks', [])),
        );

        $this->app->singleton(
            TaskRegistry::class,
            static fn(Container $app): TaskRegistry => new TaskRegistry(
                container: $app,
                list: (array) config('sconcur.tasks.list', []),
            ),
        );

        $this->app->singleton(
            CooperativeSleeper::class,
            static fn(Container $app): CooperativeSleeper => new CooperativeSleeper(
                chunkMs: $app->make(TaskPoolOptions::class)->sleepChunkMs,
            ),
        );

        $this->app->singleton(
            ControlChannel::class,
            static fn(Container $app): ControlChannel => new ControlChannel(
                cache: $app->make(CacheRepository::class),
                key: $app->make(TaskPoolOptions::class)->controlKey,
            ),
        );

        $this->app->singleton(TaskPoolLogger::class, static fn(): TaskPoolLogger => new TaskPoolLogger());
    }

    /**
     * Bindings of the WebSocket pool.
     *
     * All of them are lazy: an application that never opens a ws connection and never
     * broadcasts resolves none of this, and the process pays nothing for it. The registry
     * and the subscriber are singletons on purpose — they are the process's own state, and
     * a second copy per coroutine would be a worker whose connections cannot find each
     * other.
     */
    private function registerWsPool(): void
    {
        $this->app->singleton(
            WsOptions::class,
            static fn(): WsOptions => WsOptions::fromArray((array) config('sconcur.ws', [])),
        );

        $this->app->singleton(WsLogger::class, static fn(): WsLogger => new WsLogger());

        $this->app->singleton(MessageCodec::class, static fn(): MessageCodec => new MessageCodec());

        $this->app->singleton(PresencePayload::class, static fn(): PresencePayload => new PresencePayload());

        $this->app->singleton(ConnectionRegistry::class, static fn(): ConnectionRegistry => new ConnectionRegistry());

        $this->app->singleton(SocketIdGenerator::class, static fn(): SocketIdGenerator => new SocketIdGenerator());

        $this->app->singleton(SignatureVerifier::class, static function (Container $app): SignatureVerifier {
            $options = $app->make(WsOptions::class);

            if (!$options->isConfigured()) {
                throw new RuntimeException(
                    'config("sconcur.ws") has no app_key/app_secret. Publish the package config'
                    . ' (vendor:publish --tag=sconcur-laravel) and set SCONCUR_WS_APP_KEY/SCONCUR_WS_APP_SECRET.',
                );
            }

            return new SignatureVerifier(
                appKey: $options->appKey,
                appSecret: $options->appSecret,
            );
        });

        $this->app->singleton(BroadcastBusInterface::class, static function (Container $app): BroadcastBusInterface {
            $bus = $app->make(WsOptions::class)->bus;

            if ($bus->driver === WsBusOptions::DRIVER_LOCAL) {
                return new LocalBroadcastBus();
            }

            if ($bus->driver !== WsBusOptions::DRIVER_AMQP) {
                throw new RuntimeException(
                    sprintf('Unknown sconcur.ws.bus.driver "%s"; expected "amqp" or "local".', $bus->driver),
                );
            }

            if ($bus->dsn === '') {
                throw new RuntimeException(
                    'sconcur.ws.bus.dsn is empty: the amqp bus has no broker to reach.'
                    . ' Set SCONCUR_WS_BUS_DSN, or SCONCUR_RABBITMQ_DSN which it falls back to.',
                );
            }

            $logger = $app->make(WsLogger::class);

            return new AmqpBroadcastBus(
                options: $bus,
                logger: $logger->log(...),
            );
        });

        $this->app->singleton(
            PresenceRepositoryInterface::class,
            fn(Container $app): PresenceRepositoryInterface => $this->makePresenceRepository($app),
        );

        $this->app->singleton(BusSubscriber::class, static fn(Container $app): BusSubscriber => new BusSubscriber(
            bus: $app->make(BroadcastBusInterface::class),
            registry: $app->make(ConnectionRegistry::class),
            codec: $app->make(MessageCodec::class),
            logger: $app->make(WsLogger::class)->log(...),
        ));
    }

    /**
     * Registers the `sconcur` broadcast driver.
     *
     * resolving() rather than resolving the manager here, for the same reason the queue
     * connector does it: an application that broadcasts nothing should not pay for a
     * BroadcastManager.
     */
    private function registerBroadcaster(): void
    {
        $this->app->resolving(BroadcastManager::class, static function (BroadcastManager $manager): void {
            $manager->extend('sconcur', static fn(Container $app): SConcurBroadcaster => new SConcurBroadcaster(
                bus: $app->make(BroadcastBusInterface::class),
                verifier: $app->make(SignatureVerifier::class),
            ));
        });
    }

    /**
     * Where the member list of a presence channel lives. `auto` decides from the pool
     * size, which is the only thing that actually determines the right answer: one worker
     * holds every connection there is, several do not.
     */
    private function makePresenceRepository(Container $app): PresenceRepositoryInterface
    {
        $options = $app->make(WsOptions::class)->presence;

        if ($options->resolveStore($this->wsWorkerCount()) === WsPresenceOptions::STORE_MEMORY) {
            return new MemoryPresenceRepository();
        }

        return new CachePresenceRepository(
            cache: $app->make(CacheRepository::class),
            prefix: $options->cachePrefix,
            ttlSeconds: $options->ttlSeconds,
        );
    }

    /** The ws group's worker count, found by what the group runs rather than by its name. */
    private function wsWorkerCount(): int
    {
        foreach ((array) config('sconcur.master.groups', []) as $group) {
            if (!is_array($group) || !in_array(WsStartCommand::NAME, (array) ($group['workerArgs'] ?? []), true)) {
                continue;
            }

            return (int) ($group['workerCount'] ?? 1);
        }

        return 1;
    }

    /**
     * Registers the `sconcur_mysql` database driver.
     *
     * DatabaseManager::makeConnection() looks its extensions up by connection name
     * first and by driver name second, both before it reaches ConnectionFactory —
     * so registering by driver keeps the PDO connectors out of it entirely.
     *
     * Unconditional, unlike the async adapters: the feature works synchronously
     * outside a coroutine too, and gating this would mean `artisan tinker` could
     * not open the connection the runtime uses.
     */
    private function registerDatabaseDriver(): void
    {
        $this->app->resolving('db', static function (DatabaseManager $db): void {
            $db->extend(
                'sconcur_mysql',
                static fn(array $config, string $name): SconcurMysqlConnection => (new SconcurMysqlConnector())->connect($config, $name),
            );
        });
    }

    /**
     * Replaces the process-wide transactions manager with one that keeps a manager per
     * coroutine.
     *
     * Unconditional, like the driver: outside a coroutine the replacement keeps one
     * manager of its own and behaves exactly as the framework's does, so a process with
     * no fibers in it cannot tell the difference.
     *
     * DatabaseManager::configure() hands every connection whatever `db.transactions`
     * resolves to, once, so this has to be in place before the first connection is built
     * — which register() is. Model::saveOrFail() opens a transaction, so this is on the
     * path of an ordinary create, not only of an explicit DB::transaction().
     */
    private function registerCoroutineTransactionsManager(): void
    {
        $this->app->singleton(
            'db.transactions',
            static fn(): CoroutineTransactionsManager => new CoroutineTransactionsManager(),
        );
    }

    /**
     * Swaps config, events, router, translator and view for their coroutine-safe
     * adapters. Always, without asking what kind of process this is.
     *
     * There used to be a check on argv here, and it was wrong: once the master began
     * forwarding a group's server block to its workers, the command name stopped being
     * argv[1] — a worker is spawned as `artisan --address=… sconcur:servers:http:start
     * --masterPid=N` — so the check quietly answered no in the very processes it existed
     * for, and every adapter was inert in production. Guessing the mode from a command
     * line is not something to fix, it is something to stop doing.
     *
     * Nothing is lost by installing them everywhere. Each keeps its state in the
     * coroutine context, and outside a coroutine that resolves to the process root — one
     * store, one caller, which is what the stock implementations are.
     *
     * The container's scoped resolution needs nothing here at all any more:
     * AsyncApplication has no mode to enter. It used to, and the switch was the bug —
     * three call sites for it, one of which (the task pool) never threw it.
     *
     * @throws BindingResolutionException
     */
    private function registerAsyncAdapters(): void
    {
        $this->registerConfigAdapter();
        $this->registerEventDispatcherAdapter();
        $this->registerRouterAdapter();
        $this->registerTranslatorAdapter();
        $this->registerViewAdapter();

        // Flip the adapters from boot-time (shared) into per-coroutine mode once
        // every provider has booted.
        $this->app->booted(function ($app): void {
            foreach (self::BOOT_COMPLETED_ADAPTERS as $abstract) {
                $instance = $app->make($abstract);

                if (is_object($instance) && method_exists($instance, 'bootCompleted')) {
                    $instance->bootCompleted();
                }
            }

            if (class_exists(Model::class)) {
                Model::setEventDispatcher($app->make('events'));
            }
        });
    }

    /**
     * @throws BindingResolutionException
     */
    private function registerConfigAdapter(): void
    {
        // Swap the config repository before other providers read it.
        $original = $this->app->make('config');

        $this->app->instance('config', new AsyncConfig($original->all()));
    }

    private function registerEventDispatcherAdapter(): void
    {
        $this->app->singleton('events', fn($app) => new AsyncDispatcher($app));
    }

    private function registerRouterAdapter(): void
    {
        $this->app->singleton('router', fn($app) => new AsyncRouter($app['events'], $app));

        // A kernel resolved against the old router must be rebuilt with the new one.
        // forgetInstance() belongs to the container, not to the Application contract the
        // provider's $app is typed as — hence the check rather than an assertion.
        $app = $this->app;

        if ($app instanceof IlluminateContainer && $app->resolved(Kernel::class)) {
            $app->forgetInstance(Kernel::class);
        }
    }

    private function registerTranslatorAdapter(): void
    {
        // extend() survives the deferred TranslationServiceProvider rebinding 'translator'.
        $this->app->extend('translator', function ($translator, $app) {
            $async = new AsyncTranslator($app['translation.loader'], $app->getLocale());
            $async->setFallback($app->getFallbackLocale());

            return $async;
        });
    }

    private function registerViewAdapter(): void
    {
        $this->app->singleton('view', function ($app) {
            $factory = new AsyncViewFactory(
                $app['view.engine.resolver'],
                $app['view.finder'],
                $app['events'],
            );

            $factory->setContainer($app);
            $factory->share('app', $app);

            return $factory;
        });
    }
}
