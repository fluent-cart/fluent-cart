<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;
use Tests\Support\Traits\RequiresEmailVerification;

class CheckoutCustomerIdentityCest
{
    use PlacesCheckoutOrders;
    use RequiresEmailVerification;

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
        $this->requireEmailVerification();
    }

    public function _after(FunctionalTester $I): void
    {
        $this->removeStubCheckoutGateway();
        $this->restoreEmailVerificationDefault();
        $I->logout();
    }

    public function signedInCheckoutUsesTheLinkedCustomerWhenAccountEmailHasChanged(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $email = get_userdata($userId)->user_email;
        $originalName = get_userdata($userId)->first_name;
        // Create the email match first so an OR query cannot pass by row order.
        $guest = (new CustomerFactory())->create(['email' => $email, 'first_name' => 'Guest']);
        $oldOrder = (new OrderFactory())->create(['customer_id' => $guest->id]);
        $owner = (new CustomerFactory())->create(['user_id' => $userId, 'email' => 'old-contact@example.test']);
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(200, $status, 'Checkout must succeed: ' . wp_json_encode($body));
        $newOrder = Order::query()->where('id', '!=', $oldOrder->id)->first();
        Assert::assertNotNull($newOrder, 'Checkout must create a new order.');
        Assert::assertSame((int) $owner->id, (int) $newOrder->customer_id, 'The new order must use the stored account link.');
        Assert::assertSame($userId, (int) $owner->fresh()->user_id, 'The existing owner link must remain intact.');
        Assert::assertSame('old-contact@example.test', $owner->fresh()->email, 'Checkout must preserve the customer contact email.');
        Assert::assertEmpty($guest->fresh()->user_id, 'Checkout must not claim the matching guest customer.');
        Assert::assertSame('Guest', $guest->fresh()->first_name, 'Checkout must not overwrite the matching guest profile.');
        Assert::assertSame((int) $guest->id, (int) $oldOrder->fresh()->customer_id, 'Earlier guest purchases must keep their owner.');
        Assert::assertSame(2, Customer::query()->count(), 'The linked customer must be reused.');
        Assert::assertSame($originalName, get_userdata($userId)->first_name, 'An email mismatch must not update saved profile data before verification.');
    }

    public function signedInCheckoutWithoutACustomerDoesNotClaimAnEmailMatch(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $email = get_userdata($userId)->user_email;
        EmailVerificationService::markUnverified($userId, $email);
        $guest = (new CustomerFactory())->create(['email' => $email, 'first_name' => 'Guest']);
        $oldOrder = (new OrderFactory())->create(['customer_id' => $guest->id]);
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(200, $status, 'Checkout must succeed: ' . wp_json_encode($body));
        Assert::assertEmpty($guest->fresh()->user_id, 'An email match must not claim the guest record.');
        Assert::assertSame('Guest', $guest->fresh()->first_name, 'The guest profile must remain unchanged.');
        Assert::assertSame(1, Customer::query()->count(), 'Checkout reuses the existing customer without a duplicate.');
        Assert::assertSame((int) $guest->id, (int) Order::query()->where('id', '!=', $oldOrder->id)->first()->customer_id, 'Both purchases use the same customer.');
        Assert::assertSame((int) $guest->id, (int) $oldOrder->fresh()->customer_id, 'Historical orders keep their customer ID.');
        Assert::assertStringNotContainsString($oldOrder->uuid, wp_json_encode($body), 'Checkout must not expose historical orders.');
        [$denied] = $I->get('/fluent-cart/v2/customer-profile/orders');
        Assert::assertSame(403, $denied, 'Even an authenticated account must verify before accessing purchases.');
    }

    public function signedInCheckoutDoesNotRebindAnotherAccountsCustomer(FunctionalTester $I): void
    {
        $otherUserId = WPUserFactory::create(['user_email' => 'other-account@example.test']);
        $userId = $I->loginAs('customer');
        $other = (new CustomerFactory())->create(['user_id' => $otherUserId, 'email' => get_userdata($userId)->user_email, 'first_name' => 'Other']);
        $oldOrder = (new OrderFactory())->create(['customer_id' => $other->id]);
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(200, $status, 'Checkout must succeed for the current account: ' . wp_json_encode($body));
        Assert::assertSame($otherUserId, (int) $other->fresh()->user_id, 'The other account must keep its customer link.');
        Assert::assertSame('Other', $other->fresh()->first_name, 'The other customer profile must remain unchanged.');
        Assert::assertSame((int) $other->id, (int) $oldOrder->fresh()->customer_id, 'The other account must keep its earlier order.');
        Assert::assertNull(Customer::query()->where('user_id', $userId)->first(), 'Checkout must not claim another account customer.');
        Assert::assertSame(1, Customer::query()->count(), 'Email matching does not create a duplicate.');
        Assert::assertSame((int) $other->id, (int) Order::query()->where('id', '!=', $oldOrder->id)->first()->customer_id, 'Purchases are grouped by the existing customer email.');
    }

    public function guestCheckoutDoesNotRepairAnUnlinkedCustomerFromARegisteredEmail(FunctionalTester $I): void
    {
        $email = 'unclaimed-member@example.test';
        $userId = WPUserFactory::create(['user_email' => $email]);
        $guest = (new CustomerFactory())->create(['email' => $email, 'user_id' => 0]);
        $oldOrder = (new OrderFactory())->create(['customer_id' => $guest->id]);
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, ['billing_email' => $email]));

        Assert::assertSame(200, $status, 'Guest checkout must still succeed: ' . wp_json_encode($body));
        Assert::assertEmpty($guest->fresh()->user_id, 'A guest must not repair ownership from a registered email.');
        Assert::assertSame(1, Customer::query()->count(), 'An existing unlinked guest customer may be reused.');
        Assert::assertSame((int) $guest->id, (int) Order::query()->where('id', '!=', $oldOrder->id)->first()->customer_id, 'The guest order must stay with the unlinked customer.');
        Assert::assertSame(0, Customer::query()->where('user_id', $userId)->count(), 'The registered account must not gain guest purchases.');
        Assert::assertSame(0, get_current_user_id(), 'An email match must not authenticate the guest.');
    }

    public function guestCheckoutReusesTheEmailCustomerWithoutRebindingIt(FunctionalTester $I): void
    {
        $email = 'registered-inbox@example.test';
        $registeredUserId = WPUserFactory::create(['user_email' => $email]);
        $ownerId = WPUserFactory::create(['user_email' => 'original-account@example.test']);
        $owned = (new CustomerFactory())->create(['user_id' => $ownerId, 'email' => $email, 'first_name' => 'Original']);
        $oldOrder = (new OrderFactory())->create(['customer_id' => $owned->id]);
        $variation = (new ProductFactory())->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, ['billing_email' => $email]));

        Assert::assertSame(200, $status, 'Guest checkout must still succeed: ' . wp_json_encode($body));
        Assert::assertSame($ownerId, (int) $owned->fresh()->user_id, 'The existing owner link must not be overwritten by the account holding the email.');
        Assert::assertSame('Original', $owned->fresh()->first_name, 'A guest must not overwrite an account-owned customer profile.');
        Assert::assertSame((int) $owned->id, (int) $oldOrder->fresh()->customer_id, 'Earlier purchases must keep their owner.');
        $newOrder = Order::query()->where('id', '!=', $oldOrder->id)->first();
        Assert::assertSame((int) $owned->id, (int) $newOrder->customer_id, 'Guest checkout reuses the email customer.');
        Assert::assertSame(1, Customer::query()->count(), 'Guest checkout does not create a duplicate.');
        Assert::assertSame(0, get_current_user_id(), 'Reusing the customer does not authenticate the guest.');
        Assert::assertSame(0, Customer::query()->where('user_id', $registeredUserId)->count(), 'The account holding the submitted email must gain no customer.');
    }

    public function adminCreatingACustomerLinksAFreshRowToTheAccountHoldingItsEmail(FunctionalTester $I): void
    {
        $userId = WPUserFactory::create(['user_email' => 'member-admin-create@example.test']);
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/customers', [
            'first_name' => 'Created',
            'last_name'  => 'ByAdmin',
            'full_name'  => 'Created ByAdmin',
            'email'      => 'member-admin-create@example.test',
        ]);

        Assert::assertSame(200, $status, 'the admin create must succeed. Body: ' . wp_json_encode($body));
        $customer = Customer::query()->where('email', 'member-admin-create@example.test')->first();
        Assert::assertNotNull($customer, 'the customer must be created');
        Assert::assertSame($userId, (int) $customer->user_id, 'an admin\'s explicit creation links the new row to the account holding the email');
    }
}
