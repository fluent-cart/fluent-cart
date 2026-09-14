<?php

namespace FluentCart\App\Modules\FluentPlayer;

use FluentCart\App\App;
use FluentCart\App\Vite;

/**
 * Enqueues the admin entry that pushes the Video tab into the Product Gallery
 * dialog. The tab shows even while FluentPlayer is inactive so it can offer
 * install/activate; the tab body branches on the localized config.
 */
class FluentPlayerAdminAssets
{
    const HANDLE = 'fluent_cart_fluent_player_gallery';

    public function register(): void
    {
        add_action('fluent_cart/loading_app', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        $slug = App::getInstance()->config->get('app.slug');

        Vite::enqueueScript(
            self::HANDLE,
            'admin/Modules/Products/FluentPlayer/fluent-player-gallery.js',
            [$slug . '_global_admin_hooks']
        );
    }
}
