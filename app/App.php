<?php

namespace FluentCart\App;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface;
use FluentCart\Framework\Foundation\App as AppFacade;

/**
 * Class App
 *
 * @method static \FluentCart\Framework\Http\Request\Request request() Get the current request instance.
 * @method static \FluentCart\Framework\Database\DatabaseManager db()
 * @method static \FluentCart\Framework\Http\Response\Response response()
 * @method static \FluentCart\Framework\View\View view()
 * @method static \FluentCart\Framework\Foundation\Config config()
 */


/**
 * @method static \FluentCart\Framework\Database\Query\WPDBConnection db()
 */

class App extends AppFacade
{
    public static function slug(): string
    {
        return static::config()->get('app.slug');
    }

    public static function route(): \FluentCart\Framework\Http\Route
    {
        return static::request()->route();
    }

    public static function call($action, array $parameters = [])
    {
        return static::getInstance()->call(
            $action,
            $parameters
        );
    }

    public static function storeSettings(): StoreSettings
    {
        $cached = wp_cache_get(StoreSettings::CACHE_KEY, StoreSettings::CACHE_GROUP);
        if ($cached instanceof StoreSettings) {
            return $cached;
        }

        $storeSettings = new StoreSettings();
        wp_cache_set(StoreSettings::CACHE_KEY, $storeSettings, StoreSettings::CACHE_GROUP);

        return $storeSettings;
    }

    /**
     * Get the payment gateway manager or a specific gateway
     *
     * @param string|null $gatewayName Optional gateway name to retrieve directly
     * @return GatewayManager|PaymentGatewayInterface|null
     */
    public static function gateway(?string $gatewayName = null)
    {
        $gatewayManager = static::getInstance('gateway');

        // If gateway name is provided, return the specific gateway
        if ($gatewayName !== null) {
            return $gatewayManager->get($gatewayName);
        }

        // Otherwise return the manager instance
        return $gatewayManager;
    }

    public static function isProActive(): bool
    {
        return defined('FLUENTCART_PRO_PLUGIN_VERSION');
    }

    public static function doingRestRequest(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        // Fallback: check URL for /wp-json/
        $rest_prefix = trailingslashit(rest_get_url_prefix());
        if (isset($_SERVER['REQUEST_URI'])) {
            $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
            if (strpos($request_uri, $rest_prefix) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function isDevMode(): bool
    {
        return static::config()->get('env') === 'dev';
    }

    public static function localization()
    {
        return static::getInstance('localization');
    }
}
