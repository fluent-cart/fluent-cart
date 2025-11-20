<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\Webhook;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPalSettingsBase;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Hooks\Handlers\GlobalPaymentHandler;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\PluginInstaller\PaymentAddonManager;


class PaymentMethodController extends Controller
{
    public function index(Request $request, GlobalPaymentHandler $globalHandler)
    {
        try {
            $gateways = $globalHandler->getAll();
            $categorizedGateways = [
                'available' => [],
                'offline' => [],
                'is_pro_required' => [],
                'upcoming' => []
            ];

            foreach ($gateways as $gateway) {
                if (isset($gateway['requires_pro'])) {
                    $categorizedGateways['is_pro_required'][] = $gateway;
                } elseif (Arr::get($gateway, 'upcoming', false) === true) {
                    $categorizedGateways['upcoming'][] = $gateway;
                } else {
                    $categorizedGateways['available'][] = $gateway;
                }
            }

            return [
                'gateways' => array_merge(
                    $categorizedGateways['available'],
                    $categorizedGateways['is_pro_required'],
                    $categorizedGateways['upcoming']
                )
            ];

        } catch (\Exception $error) {
            return $this->sendError([
                'message' => $error->getMessage()
            ], 423);
        }
    }

    public function store(Request $request, GlobalPaymentHandler  $globalHandler)
    {
        $data = $request->settings;
        $method = sanitize_text_field($request->method);
        if (GatewayManager::has($method)) {
            $methodInstance = GatewayManager::getInstance($method);
            wp_send_json(
                $methodInstance->updateSettings($data),
                200
            );
        } else {
            throw new \Exception(esc_html__('No valid payment method found!', 'fluent-cart'));
        }
    }

    public function getSettings(Request $request, GlobalPaymentHandler $globalHandler)
    {
        try {
            return $globalHandler->getSettings(sanitize_text_field($request->method));
        } catch (\Exception $error) {
            return $this->sendError([
                'message' => $error->getMessage()
            ], 423);
        }
    }

public function saveDesign(Request $request)
{
    try {
        $method = sanitize_text_field($request->get('method'));
        if (!GatewayManager::has($method)) {
            return $this->sendError(['message' => __('Invalid payment method', 'fluent-cart')], 422);
        }

        $checkoutLabel = $request->getSafe('checkout_label', 'sanitize_text_field');
        $checkoutLogo = $request->getSafe('checkout_logo', 'sanitize_url');
        $checkoutInstructions = $request->getSafe('checkout_instructions', 'wp_kses_post');
        $methodInstance = GatewayManager::getInstance($method);
     
        if (!$methodInstance) {
            return $this->sendError(['message' => __('Invalid payment method instance', 'fluent-cart')], 422);
        }

        // Merge into existing settings and persist via gateway's updateSettings
        $current = (array) $methodInstance->settings->get();
        $current['checkout_label'] = $checkoutLabel;
        $current['checkout_logo']  = $checkoutLogo;
        $current['checkout_instructions'] = $checkoutInstructions;

        if (method_exists($methodInstance, 'updateSettings')) {
            $saved = $methodInstance->updateSettings($current);
        } else {
            return $this->sendError(['message' => __('Gateway cannot update settings', 'fluent-cart')], 500);
        }

        return $this->sendSuccess([
            'message' => __('Checkout design settings saved', 'fluent-cart'),
            'settings' => $saved
        ]);
    } catch (\Throwable $e) {
        return $this->sendError(['message' => $e->getMessage()], 500);
    }
}

    public function connectInfo(Request $request, GlobalPaymentHandler $globalHandler)
    {
        if (GatewayManager::has($request->getSafe('method', 'sanitize_text_field'))) {
            $methodInstance = GatewayManager::getInstance($request->getSafe('method', 'sanitize_text_field'));
            if (method_exists($methodInstance, 'getConnectInfo')) {
                wp_send_json(
                    $methodInstance->getConnectInfo(),
                    200
                );
            }
        }
    }

    public function disconnect(Request $request, GlobalPaymentHandler $globalHandler)
    {
        return $globalHandler->disconnect(
            sanitize_text_field($request->method),
            sanitize_text_field($request->mode),
        );
    }

    public function setPayPalWebhook(Request $request)
    {
        $setupWebhook = (new Webhook())->registerWebhook($request->getSafe('mode', 'sanitize_text_field'));
        if (is_wp_error($setupWebhook)) {
            return $this->sendError([
                'message' => $setupWebhook->get_error_message()
            ], 423);
        }

        return $this->sendSuccess([
            'message' => __('Webhook setup successfully! Please reload the page.', 'fluent-cart')
        ]);
    }

    public function checkPayPalWebhook(Request $request)
    {
       return (new Webhook())->maybeSetWebhook($request->getSafe('mode', 'sanitize_text_field'));
    }
    
    public function reorder(Request $request)
    {
        try {
            $order = $request->get('order');
            if (!is_array($order)) {
                return $this->sendError([
                    'message' => __('Invalid order format', 'fluent-cart')
                ], 422);
            }
            
            // Sanitize the order array
            $order = array_map('sanitize_text_field', $order);
            
            // Save to WordPress options table
            update_option('fluent_cart_payment_methods_order', $order);
            
            return $this->sendSuccess([
                'message' => __('Payment methods order saved successfully', 'fluent-cart'),
                'order' => $order
            ]);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function installAddon(Request $request)
    {
        try {
            $sourceType = $request->getSafe('source_type', 'sanitize_text_field');
            $sourceLink = $request->getSafe('source_link', 'sanitize_url');
            $pluginSlug = $request->getSafe('plugin_slug', 'sanitize_text_field');
            
            if (empty($pluginSlug) || empty($sourceType)) {
                return $this->sendError([
                    'message' => __('Plugin slug, source type are required', 'fluent-cart')
                ], 422);
            }

            if ($sourceType === 'github' && empty($sourceLink)) {
                return $this->sendError([
                    'message' => __('Source link is required', 'fluent-cart')
                ], 422);
            }

            $manager = new PaymentAddonManager();
            $result = $manager->installAddon($sourceType, $sourceLink, $pluginSlug);

            if (is_wp_error($result)) {
                return $this->sendError([
                    'message' => $result->get_error_message()
                ], 423);
            }

            return $this->sendSuccess([
                'message' => __('Payment addon installed and activated successfully!', 'fluent-cart')
            ]);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function activateAddon(Request $request)
    {
        try {
            $pluginFile = $request->getSafe('plugin_file', 'sanitize_text_field');
            
            if (empty($pluginFile)) {
                return $this->sendError([
                    'message' => __('Plugin file path is required', 'fluent-cart')
                ], 422);
            }

            $manager = new PaymentAddonManager();
            $result = $manager->activateAddon($pluginFile);

            if (is_wp_error($result)) {
                return $this->sendError([
                    'message' => $result->get_error_message()
                ], 423);
            }

            return $this->sendSuccess([
                'message' => __('Payment addon activated successfully!', 'fluent-cart')
            ]);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
