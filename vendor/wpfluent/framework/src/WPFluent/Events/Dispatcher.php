<?php

namespace FluentCart\Framework\Events;

use Closure;
use Exception;
use ReflectionClass;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCart\Framework\Support\Helper;
use FluentCart\Framework\Container\Container;
use FluentCart\Framework\Support\MacroableTrait;
use FluentCart\Framework\Support\ReflectsClosures;
use FluentCart\Framework\Events\DispatcherInterface;
use FluentCart\Framework\Events\ShouldDispatchAfterCommit;
use FluentCart\Framework\Events\ShouldHandleEventsAfterCommit;
use FluentCart\Framework\Container\Contracts\Container as ContainerContract;


class Dispatcher implements DispatcherInterface
{
    use MacroableTrait, ReflectsClosures;

    /**
     * The IoC container instance.
     *
     * @var \FluentCart\Framework\Container\Contracts\Container
     */
    protected $container;

    /**
     * The registered event listeners.
     *
     * @var array
     */
    protected $listeners = [];

    /**
     * The wildcard listeners.
     *
     * @var array
     */
    protected $wildcards = [];

    /**
     * The cached wildcard listeners.
     *
     * @var array
     */
    protected $wildcardsCache = [];


    /**
     * The stack of listeners being deferred.
     * 
     * @var integer
     */
    protected $deferDepth = 0;

    /**
     * The currently deferred events.
     *
     * @var array
     */
    protected $deferredEvents = [];

    /**
     * Indicates if events should be deferred.
     *
     * @var bool
     */
    protected $deferringEvents = false;

    /**
     * The specific events to defer (null means defer all events).
     *
     * @var array|null
     */
    protected $eventsToDefer = null;

    /**
     * The transaction manager instance.
     * 
     * @var \FluentCart\Framework\Database\DatabaseTransactionsManager|null
     */
    protected $transactionManagerResolver = null;

    /**
     * Create a new event dispatcher instance.
     *
     * @param  \FluentCart\Framework\Container\Contracts\Container|null  $container
     * @return void
     */
    public function __construct(?ContainerContract $container = null)
    {
        $this->container = $container ?: new Container;
    }

    /**
     * Register an event listener with the dispatcher.
     *
     * @param  \Closure|string|array  $events
     * @param  \Closure|string|array|null  $listener
     * @return void
     */
    public function listen($events, $listener = null)
    {
        if (class_exists('ReflectionUnionType')) {
            if ($events instanceof Closure) {
                return Helper::collect($this->firstClosureParameterTypes($events))
                    ->each(function ($event) use ($events) {
                        $this->listen($event, $events);
                    });
            }
        }

        foreach ((array) $events as $event) {
            if (Str::contains($event, '*')) {
                $this->setupWildcardListen($event, $listener);
            } else {
                $this->listeners[$event][] = $this->makeListener($listener);
            }
        }
    }

    /**
     * Setup a wildcard listener callback.
     *
     * @param  string  $event
     * @param  \Closure|string  $listener
     * @return void
     */
    protected function setupWildcardListen($event, $listener)
    {
        $this->wildcards[$event][] = $this->makeListener($listener, true);

        $this->wildcardsCache = [];
    }

    /**
     * Determine if a given event has listeners.
     *
     * @param  string  $eventName
     * @return bool
     */
    public function hasListeners($eventName)
    {
        return isset($this->listeners[$eventName]) ||
               isset($this->wildcards[$eventName]) ||
               $this->hasWildcardListeners($eventName);
    }

