<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use FluentCart\App\Models\OrderAddress;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;
use Tests\Support\Traits\RequiresEmailVerification;

/**
 * Spec: Cases/checkout-functional.md → "Guest and signed-in checkout — customer linkage" section, items 47-55 and 65.
 *
 * TC-106. Proves how one real `place-order` reuses, links, and completes customer records:
 * a guest against an existing customer or WordPress account, a signed-in user with or
 * without a customer record, saved addresses, name sync, the account-creation flag, and the
 * separate shipping address. Every linkage assertion reads the `fct_customers`,
 * `fct_customer_addresses`, `fct_order_addresses`, or `fct_orders` row back from the database.
 */
class GuestVsCustomerCheckoutCest
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

    /**
     * Spec: Cases/checkout-functional.md [47]
     *   "A guest whose billing email already belongs to a customer gets the order attached to
     *    that customer, and no second customer is created."
     */
    public function guestWithAKnownEmailIsAttachedToTheExistingCustomer(FunctionalTester $I): void
    {
        // An unrelated customer created FIRST: a lookup that ignores the email would land on this row.
        $bystander = (new CustomerFactory())->create(['email' => 'bystander-47@example.test', 'first_name' => 'By', 'last_name' => 'Stander']);
        $existing = (new CustomerFactory())->create(['email' => 'returning@example.test', 'first_name' => 'Returning', 'last_name' => 'Buyer']);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1200])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_email'      => 'returning@example.test',
            'billing_full_name'  => 'Returning Buyer',
            'billing_first_name' => 'Returning',
            'billing_last_name'  => 'Buyer',
        ]));

        Assert::assertSame(200, $status, 'place-order must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must report success');
        Assert::assertSame(2, Customer::query()->count(), 'a known email must not create a third customer next to the bystander and the returning buyer');
        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order');
        Assert::assertSame((int) $existing->id, (int) Order::query()->first()->customer_id, 'the order must be attached to the customer owning the billing email, not to the first customer on file');
        Assert::assertSame(0, (int) Customer::query()->find($existing->id)->user_id, 'a guest order must not invent a user link on the existing customer');
        $untouched = Customer::query()->find($bystander->id);
        Assert::assertSame('By', (string) $untouched->first_name, 'the unrelated customer must keep its first name');
        Assert::assertSame('Stander', (string) $untouched->last_name, 'the unrelated customer must keep its last name');
        Assert::assertSame(0, Order::query()->where('customer_id', $bystander->id)->count(), 'the unrelated customer must not receive the order');
    }

    /**
     * Spec: Cases/checkout-functional.md [48]
     *   "A guest using a registered account email gets an unlinked customer record
     *    until ownership is proved."
     */
    public function guestWithAWordPressAccountEmailLeavesTheCustomerUnlinked(FunctionalTester $I): void
    {
        $bystander = (new CustomerFactory())->create(['email' => 'bystander-48@example.test']);
        $userId = WPUserFactory::create(['user_login' => 'member48', 'user_email' => 'member48@example.test']);
        Assert::assertSame(0, Customer::query()->where('email', 'member48@example.test')->count(), 'precondition: no customer exists yet for the account');
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1800])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_email' => 'member48@example.test',
        ]));

        Assert::assertSame(200, $status, 'place-order must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(2, Customer::query()->count(), 'the guest order must create exactly one new customer next to the bystander');
        $customer = Customer::query()->where('email', 'member48@example.test')->first();
        Assert::assertNotNull($customer, 'the customer must carry the submitted email');
        Assert::assertEmpty($customer->user_id, 'A registered email alone must not link the guest customer to that account.');
        Assert::assertSame(0, Customer::query()->where('user_id', $userId)->count(), 'Guest checkout must not add a customer to the registered account.');
        Assert::assertSame((int) $customer->id, (int) Order::query()->first()->customer_id, 'the order must belong to the unlinked guest customer');
        Assert::assertSame(0, (int) Customer::query()->find($bystander->id)->user_id, 'the unrelated customer must not be linked to the account');
    }

    /**
     * Spec: Cases/checkout-functional.md [49]
     *   "A signed-in user with no customer record gets one created with the account email and
     *    linked to the user id, and a different billing email in the form is ignored."
     */
    public function signedInUserWithoutACustomerGetsOneCreatedFromTheAccount(FunctionalTester $I): void
    {
        $bystander = (new CustomerFactory())->create(['email' => 'bystander-49@example.test']);
        $userId = $I->loginAs('customer');
        $accountEmail = (string) get_userdata($userId)->user_email;
        Assert::assertSame(0, Customer::query()->where('email', $accountEmail)->orWhere('user_id', $userId)->count(), 'precondition: the signed-in user must have no customer record yet');
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 2200])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_email' => 'someone-else@example.test',
        ]));

        Assert::assertSame(200, $status, 'place-order must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(2, Customer::query()->count(), 'the signed-in checkout must create exactly one new customer next to the bystander');
        $customer = Customer::query()->where('email', $accountEmail)->first();
        Assert::assertNotNull($customer, 'the customer must be created with the account email, not the email typed into the form');
        Assert::assertSame(0, (int) Customer::query()->find($bystander->id)->user_id, 'the unrelated customer must not be linked to the signed-in user');
        Assert::assertSame($userId, (int) $customer->user_id, 'the customer must be linked to the signed-in user');
        Assert::assertSame((int) $customer->id, (int) Order::query()->first()->customer_id, 'the order must belong to the new customer');
        Assert::assertSame(0, Customer::query()->where('email', 'someone-else@example.test')->count(), 'the form email must not produce a customer of its own');
    }

    /**
     * Spec: Cases/checkout-functional.md [50]
     *   "A signed-in user whose account email matches an unlinked customer record does not take it
     *    over at checkout; the order uses the existing row without granting access."
     */
    public function signedInUserWithAnUnlinkedCustomerAtTheirEmailDoesNotTakeItOver(FunctionalTester $I): void
    {
        $bystander = (new CustomerFactory())->create(['email' => 'bystander-50@example.test', 'user_id' => 0]);
        $userId = $I->loginAs('customer');
        $accountEmail = (string) get_userdata($userId)->user_email;
        // A guest bought under this address before the account pointed itself at it, unproven.
        EmailVerificationService::markUnverified($userId, $accountEmail);
        $guest = (new CustomerFactory())->create(['email' => $accountEmail, 'user_id' => 0]);
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 900])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(200, $status, 'place-order must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(0, (int) Customer::query()->find($guest->id)->user_id, 'an email match must not bind the guest\'s record to the signed-in account');
        Assert::assertSame(1, Order::query()->where('customer_id', $guest->id)->count(), 'The order reuses the existing customer');
        Assert::assertNull(Customer::query()->where('user_id', $userId)->first(), 'Checkout does not grant ownership');
        Assert::assertSame(2, Customer::query()->count(), 'No duplicate customer is created');
        Assert::assertSame(0, (int) Customer::query()->find($bystander->id)->user_id, 'the unrelated unlinked customer must stay unlinked');
    }

    /**
     * Spec: Cases/checkout-functional.md [51]
     *   "A first-time customer gets one billing and one shipping address saved to the profile; a
     *    returning customer with saved addresses gets no duplicates."
     */
    public function firstCheckoutSavesProfileAddressesOnceAndReturnVisitsAddNone(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000])->create()->variants->first();
        $firstCart = $this->buildInstantCart($I, [[$variation, 1]]);
        [, $firstBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($firstCart->cart_hash, ['billing_email' => 'addresses@example.test']));
        Assert::assertSame('success', (string) ($firstBody['status'] ?? ''), 'precondition: the first checkout must succeed. Body: ' . wp_json_encode($firstBody));

        Assert::assertSame(1, Customer::query()->count(), 'the first checkout must create one customer');
        $customer = Customer::query()->first();
        $billing = CustomerAddresses::query()->where('customer_id', $customer->id)->where('type', 'billing')->get();
        $shipping = CustomerAddresses::query()->where('customer_id', $customer->id)->where('type', 'shipping')->get();
        Assert::assertCount(1, $billing, 'the first checkout must save exactly one billing address to the profile');
        Assert::assertCount(1, $shipping, 'the first checkout must save exactly one shipping address to the profile');
        Assert::assertSame('20 Checkout Way', (string) $billing->first()->address_1, 'the saved billing address must be the submitted street');
        Assert::assertSame('1', (string) $billing->first()->is_primary, 'the only billing address must be the primary one');

        $secondCart = $this->buildInstantCart($I, [[$variation, 2]]);
        [, $secondBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($secondCart->cart_hash, [
            'billing_email'     => 'addresses@example.test',
            'billing_address_1' => '77 Second Visit Rd',
        ]));
        Assert::assertSame('success', (string) ($secondBody['status'] ?? ''), 'the return checkout must succeed. Body: ' . wp_json_encode($secondBody));

        Assert::assertSame(1, Customer::query()->count(), 'the return checkout must reuse the customer');
        Assert::assertSame(2, CustomerAddresses::query()->where('customer_id', $customer->id)->count(), 'a returning customer with saved addresses must not get duplicate profile addresses');
        Assert::assertSame(2, Order::query()->count(), 'both checkouts must produce an order');
        Assert::assertSame('77 Second Visit Rd', (string) OrderAddress::query()->where('order_id', Order::query()->orderBy('id', 'desc')->first()->id)->where('type', 'billing')->value('address_1'), 'the second order must still store the address it was placed with');
    }

    /**
     * Spec: Cases/checkout-functional.md [52]
     *   "Checkout writes the submitted first and last name onto the customer record, and for a
     *    signed-in user onto the WordPress profile as well."
     */
    public function checkoutSyncsTheSubmittedNameToTheCustomerAndSignedInProfile(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId, ['first_name' => 'Old', 'last_name' => 'Name']);
        update_user_meta($userId, 'first_name', 'Old');
        update_user_meta($userId, 'last_name', 'Name');
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1500])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_full_name'  => 'Grace Hopper',
            'billing_first_name' => 'Grace',
            'billing_last_name'  => 'Hopper',
        ]));

        Assert::assertSame(200, $status, 'place-order must answer HTTP 200. Body: ' . wp_json_encode($body));
        $stored = Customer::query()->find($customer->id);
        Assert::assertSame('Grace', (string) $stored->first_name, 'the customer first name must follow the billing form');
        Assert::assertSame('Hopper', (string) $stored->last_name, 'the customer last name must follow the billing form');
        Assert::assertSame('Grace', (string) get_user_meta($userId, 'first_name', true), 'the signed-in WordPress profile first name must follow the billing form');
        Assert::assertSame('Hopper', (string) get_user_meta($userId, 'last_name', true), 'the signed-in WordPress profile last name must follow the billing form');
    }

    /**
     * Spec: Cases/checkout-functional.md [53]
     *   "With account creation left at the store default, a guest order is flagged to create an
     *    account after payment."
     */
    public function guestOrderIsFlaggedForAccountCreationUnderTheStoreDefault(FunctionalTester $I): void
    {
        Assert::assertSame('all', (string) (new StoreSettings())->get('user_account_creation_mode'), 'precondition: the store default must be to create accounts for everyone');
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1300])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, ['billing_email' => 'brand-new@example.test']));

        Assert::assertSame(200, $status, 'place-order must answer HTTP 200. Body: ' . wp_json_encode($body));
        global $wpdb;
        $config = json_decode((string) $wpdb->get_var("SELECT config FROM {$wpdb->prefix}fct_orders ORDER BY id DESC LIMIT 1"), true);
        Assert::assertSame('yes', (string) ($config['create_account_after_paid'] ?? ''), 'under the default mode a guest order must be flagged to create an account once paid');
        Assert::assertSame(0, (int) Customer::query()->first()->user_id, 'the account itself must not exist before payment');
    }

    /**
     * Spec: Cases/checkout-functional.md [54]
     *   "With account creation set to the customer's choice, a guest order is flagged only when the
     *    guest asks for an account."
     */
    public function underCustomerChoiceAGuestOrderIsFlaggedOnlyWhenAsked(FunctionalTester $I): void
    {
        $storeSettings = get_option('fluent_cart_store_settings', []);
        update_option('fluent_cart_store_settings', array_merge(is_array($storeSettings) ? $storeSettings : [], ['user_account_creation_mode' => 'user_choice']));
        StoreSettings::clearCache();
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1100])->create()->variants->first();
        global $wpdb;

        $askingCart = $this->buildInstantCart($I, [[$variation, 1]]);
        [, $askingBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($askingCart->cart_hash, [
            'billing_email'        => 'wants-account@example.test',
            'allow_create_account' => 'yes',
        ]));
        Assert::assertSame('success', (string) ($askingBody['status'] ?? ''), 'the opted-in checkout must succeed. Body: ' . wp_json_encode($askingBody));
        $askingConfig = json_decode((string) $wpdb->get_var("SELECT config FROM {$wpdb->prefix}fct_orders ORDER BY id DESC LIMIT 1"), true);
        Assert::assertSame('yes', (string) ($askingConfig['create_account_after_paid'] ?? ''), 'a guest who asks for an account must have the order flagged');

        $silentCart = $this->buildInstantCart($I, [[$variation, 1]]);
        [, $silentBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($silentCart->cart_hash, [
            'billing_email' => 'no-account@example.test',
        ]));
        Assert::assertSame('success', (string) ($silentBody['status'] ?? ''), 'the silent checkout must succeed. Body: ' . wp_json_encode($silentBody));
        $silentConfig = json_decode((string) $wpdb->get_var("SELECT config FROM {$wpdb->prefix}fct_orders ORDER BY id DESC LIMIT 1"), true);
        Assert::assertSame('no', (string) ($silentConfig['create_account_after_paid'] ?? ''), 'a guest who does not ask must not have the order flagged');
    }

    /**
     * Spec: Cases/checkout-functional.md [55]
     *   "When the shopper ships to a different address, the order stores that shipping address
     *    separately from billing; otherwise the shipping address mirrors the billing one."
     */
    public function shippingToADifferentAddressIsStoredSeparatelyOtherwiseItMirrorsBilling(FunctionalTester $I): void
    {
        $variation = $this->buildPhysicalVariation(2500);
        $method = $this->buildShippingMethod(['amount' => 5]);

        $differentCart = $this->buildInstantCart($I, [[$variation, 1]]);
        [, $differentBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($differentCart->cart_hash, $this->shippingChoice((int) $method->id) + [
            'billing_email'      => 'ship-elsewhere@example.test',
            'ship_to_different'  => 'yes',
            'shipping_full_name' => 'Dock Receiver',
            'shipping_address_1' => '9 Dock Street',
            'shipping_city'      => 'Boston',
            'shipping_state'     => 'MA',
            'shipping_postcode'  => '02108',
            'shipping_country'   => 'US',
        ]));
        Assert::assertSame('success', (string) ($differentBody['status'] ?? ''), 'the ship-to-different checkout must succeed. Body: ' . wp_json_encode($differentBody));
        $differentOrder = Order::query()->orderBy('id', 'desc')->first();
        $shipping = OrderAddress::query()->where('order_id', $differentOrder->id)->where('type', 'shipping')->first();
        $billing = OrderAddress::query()->where('order_id', $differentOrder->id)->where('type', 'billing')->first();
        Assert::assertNotNull($shipping, 'the order must store a shipping address row');
        Assert::assertSame('9 Dock Street', (string) $shipping->address_1, 'the shipping row must keep the separate street');
        Assert::assertSame('Boston', (string) $shipping->city, 'the shipping row must keep the separate city');
        Assert::assertSame('MA', (string) $shipping->state, 'the shipping row must keep the separate state');
        Assert::assertSame('02108', (string) $shipping->postcode, 'the shipping row must keep the separate postcode');
        Assert::assertSame('Dock Receiver', (string) $shipping->name, 'the shipping row must keep the receiver name');
        Assert::assertSame('20 Checkout Way', (string) $billing->address_1, 'the billing row must keep the billing street');

        $sameCart = $this->buildInstantCart($I, [[$variation, 1]]);
        [, $sameBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($sameCart->cart_hash, $this->shippingChoice((int) $method->id) + [
            'billing_email'     => 'ship-home@example.test',
            'ship_to_different' => 'no',
        ]));
        Assert::assertSame('success', (string) ($sameBody['status'] ?? ''), 'the ship-to-billing checkout must succeed. Body: ' . wp_json_encode($sameBody));
        $sameOrder = Order::query()->orderBy('id', 'desc')->first();
        $mirrored = OrderAddress::query()->where('order_id', $sameOrder->id)->where('type', 'shipping')->first();
        Assert::assertNotNull($mirrored, 'the order must still store a shipping address row');
        foreach (['address_1', 'city', 'state', 'postcode', 'country'] as $column) {
            Assert::assertSame((string) $this->checkoutPayload('')['billing_' . $column], (string) $mirrored->{$column}, "the mirrored shipping {$column} must equal the billing {$column}");
        }
    }

    /**
     * Spec: Cases/checkout-functional.md [65]
     *   "A returning guest keeps the saved customer name; the new billing name belongs to the order."
     */
    public function returningGuestKeepsTheSavedProfileAndUsesTheBillingNameOnTheOrder(FunctionalTester $I): void
    {
        $existing = (new CustomerFactory())->create(['email' => 'renamed-guest@example.test', 'first_name' => 'Old', 'last_name' => 'Name', 'user_id' => 0]);
        Assert::assertFalse(get_user_by('email', 'renamed-guest@example.test'), 'precondition: no WordPress account may exist for the returning guest');
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1600])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_email'      => 'renamed-guest@example.test',
            'billing_full_name'  => 'Grace Hopper',
            'billing_first_name' => 'Grace',
            'billing_last_name'  => 'Hopper',
        ]));

        Assert::assertSame(200, $status, 'place-order must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must report success');
        Assert::assertSame(1, Customer::query()->count(), 'the returning guest must be reused, not duplicated');
        Assert::assertSame((int) $existing->id, (int) Order::query()->first()->customer_id, 'the order must belong to the returning guest');
        $stored = Customer::query()->find($existing->id);
        Assert::assertSame('Old', (string) $stored->first_name, 'An unverified checkout cannot overwrite the saved first name');
        Assert::assertSame('Name', (string) $stored->last_name, 'An unverified checkout cannot overwrite the saved last name');
        $billing = OrderAddress::query()->where('order_id', Order::query()->first()->id)->where('type', 'billing')->first();
        Assert::assertSame('Grace', $billing->first_name, 'The new order keeps the submitted first name');
        Assert::assertSame('Hopper', $billing->last_name, 'The new order keeps the submitted last name');
        Assert::assertSame(0, (int) $stored->user_id, 'renaming must not invent a user link');
    }
}
