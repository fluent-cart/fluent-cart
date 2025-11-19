<?php

namespace FluentCart\App\Modules\PaymentMethods\Core;

use FluentCart\Api\StoreSettings;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;

/**
 * Payment Gateway Manager
 *
 * Manages payment gateway instances using the Singleton pattern.
 * Supports both traditional and direct gateway access patterns:
 *
 * Traditional: GatewayManager::getInstance()->get('stripe')
 * Direct: GatewayManager::getInstance('stripe')
 * Static: GatewayManager::gateway('stripe')
 */
class GatewayManager
{
    protected static ?GatewayManager $instance = null;
    protected array $gateways = [];
    public static ?StoreSettings $storeSettings = null;

    /**
     * Get the singleton instance or a specific gateway
     *
     * @param string|null $gatewayName Optional gateway name to retrieve directly
     * @return GatewayManager|PaymentGatewayInterface|null
     */
    public static function getInstance(?string $gatewayName = null)
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        // If gateway name is provided, return the specific gateway
        if ($gatewayName !== null) {
            return self::$instance->get($gatewayName);
        }

        // Otherwise return the manager instance
        return self::$instance;
    }
    public static function storeSettings(): StoreSettings
    {
        if (!self::$storeSettings) {
            self::$storeSettings = new StoreSettings();
        }
        return self::$storeSettings;
    }

    /**
     * Static convenience method to get a gateway directly
     *
     * @param string $gatewayName
     * @return PaymentGatewayInterface|null
     */
    public static function gateway(string $gatewayName): ?PaymentGatewayInterface
    {
        return self::getInstance($gatewayName);
    }

    /**
     * Check if a gateway is registered
     *
     * @param string $gatewayName
     * @return bool
     */
    public static function has($gatewayName): bool
    {
        if (!$gatewayName) {
            return false;
        }
        return self::getInstance()->get($gatewayName) !== null;
    }

    /**
     * Register a payment gateway
     *
     * @param string $name Gateway identifier
     * @param PaymentGatewayInterface $gateway Gateway instance
     */
    public function register(string $name, PaymentGatewayInterface $gateway)
    {
        // Call boot method to allow each gateway to hook AJAX/IPN/webhooks
        if (method_exists($gateway, 'boot')) {
            $gateway->boot();
        }

        if (method_exists($gateway, 'setStoreSettings')) {
            $gateway->setStoreSettings(self::storeSettings());
        }

        $this->gateways[$name] = $gateway;
    }

    /**
     * Get a specific payment gateway
     *
     * @param string $name Gateway identifier
     * @return PaymentGatewayInterface|null
     */
    public function get(string $name): ?PaymentGatewayInterface
    {
        return $this->gateways[$name] ?? null;
    }

    /**
     * Get all registered gateways
     *
     * @return array
     */
    public function all(): array
    {
        return $this->gateways;
    }

    /**
     * Get all enabled gateways
     *
     * @return array
     */
    public function enabled(): array
    {
        return array_filter($this->gateways, function($gateway) {
            return method_exists($gateway, 'isEnabled') ? $gateway->isEnabled() : true;
        });
    }

    /**
     * Get gateway names
     *
     * @return array
     */
    public function names(): array
    {
        return array_keys($this->gateways);
    }

    /**
     * Remove a gateway
     *
     * @param string $name Gateway identifier
     * @return bool
     */
    public function remove(string $name): bool
    {
        if (isset($this->gateways[$name])) {
            unset($this->gateways[$name]);
            return true;
        }
        return false;
    }

    public function getAllMeta(): array
    {
        $meta = [];
        $requiredKeys = [
            'brand_color', 'description', 'icon', 'logo',
            'route', 'status', 'title'
        ];

        foreach ($this->gateways as $key => $gateway) {
            $data = $gateway->getMeta();
            foreach ($requiredKeys as $rKey) {
                if (!array_key_exists($rKey, $data)) {
                    throw new \Exception(
                        sprintf(
                            /* translators: %1$s is the required meta key, %2$s is the gateway identifier */
                            esc_html__('Missing required meta key "%1$s" for gateway: %2$s', 'fluent-cart'),
                            esc_html($rKey),
                            esc_html($key)
                        )
                    );
                }
            }

            $meta[] = $data;
        }

        return $meta;
    }

    public function getRoutes()
    {
        $routes = [];
        foreach ($this->gateways as $key => $gateway) {
            $routes[] = [
                'path' => $gateway->getMeta('route'),
                'name' => $gateway->getMeta('route'),
                'upcoming' => $gateway->getMeta('upcoming'),
                'meta' => [
                    'title' => $gateway->getMeta('title'),
                    'label' => $gateway->getMeta('label'),
                    'admin_title' => $gateway->getMeta('admin_title')
                ]
            ];
        }
        return $routes;
    }


}
