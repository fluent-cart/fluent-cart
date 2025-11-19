<?php

namespace FluentCart\App;

use FluentCart\Api\StoreSettings;
use FluentCart\Framework\Support\Once;
use FluentCart\Framework\Foundation\App as AppFacade;

/**
 * Class App
 *
 * @method static \FluentCart\Framework\Http\Request\Request request() Get the current request instance.
 * @method static \FluentCart\Framework\Database\DBManager db()
 * @method static \FluentCart\Framework\Http\Response\Response response()
 * @method static \FluentCart\Framework\View\View view()
 * @method static \FluentCart\Framework\Foundation\Config config()
 */


/**
 * @method static \FluentCart\Framework\Database\Query\WPDBConnection db()
 */

class App extends AppFacade
{
    public static function slug()
    {
        return static::config()->get('app.slug');
    }

    public static function route()
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

    public static function storeSettings()
    {
        return Once::call(function () {
            return new StoreSettings();
        });
    }

    /**
     * Get the payment gateway manager or a specific gateway
     *
     * @param string|null $gatewayName Optional gateway name to retrieve directly
     * @return \FluentCart\App\Modules\PaymentMethods\Core\GatewayManager|\FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface|null
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

    public static function isProActive()
    {
        return defined('FLUENTCART_PRO_PLUGIN_VERSION');
    }

    public static function doingRestRequest()
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

    public static function isDevMode()
    {
        return static::config()->get('env') === 'dev';
    }

    public static function localization()
    {
        return static::getInstance('localization');
    }
}
