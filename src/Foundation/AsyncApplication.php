<?php

declare(strict_types=1);

namespace SConcur\Laravel\Foundation;

use Closure;
use Fiber;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use SConcur\Context\Context;
use SConcur\Laravel\Events\AsyncDispatcher;
use SConcur\Laravel\Routing\AsyncRouter;

/**
 * Coroutine-scoped application: a single shared container whose request-scoped
 * services are resolved from the current coroutine's context instead of being
 * cloned/swapped per request.
 *
 * There is no mode to turn on. It used to have one, and the switch was the bug: it had to
 * be thrown by whoever knew the process would run coroutines, which meant three call
 * sites, one of which (the task pool) never did it — and before that, a check on argv
 * that stopped matching the moment the master began passing a group's flags ahead of the
 * command name. Nothing detects anything now.
 *
 * Outside a coroutine this costs nothing: there is one caller, so a scoped service is a
 * single instance — which is what the container would have given anyway. It is kept in a
 * store of this object's own rather than in the coroutine context, because the context
 * outside a fiber is the process root, and every coroutine reads through to that.
 *
 * Ported from yangusik/laravel-spawn (AsyncApplication), adapted to SConcur's
 * Context::current(). See .ai/plans/bridge/fiber-safe-laravel-bridge.md §4.
 */
class AsyncApplication extends Application
{
    /**
     * Scoped services safe to proxy via offsetGet (Facades). Services passed to
     * typed PHP parameters must NOT be here — the proxy does not implement their types.
     */
    private const array FACADE_PROXIED_MAP = [
        'auth'    => true,
        'session' => true,
        // Cookie's facade accessor too: a facade caches the instance it resolved in a
        // static shared by the whole process, so without the proxy the first coroutine to
        // touch Cookie:: would pin its own CookieJar there for every later one, and a
        // queued cookie would be flushed onto somebody else's response.
        'cookie'  => true,
    ];

    /** @var array<string, Closure> user-registered scoped factories */
    private array $scopedBindings = [];

    /**
     * Scoped instances for callers that are not in a coroutine at all.
     *
     * Held here rather than in the coroutine context, which outside a fiber is the process
     * root — and the root is never released, while every coroutine reads through to it. An
     * instance built during bootstrap or in a start command's own body would therefore be
     * shared by every request, message and task in the process: one AuthManager, one
     * SessionGuard, one $user. Which is the failure this class exists to prevent, entered
     * through the back door.
     *
     * Outside a coroutine there is a single caller, so a single instance is right — and
     * that is exactly what this is.
     *
     * @var array<string, mixed>
     */
    private array $rootScoped = [];

    /**
     * config('sconcur.scoped_services') as alias => 1, read once the config repository
     * exists. Null until then — a resolve during bootstrap must not freeze an empty list
     * for the life of the process.
     *
     * @var array<string, int>|null
     */
    private ?array $scopedServices = null;

    public function scopedSingleton(string $abstract, Closure $factory): void
    {
        $this->scopedBindings[$abstract] = $factory;
    }

    /**
     * 'request' is always resolvable so code checking bound('request') during
     * bootstrap (before any HTTP request) does not crash.
     */
    public function bound($abstract): bool
    {
        if ($this->getAlias($abstract) === 'request') {
            return true;
        }

        return parent::bound($abstract);
    }

    public function offsetGet($key): mixed
    {
        $alias = $this->getAlias($key);

        if (isset(self::FACADE_PROXIED_MAP[$alias])) {
            return new ScopedServiceProxy(fn() => $this->tryResolveScoped($alias));
        }

        if ($alias === 'request') {
            return $this->resolveRequest();
        }

        return parent::offsetGet($key);
    }

    /**
     * The two coroutine-safe services that cannot wait for SConcurServiceProvider.
     *
     * Both of Laravel's kernels are built before a single provider registers — the console
     * one by handleCommand(), the HTTP one by handleRequest() — and each keeps what it was
     * handed. The console kernel keeps the dispatcher: it is the one
     * Illuminate\Console\Application is later built from, and the one ArtisanStarting,
     * CommandStarting, CommandFinished and Terminating are dispatched into. The HTTP kernel
     * keeps the router. A swap from a provider's register() never reaches the object
     * serving the command or request that registered it, and for the console kernel nothing
     * can be done afterwards at all — it is inside its own handle() by then.
     *
     * Here there is nothing yet to capture them. The other three adapters stay with the
     * provider: config, translator and view do not exist until the bootstrappers and the
     * providers they belong to have run, and nothing captures them this early.
     *
     * An application on the stock Illuminate\Foundation\Application gets neither, which is
     * the right answer for it: without this container there are no coroutines to be safe
     * from, and the stock dispatcher and router are what a single-caller process wants.
     *
     * Both bindings are lazy — nothing is resolved while the container is being built.
     */
    protected function registerBaseServiceProviders(): void
    {
        parent::registerBaseServiceProviders();

        $this->registerEventDispatcher();
        $this->registerRouter();
    }

