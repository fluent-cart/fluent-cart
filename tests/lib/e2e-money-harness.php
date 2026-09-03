<?php
/**
 * Phase 27 checkout boundary shared by the opt-in E2E money flows.
 */

use FluentCart\Api\Checkout\CheckoutApi;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface;
use FluentCart\App\Services\Payments\PaymentInstance;

class FcPhase27InertGateway implements PaymentGatewayInterface
{
    /** @var array<int,int> */
    public $amounts = [];

    public function has(string $feature): bool
    {
        return $feature === 'payment';
    }

    public function meta(): array
    {
        return [
            'title'       => 'Phase 27 inert gateway',
            'route'       => 'phase27_inert',
            'slug'        => 'phase27_inert',
            'description' => 'Test-only inert gateway',
            'logo'        => '',
            'icon'        => '',
            'brand_color' => '#000000',
            'upcoming'    => false,
            'status'      => true,
        ];
    }

    public function getMeta($key = '')
    {
        $meta = $this->meta();

        return $key === '' ? $meta : ($meta[$key] ?? '');
    }

    public function validatePaymentMethod($status): array
    {
        return ['isValid' => true, 'reason' => ''];
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $amount = (int) $paymentInstance->order->total_amount;
        $this->amounts[] = $amount;

        return [
            'status' => 'success',
            'amount' => $amount,
            'mode'   => 'inert',
        ];
    }

    public function handleIPN()
    {
        return null;
    }

    public function getOrderInfo(array $data)
    {
        return [];
    }

    public function fields()
    {
        return [];
    }
}

class FcPhase27CheckoutHarness
{
    /**
     * Complete one anonymous checkout and adopt its exact pending Order ID.
     *
     * @param string              $suffix
     * @param int                 $customerId
     * @param array<string,mixed> $cartItem
     * @param array<string,mixed> $checkoutData
     * @param array<int,string>   $coupons
     * @return array<string,mixed>
     */
    public static function place($suffix, $customerId, array $cartItem, array $checkoutData, array $coupons = [])
    {
        $requestKey = Helper::INSTANT_CHECKOUT_URL_PARAM;
        $previousHash = App::request()->get($requestKey);
        $previousUserId = get_current_user_id();
        $previousRemoteAddress = isset($_SERVER['REMOTE_ADDR'])
            ? (string) $_SERVER['REMOTE_ADDR']
            : null;
        $gateway = new FcPhase27InertGateway();
        $slug = 'phase27_inert';
        $registeredManagers = [];
        $taxBehaviorFilter = function () {
            return 1;
        };
        $dieFilter = function () {
            return function () {
                throw new RuntimeException('phase27-json-terminated');
            };
        };
        $cartHash = '';
        $capturedOutput = '';
        $terminated = false;
        $order = null;

        $input = [
            'billing_email'      => FcFixture::identity(),
            'billing_full_name'  => 'Phase Twenty Seven',
            'billing_first_name' => 'Phase',
            'billing_last_name'  => 'Twenty Seven',
            'billing_country'    => 'BD',
            'billing_state'      => 'BD-13',
            'billing_city'       => 'Dhaka',
            'billing_postcode'   => '1205',
            'billing_address_1'  => 'Phase 27 exact checkout',
            'billing_address_2'  => '',
            'billing_phone'      => '',
            'ship_to_different'  => 'no',
            'agree_terms'        => 'yes',
            '_fct_pay_method'    => $slug,
            'order_notes'        => FcFixture::orderMarker(),
            'user_tz'            => 'UTC',
        ];
        $checkoutData['form_data'] = $input;

        try {
            $cart = FcAutomationFixture::cart(
                'phase27-' . $suffix,
                '2001-02-03 04:05:06',
                [
                    'customer_id'   => (int) $customerId,
                    'cart_group'    => 'instant',
                    'stage'         => 'cart',
                    'cart_data'     => [$cartItem],
                    'checkout_data' => $checkoutData,
                    'coupons'       => $coupons,
                ]
            );
            $cartHash = (string) $cart->cart_hash;

            foreach ([GatewayManager::getInstance(), App::gateway()] as $manager) {
                if (!$manager || isset($registeredManagers[spl_object_hash($manager)])) {
                    continue;
                }
                if ($manager->get($slug) !== null) {
                    throw new RuntimeException('Phase 27 inert gateway slug is already registered.');
                }
                $manager->register($slug, $gateway);
                $registeredManagers[spl_object_hash($manager)] = $manager;
            }

            App::request()->set($requestKey, $cartHash);
            wp_set_current_user(0);
            $_SERVER['REMOTE_ADDR'] = '192.0.2.'
                . (1 + (hexdec(substr(hash('sha256', FcFixture::identity() . $suffix), 0, 2)) % 253));
            CartResource::resetCartCache();
            add_filter('fluent_cart/cart/tax_behavior', $taxBehaviorFilter, PHP_INT_MAX, 2);
            if (!defined('DOING_AJAX')) {
                define('DOING_AJAX', true);
            }
            add_filter('wp_die_ajax_handler', $dieFilter, PHP_INT_MAX);

            ob_start();
            try {
                CheckoutApi::placeOrder($input, true);
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== 'phase27-json-terminated') {
                    throw $e;
                }
                $terminated = true;
            } finally {
                $capturedOutput = ob_get_clean();
                remove_filter('wp_die_ajax_handler', $dieFilter, PHP_INT_MAX);
            }

            $storedCart = FcAutomationFixture::findCart($cartHash);
            $payload = json_decode($capturedOutput, true);
            if ($storedCart === null || (int) $storedCart->order_id <= 0) {
                throw new RuntimeException(
                    'Phase 27 checkout did not link an Order: ' . wp_json_encode($payload)
                );
            }
            $order = FcFixture::captureCheckoutOrder((int) $storedCart->order_id);

            return [
                'terminated'      => $terminated,
                'payload'         => is_array($payload) ? $payload : [],
                'order'           => $order,
                'gateway_amounts' => $gateway->amounts,
                'cart_hash'       => $cartHash,
            ];
        } finally {
            if ($cartHash !== '') {
                global $wpdb;
                $lockName = 'fct_checkout_' . md5($wpdb->prefix . $cartHash);
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
                $storedCart = FcAutomationFixture::findCart($cartHash);
                if ($storedCart && (int) $storedCart->order_id > 0 && $order === null) {
                    FcFixture::captureCheckoutOrder((int) $storedCart->order_id);
                }
            }
            foreach ($registeredManagers as $manager) {
                $manager->remove($slug);
            }
            remove_filter('fluent_cart/cart/tax_behavior', $taxBehaviorFilter, PHP_INT_MAX);
            wp_set_current_user($previousUserId);
            if ($previousRemoteAddress === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousRemoteAddress;
            }
            App::request()->set($requestKey, $previousHash);
            CartResource::resetCartCache();
        }
    }
}
