<?php

namespace FluentCart\App\Modules\Turnstile;

use FluentCart\Api\ModuleSettings;
use FluentCart\Framework\Support\Arr;

class TurnstileBoot
{
    public function register()
    {
        add_action('fluent_cart/before_payment_methods', [$this, 'renderTurnstileWidget'], 10, 1);

        add_action('wp_footer', [$this, 'injectWidgetViaFooter'], 999);

        add_action('wp_enqueue_scripts', [$this, 'enqueueTurnstileScript'], 20);

        add_filter('wp_script_attributes', [$this, 'addScriptAttributes'], 10, 1);

        add_action('template_redirect', [$this, 'preventCaching'], 1);

        add_filter('fluent_cart/checkout/localize_data', [$this, 'localizeTurnstileData'], 10, 1);

        (new TurnstileValidator())->register();
    }

    public function renderTurnstileWidget($data = [])
    {
        $settings = ModuleSettings::getSettings('turnstile');
        $siteKey = Arr::get($settings, 'site_key', '');

        $isActive = TurnstileValidator::isConfigured($settings);

        // Checkout blocks, builder widgets and Pro inline checkout render on pages
        // isCheckoutContext() can't recognise, so load from the render itself.
        if ($isActive) {
            $this->loadScript();
            $this->markUncacheable();
        }
        ?>
        <div class="fct-turnstile-wrapper" data-fluent-cart-turnstile-widget data-turnstile-active="<?php echo $isActive ? 'yes' : 'no'; ?>">
            <?php if ($isActive): ?>
            <div class="cf-turnstile"
                 data-sitekey="<?php echo esc_attr($siteKey); ?>"
                 data-callback="fluentCartTurnstileCallback"
                 data-size="flexible"
                 data-appearance="interaction-only"
                 data-theme="auto">
            </div>
            <?php else: ?>
            <div class="cf-turnstile" style="display: none;"></div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function enqueueTurnstileScript()
    {
        $settings = ModuleSettings::getSettings('turnstile');
        if (TurnstileValidator::isConfigured($settings) && $this->isCheckoutContext()) {
            $this->loadScript();
        }
    }

    private function loadScript()
    {
        wp_enqueue_script(
            'cloudflare-turnstile',
            'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', // phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent -- Cloudflare requires Turnstile api.js from its own host
            [],
            null,
            true
        );
    }

    /**
     * Opt api.js out of JS delay/defer optimisers; a delayed script leaves checkout without a token.
     * Rocket Loader reads data-cfasync only before src.
     */
    public function addScriptAttributes($attributes)
    {
        if (Arr::get($attributes, 'id') === 'cloudflare-turnstile-js') {
            $attributes = array_merge([
                'data-cfasync'     => 'false',
                'nowprocket'       => true,
                'data-no-optimize' => '1',
                'data-noptimize'   => '1',
                'nitro-exclude'    => true,
            ], $attributes);
        }

        return $attributes;
    }

    /**
     * Caching plugins only auto-exclude checkouts they know (WooCommerce, EDD), and a
     * cached checkout serves a stale site key and nonces.
     */
    public function preventCaching()
    {
        $settings = ModuleSettings::getSettings('turnstile');
        if (!TurnstileValidator::isConfigured($settings) || !$this->isCheckoutContext()) {
            return;
        }

        $this->markUncacheable();
    }

    private function markUncacheable()
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        // Mid-render the headers are gone; page caches still read DONOTCACHEPAGE at the end.
        if (!headers_sent()) {
            nocache_headers();
        }
    }

    public function localizeTurnstileData($data)
    {
        $settings = ModuleSettings::getSettings('turnstile');
        if (isset($data['fluentcart_checkout_vars'])) {
            $data['fluentcart_checkout_vars']['turnstile'] = [
                'enabled'  => TurnstileValidator::isConfigured($settings),
                'site_key' => Arr::get($settings, 'site_key', '')
            ];
        }
        return $data;
    }

