<?php

namespace FluentCart\App\Services\PluginInstaller;

use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\Framework\Support\Arr;

class PaymentAddonManager extends AddonManager
{
    /**
     * The install source a registered payment add-on lists for itself, or null when no
     * payment gateway lists this plugin slug.
     *
     * @param string $pluginSlug
     * @return array{type: string, link: string}|null
     */
    public static function getListedSource($pluginSlug)
    {
        foreach (GatewayManager::getInstance()->all() as $gateway) {
            $meta = $gateway->meta();
            $source = Arr::get($meta, 'addon_source', []);

            if (!empty($meta['is_addon']) && is_array($source) && Arr::get($source, 'slug') === $pluginSlug) {
                return [
                    'type' => (string) Arr::get($source, 'type', ''),
                    'link' => (string) Arr::get($source, 'link', ''),
                ];
            }
        }

        return null;
    }
}
