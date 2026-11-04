<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\ConfiguresStoreOrderMode;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Typing someone else's email at guest checkout must not hand the buyer a
 * logged-in account that owns that person's earlier guest purchases.
 * Runs on the shipped defaults: account creation "all", auto-login on,
 * email verification off.
 */
class GuestEmailAccountTakeoverCest
{
    use PlacesCheckoutOrders;
    use ConfiguresStoreOrderMode;

    const VICTIM_EMAIL = 'past-buyer@example.test';

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
        $this->setStoreSetting('user_account_creation_mode', 'all');
        $this->setStoreSetting('auto_login_after_account_creation', 'yes');
        $this->setStoreSetting('require_customer_email_verification', 'no');
    }

    public function _after(FunctionalTester $I): void
    {
        $this->removeStubCheckoutGateway();
        $I->logout();
    }

    public function guestUsingAPastBuyersEmailIsNotLoggedInAsThatBuyer(FunctionalTester $I): void
    {
        $victimOrder = $this->placeGuestOrder($I, 1500, 'Past', 'Buyer');
        $victimCustomerId = (int) $victimOrder->customer_id;
        $I->logout();
        Assert::assertSame(0, (int) Customer::query()->find($victimCustomerId)->user_id, 'precondition: the past buyer has no WordPress account');
        Assert::assertFalse((bool) email_exists(self::VICTIM_EMAIL), 'precondition: no WordPress user owns the email yet');

        $this->placeGuestOrder($I, 0, 'Some', 'Stranger');

        $linkedUserId = (int) Customer::query()->find($victimCustomerId)->user_id;
        $currentUserId = get_current_user_id();
        $victimUserId = (int) email_exists(self::VICTIM_EMAIL);
        Assert::assertGreaterThan(0, $victimUserId, 'checkout creates the account; its password is set from the past buyer\'s inbox');
        Assert::assertSame($victimUserId, $linkedUserId, 'the earlier customer belongs to that account, which only the inbox owner can open');
        Assert::assertSame(0, $currentUserId, 'the visitor must remain logged out');
        Assert::assertFalse(
            $currentUserId > 0 && $currentUserId === $linkedUserId,
            "the stranger's browser must not end up logged in as the account that owns the past buyer's orders (user {$currentUserId}, linked {$linkedUserId})"
        );
    }

    public function strangerOptingIntoAnAccountIsNotLoggedInAsAPaidBuyerWhoDeclinedOne(FunctionalTester $I): void
    {
        $this->setStoreSetting('user_account_creation_mode', 'user_choice');
        $victimOrder = $this->placeGuestOrder($I, 0, 'Past', 'Buyer');
        $victimCustomerId = (int) $victimOrder->customer_id;
        $I->logout();
        Assert::assertSame('paid', (string) $victimOrder->fresh()->payment_status, 'precondition: the past buyer paid');
        Assert::assertFalse((bool) email_exists(self::VICTIM_EMAIL), 'precondition: the past buyer declined an account');

        $this->placeGuestOrder($I, 0, 'Some', 'Stranger', ['allow_create_account' => 'yes']);

        $linkedUserId = (int) Customer::query()->find($victimCustomerId)->user_id;
        $currentUserId = get_current_user_id();
        $victimUserId = (int) email_exists(self::VICTIM_EMAIL);
        Assert::assertGreaterThan(0, $victimUserId, 'checkout creates the account; its password is set from the past buyer\'s inbox');
        Assert::assertSame($victimUserId, $linkedUserId, 'the earlier customer belongs to that account, which only the inbox owner can open');
        Assert::assertSame(0, $currentUserId, 'the visitor must remain logged out');
        Assert::assertFalse(
            $currentUserId > 0 && $currentUserId === $linkedUserId,
            "the stranger's browser must not end up logged in as the account that owns the past buyer's orders (user {$currentUserId}, linked {$linkedUserId})"
        );
    }

    private function placeGuestOrder(FunctionalTester $I, int $priceCents, string $first, string $last, array $extra = []): Order
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => $priceCents])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $before = (int) Order::query()->max('id');

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_email'      => self::VICTIM_EMAIL,
            'billing_full_name'  => $first . ' ' . $last,
            'billing_first_name' => $first,
            'billing_last_name'  => $last,
        ] + $extra));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the guest order is accepted. Body: ' . wp_json_encode($body));
        $order = Order::query()->where('id', '>', $before)->first();
        Assert::assertNotNull($order, 'the guest order was written');

        return $order;
    }
}
