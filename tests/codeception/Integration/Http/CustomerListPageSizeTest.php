<?php

namespace Tests\Integration\Http;

use FluentCart\App\App;
use FluentCart\App\Http\Controllers\FrontendControllers\CustomerOrderController;
use FluentCart\App\Http\Controllers\FrontendControllers\CustomerProfileController;
use FluentCart\App\Http\Controllers\FrontendControllers\CustomerSubscriptionController;
use FluentCart\Framework\Http\Request\Request as FluentRequest;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * Customer-portal lists cap per_page at 100 like the shop, and treat zero or
 * negative values as the default 10 (FIX-PLAN #12). Downloads divided by a
 * zero per_page.
 */
class CustomerListPageSizeTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $customer = (new CustomerFactory())->createWithUser();
        wp_set_current_user((int) $customer->user_id);
        (new OrderFactory())->create(['customer_id' => $customer->id]);
    }

    private function request(array $params): FluentRequest
    {
        return new FluentRequest(App::getInstance(), $params, []);
    }

    private function sizes(callable $call): array
    {
        $out = [];
        foreach ([500 => 100, 0 => 10, -5 => 10, 25 => 25] as $asked => $expected) {
            $out[$asked] = [$expected, (int) $call($this->request(['per_page' => $asked, 'page' => 1]))];
        }
        return $out;
    }

    private function assertSizes(string $list, array $sizes): void
    {
        foreach ($sizes as $asked => [$expected, $actual]) {
            $this->assertSame($expected, $actual, "$list per_page=$asked");
        }
    }

    public function test_customer_order_list_caps_the_page_size(): void
    {
        $this->assertSizes('orders', $this->sizes(function ($request) {
            return (new CustomerOrderController())->getOrders($request)->get_data()['orders']->perPage();
        }));
    }

    public function test_customer_subscription_list_caps_the_page_size(): void
    {
        $this->assertSizes('subscriptions', $this->sizes(function ($request) {
            return (new CustomerSubscriptionController())->getSubscriptions($request)->get_data()['subscriptions']['per_page'];
        }));
    }

    public function test_customer_download_list_caps_the_page_size(): void
    {
        $this->assertSizes('downloads', $this->sizes(function ($request) {
            return (new CustomerProfileController())->getDownloads($request)->get_data()['downloads']['per_page'];
        }));
    }
}
