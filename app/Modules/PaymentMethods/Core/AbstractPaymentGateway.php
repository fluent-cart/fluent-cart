<?php

namespace FluentCart\App\Modules\PaymentMethods\Core;

use FluentCart\Api\Helper;
use FluentCart\Api\Orders;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Api\Resource\ActivityResource;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Renderer\Receipt\ReceiptRenderer;
use FluentCart\Framework\Support\Arr;

abstract class AbstractPaymentGateway implements PaymentGatewayInterface
{

    private $methodSlug = '';

    public array $supportedFeatures = [];

    public StoreSettings $storeSettings;

    public ?AbstractSubscriptionModule $subscriptions;

    public BaseGatewaySettings $settings;

    public function __construct(BaseGatewaySettings $settings, ?AbstractSubscriptionModule $subscriptions = null)
    {
        $this->settings = $settings;
        $this->methodSlug = $this->getMeta('slug');

        if ($subscriptions) {
            $this->supportedFeatures[] = 'subscriptions';
        }
        $this->subscriptions = $subscriptions;

        // register global hooks
        $this->init();
    }

    public function init(): void
    {
        add_filter('fluent_cart/transaction/url_' . $this->methodSlug, [$this, 'getTransactionUrl'], 10, 2);
        add_filter('fluent_cart/subscription/url_' . $this->methodSlug, [$this, 'getSubscriptionUrl'], 10, 2);
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures);
    }

    public function getMeta($key = '')
    {
        $meta = $this->meta();

        $gatewaySettings = $this->settings->get();

        if (isset($gatewaySettings['checkout_label']) && !empty($gatewaySettings['checkout_label'])) {
            $meta['title'] = $gatewaySettings['checkout_label'];
        }

        if (isset($gatewaySettings['checkout_logo']) && !empty($gatewaySettings['checkout_logo'])) {
            $meta['logo'] = $gatewaySettings['checkout_logo'];
        }

        if (isset($gatewaySettings['checkout_instructions']) && !empty($gatewaySettings['checkout_instructions'])) {
            $meta['instructions'] = $gatewaySettings['checkout_instructions'];
        }

        if ($key !== '') {
            return Arr::get($meta, $key, '');
        }
        return $meta;
    }

    public function isUpcoming(): bool
    {
        return $this->getMeta('upcoming');
    }

    public function setStoreSettings(StoreSettings $settings): void
    {
        $this->storeSettings = $settings;
    }

    public function storeSettings(): StoreSettings
    {
        return $this->storeSettings;
    }

    public function isCurrencySupported(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return $this->settings->get('is_active') === 'yes';
    }

    public static function validateSettings($data): array
    {
        return $data;
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        return $data;
    }

    public function updateSettings($data)
    {
        if ($this->isUpcoming()) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Payment method is upcoming! Not available for right now!', 'fluent-cart')
            ], 422);
        }

        $oldSettings = $this->settings->get();
        $settings = wp_parse_args($data, $oldSettings);
        $settings = Helper::sanitize($settings, $this->fields());
        $is_active = Arr::get($settings, 'is_active', 'no');
        // validate if the settings/credentials are correct
        if ('yes' === $is_active) {
            $response = static::validateSettings($settings);
            if (isset($reponse['status']) && $response['status'] === 'failed') {
                wp_send_json(
                    [
                        'status'  => 'failed',
                        'message' => $response['message'] ? $response['message'] : __('Invalid credentials!', 'fluent-cart'),
                        'data'    => []
                    ],
                    422
                );
            }
        }

        $settings = static::beforeSettingsUpdate($settings, $oldSettings);
