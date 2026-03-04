<?php

namespace FluentCart\App\Modules\Wishlist;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Models\Customer;
use FluentCart\App\Services\WishlistService;
use FluentCart\App\Hooks\Handlers\BlockEditors\Buttons\WishlistButtonBlockEditor;

class WishlistModule
{
    public function register()
    {
        add_filter('fluent_cart/module_setting/fields', function ($fields) {
            $fields['wishlist'] = [
                'title'       => __('Wishlist', 'fluent-cart'),
                'description' => __('Allow customers to save products to a wishlist for later.', 'fluent-cart'),
                'type'        => 'component',
                'component'   => 'ModuleSettings',
            ];
            $fields['wishlist_guest'] = [
                'title'       => __('Guest Wishlist', 'fluent-cart'),
                'description' => __('Allow guest (non-logged-in) users to add products to a wishlist.', 'fluent-cart'),
                'type'        => 'component',
                'component'   => 'ModuleSettings',
            ];
            return $fields;
        }, 10, 1);

        add_filter('fluent_cart/module_setting/default_values', function ($values) {
            if (empty($values['wishlist']['active'])) {
                $values['wishlist']['active'] = 'yes';
            }
            if (empty($values['wishlist_guest']['active'])) {
                $values['wishlist_guest']['active'] = 'no';
            }
            return $values;
        }, 10, 1);

        if (!WishlistService::isEnabled()) {
            return;
        }

        // Register the Gutenberg block
        WishlistButtonBlockEditor::register();

        // Merge guest wishlist into customer's wishlist on login
        add_action('wp_login', [$this, 'mergeGuestWishlistOnLogin'], 10, 2);
    }

    /**
     * @param string $userLogin
     * @param \WP_User $user
     */
    public function mergeGuestWishlistOnLogin($userLogin, $user)
    {
        $sessionId = WishlistService::getGuestSessionId();
        if (!$sessionId) {
            return;
        }

        $customer = Customer::where('user_id', $user->ID)->first();
        if (!$customer) {
            $customer = Customer::where('email', $user->user_email)->first();
        }

        if ($customer) {
            WishlistService::mergeGuestWishlist($sessionId, $customer->id);
        }
    }
}
