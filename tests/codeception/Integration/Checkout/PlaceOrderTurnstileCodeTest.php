<?php

namespace Tests\Integration\Checkout;

use FluentCart\Api\Checkout\CheckoutApi;
use FluentCart\Api\ModuleSettings;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\Turnstile\TurnstileValidator;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\BindsFluentRequest;
use Tests\Support\WpDieCapture;

/**
 * The checkout script retries once on an expired Turnstile token, so the
 * refusal must carry its error code, and must come before any order exists.
 */
class PlaceOrderTurnstileCodeTest extends DatabaseTestCase
{
    use BindsFluentRequest;

    private $originalSettings;

    /** @var TurnstileValidator */
    private $validator;

    /** @var callable|null */
    private $http = null;

    public function setUp(): void
    {
        parent::setUp();
        CartResource::resetCartCache();
        CartCheckoutHelper::resetInstance();

        $this->originalSettings = get_option(ModuleSettings::MODULE_SETTINGS_OPTION, null);
        $settings = is_array($this->originalSettings) ? $this->originalSettings : [];
        $settings['turnstile'] = ['active' => 'yes', 'site_key' => 'site-key', 'secret_key' => 'secret-key'];
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, $settings);
        ModuleSettings::getAllSettings(false);

        $this->validator = new TurnstileValidator();
        $this->validator->register();

        $this->http = function ($preempt, $args, $url) {
            if (strpos($url, 'challenges.cloudflare.com') === false) {
                return $preempt;
            }
            return [
                'headers'  => [],
                'body'     => wp_json_encode(['success' => false, 'error-codes' => ['timeout-or-duplicate']]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
            ];
        };
        add_filter('pre_http_request', $this->http, 10, 3);
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', $this->http, 10);
        remove_filter('fluent_cart/checkout/validate_before_process', [$this->validator, 'validateCheckout'], 10);

        if ($this->originalSettings === null) {
            delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        } else {
            update_option(ModuleSettings::MODULE_SETTINGS_OPTION, $this->originalSettings);
        }
        ModuleSettings::getAllSettings(false);

        CartResource::resetCartCache();
        CartCheckoutHelper::resetInstance();
        $this->bindFluentRequest([]);
        parent::tearDown();
    }

    public function test_an_expired_token_is_refused_with_its_code_before_any_order_exists(): void
    {
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 5000])->create();
        $item = CartHelper::generateCartItemFromVariation($product->variants->first(), 1);
        $cart = (new CartFactory())->withCartItems([$item])->create(['cart_group' => 'instant']);

        $data = [
            'fct_cart_hash'      => $cart->cart_hash,
            'cf_turnstile_token' => 'expired-token',
            'billing_full_name'  => 'Token Buyer',
            'billing_email'      => 'token-buyer@example.test',
            'billing_country'    => 'US',
            'agree_terms'        => 'yes',
        ];
        $this->bindFluentRequest($data);
        $ordersBefore = Order::query()->count();

        $result = WpDieCapture::capture(function () use ($data) {
            CheckoutApi::placeOrder($data, true);
        });

        $this->assertSame(403, $result['status'], 'Body: ' . $result['body']);
        $this->assertSame('failed', $result['json']['status'] ?? null);
        $this->assertSame('turnstile_invalid', $result['json']['code'] ?? null);
        $this->assertSame($ordersBefore, Order::query()->count(), 'a refused token must not create an order');
    }
}