    /**
     * Determine if the given event has any wildcard listeners.
     *
     * @param  string  $eventName
     * @return bool
     */
    public function hasWildcardListeners($eventName)
    {
        foreach ($this->wildcards as $key => $listeners) {
            if (Str::is($key, $eventName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register an event and payload to be fired later.
     *
     * @param  string  $event
     * @param  array  $payload
     * @return void
     */
    public function push($event, $payload = [])
    {
        $this->listen($event.'_pushed', function () use ($event, $payload) {
            $this->dispatch($event, $payload);
        });
    }

    /**
     * Flush a set of pushed events.
     *
     * @param  string  $event
     * @return void
     */
    public function flush($event)
    {
        $this->dispatch($event.'_pushed');
    }

    /**
     * Register an event subscriber with the dispatcher.
     *
     * @param  object|string  $subscriber
     * @return void
     */
    public function subscribe($subscriber)
    {
        $subscriber = $this->resolveSubscriber($subscriber);

        $events = $subscriber->subscribe($this);

        if (is_array($events)) {
            foreach ($events as $event => $listeners) {
                foreach (Arr::wrap($listeners) as $listener) {
                    if (is_string($listener) && method_exists($subscriber, $listener)) {
                        $this->listen($event, [get_class($subscriber), $listener]);

                        continue;
                    }

                    $this->listen($event, $listener);
                }
            }
        }
    }

    /**
     * Resolve the subscriber instance.
     *
     * @param  object|string  $subscriber
     * @return mixed
     */
    protected function resolveSubscriber($subscriber)
    {
        if (is_string($subscriber)) {
            return $this->container->make($subscriber);
        }

        return $subscriber;
    }

    /**
     * Execute the given callback while deferring events,
     * then dispatch all the deferred events.
     *
     * @param  callable  $callback
     * @param  array|null  $events
     * @return mixed
     */
    public function defer(callable $callback, ?array $events = null)
    {
        $this->deferDepth++;

        $previousEventsToDefer = $this->eventsToDefer;

        if ($events !== null) {
            $this->eventsToDefer = $events;
        }

        try {
            return $callback();
        } finally {
            $this->deferDepth--;

            if ($this->deferDepth === 0) {
                $events = $this->deferredEvents;
                $this->deferredEvents = [];
                $this->eventsToDefer = null;

                foreach ($events as $args) {
                    $this->dispatch(...$args);
                }
            } else {
                $this->eventsToDefer = $previousEventsToDefer;
            }
        }
    }

    /**
     * Determine if the given event should be deferred.
     *
     * @param  string  $event
     * @return bool
     */
    protected function shouldDeferEvent($event)
    {
        if ($this->deferDepth === 0) {
            return false;
        }

        if ($this->eventsToDefer === null) {
            return true;
        }

        return in_array($event, $this->eventsToDefer, true);
    }

    /**
     * Fire an event until the first non-null response is returned.
     *
     * @param  string|object  $event
     * @param  mixed  $payload
     * @return array|null
     */
    public function until($event, $payload = [])
    {
        return $this->dispatch($event, $payload, true);
    }

    /**
     * Fire an event and call the listeners.
     *
     * @param  string|object  $event
     * @param  mixed  $payload
     * @param  bool  $halt
     * @return array|null
     */
    public function dispatch($event, $payload = [], $halt = false)
    {
        // When the given "event" is actually an object we will assume it is
        // an event object and use the class as the event name and this
        // event itself as the payload to the handler, which makes
        // object based events quite simple.
        
        [$isEventObject, $event, $payload] = [
            is_object($event),
            ...$this->parseEventAndPayload($event, $payload),
        ];

        if ($this->shouldDeferEvent($event)) {
            $this->deferredEvents[] = func_get_args();

            return null;
        }

        // If the event is not intended to be dispatched unless the current
        // database transaction is successful, we'll register a callback
        // which will handle dispatching this event on the next
        // successful DB transaction commit.
        if ($isEventObject &&
            $payload[0] instanceof ShouldDispatchAfterCommit &&
            ! is_null($transactions = $this->resolveTransactionManager())) {
            $transactions->addCallback(
                fn () => $this->invokeListeners($event, $payload, $halt)
            );

            return null;
        }

        return $this->invokeListeners($event, $payload, $halt);
    }

    /**
     * Broadcast an event and call its listeners.
     *
     * @param  string|object  $event
     * @param  mixed  $payload
     * @param  bool  $halt
     * @return array|null
     */
    protected function invokeListeners($event, $payload, $halt = false)
    {
        $responses = [];

        foreach ($this->getListeners($event) as $listener) {
            $response = $listener($event, $payload);

            // If a response is returned from the listener and event halting is 
            // enabled we will just return this response, and not call the
            // rest of the event listeners. Otherwise we will add the
            // response on the response list.
            if ($halt && ! is_null($response)) {
                return $response;
            }

            // If a boolean false is returned from a listener, we will stop
            // propagating the event to any further listeners down in the
            // chain, else we keep on looping through the listeners
            // and firing every one in our sequence.
            if ($response === false) {
                break;
            }

            $responses[] = $response;
        }

        return $halt ? null : $responses;
    }

    /**
     * Parse the given event and payload and prepare them for dispatching.
     *
     * @param  mixed  $event
     * @param  mixed  $payload
     * @return array
     */
    protected function parseEventAndPayload($event, $payload)
    {
        if (is_object($event)) {
            [$payload, $event] = [[$event], get_class($event)];
        }

        return [$event, Arr::wrap($payload)];
    }

    /**
     * Get all of the listeners for a given event name.
     *
     * @param  string  $eventName
     * @return array
     */
    public function getListeners($eventName)
    {
        $listeners = $this->listeners[$eventName] ?? [];

        $listeners = array_merge(
            $listeners,
            $this->wildcardsCache[$eventName] ?? $this->getWildcardListeners($eventName)
        );

        return class_exists($eventName, false)
                    ? $this->addInterfaceListeners($eventName, $listeners)
                    : $listeners;
    }

    /**
     * Get the wildcard listeners for the event.
     *
     * @param  string  $eventName
     * @return array
     */
    protected function getWildcardListeners($eventName)
    {
        $wildcards = [];

        foreach ($this->wildcards as $key => $listeners) {
            if (Str::is($key, $eventName)) {
                $wildcards = array_merge($wildcards, $listeners);
            }
        }

        return $this->wildcardsCache[$eventName] = $wildcards;
    }

    /**
     * Add the listeners for the event's interfaces to the given array.
     *
     * @param  string  $eventName
     * @param  array  $listeners
     * @return array
     */
    protected function addInterfaceListeners($eventName, array $listeners = [])
    {
        foreach (class_implements($eventName) as $interface) {
            if (isset($this->listeners[$interface])) {
                foreach ($this->listeners[$interface] as $names) {
                    $listeners = array_merge($listeners, (array) $names);
                }
            }
        }

        return $listeners;
    }

    /**
     * Register an event listener with the dispatcher.
     *
     * @param  \Closure|string|array  $listener
     * @param  bool  $wildcard
     * @return \Closure
     */
    public function makeListener($listener, $wildcard = false)
    {
        if (is_string($listener)) {
            return $this->createClassListener($listener, $wildcard);
        }

        if (
            is_array($listener) &&
            isset($listener[0]) &&
            is_string($listener[0])
        ) {
            return $this->createClassListener($listener, $wildcard);
        }

        if (is_object($listener) && !$listener instanceof Closure) {
            return $this->createClassListener($listener, $wildcard);
        }

        return function ($event, $payload) use ($listener, $wildcard) {
            if ($wildcard) {
                return $listener($event, $payload);
            }

            return $listener(...array_values($payload));
        };
    }

    /**
     * Create a class based listener using the IoC container.
     *
     * @param  string  $listener
     * @param  bool  $wildcard
     * @return \Closure
     */
    public function createClassListener($listener, $wildcard = false)
    {
        return function ($event, $payload) use ($listener, $wildcard) {
            if ($wildcard) {
                return call_user_func(
                    $this->createClassCallable($listener), $event, $payload
                );
            }

            $callable = $this->createClassCallable($listener);

            return $callable(...array_values($payload));
        };
    }

    /**
     * Create the class based event callable.
     *
     * @param  array|string  $listener
     * @return callable
     */
    protected function createClassCallable($listener)
    {
        [$class, $method] = is_array($listener)
            ? $listener
            : $this->parseClassCallable($listener);

        if (!method_exists($class, $method)) {
            $method = '__invoke';
        }

        if (!is_object($class)) {
            $class = $this->container->make($class);
        }

        return $this->ShouldBeDispatchedAfterTransactions($class)
            ? $this->createCallbackToRunAfterCommits($class, $method)
            : [$class, $method];
    }

    /**
     * Parse the class listener into class and method.
     *
     * @param  string  $listener
     * @return array
     */
    protected function parseClassCallable($listener)
    {
        if (is_object($listener)) {
            return [$listener, '__invoke'];
        }

        return Str::parseCallback($listener, 'handle');
    }

    /**
     * Determine if the given event handler should be dispatched after
     * all database transactions have committed.
     *
     * @param  object|mixed  $listener
     * @return bool
     */
    protected function ShouldBeDispatchedAfterTransactions($listener)
    {
        return (($listener->afterCommit ?? null) ||
            $listener instanceof ShouldDispatchAfterCommit
        ) && $this->resolveTransactionManager();
    }

    /**
     * Create a callable for dispatching a listener after database transactions.
     *
     * @param  mixed  $listener
     * @param  string  $method
     * @return \Closure
     */
    protected function createCallbackToRunAfterCommits($listener, $method)
    {
        return function () use ($method, $listener) {
            $payload = func_get_args();

            $this->resolveTransactionManager()->addCallback(
                fn() => $listener->$method(...$payload)
            );
        };
    }

    /**
     * Remove a set of listeners from the dispatcher.
     *
     * @param  string  $event
     * @return void
     */
    public function forget($event)
    {
        if (Str::contains($event, '*')) {
            unset($this->wildcards[$event]);
        } else {
            unset($this->listeners[$event]);
        }

        foreach ($this->wildcardsCache as $key => $listeners) {
            if (Str::is($event, $key)) {
                unset($this->wildcardsCache[$key]);
            }
        }
    }

    /**
     * Forget all of the pushed listeners.
     *
     * @return void
     */
    public function forgetPushed()
    {
        foreach ($this->listeners as $key => $value) {
            if (Str::endsWith($key, '_pushed')) {
                $this->forget($key);
            }
        }
    }

    /**
     * Resolve the transaction manager instance.
     * 
     * @return \FluentCart\Framework\Database\DatabaseTransactionsManager
     */
    protected function resolveTransactionManager()
    {
        return call_user_func($this->transactionManagerResolver);
    }

    /**
     * 
     * Set the transaction manager resolver.
     * 
     * @param callable $resolver
     */
    public function setTransactionManagerResolver(callable $resolver)
    {
        $this->transactionManagerResolver = $resolver;

        return $this;
    }
}