    /**
     * A callable $abstract has no alias and cannot be scoped, so it goes straight to the
     * parent — the container accepts one, and asking getAlias() for its name would be a
     * type error rather than a miss.
     *
     * @param array<array-key, mixed> $parameters
     */
    protected function resolve($abstract, $parameters = [], $raiseEvents = true)
    {
        $alias = is_string($abstract) ? $this->getAlias($abstract) : null;

        if ($alias === 'request') {
            return $this->resolveRequest();
        }

        $instance = $alias === null ? null : $this->tryResolveScoped($alias);

        if ($instance !== null) {
            return $instance;
        }

        return parent::resolve($abstract, $parameters, $raiseEvents);
    }

    private function registerEventDispatcher(): void
    {
        // The two resolvers are what Illuminate\Events\EventServiceProvider gives the stock
        // dispatcher, and neither is optional: without the queue one a ShouldQueue listener
        // resolves null and fails, without the transaction one an event marked afterCommit
        // is dispatched straight away instead of after the commit.
        $this->singleton(
            'events',
            static fn(Container $container): AsyncDispatcher => (new AsyncDispatcher($container))
                // 'queue' rather than the Factory contract, and mixed rather than the
                // factory: Dispatcher::setQueueResolver() is annotated callable(): Queue,
                // while what resolveQueue() then calls connection() on is the factory.
                ->setQueueResolver(static fn(): mixed => $container->make('queue'))
                ->setTransactionManagerResolver(
                    static fn(): mixed => $container->bound('db.transactions')
                        ? $container->make('db.transactions')
                        : null,
                ),
        );
    }

    private function registerRouter(): void
    {
        $this->singleton(
            'router',
            static fn(Container $container): AsyncRouter => new AsyncRouter(
                events: $container->make('events'),
                container: $container,
            ),
        );
    }

    private function resolveRequest(): object
    {
        $fromContext = $this->scopedFind(ScopedService::REQUEST->value);

        if ($fromContext !== null) {
            return $fromContext;
        }

        return $this->instances['request'] ?? Request::createFromGlobals();
    }

    /**
     * Resolve a scoped service from the current coroutine context, or null if the
     * alias is not scoped.
     *
     * TODO: mirror laravel-spawn's afterResolving/fireResolvingCallbacks handling
     * for adapters registered via afterResolving('session', ...).
     */
    private function tryResolveScoped(string $alias): mixed
    {
        $key = ScopedService::tryFrom($alias);

        if ($key === null
            && !isset($this->scopedBindings[$alias])
            && !$this->isConfiguredScoped($alias)
        ) {
            return null;
        }

        $ctxKey = $key !== null ? $key->value : $alias;

        $instance = $this->scopedFind($ctxKey);

        if ($instance !== null) {
            return $instance;
        }

        if (isset($this->scopedBindings[$alias])) {
            $instance = ($this->scopedBindings[$alias])($this);
        } else {
            $bindings = $this->getBindings();

            if (!isset($bindings[$alias])) {
                return null;
            }

            $concrete = $bindings[$alias]['concrete'];

            if ($concrete instanceof Closure) {
                $instance = $concrete($this);
            } elseif (is_string($concrete) && class_exists($concrete)) {
                $instance = $this->build($concrete);
            } else {
                // A binding that is neither a factory nor a class name is nothing this
                // can scope; the container knows what to do with it.
                return null;
            }
        }

        $this->scopedStore($ctxKey, $instance);

        return $instance;
    }

    /**
     * A scoped instance of the caller: the coroutine's own, or the process's when there is
     * no coroutine. See $rootScoped for why the two are not the same store.
     */
    private function scopedFind(string $key): mixed
    {
        return Fiber::getCurrent() === null
            ? ($this->rootScoped[$key] ?? null)
            : Context::current()->find($key);
    }

    private function scopedStore(string $key, mixed $instance): void
    {
        if (Fiber::getCurrent() === null) {
            $this->rootScoped[$key] = $instance;

            return;
        }

        Context::current()->set($key, $instance);
    }

    /**
     * Whether the application asked for this alias to be scoped, in
     * config('sconcur.scoped_services').
     *
     * Read straight from the stored instance rather than through make(): resolving the
     * config repository goes through resolve(), which asks this question, which would
     * ask for the repository again.
     */
    private function isConfiguredScoped(string $alias): bool
    {
        if ($this->scopedServices === null) {
            $config = $this->instances['config'] ?? null;

            if (!$config instanceof ConfigRepository) {
                return false;
            }

            $this->scopedServices = array_flip((array) $config->get('sconcur.scoped_services', []));
        }

        return isset($this->scopedServices[$alias]);
    }
}
