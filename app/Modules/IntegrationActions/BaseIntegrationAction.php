<?php

namespace FluentCart\App\Modules\IntegrationActions;


abstract class BaseIntegrationAction
{
    public string $slug;
    private static array $actions = [];

    public function __construct()
    {

    }

    public function init()
    {
        add_filter('fluent_cart/integration/get_global_integration_actions', function () {
            return $this->register();
        }, 10, 1);

        $this->registerHooks();
    }

    public function registerHooks()
    {
        //This hook will allow others to register their storage driver with individual storage providers
    }


    public function register(): array
    {
        static::$actions[] = [
            "slug"     => $this->getSlug(),
            'instance' => $this
        ];
        return static::$actions;
    }

    abstract public function handle();

    abstract public function getSlug(): string;

}

