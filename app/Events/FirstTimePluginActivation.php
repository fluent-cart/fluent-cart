<?php

namespace FluentCart\App\Events;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Listeners\FirstTimePluginActivation as FirstTimeEvents;

class FirstTimePluginActivation extends EventDispatcher
{
    protected array $listeners = [
        //FirstTimeEvents\CreatePages::class,
        FirstTimeEvents\ManagePayments::class,
        FirstTimeEvents\SetupStore::class
    ];
    public StoreSettings $storeSettings;

    public function __construct()
    {
        $this->storeSettings = new StoreSettings();
    }

    public function toArray(): array
    {
        return [
            'settings' => $this->storeSettings->toArray()
        ];
    }

    public function getActivityEventModel()
    {
        return null;
    }

    public function shouldCreateActivity(): bool
    {
        return false;
    }

    public static function handle()
    {
        if (get_option('fluent_cart_do_activation_redirect', false)) {
            delete_option('fluent_cart_do_activation_redirect');
            if (get_option('fluent_cart_plugin_once_activated') !== '1') {
                update_option('fluent_cart_plugin_once_activated', true);
                (new static())->dispatch();
                wp_redirect(admin_url('admin.php?page=fluent-cart#/onboarding'));
            }
        }
    }
}
