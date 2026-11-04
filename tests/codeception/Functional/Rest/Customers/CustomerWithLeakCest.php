<?php

namespace Tests\Functional\Rest\Customers;

use FluentCart\App\Http\Controllers\CustomerController;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\LabelFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;
use FluentCart\App\Models\Customer;

/**
 * Security regression: GET /customers/{id} eager-loads whatever the client puts in
 * `with`. `Customer::wpUser()` points at the WordPress `users` table, so a
 * `customers/view` actor could ask for the password hash (`user_pass`) and the
 * password-reset token (`user_activation_key`).
 *
 * The endpoint must now allowlist the relations it will eager-load, hard-exclude
 * `wpUser` at every nesting depth, and drop unknown entries silently instead of
 * turning them into a 500.
 */
class CustomerWithLeakCest
{
    /**
     * Column names that must never appear anywhere in a REST payload.
     */
    private const CREDENTIAL_COLUMNS = ['user_pass', 'user_activation_key'];

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * `with[]=wpUser` — the direct relation. Must not surface credential columns.
     */
    public function flatWpUserRelationNeverLeaksCredentialColumns(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->createWithUser(['email' => 'leak-flat@example.test']);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'with' => ['wpUser'],
        ]);

        $I->seeResponseCodeIs(200);
        $this->assertNoCredentialColumns($body, 'with[]=wpUser');
    }

    /**
     * `with[]=wpUser:user_pass` — the column-select suffix shape. A naive prefix
     * check on the relation name lets this through and the suffix explicitly
     * SELECTs the hash.
     */
    public function columnSelectSuffixNeverLeaksCredentialColumns(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->createWithUser(['email' => 'leak-suffix@example.test']);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'with' => ['wpUser:ID,user_pass,user_activation_key'],
        ]);

        $I->seeResponseCodeIs(200);
        $this->assertNoCredentialColumns($body, 'with[]=wpUser:ID,user_pass,user_activation_key');
    }

    /**
     * `with[]=orders.customer.wpUser` — the dotted-string shape. Matching only the
     * first segment (`orders`) would wave the whole chain through.
     */
    public function dottedNestedPathNeverLeaksCredentialColumns(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->createWithUser(['email' => 'leak-dotted@example.test']);
        (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'LEAK-DOTTED']);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'with' => ['orders.customer.wpUser'],
        ]);

        $I->seeResponseCodeIs(200);
        $this->assertNoCredentialColumns($body, 'with[]=orders.customer.wpUser');
    }

    /**
     * `with[orders][]=customer.wpUser` — the nested-array shape the ORM flattens
     * into `orders` + `orders.customer.wpUser`.
     */
    public function nestedArrayShapeNeverLeaksCredentialColumns(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->createWithUser(['email' => 'leak-nested@example.test']);
        (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'LEAK-NESTED']);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'with' => ['orders' => ['customer.wpUser']],
        ]);

        $I->seeResponseCodeIs(200);
        $this->assertNoCredentialColumns($body, 'with[orders][]=customer.wpUser');
    }

    /**
     * `with[customer][]=wpUser` — the exact shape named in the report. `customer`
     * is not a relation on Customer, so this must be dropped, not 500.
     */
    public function nestedArrayUnderUnknownParentIsDroppedSilently(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->createWithUser(['email' => 'leak-unknown-parent@example.test']);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'with' => ['customer' => ['wpUser']],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            (int) $customer->id,
            (int) ($body['customer']['id'] ?? 0),
            'an unknown relation must degrade to a normal customer payload, not an error'
        );
        $this->assertNoCredentialColumns($body, 'with[customer][]=wpUser');
    }

    /**
     * An unknown relation name must be dropped silently. Before the fix the ORM
     * raised RelationNotFoundException, so a stale admin build hard-failed with a
     * 500 instead of degrading.
     */
    public function unknownRelationDegradesInsteadOfErroring(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'leak-bogus@example.test']);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'with' => ['thisRelationDoesNotExist'],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            (int) $customer->id,
            (int) ($body['customer']['id'] ?? 0),
            'an unknown relation must be dropped silently and still return the customer'
        );
        Assert::assertArrayNotHasKey(
            'thisRelationDoesNotExist',
            (array) $body['customer'],
            'an unknown relation must not appear as a key in the payload'
        );
    }

    /**
     * The four relation names the customer detail screen used to send one by one.
     * SingleCustomer.vue now sends the `admin_customer_detail` screen key instead,
     * but all four remain public keys, so this list must keep eager-loading for
     * any consumer that still names them.
     */
    public function allowlistedRelationsStillLoadForTheDetailScreen(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'leak-allowed@example.test']);

        $addressFactory = new CustomerAddressFactory();
        $billing  = $addressFactory->create((int) $customer->id, ['type' => 'billing', 'address_1' => '1 Billing St']);
        $shipping = $addressFactory->create((int) $customer->id, ['type' => 'shipping', 'address_1' => '2 Shipping Ave']);

        $labelFactory = new LabelFactory();
        $label        = $labelFactory->create();
        $labelFactory->attachTo((int) $label->id, (int) $customer->id, Customer::class);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id, [
            // The four public keys, named individually.
            'with' => ['shipping_address', 'billing_address', 'labels', 'subscriptions'],
        ]);

        $I->seeResponseCodeIs(200);

        $payload = (array) $body['customer'];
        Assert::assertArrayHasKey('billing_address', $payload, 'billing_address must still eager-load');
        Assert::assertArrayHasKey('shipping_address', $payload, 'shipping_address must still eager-load');
        Assert::assertArrayHasKey('labels', $payload, 'labels must still eager-load');
        Assert::assertArrayHasKey('subscriptions', $payload, 'subscriptions must still eager-load');

        $billingIds  = array_map('intval', array_column((array) $payload['billing_address'], 'id'));
        $shippingIds = array_map('intval', array_column((array) $payload['shipping_address'], 'id'));
        Assert::assertContains((int) $billing->id, $billingIds, 'seeded billing address must surface');
        Assert::assertContains((int) $shipping->id, $shippingIds, 'seeded shipping address must surface');

        $labelIds = array_map('intval', array_column((array) $payload['labels'], 'label_id'));
        Assert::assertContains((int) $label->id, $labelIds, 'seeded label must surface');

        $this->assertNoCredentialColumns($body, 'allowlisted detail-screen relations');
    }

    /**
     * The screen key SingleCustomer.vue actually sends now. It must resolve to
     * the same four relations the screen used to name one by one — and to
     * nothing else, because a screen key that quietly widens is the failure mode
     * the whole two-tier map exists to prevent.
     */
    public function screenKeyLoadsExactlyWhatTheDetailScreenRenders(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'screen-key@example.test']);

        $addressFactory = new CustomerAddressFactory();
        $billing  = $addressFactory->create((int) $customer->id, ['type' => 'billing', 'address_1' => '1 Billing St']);
        $shipping = $addressFactory->create((int) $customer->id, ['type' => 'shipping', 'address_1' => '2 Shipping Ave']);

        $labelFactory = new LabelFactory();
        $label        = $labelFactory->create();
        $labelFactory->attachTo((int) $label->id, (int) $customer->id, Customer::class);

        // An order exists but is NOT part of this screen's payload: the same
        // screen pages its order table off GET customers/{id}/orders.
        (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'SCREEN-KEY-1']);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'with' => ['admin_customer_detail'],
        ]);

        $I->seeResponseCodeIs(200);

        $payload = (array) $body['customer'];

        foreach (['shipping_address', 'billing_address', 'labels', 'subscriptions'] as $relation) {
            Assert::assertArrayHasKey(
                $relation,
                $payload,
                sprintf('admin_customer_detail must eager-load `%s` — the detail screen renders it', $relation)
            );
        }

        Assert::assertArrayNotHasKey(
            'orders',
            $payload,
            'admin_customer_detail must not widen into `orders` — the screen pages those separately'
        );

        $billingIds  = array_map('intval', array_column((array) $payload['billing_address'], 'id'));
        $shippingIds = array_map('intval', array_column((array) $payload['shipping_address'], 'id'));
        Assert::assertContains((int) $billing->id, $billingIds, 'seeded billing address must surface under the screen key');
        Assert::assertContains((int) $shipping->id, $shippingIds, 'seeded shipping address must surface under the screen key');

        $labelIds = array_map('intval', array_column((array) $payload['labels'], 'label_id'));
        Assert::assertContains((int) $label->id, $labelIds, 'seeded label must surface under the screen key');

        Assert::assertContains(
            (int) $label->id,
            array_map('intval', (array) ($payload['selected_labels'] ?? [])),
            'selected_labels is derived from the loaded labels relation and must still be populated'
        );

        $this->assertNoCredentialColumns($body, 'with[]=admin_customer_detail');
    }

    /**
     * Every public key resolves on its own. These are first-class entry points
     * for a consumer that is not the detail screen, so each has to answer.
     */
    public function everyPublicKeyResolvesOnItsOwn(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'public-keys@example.test']);

        $addressFactory = new CustomerAddressFactory();
        $addressFactory->create((int) $customer->id, ['type' => 'billing', 'address_1' => '3 Billing St', 'is_primary' => 1]);
        $addressFactory->create((int) $customer->id, ['type' => 'shipping', 'address_1' => '4 Shipping Ave', 'is_primary' => 1]);

        $labelFactory = new LabelFactory();
        $label        = $labelFactory->create();
        $labelFactory->attachTo((int) $label->id, (int) $customer->id, Customer::class);

        (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'PUBLIC-KEY-1']);

        // `orders` is deliberately absent: it is an unbounded to-many and the
        // order history has its own paginated endpoint. See publicSubscriptions()
        // in CustomerController for why `subscriptions` is still here.
        $publicKeys = [
            'shipping_address',
            'billing_address',
            'primary_shipping_address',
            'primary_billing_address',
            'labels',
            'subscriptions',
        ];

        foreach ($publicKeys as $key) {
            [$status, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id, [
                'with' => [$key],
            ]);

            Assert::assertSame(200, $status, "with[]={$key} must answer");
            Assert::assertArrayHasKey(
                $key,
                (array) $body['customer'],
                "with[]={$key} is a public entry point and must eager-load its relation"
            );
            $this->assertNoCredentialColumns($body, "with[]={$key}");
        }
    }

    /**
     * Two public keys in one request both resolve, and neither clobbers the
     * other — each callback issues its own eager load and the caller merges.
     */
    public function twoPublicKeysInOneRequestBothResolve(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'public-keys-pair@example.test']);

        (new CustomerAddressFactory())->create((int) $customer->id, ['type' => 'billing', 'address_1' => '5 Billing St']);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'with' => ['billing_address', 'subscriptions'],
        ]);

        $I->seeResponseCodeIs(200);

        $payload = (array) $body['customer'];
        Assert::assertArrayHasKey('billing_address', $payload, 'the first key must survive the second');
        Assert::assertArrayHasKey('subscriptions', $payload, 'the second key must survive the first');
    }

    /**
     * Every gated entry consults PermissionManager, and refuses when the caller
     * holds nothing.
     *
     * Exercised through the map rather than over HTTP on purpose. In this suite
     * only the free plugin is loaded, so PermissionManager::getUserPermissions()
     * short-circuits on `!App::isProActive()` and returns [] for every
     * non-administrator — which means the ROUTE's own customers/view denies a
     * worker or accountant before find() ever runs, and there is no HTTP-visible
     * actor who holds customers/view but lacks labels/view. The anonymous caller
     * is the one state that reaches every callback and is refused by all of them;
     * it is also the real cron/WP-CLI case, where the same map runs with no
     * current user.
     */
    public function everyGatedEntryRefusesACallerHoldingNothing(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'gate-refusal@example.test']);
        (new CustomerAddressFactory())->create((int) $customer->id, ['type' => 'billing', 'address_1' => '6 Billing St']);

        $everyKey = [
            'admin_customer_detail',
            'shipping_address',
            'billing_address',
            'primary_shipping_address',
            'primary_billing_address',
            'labels',
            'subscriptions',
        ];

        $controller = new CustomerController();
        $resolve    = new \ReflectionMethod($controller, 'resolveEagerLoads');
        $resolve->setAccessible(true);

        // Baseline: with every permission, every key contributes something.
        foreach ($everyKey as $key) {
            Assert::assertNotEmpty(
                $resolve->invoke($controller, [$key]),
                "baseline: with[]={$key} must resolve to at least one relation for a permitted caller"
            );
        }

        $I->logout();

        foreach ($everyKey as $key) {
            Assert::assertSame(
                [],
                $resolve->invoke($controller, [$key]),
                "with[]={$key} must contribute nothing when the caller holds no permission — the gate lives in the callback, not in the route"
            );
        }
    }

    /**
     * `labels` is gated on labels/view, and the payload must not smuggle the
     * relation in some other way. Reading `$customer['labels']` in find() used to
     * lazy-load it and cache it onto the model, so the relation was serialized
     * for a caller who never asked for it.
     */
    public function labelsDoNotLoadWhenNobodyAskedForThem(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'labels-unrequested@example.test']);

        $labelFactory = new LabelFactory();
        $label        = $labelFactory->create();
        $labelFactory->attachTo((int) $label->id, (int) $customer->id, Customer::class);

        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'with' => ['billing_address'],
        ]);

        $I->seeResponseCodeIs(200);

        Assert::assertArrayNotHasKey(
            'labels',
            (array) $body['customer'],
            'the labels relation must not appear when it was never requested'
        );
    }

    private function assertNoCredentialColumns($body, string $context): void
    {
        $encoded = json_encode($body);

        foreach (self::CREDENTIAL_COLUMNS as $column) {
            Assert::assertStringNotContainsString(
                $column,
                (string) $encoded,
                sprintf('%s must never expose the WordPress `%s` column', $context, $column)
            );
        }
    }
}
