<?php

namespace FluentCart\App\Events;

use FluentCart\App\Listeners\Activity;
use FluentCart\Framework\Support\ArrayableInterface;

abstract class EventDispatcher implements ArrayableInterface
{
    protected array $listeners = [];
    public string $hook = '';
    public bool $autoFireHook = true;


    public function dispatch()
    {
        $this->beforeDispatch();
        $iterableListeners = $this->listeners;
        $iterableListeners[] = Activity::class;

        foreach ($iterableListeners as $listener) {
            $listener::handle($this);
        }

        $this->afterDispatch();

        if (!empty($this->hook) && $this->autoFireHook) {
            do_action($this->hook, $this->toArray());
        }
    }

    public function shouldCreateActivity(): bool
    {
        return true;
    }

    public function getEventInfoForActivity(): array
    {
        return [];
    }


    /**
     * @return mixed
     */
    abstract public function getActivityEventModel();

    public function beforeDispatch()
    {

    }

    public function afterDispatch()
    {

    }

}
