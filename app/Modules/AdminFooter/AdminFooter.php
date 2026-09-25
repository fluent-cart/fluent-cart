<?php

namespace FluentCart\App\Modules\AdminFooter;

/**
 * WP admin footer on FluentCart screens: a quiet feedback link on the left,
 * the FluentCart version on the right. Like FluentCRM, the Pro version is added
 * only when Pro is active and its version differs from Core's.
 */
class AdminFooter
{
    public const FEEDBACK_URL = 'https://wordpress.org/support/plugin/fluent-cart/reviews/#new-post';

    /**
     * Called only on the FluentCart admin page (see MenuHandler::register()).
     */
    public function register(): void
    {
        add_filter('admin_footer_text', [$this, 'filterFooterText']);
        // After core_update_footer (priority 10) so this replaces the WordPress version.
        add_filter('update_footer', [$this, 'filterUpdateFooter'], 11);
    }

    public function filterFooterText($text): string
    {
        return sprintf(
            '<span id="footer-thankyou">%s</span>',
            sprintf(
                /* translators: %1$s: opening link tag to the FluentCart reviews page on WordPress.org, %2$s: closing link tag */
                esc_html__('Enjoying FluentCart? %1$sTell us what you think%2$s', 'fluent-cart'),
                '<a href="' . esc_url(self::FEEDBACK_URL) . '" target="_blank" rel="noopener noreferrer">',
                '</a>'
            )
        );
    }

    public function filterUpdateFooter($text): string
    {
        return esc_html(self::getVersionLabel(self::getProVersion()));
    }

    public static function getVersionLabel(?string $proVersion): string
    {
        $label = FLUENTCART_VERSION;

        if ($proVersion && $proVersion !== FLUENTCART_VERSION) {
            /* translators: %s: FluentCart Pro version number */
            $label .= ' & ' . sprintf(__('Pro %s', 'fluent-cart'), $proVersion);
        }

        return $label;
    }

    /**
     * Pro version only while FluentCart Pro is active (its constant is defined on load).
     */
    public static function getProVersion(): ?string
    {
        return defined('FLUENTCART_PRO_PLUGIN_VERSION') ? (string)FLUENTCART_PRO_PLUGIN_VERSION : null;
    }
}
