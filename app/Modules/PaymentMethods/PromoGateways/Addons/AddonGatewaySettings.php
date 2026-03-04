<?php

namespace FluentCart\App\Modules\PaymentMethods\PromoGateways\Addons;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Services\PluginInstaller\PaymentAddonManager;
use FluentCart\Framework\Support\Arr;

class AddonGatewaySettings extends BaseGatewaySettings
{
    protected $gatewaySlug;
    protected $customStyles = [];

    public function __construct($gatewaySlug)
    {
        $this->gatewaySlug = $gatewaySlug;
        $this->methodHandler = 'fluent_cart_payment_settings_addon_gateway';
        parent::__construct();
    }

  
    public function setCustomStyles($styles)
    {
        $this->customStyles = $styles;
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
    
    /**
     * Validate addon configuration
     * 
     * @param array $config
     * @return array|\WP_Error
     */
    public function validateAddonConfig($config)
    {
        $requiredKeys = ['title', 'description', 'addon_slug', 'addon_file', 'addon_source'];
        
        foreach ($requiredKeys as $key) {
            if (empty($config[$key])) {
                return new \WP_Error(
                    'missing_config',
                    sprintf(__('Missing required configuration key: %s', 'fluent-cart'), $key)
                );
            }
        }
        
        // Validate addon_source structure
        if (!is_array($config['addon_source']) || empty($config['addon_source']['type'])) {
            return new \WP_Error(
                'invalid_source',
                __('Invalid addon_source configuration', 'fluent-cart')
            );
        }
        
        return $config;
    }
    
    public function getMode()
    {
        return 'test';
    }
    
    public function isActive(): bool
    {
        return false;
    }

   
    protected function getAddonNoticeStyles()
    {
        $defaults = [
            'light' => [
                'primary_gradient' => 'linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%)',
                'text_primary' => '#1e293b',
                'text_secondary' => '#475569',
                'text_muted' => '#64748b',
                'icon_bg' => '#dbeafe',
                'icon_color' => '#3b82f6',
                'feature_icon' => '#10b981',
                'status_warning' => '#f59e0b',
                'status_info' => '#3b82f6',
                'status_success' => '#10b981',
                'button_primary_bg' => '#3b82f6',
                'button_primary_text' => '#ffffff',
                'button_success_bg' => '#10b981',
                'button_success_text' => '#ffffff',
                'border_color' => '#e2e8f0',
                'feature_bg' => '#ffffff',
                'status_bg' => '#ffffff'
            ],
            'dark' => [
                'primary_gradient' => 'linear-gradient(135deg, #1e293b 0%, #0f172a 100%)',
                'text_primary' => '#f1f5f9',
                'text_secondary' => '#cbd5e1',
                'text_muted' => '#94a3b8',
                'icon_bg' => 'rgba(59, 130, 246, 0.15)',
                'icon_color' => '#60a5fa',
                'feature_icon' => '#34d399',
                'status_warning' => '#fbbf24',
                'status_info' => '#60a5fa',
                'status_success' => '#34d399',
                'button_primary_bg' => '#3b82f6',
                'button_primary_text' => '#ffffff',
                'button_success_bg' => '#10b981',
                'button_success_text' => '#ffffff',
                'border_color' => '#334155',
                'feature_bg' => 'rgba(15, 23, 42, 0.5)',
                'status_bg' => 'rgba(15, 23, 42, 0.5)'
            ]
        ];
        
        if (!empty($this->customStyles)) {
            $defaults['light'] = array_merge($defaults['light'], $this->customStyles['light'] ?? []);
            $defaults['dark'] = array_merge($defaults['dark'], $this->customStyles['dark'] ?? []);
        }
        
        return $defaults;
    }

    protected function renderIcon($svgPath, $styles = '')
    {
        return '<svg style="' . $styles . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="' . $svgPath . '"/></svg>';
    }

 
    protected function renderFeatureItem($text, $mode = 'light')
    {
        $styles = $this->getAddonNoticeStyles()[$mode];
        $checkIcon = $this->renderIcon(
            'M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z',
            'width: 18px; height: 18px; fill: ' . $styles['feature_icon'] . '; margin-right: 10px; flex-shrink: 0;'
        );
        
        return '<div style="display: flex; align-items: center; margin-bottom: 10px;">'
            . $checkIcon
            . '<span style="font-size: 14px; color: ' . $styles['text_secondary'] . ';">' . esc_html($text) . '</span>'
            . '</div>';
    }

 
    protected function renderInstallButton($addonSlug, $addonFile, $addonSource, $repoLink, $mode = 'light')
    {
        $styles = $this->getAddonNoticeStyles()[$mode];
        $downloadIcon = 'M13 10H18L12 16L6 10H11V3H13V10M4 19H20V12H22V20C22 20.5304 21.7893 21.0391 21.4142 21.4142C21.0391 21.7893 20.5304 22 20 22H4C3.46957 22 2.96086 21.7893 2.58579 21.4142C2.21071 21.0391 2 20.5304 2 20V12H4V19Z';
        
        return '<button type="button" class="fct-btn fct-btn-primary fct-install-addon-btn" 
            style="display: inline-flex; align-items: center; background: ' . $styles['button_primary_bg'] . '; color: ' . $styles['button_primary_text'] . '; padding: 10px 24px; border-radius: 6px; border: none; font-weight: 500; font-size: 14px; cursor: pointer; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);"
            data-addon-slug="' . esc_attr($addonSlug) . '" 
            data-addon-file="' . esc_attr($addonFile) . '" 
            data-repo-link="' . esc_attr($repoLink) . '" 
            data-source-type="' . esc_attr(Arr::get($addonSource, 'type', 'github')) . '" 
            data-source-link="' . esc_attr($addonSource['link'] ?? '') . '">'
            . $this->renderIcon($downloadIcon, 'width: 16px; height: 16px; margin-right: 6px; fill: ' . $styles['button_primary_text'] . ';')
            . __('Install & Activate', 'fluent-cart')
            . '</button>';
    }

 
    protected function renderActivateButton($addonFile, $mode = 'light')
    {
        $styles = $this->getAddonNoticeStyles()[$mode];
        $checkIcon = 'M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z';
        
        return '<button type="button" class="fct-btn fct-btn-success fct-activate-addon-btn" 
            style="display: inline-flex; align-items: center; background: ' . $styles['button_success_bg'] . '; color: ' . $styles['button_success_text'] . '; padding: 10px 24px; border-radius: 6px; border: none; font-weight: 500; font-size: 14px; cursor: pointer; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);"
            data-addon-file="' . esc_attr($addonFile) . '">'
            . $this->renderIcon($checkIcon, 'width: 16px; height: 16px; margin-right: 6px; fill: ' . $styles['button_success_text'] . ';')
            . __('Activate Addon', 'fluent-cart')
            . '</button>';
    }

 
    protected function renderActiveBadge()
    {
        $styles = $this->getAddonNoticeStyles();
        $checkIcon = 'M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z';
        
        return '<span class="fct-badge fct-badge-success" style="display: inline-flex; align-items: center; background: #dcfce7; color: #166534; padding: 8px 16px; border-radius: 6px; font-weight: 500; font-size: 14px; border: 1px solid #bbf7d0;">'
            . $this->renderIcon($checkIcon, 'width: 16px; height: 16px; margin-right: 6px; fill: #166534;')
            . __('Active', 'fluent-cart')
            . '</span>';
    }

    /**
     * Generate addon notice HTML
     * 
     * @param array $config Configuration array with:
     *   - title: Gateway title
     *   - description: Gateway description
     *   - features: Array of feature strings
     *   - icon_path: SVG path for the gateway icon
     *   - addon_slug: Plugin slug
     *   - addon_file: Plugin file path
     *   - addon_source: Array with 'type' and 'link'
     * 
     * @return string HTML for the addon notice
     */
    public function generateAddonNotice($config)
    {
        // Validate configuration before proceeding
        $validation = $this->validateAddonConfig($config);
        if (is_wp_error($validation)) {
            return '<div class="fct-addon-notice fct-addon-notice-error" style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 20px; color: #991b1b; text-align: center; margin: 20px 0;">'
            . '<p>' . esc_html($validation->get_error_message()) . '</p>'
            . '</div>';
        }
        
        $allStyles = $this->getAddonNoticeStyles();
        
        // Get addon status
        $addonStatus = PaymentAddonManager::getAddonStatus(
            $config['addon_slug'],
            $config['addon_file']
        );
        
        $isInstalled = $addonStatus['is_installed'] ?? false;
        $isActive = $addonStatus['is_active'] ?? false;
        $footerText = $config['footer_text'] ?? __('Free addon - Click the button above to get started', 'fluent-cart');
        
        $html = '';
        
        // Render both light and dark mode versions
        foreach (['light', 'dark'] as $mode) {
            $styles = $allStyles[$mode];
            
            // Determine status and action button for this mode
            if (!$isInstalled) {
                $statusMessage = __('Not Installed', 'fluent-cart');
                $statusColor = $styles['status_warning'];
                $actionButton = $this->renderInstallButton(
                    $config['addon_slug'],
                    $config['addon_file'],
                    $config['addon_source'],
                    $config['repo_link'] ?? '',
                    $mode
                );
            } elseif ($isInstalled && !$isActive) {
                $statusMessage = __('Installed - Not Active', 'fluent-cart');
                $statusColor = $styles['status_info'];
                $actionButton = $this->renderActivateButton($config['addon_file'], $mode);
            } else {
                $statusMessage = __('Active', 'fluent-cart');
                $statusColor = $styles['status_success'];
                $actionButton = $this->renderActiveBadge();
            }
            
            $modeClass = $mode === 'dark' ? 'fct-addon-notice-dark' : 'fct-addon-notice-light';
            $displayStyle = $mode === 'dark' ? 'display: none;' : '';
            
            // Build notice HTML
            $html .= '<div class="fct-addon-notice ' . $modeClass . '" style="' . $displayStyle . ' background: ' . $styles['primary_gradient'] . '; border: 1px solid ' . $styles['border_color'] . '; border-radius: 8px; padding: 28px; color: ' . $styles['text_primary'] . '; text-align: center; margin: 20px 0;">';
            
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
            
            // Status & Action
            $html .= '<div style="background: ' . $styles['status_bg'] . '; border: 1px solid ' . $styles['border_color'] . '; border-radius: 6px; padding: 18px; margin: 20px 0;">';
            $html .= '<div style="display: flex; align-items: center; justify-content: center; margin-bottom: 14px;">';
            $html .= '<div style="width: 8px; height: 8px; background: ' . $statusColor . '; border-radius: 50%; margin-right: 8px;"></div>';
            $html .= '<span style="font-size: 14px; font-weight: 500; color: ' . $styles['text_secondary'] . ';">' . esc_html($statusMessage) . '</span>';
            $html .= '</div>';
            $html .= '<div style="display: flex; justify-content: center;">' . $actionButton . '</div>';
            $html .= '</div>';
            
            // Footer
            $html .= '<p style="margin: 0; font-size: 12px; color: ' . $styles['text_muted'] . ';">' 
                . esc_html($footerText)
                . '</p>';
            
            $html .= '</div>';
        }
        
        // Add CSS to toggle between light/dark modes
        $html .= '<style>
            html.dark .fct-addon-notice-light,
            body.dark .fct-addon-notice-light,
            [data-theme="dark"] .fct-addon-notice-light {
                display: none !important;
            }
            html.dark .fct-addon-notice-dark,
            body.dark .fct-addon-notice-dark,
            [data-theme="dark"] .fct-addon-notice-dark {
                display: block !important;
            }
        </style>';
        
        return $html;
    }
}