//        unset($settings['payment_mode']);
        unset($settings['provider']);
        fluent_cart_update_option($this->settings->methodHandler, $settings);

        return $settings;
    }

    public function getSuccessUrl($transaction, $args = [])
    {
        $paymentHelper = new PaymentHelper($this->getMeta('route'));
        return $paymentHelper->successUrl($transaction->uuid, $args);
    }

    public static function getCancelUrl(): string
    {
        $checkoutPage = (new StoreSettings())->getCheckoutPage();
        // get cart hash from url
        $cartHash = App::request()->get('fct_cart_hash', '');
        if ($cartHash) {
            return add_query_arg([
                'fct_cart_hash' => $cartHash
            ], $checkoutPage);
        }
        return $checkoutPage;
    }

    public function paymentFailedNote($content, $data)
    {
        $request = Arr::get($data, 'request');
        $trx_hash = $request->getSafe('trx_hash', 'sanitize_text_field');
        $transaction = OrderTransaction::query()->where('uuid', $trx_hash)->first();
        if (!$transaction) {
            return __('Transaction not found!', 'fluent-cart');
        }

        $order = (new Orders())->getById($transaction->order_id);

        if (!$order || $transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return '';
        }

        $failedtitle = __('Payment Failed', 'fluent-cart');
        $hasLog = ActivityResource::getQuery()->where('module_id', $order->id)
            ->where('module_name', 'Order')
            ->where('status', 'error')
            ->where('title', $failedtitle)
            ->count();

        if (!$hasLog) {
            $content = 'Payment Failed Reason: ' . $request->getSafe('reason', 'sanitize_text_field');
            fluent_cart_error_log($failedtitle, $content, [
                'module_id'   => $order->id,
                'module_name' => 'Order'
            ]);
        }

        ob_start();
        (new ReceiptRenderer())->renderConfirmationError([
            'order'              => $order,
            'failed_reason'      => $request->getSafe('reason', 'sanitize_text_field'),
            'custom_payment_url' => PaymentHelper::getCustomPaymentLink($order->uuid)
        ]);
        return ob_get_clean();
    }

    protected function getListenerUrl($args = null)
    {
        return (new PaymentHelper($this->getMeta('route')))->listenerUrl($args);
    }

    public function getOrderByHash($orderHash)
    {
        return (new Orders())->getByHash($orderHash);
    }

    public function validateSubscriptions($items): bool
    {
        $hasSubscription = (new CartResource())->hasSubscriptionProduct($items);

        if ($hasSubscription && !$this->has('subscriptions')) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Subscription payment is not avalable for this gateway. Please choose another payment method!', 'fluent-cart')
            ], 422);
        }
        return $hasSubscription;
    }

    public function validatePaymentMethod($data)
    {
        $isZeroPayment = Arr::get($data, 'isZeroPayment', false);
        if (!$this->isEnabled() && !$isZeroPayment) {
            return [
                'isValid' => false,
                'reason'  => sprintf(
                    /* translators: %s is the payment method name */
                    __('Selected payment method %s is not active!', 'fluent-cart'),
                    $this->getMeta('route')
                )
            ];
        }

        $hasSubscriptions = (CartCheckoutHelper::make())->hasSubscription();
        if ($hasSubscriptions === 'yes' && !$this->has('subscriptions')) {
            return [
                'isValid' => false,
                'reason'  => sprintf(
                    /* translators: %s is the payment method name */
                    __('Subscription is not active for Selected payment method %s!', 'fluent-cart'),
                    $this->getMeta('route')
                )
            ];
        }

        return [
            'isValid' => true,
        ];
    }

    public function updateOrderDataByOrder($order, $transactionData, $transaction)
    {
        if ($order == null) {
            return;
        }

        $transaction->fill($transactionData);
        $transaction->save();


        $paymentStatus = Status::syncPaymentStatus(Arr::get($transactionData, 'status'));
        $orderStatus = !in_array($paymentStatus, [Status::TRANSACTION_SUCCEEDED, Status::PAYMENT_PAID]) ? $paymentStatus : Status::ORDER_PROCESSING;

        $statusHelper = (new StatusHelper())->setOrder($order);
        $statusHelper->updateTransactionData($transactionData, $transaction);

        if ($amount = Arr::get($transactionData, 'total')) {
            $statusHelper->updateTotalPaid($amount);
        }

        $statusHelper->changeOrderStatus($orderStatus, $paymentStatus, $this->getMeta('title'), $this->getMeta('slug'));

        //If product is digital and processing then trigger status to completed
        if ($order->fulfillment_type == 'digital' && $orderStatus === Status::ORDER_PROCESSING && $order->total_amount <= $order->total_paid) {
            $statusHelper->changeOrderStatus(Status::ORDER_COMPLETED, $paymentStatus, $this->getMeta('title'), $this->getMeta('slug'));
        }

        do_action('fluent_cart/payments/after_payment_' . $paymentStatus, [
            'order' => $order
        ]);
    }

    public function getCheckoutItems(): array
    {
        return (CartCheckoutHelper::make())->getItems();
    }

    public function getSettings(): BaseGatewaySettings
    {
        return $this->settings;
    }

    public function renderStoreModeNotice(): string
    {
        if ((new StoreSettings())->get('order_mode') == 'test') {
            return '<div class="mt-5"><span class="text-warning-500">' . __('Your Store is in Test mode, Change Store\'s \'Order Mode\' to \'Live\' and update related settings to enable Live payment !!', 'fluent-cart') . '</span></div>';
        }
        return '<div class="mt-5"><span class="text-success-500">' . __('Your Store is in Live mode', 'fluent-cart') . '</span></div>';
    }

    public function beforeRenderPaymentMethod($hasSubscription): void
    {
        $this->enqueue($hasSubscription);
    }

    public function getEnqueueVersion()
    {
        return FLUENTCART_VERSION;
    }

    public function getEnqueueScriptSrc($hasSubscription): array
    {
        return [];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [];
    }

    public function getTransactionUrl($url, $data)
    {
        return $url;
    }

    public function getSubscriptionUrl($url, $data)
    {
        return $url;
    }

    public function processRefund($transaction, $amount, $args)
    {
        return new \WP_Error('not_implemented', __('Refund process is not implemented for this payment gateway.', 'fluent-cart'));
    }

    public function enqueue($hasSubscription): void
    {
        $styles = $this->getEnqueueStyleSrc();
        $scripts = $this->getEnqueueScriptSrc($hasSubscription);

        foreach ($styles as $style) {
            wp_enqueue_style(
                Arr::get($style, 'handle'),
                Arr::get($style, 'src'),
                Arr::get($style, 'deps', null),
                $this->getEnqueueVersion()
            );
        }

        $handleToEnqueue = '';
        $scriptHandles = [];
        foreach ($scripts as $script) {
            $handle = Arr::get($script, 'handle');
            if (empty($handleToEnqueue)) {
                $handleToEnqueue = $handle;
            }
            $scriptHandles[] = $handle;
            wp_enqueue_script(
                $handle,
                Arr::get($script, 'src'),
                Arr::get($script, 'deps', null),
                $this->getEnqueueVersion(),
                Arr::get($script, 'in_footer', false),
            );
        }

        // Add filter to prevent consent plugins from blocking payment gateway scripts
        if (!empty($scriptHandles) && is_array($scriptHandles)) {
            $gatewayInstance = $this;
        
            add_filter('script_loader_tag', function($tag, $handle, $src) use ($scriptHandles, $gatewayInstance) {
        
                if (!in_array($handle, $scriptHandles, true)) {
                    return $tag;
                }

                // This makes payment scripts load as "necessary" cookies
                $attributes = [
                    'data-category="necessary"',
                    'data-consent-category="necessary"',
                    'data-cookieconsent="ignore"',
                    'data-no-optimize="1"',
                    'data-cfasync="false"',
                ];
        
                $serviceName = '';
        
                if (is_object($gatewayInstance)) {
                    if (method_exists($gatewayInstance, 'getConsentServiceName')) {
                        $serviceName = (string) $gatewayInstance->getConsentServiceName();
                    }
        
                    if (empty($serviceName) && method_exists($gatewayInstance, 'getMeta')) {
                        $serviceName = (string) $gatewayInstance->getMeta('title');
                    }
                }
        
                if (!empty($serviceName)) {
                    $serviceName = esc_attr($serviceName);
                    $attributes[] = 'data-usercentrics="' . $serviceName . '"';
                    $attributes[] = 'data-service="' . $serviceName . '"';
                }
        
                $tag = preg_replace(
                    '/<script(\s+)/',
                    '<script$1' . implode(' ', $attributes) . ' ',
                    $tag,
                    1
                );
        
                return $tag;
            }, 10, 3);
        }

        if (!empty($handleToEnqueue)) {
            foreach ($this->getLocalizeData() as $key => $val) {
                wp_localize_script($handleToEnqueue, $key, $val);
            }
        }


    }

    public function prepare($mode, $hasSubscription)
    {
        $this->beforeRenderPaymentMethod($hasSubscription);
        $this->render($mode);
        do_action('fluent-cart/after_render_payment_method_' . $this->getMeta('route'));
    }

    public function render($mode = 'logo')
    {
        $content = '';
        if ($mode === 'logo') {
            $content .= '<img src="' . esc_url($this->getMeta('logo') ?? '') . '"alt="' . esc_attr($this->getMeta('title')) . '"/>';
        } elseif ($mode === 'radio') {
            $content .= '<span class="checkmark">' . '</span>';
        } else {
            $content .= '<span>' . $this->getMeta('title') . '</span>';
        }
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function getLocalizeData(): array
    {
//        $example = [
//            'var_name' => [
//                'key' => 'value'
//            ],
//
//            'var_name_two' => [
//                'key' => 'value'
//            ],
//        ];
        return [];
    }

    /**
     * Get the consent service name for this payment gateway
     * Used by consent management plugins (Usercentrics, CookieYes, etc.)
     * Override in child classes to specify the service name
     * 
     * @return string|null Service name or null to use gateway slug
     */
    public function getConsentServiceName(): ?string
    {
        // By default, return null and let the system use gateway meta title
        return null;
    }

}