    /**
     * Inject widget via wp_footer as last resort
     */
    public function injectWidgetViaFooter()
    {
        if ($this->isCheckoutContext()) {
            $widgetHtml = $this->getWidgetHtml();
            ?>
            <script>
            (function() {
                if (document.querySelector('[data-fluent-cart-turnstile-widget]')) {
                    return;
                }

                const widgetHtml = <?php echo json_encode($widgetHtml); ?>;

                const checkoutForm = document.querySelector('[data-fluent-cart-checkout-page-checkout-form]');
                if (checkoutForm) {
                    checkoutForm.insertAdjacentHTML('beforeend', widgetHtml);
                } else {
                    document.body.insertAdjacentHTML('beforeend', widgetHtml);
                }

                function renderTurnstileWidget() {
                    const widget = document.querySelector('[data-fluent-cart-turnstile-widget] .cf-turnstile');
                    if (!widget || typeof turnstile === 'undefined') {
                        return false;
                    }

                    const siteKey = widget.getAttribute('data-sitekey') || window.fluentcart_checkout_vars?.turnstile?.site_key;
                    const widgetId = widget.getAttribute('data-widget-id');

                    if (!siteKey || widgetId) {
                        return !!widgetId;
                    }

                    try {
                        const renderedId = turnstile.render(widget, {
                            sitekey: siteKey,
                            execution: 'render',
                            callback: function(token) {
                                if (typeof window.fluentCartTurnstileCallback === 'function') {
                                    window.fluentCartTurnstileCallback(token);
                                } else {
                                    window.fluentCartTurnstileToken = token;
                                }
                            },
                            'expired-callback': function() {
                                window.fluentCartTurnstileToken = null;
                                if (window.fluentCartTurnstileHandlerInstance) {
                                    window.fluentCartTurnstileHandlerInstance.handleExpired();
                                }
                            },
                            'error-callback': function() {
                                window.fluentCartTurnstileToken = null;
                                if (window.fluentCartTurnstileHandlerInstance) {
                                    window.fluentCartTurnstileHandlerInstance.handleError();
                                }
                            },
                            size: 'flexible',
                            appearance: 'interaction-only',
                            theme: 'auto'
                        });
                        if (renderedId) {
                            widget.setAttribute('data-widget-id', renderedId);
                        }
                        return true;
                    } catch (error) {
                        return false;
                    }
                }

                if (!renderTurnstileWidget()) {
                    let attempts = 0;
                    const maxAttempts = 50;
                    const checkInterval = setInterval(function() {
                        attempts++;
                        if (renderTurnstileWidget() || attempts >= maxAttempts) {
                            clearInterval(checkInterval);
                        }
                    }, 100);
                }
            })();
            </script>
            <?php
        }
    }

    private function isCheckoutContext()
    {
        $templateService = \FluentCart\App\Services\TemplateService::getCurrentFcPageType();
        if ($templateService === 'checkout') {
            return true;
        }

        $fluentCartRequest = isset($_GET['fluent-cart']) ? sanitize_text_field(wp_unslash($_GET['fluent-cart'])) : '';
        if ($fluentCartRequest === 'modal_checkout') {
            return true;
        }

        if (is_page()) {
            global $post;
            if ($post && has_shortcode($post->post_content, 'fluent_cart_checkout')) {
                return true;
            }
        }

        return false;
    }

    private function getWidgetHtml()
    {
        $settings = ModuleSettings::getSettings('turnstile');
        $siteKey = Arr::get($settings, 'site_key', '');
        $isActive = TurnstileValidator::isConfigured($settings);

        $html = '<div class="fct-turnstile-wrapper" data-fluent-cart-turnstile-widget data-turnstile-active="' . ($isActive ? 'yes' : 'no') . '">';

        if ($isActive) {
            $html .= '<div class="cf-turnstile" data-sitekey="' . esc_attr($siteKey) . '" data-callback="fluentCartTurnstileCallback" data-size="flexible" data-appearance="interaction-only" data-theme="auto"></div>';
        } else {
            $html .= '<div class="cf-turnstile" style="display: none;"></div>';
        }

        $html .= '</div>';

        return $html;
    }
}
