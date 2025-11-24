<?php

namespace FluentCart\App\Pro;

use FluentCart\App\Services\Permission\PermissionManager;
use FluentCartPro\App\Hooks\Handlers\AdminMenuHandler;
use FluentCartPro\App\Hooks\Handlers\SubscriptionRenewalHandler;
use FluentCartPro\App\Hooks\Handlers\UpgradeHandler;
use FluentCartPro\App\Modules\Integrations\IntegrationsInit;
use FluentCartPro\App\Modules\Licensing\Licensing;
use FluentCartPro\App\Modules\PaymentMethods\MollieGateway\Mollie;
use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\Paddle;
use FluentCartPro\App\Modules\Promotional\PromotionalInit;
use FluentCartPro\App\Modules\StockManagement\StockManagement;
use FluentCartPro\App\Services\PluginManager\FluentLicensing;

class ProBootstrap
{
    public static function register($app)
    {
        (new Licensing())->register($app);
        (new StockManagement())->register($app);
        (new PromotionalInit())->register($app);

        add_action('admin_menu', [new AdminMenuHandler(), 'add']);
        (new UpgradeHandler())->register();
        (new SubscriptionRenewalHandler())->register();

        $fluentLicensing = new FluentLicensing();
        $fluentLicensing->register([
            'item_id'      => 21480,
            'basename'     => plugin_basename(FLUENTCART_PRO_PLUGIN_FILE_PATH),
            'version'      => FLUENTCART_PRO_PLUGIN_VERSION,
            'api_url'      => 'https://fluentcart.com/',
            'activate_url' => admin_url('admin.php?page=webmakerr#/settings/licensing'),
            'plugin_title' => 'FluentCart Pro',
        ]);

        $licenseNotice = $fluentLicensing->getLicenseNotice();

        if ($licenseNotice) {
            add_action('admin_notices', function () use ($licenseNotice) {
                $class = 'notice notice-error fluent-cart-notice';
                $message = $licenseNotice['message'];
                printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), wp_kses_post($message));
            });

            add_filter('fluent_cart/admin_notices', function ($notices) use ($licenseNotice) {
                if (!$licenseNotice || !PermissionManager::userCan('is_super_admin')) {
                    return $notices;
                }

                if (!empty($licenseNotice['message'])) {
                    $notices[] = '<div>' . $licenseNotice['message'] . '</div>';
                }

                return $notices;
            });
        }

        add_action('fluent_cart/init', function ($app) {
            Paddle::register();
            Mollie::register();
        });

        (new IntegrationsInit())->register();

        add_filter($app->config->get('app.slug') . '_encryption_key', function ($default) {
            return $default ?: implode('', range('a', 'p'));
        });
    }
}
