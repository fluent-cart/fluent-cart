<?php

namespace Tests\Integration\Events;

use FluentCart\App\Hooks\Handlers\UserHandler;
use FluentCart\App\Listeners\Order\OrderPaid;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\WPUserFactory;

class GuestCheckoutIdentityTest extends WPTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_paid_order_does_not_claim_a_registered_account_by_email(): void
    {
        $email = 'paid-guest@example.com';
        $userId = WPUserFactory::create(['user_email' => $email]);
        $guest = (new CustomerFactory())->create(['email' => $email, 'user_id' => 0]);
        $order = (new OrderFactory())->create(['customer_id' => $guest->id, 'config' => ['create_account_after_paid' => 'yes']]);
        wp_set_current_user(0);

        OrderPaid::maybeCreateUser($order);

        $this->assertEmpty($guest->fresh()->user_id, 'Payment completion must not bind a registered account by email.');
        $this->assertSame($userId, (int) email_exists($email), 'The existing WordPress account must be left intact.');
        $this->assertSame(0, get_current_user_id(), 'Payment completion must not authenticate an existing account.');
    }

    public function test_cart_completion_does_not_claim_a_registered_account_by_email(): void
    {
        $email = 'completed-guest@example.com';
        $userId = WPUserFactory::create(['user_email' => $email]);
        $guest = (new CustomerFactory())->create(['email' => $email, 'user_id' => 0]);
        $order = (new OrderFactory())->create(['customer_id' => $guest->id]);
        wp_set_current_user(0);

        (new UserHandler())->maybeCreateUser(['order' => $order, 'cart' => null]);

        $this->assertEmpty($guest->fresh()->user_id, 'The cart completion handler must not repair a customer link by email.');
        $this->assertSame($userId, (int) email_exists($email), 'The registered account must be unchanged.');
    }
}
