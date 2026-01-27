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

        add_filter('fluent_cart/checkout/localize_data', [$this, 'localizeTurnstileData'], 10, 1);

        (new TurnstileValidator())->register();
    }

    public function renderTurnstileWidget($data = [])
    {
        $settings = ModuleSettings::getSettings('turnstile');
        $enableTurnstile = Arr::get($settings, 'active', 'no');
        $siteKey = Arr::get($settings, 'site_key', '');

        $isActive = ($enableTurnstile === 'yes' && !empty($siteKey));
        ?>
        <div class="fct-turnstile-wrapper" data-fluent-cart-turnstile-widget style="position: fixed; bottom: 0; left: 0; width: 1px; height: 1px; overflow: hidden; opacity: 0; pointer-events: none;" data-turnstile-active="<?php echo $isActive ? 'yes' : 'no'; ?>">
            <?php if ($isActive): ?>
            <div class="cf-turnstile"
                 data-sitekey="<?php echo esc_attr($siteKey); ?>"
                 data-callback="fluentCartTurnstileCallback"
                 data-size="invisible"
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
        if (Arr::get($settings, 'active', 'no') === 'yes' && !empty(Arr::get($settings, 'site_key', ''))) {
            $isCheckoutPage = false;

            $templateService = \FluentCart\App\Services\TemplateService::getCurrentFcPageType();
            if ($templateService === 'checkout') {
                $isCheckoutPage = true;
            }

            if (!$isCheckoutPage) {
                $isCheckoutPage = \FluentCart\App\App::request()->get('fluent-cart') === 'modal_checkout';
            }

            if (!$isCheckoutPage && is_page()) {
                global $post;
                if ($post && has_shortcode($post->post_content, 'fluent_cart_checkout')) {
                    $isCheckoutPage = true;
                }
            }

            if ($isCheckoutPage) {
                wp_enqueue_script(
                    'cloudflare-turnstile',
                    'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
                    [],
                    null,
                    true
                );
            }
        }
    }

    public function localizeTurnstileData($data)
    {
        $settings = ModuleSettings::getSettings('turnstile');
        if (isset($data['fluentcart_checkout_vars'])) {
            $data['fluentcart_checkout_vars']['turnstile'] = [
                'enabled'  => Arr::get($settings, 'active', 'no') === 'yes',
                'site_key' => Arr::get($settings, 'site_key', '')
            ];
        }
        return $data;
    }

    /**
     * Inject widget via wp_footer as last resort
     * This should ALWAYS work since wp_footer fires on every page
     */
    public function injectWidgetViaFooter()
    {
        $templateService = \FluentCart\App\Services\TemplateService::getCurrentFcPageType();
        $isCheckoutPage = ($templateService === 'checkout');

        if (!$isCheckoutPage && is_page()) {
            global $post;
            if ($post && has_shortcode($post->post_content, 'fluent_cart_checkout')) {
                $isCheckoutPage = true;
            }
        }

        // Also check if checkout form exists in DOM (more reliable)
        $hasCheckoutForm = false;
        if (is_admin() === false) {
            $hasCheckoutForm = true;
        }

        if ($isCheckoutPage || (!is_admin() && $hasCheckoutForm)) {
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
                            callback: function(token) {
                                if (typeof window.fluentCartTurnstileCallback === 'function') {
                                    window.fluentCartTurnstileCallback(token);
                                } else {
                                    window.fluentCartTurnstileToken = token;
                                }
                            },
                            size: 'invisible',
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
                    const maxAttempts = 50; // 5 seconds
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

    /**
     * Get widget HTML as string
     */
    private function getWidgetHtml()
    {
        $settings = ModuleSettings::getSettings('turnstile');
        $enableTurnstile = Arr::get($settings, 'active', 'no');
        $siteKey = Arr::get($settings, 'site_key', '');
        $isActive = ($enableTurnstile === 'yes' && !empty($siteKey));

        $html = '<div class="fct-turnstile-wrapper" data-fluent-cart-turnstile-widget style="position: fixed; bottom: 0; left: 0; width: 1px; height: 1px; overflow: hidden; opacity: 0; pointer-events: none;" data-turnstile-active="' . ($isActive ? 'yes' : 'no') . '">';

        if ($isActive) {
            $html .= '<div class="cf-turnstile" data-sitekey="' . esc_attr($siteKey) . '" data-callback="fluentCartTurnstileCallback" data-size="invisible" data-theme="auto"></div>';
        } else {
            $html .= '<div class="cf-turnstile" style="display: none;"></div>';
        }

        $html .= '</div>';

        return $html;
    }
}

