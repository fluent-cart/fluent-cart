<?php

namespace FluentCart\App\Modules\PaymentMethods\PromoGateways\Pro;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\Framework\Support\Arr;

class PromoGatewaySettings extends BaseGatewaySettings
{
    protected $gatewaySlug;
    protected $customStyles = [];

    public function __construct($gatewaySlug)
    {
        $this->gatewaySlug = $gatewaySlug;
        $this->methodHandler = 'fluent_cart_payment_settings_promo_gateway';
        parent::__construct();
    }

    public static function getDefaults()
    {
        return [
            'is_active' => 'no'
        ];
    }

    public function get($key = null)
    {
        if ($key === 'is_active') {
            return 'no';
        }
        
        if ($key) {
            return isset($this->settings[$key]) ? $this->settings[$key] : null;
        }
        
        return $this->settings;
    }
    
    public function getMode()
    {
        return 'test';
    }
    
    public function isActive(): bool
    {
        return false;
    }

    public function setCustomStyles($styles)
    {
        $this->customStyles = $styles;
    }

    protected function getPromoNoticeStyles()
    {
        $defaults = [
            'light' => [
                'primary_gradient' => 'linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%)',
                'text_primary' => '#1e293b',
                'text_secondary' => '#475569',
                'text_muted' => '#64748b',
                'icon_bg' => '#e0e7ff',
                'icon_color' => '#6366f1',
                'feature_icon' => '#10b981',
                'button_bg' => '#6366f1',
                'button_text' => '#ffffff',
                'border_color' => '#e2e8f0',
                'feature_bg' => '#ffffff'
            ],
            'dark' => [
                'primary_gradient' => 'linear-gradient(135deg, #1e293b 0%, #0f172a 100%)',
                'text_primary' => '#f1f5f9',
                'text_secondary' => '#cbd5e1',
                'text_muted' => '#94a3b8',
                'icon_bg' => 'rgba(99, 102, 241, 0.15)',
                'icon_color' => '#818cf8',
                'feature_icon' => '#34d399',
                'button_bg' => '#6366f1',
                'button_text' => '#ffffff',
                'border_color' => '#334155',
                'feature_bg' => 'rgba(15, 23, 42, 0.5)'
            ]
        ];

        if (!empty($this->customStyles)) {
            $defaults['light'] = array_merge($defaults['light'], $this->customStyles['light'] ?? []);
            $defaults['dark'] = array_merge($defaults['dark'], $this->customStyles['dark'] ?? []);
        }
        
        return $defaults;
    }

    /**
     * Render SVG icon
     */
    protected function renderIcon($svgPath, $styles = '')
    {
        return '<svg style="' . $styles . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="' . $svgPath . '"/></svg>';
    }

    /**
     * Render a feature list item with checkmark
     */
    protected function renderFeatureItem($text, $mode = 'light')
    {
        $styles = $this->getPromoNoticeStyles()[$mode];
        $checkIcon = $this->renderIcon(
            'M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z',
            'width: 18px; height: 18px; fill: ' . $styles['feature_icon'] . '; margin-right: 10px; flex-shrink: 0;'
        );
        
        return '<div style="display: flex; align-items: center; margin-bottom: 10px;">'
            . $checkIcon
            . '<span style="font-size: 14px; color: ' . $styles['text_secondary'] . ';">' . esc_html($text) . '</span>'
            . '</div>';
    }

