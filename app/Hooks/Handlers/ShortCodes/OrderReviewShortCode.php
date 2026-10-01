<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\App\App;
use FluentCart\App\Services\Renderer\OrderReviewRenderer;

/**
 * [fluent_cart_order_review] — the "review your order" page as a shortcode, so
 * a store can pick which page hosts it the same way it picks the receipt page.
 *
 * The ?fluent-cart=order-review route renders the same thing without a page.
 * Both exist for the same reason the receipt has both: the route always works
 * (nothing to configure, nothing for an owner to delete), and the page lets the
 * owner put the store's own header, footer and styling around it.
 */
class OrderReviewShortCode
{
    public function register()
    {
        add_shortcode('fluent_cart_order_review', [$this, 'render']);
    }

    /**
     * @param array|string $atts
     * @return string
     */
    public function render($atts = [])
    {
        // The hash comes from the query string, not the shortcode: the page is
        // one page serving every order, and the link in the email is what
        // carries the identity.
        $orderHash = sanitize_text_field((string) App::request()->get('order_hash', ''));

        // In the block editor the shortcode is rendered for preview with no
        // order in the URL, and a "we could not find that order" panel is a
        // poor thing to show an admin laying out the page.
        // The block editor previews it through the REST block renderer, with
        // no action parameter at all, so that request is a preview as well.
        $isEditorPreview = App::request()->get('action') === 'edit'
            || (defined('REST_REQUEST') && REST_REQUEST);
        if ($orderHash === '' && $isEditorPreview) {
            return '';
        }

        ob_start();
        // The page this shortcode sits on already prints its own title, so the
        // renderer's <h1> would be the second identical heading on screen.
        (new OrderReviewRenderer($orderHash, ['showTitle' => false]))->render();

        return ob_get_clean();
    }
}
