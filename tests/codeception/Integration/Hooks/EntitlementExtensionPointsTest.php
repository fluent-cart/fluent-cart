<?php

namespace Tests\Integration\Hooks;

use FluentCart\App\Helpers\AdminHelper;
use FluentCart\App\Services\Email\EmailPreviewService;
use FluentCart\App\Services\ShortCodeParser\Parsers\OrderParser;
use FluentCart\App\Services\TemplateService;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\RestTestCase;

/**
 * Generic extension points that let a module show what an order grants:
 * subscription page widgets, product tab icons and receipt entitlements.
 */
class EntitlementExtensionPointsTest extends RestTestCase
{
    private function createSubscription()
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'type' => 'subscription']);

        return (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => 1,
            'variation_id'    => 1,
        ]);
    }

    private function getWidgets(array $data, $userId = null): \WP_REST_Response
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/widgets');
        $request->set_query_params(['filter' => 'single_subscription_page', 'data' => $data]);

        return $userId ? $this->dispatchRequestAs($userId, $request) : $this->dispatchRequest($request);
    }

    public function test_subscription_page_widgets_receive_the_subscription(): void
    {
        $subscription = $this->createSubscription();
        $receivedId = null;

        add_filter('fluent_cart/widgets/single_subscription_page', function ($widgets, $data) use (&$receivedId) {
            $receivedId = $data['subscription']->id;
            $widgets[] = ['type' => 'html', 'title' => 'Membership', 'content' => '<p>Gold</p>'];
            return $widgets;
        }, 10, 2);

        $response = $this->getWidgets(['subscription_id' => $subscription->id]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame($subscription->id, $receivedId);
        $this->assertSame('Membership', $response->get_data()['widgets'][0]['title']);
    }

    public function test_subscription_page_widgets_reject_an_unknown_subscription(): void
    {
        $called = false;
        add_filter('fluent_cart/widgets/single_subscription_page', function ($widgets) use (&$called) {
            $called = true;
            return $widgets;
        });

        $response = $this->getWidgets(['subscription_id' => 99999999]);

        $this->assertNotSame(200, $response->get_status());
        $this->assertFalse($called);
    }

    public function test_product_tab_icon_renders_before_the_label(): void
    {
        $product = (new ProductFactory())->create();

        add_filter('fluent_cart/product_admin_items', function ($items) {
            $items['membership'] = [
                'label' => 'Membership',
                'link'  => 'https://example.test/membership',
                'icon'  => '<svg viewBox="0 0 20 20"><path d="M0 0h20v20H0z"/></svg>',
            ];
            return $items;
        });

        $html = AdminHelper::getProductMenu($product);

        $this->assertMatchesRegularExpression(
            '#fct-admin-product-menu-icon" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M0 0h20v20H0z"/></svg></span>\s*Membership#',
            $html
        );
    }

    public function test_order_entitlements_action_prints_in_the_default_receipt_and_the_receipt_smartcode(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $contexts = [];

        add_action('fluent_cart/email/order_entitlements', function ($data) use (&$contexts, $order) {
            $this->assertSame($order->id, $data['order']->id);
            $contexts[] = $data['context'];
            echo '<div class="test-entitlement">Gold membership</div>';
        });

        $defaultBody = TemplateService::getTemplateByPathName('order.paid.customer', ['order' => $order]);
        $receipt = (new OrderParser(['order' => $order]))->getPaymentReceipt();

        $this->assertStringContainsString('test-entitlement', $defaultBody);
        $this->assertStringContainsString('test-entitlement', $receipt);
        $this->assertSame(['order_paid_customer', 'payment_receipt'], $contexts);
    }

    public function test_order_entitlements_action_skips_the_email_preview_stub_order(): void
    {
        $fired = false;
        add_action('fluent_cart/email/order_entitlements', function () use (&$fired) {
            $fired = true;
        });

        $previewOrder = (new EmailPreviewService())->getPreviewData('order.paid.customer')['order'];
        TemplateService::getTemplateByPathName('order.paid.customer', ['order' => $previewOrder]);

        $this->assertFalse($fired);
    }

    /**
     * @dataProvider mainBodySlotProvider
     */
    public function test_main_body_widget_slots_receive_their_model(string $filter, string $idKey, string $modelKey): void
    {
        $subscription = $this->createSubscription();
        $ids = [
            'order_id'        => $subscription->parent_order_id,
            'customer_id'     => $subscription->customer_id,
            'subscription_id' => $subscription->id,
        ];
        $receivedId = null;

        add_filter('fluent_cart/widgets/' . $filter, function ($widgets, $data) use (&$receivedId, $modelKey) {
            $receivedId = $data[$modelKey]->id;
            $widgets[] = ['type' => 'html', 'title' => 'Membership', 'content' => '<p>Gold</p>'];
            return $widgets;
        }, 10, 2);

        $response = $this->getWidgetsFor($filter, [$idKey => $ids[$idKey]]);
        $missing = $this->getWidgetsFor($filter, [$idKey => 99999999]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame($ids[$idKey], $receivedId);
        $this->assertSame('Membership', $response->get_data()['widgets'][0]['title']);
        $this->assertNotSame(200, $missing->get_status());
    }

    public function mainBodySlotProvider(): array
    {
        return [
            'order'        => ['single_order_page_main', 'order_id', 'order'],
            'customer'     => ['customer_page_main', 'customer_id', 'customer'],
            'subscription' => ['single_subscription_page_main', 'subscription_id', 'subscription'],
        ];
    }

    private function getWidgetsFor(string $filter, array $data): \WP_REST_Response
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/widgets');
        $request->set_query_params(['filter' => $filter, 'data' => $data]);

        return $this->dispatchRequest($request);
    }
}