    /**
     * Check if FluentCart Pro is installed
     */
    protected function isProInstalled()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $all_plugins = get_plugins();
        return isset($all_plugins['fluent-cart-pro/fluent-cart-pro.php']);
    }

    /**
     * Check if FluentCart Pro is active
     */
    protected function isProActive()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        return is_plugin_active('fluent-cart-pro/fluent-cart-pro.php');
    }

    /**
     * Render upgrade or activate button
     */
    protected function renderActionButton($mode = 'light')
    {
        $styles = $this->getPromoNoticeStyles()[$mode];
        
        // If Pro is installed but not active, show activate button
        if ($this->isProInstalled() && !$this->isProActive()) {
            return '<button class="fct-activate-addon-btn" data-addon-file="fluent-cart-pro/fluent-cart-pro.php" style="display: inline-block; background: ' . $styles['button_bg'] . '; color: ' . $styles['button_text'] . '; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-weight: 500; font-size: 15px; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); border: none; cursor: pointer;">'
                . __('Activate FluentCart Pro', 'fluent-cart')
                . '</button>';
        }
        
        // Otherwise show upgrade button
        $upgradeUrl = Helper::getUpgradeUrl('feature_lock_gateway_' . $this->gatewaySlug);

        return '<a href="' . esc_url($upgradeUrl) . '" target="_blank" style="display: inline-block; background: ' . $styles['button_bg'] . '; color: ' . $styles['button_text'] . '; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-weight: 500; font-size: 15px; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);">'
            . __('Upgrade to FluentCart Pro', 'fluent-cart') . ' →'
            . '</a>';
    }

    /**
     * Generate upgrade to pro notice HTML
     * 
     * @param array $config Configuration array with:
     *   - title: Gateway title
     *   - description: Gateway description
     *   - features: Array of feature strings
     *   - icon_path: SVG path for the gateway icon
     *   - footer_text: Optional footer text
     * @return string HTML for the upgrade notice
     */
    public function generateUpgradeNotice($config)
    {
        $allStyles = $this->getPromoNoticeStyles();

        // Determine footer text based on Pro status
        $defaultFooterText = __('Unlock all premium payment gateways and features', 'fluent-cart');
        if ($this->isProInstalled() && !$this->isProActive()) {
            $defaultFooterText = __('FluentCart Pro is installed - Activate to unlock all premium features', 'fluent-cart');
        }
        
        $footerText = Arr::get($config, 'footer_text') ? Arr::get($config, 'footer_text') : $defaultFooterText;
        
        $html = '';
        
        foreach (['light', 'dark'] as $mode) {
            $styles = $allStyles[$mode];
            $modeClass = $mode === 'dark' ? 'fct-promo-notice-dark' : 'fct-promo-notice-light';
            $displayStyle = $mode === 'dark' ? 'display: none;' : '';
            
            $html .= '<div class="fct-promo-notice ' . $modeClass . '" style="' . $displayStyle . ' background: ' . $styles['primary_gradient'] . '; border: 1px solid ' . $styles['border_color'] . '; border-radius: 8px; padding: 28px; color: ' . $styles['text_primary'] . '; text-align: center; margin: 20px 0;">';
            
            // Icon
            $html .= '<div style="background: ' . $styles['icon_bg'] . '; width: 64px; height: 64px; border-radius: 12px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center;">';
            $html .= $this->renderIcon($config['icon_path'], 'width: 32px; height: 32px; fill: ' . $styles['icon_color'] . ';');
            $html .= '</div>';
            
            // Title
            $html .= '<h3 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 600; color: ' . $styles['text_primary'] . ';">' 
                . esc_html($config['title']) 
                . '</h3>';
            
            // Description
            $html .= '<p style="margin: 0 0 20px 0; font-size: 14px; color: ' . $styles['text_secondary'] . '; line-height: 1.6;">' 
                . esc_html($config['description']) 
                . '</p>';
            
            // Features
            if (!empty($config['features'])) {
                $html .= '<div style="background: ' . $styles['feature_bg'] . '; border: 1px solid ' . $styles['border_color'] . '; border-radius: 6px; padding: 18px; margin: 20px 0; text-align: left;">';
                foreach ($config['features'] as $feature) {
                    $html .= $this->renderFeatureItem($feature, $mode);
                }
                $html .= '</div>';
            }
            
            // Action Button (Upgrade or Activate)
            $html .= '<div style="margin: 24px 0 16px 0;">' . $this->renderActionButton($mode) . '</div>';
            
            // Footer
            $html .= '<p style="margin: 0; font-size: 12px; color: ' . $styles['text_muted'] . ';">' 
                . esc_html($footerText)
                . '</p>';
            
            $html .= '</div>';
        }
        
        // Add CSS to toggle between light/dark modes
        $html .= '<style>
            @media (prefers-color-scheme: dark) {
                .fct-promo-notice-light { display: none !important; }
                .fct-promo-notice-dark { display: block !important; }
            }
            html.dark .fct-promo-notice-light,
            body.dark .fct-promo-notice-light,
            [data-theme="dark"] .fct-promo-notice-light { 
                display: none !important; 
            }
            html.dark .fct-promo-notice-dark,
            body.dark .fct-promo-notice-dark,
            [data-theme="dark"] .fct-promo-notice-dark { 
                display: block !important; 
            }
        </style>';
        
        return $html;
    }
}
