<?php

namespace Tests\Functional\Permissions;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\StripsCustomerPermissions;

/**
 * Spec: Cases/Functional/customers.md → "Permissions" section, items 60-121.
 *
 * Customer routes (under /customers, CustomerPolicy) and their meta permissions:
 *   GET    /customers                                   customers/view
 *   POST   /customers                                   customers/manage
 *   GET    /customers/get-stats/{customer}              customers/view
 *   GET    /customers/attachable-user                   customers/manage
 *   POST   /customers/{id}/attachable-user              customers/manage
 *   POST   /customers/{id}/detach-user                  customers/manage
 *   GET    /customers/{id}                              customers/view
 *   GET    /customers/{id}/order                        customers/view
 *   PUT    /customers/{id}                              customers/manage
 *   PUT    /customers/{id}/additional-info              customers/manage
 *   POST   /customers/do-bulk-action                    customers/manage
 *   GET    /customers/{id}/orders                       customers/view
 *   GET    /customers/{id}/address                      customers/view
 *   PUT    /customers/{id}/address                      customers/manage
 *   POST   /customers/{id}/address                      customers/manage
 *   DELETE /customers/{id}/address                      customers/delete
 *   POST   /customers/{id}/address/make-primary         customers/manage
 *   POST   /customers/{id}/recalculate-ltv              customers/manage
 *
 * PermissionManager role grants:
 *   super_admin  — all permissions
 *   manager      — customers/view, customers/manage, customers/delete
 *   worker       — customers/view (only)
 *   accountant   — customers/view (only)
 *   customer     — none (subscriber WP role, no FC admin role)
 *   guest        — none (logged-out)
 */
class CustomersPermissionsCest
{
    use StripsCustomerPermissions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    // -----------------------------------------------------------------------
    // Items 60-65: Worker WITHOUT customers/view cannot perform view actions
    // -----------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/customers.md [60]
     *   "A worker without the \"view customers\" permission cannot list customers."
     */
    public function workerWithoutViewCustomersCannotListCustomers(FunctionalTester $I): void
    {
        $remove = $this->stripPermissionFromRole('worker', 'customers/view');
        try {
            $I->loginAsWorker();
            [, $body] = $I->get('/fluent-cart/v2/customers');
            $I->seeResponseCodeIs(403);
            Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without view perm must surface rest_forbidden code on list');
        } finally {
            $remove();
        }
    }

    /**
     * Spec: Cases/Functional/customers.md [61]
     *   "A worker without the \"view customers\" permission cannot open a single customer."
     */
    public function workerWithoutViewCustomersCannotOpenSingleCustomer(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-view-single-' . uniqid('', true) . '@example.test']);
        $remove = $this->stripPermissionFromRole('worker', 'customers/view');
        try {
            $I->loginAsWorker();
            [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id);
            $I->seeResponseCodeIs(403);
            Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without view perm must surface rest_forbidden code on single open');
        } finally {
            $remove();
        }
    }

    /**
     * Spec: Cases/Functional/customers.md [62]
     *   "A worker without the \"view customers\" permission cannot view a customer's orders."
     */
    public function workerWithoutViewCustomersCannotViewCustomerOrders(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-view-orders-' . uniqid('', true) . '@example.test']);
        $remove = $this->stripPermissionFromRole('worker', 'customers/view');
        try {
            $I->loginAsWorker();
            [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/orders');
            $I->seeResponseCodeIs(403);
            Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without view perm must surface rest_forbidden code on customer orders');
        } finally {
            $remove();
        }
    }

    /**
     * Spec: Cases/Functional/customers.md [63]
     *   "A worker without the \"view customers\" permission cannot list a customer's addresses."
     */
    public function workerWithoutViewCustomersCannotListCustomerAddresses(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-view-addr-' . uniqid('', true) . '@example.test']);
        $remove = $this->stripPermissionFromRole('worker', 'customers/view');
        try {
            $I->loginAsWorker();
            [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/address');
            $I->seeResponseCodeIs(403);
            Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without view perm must surface rest_forbidden code on address list');
        } finally {
            $remove();
        }
    }

    /**
     * Spec: Cases/Functional/customers.md [64]
     *   "A worker without the \"view customers\" permission cannot view a customer's lifetime statistics."
     */
    public function workerWithoutViewCustomersCannotViewCustomerLifetimeStats(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-view-stats-' . uniqid('', true) . '@example.test']);
        $remove = $this->stripPermissionFromRole('worker', 'customers/view');
        try {
            $I->loginAsWorker();
            [, $body] = $I->get('/fluent-cart/v2/customers/get-stats/' . (int) $customer->id);
            $I->seeResponseCodeIs(403);
            Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without view perm must surface rest_forbidden code on stats');
        } finally {
            $remove();
        }
    }

    /**
     * Spec: Cases/Functional/customers.md [65]
     *   "A worker without the \"view customers\" permission cannot fetch a customer's full order history with line items."
     */
    public function workerWithoutViewCustomersCannotFetchFullOrderHistory(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-view-history-' . uniqid('', true) . '@example.test']);
        $remove = $this->stripPermissionFromRole('worker', 'customers/view');
        try {
            $I->loginAsWorker();
            [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/order');
            $I->seeResponseCodeIs(403);
            Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without view perm must surface rest_forbidden code on full history');
        } finally {
            $remove();
        }
    }

    // -----------------------------------------------------------------------
    // Items 66-77: Worker WITHOUT manage/delete cannot perform manage actions
    // -----------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/customers.md [66]
     *   "A worker without the \"manage customers\" permission cannot create a customer."
     */
    public function workerWithoutManageCustomersCannotCreateCustomer(FunctionalTester $I): void
    {
        // Worker does not have customers/manage by default — no strip needed.
        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/customers', [
            'full_name' => 'Worker Tries',
            'email'     => 'worker-create-' . uniqid('', true) . '@example.test',
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without manage perm must surface rest_forbidden code on create');
    }

    /**
     * Spec: Cases/Functional/customers.md [67]
     *   "A worker without the \"manage customers\" permission cannot update a customer."
     */
    public function workerWithoutManageCustomersCannotUpdateCustomer(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-update-' . uniqid('', true) . '@example.test']);
        $I->loginAsWorker();
        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'id'        => (int) $customer->id,
            'full_name' => 'Renamed By Worker',
            'email'     => $customer->email,
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without manage perm must surface rest_forbidden code on update');
    }

    /**
     * Spec: Cases/Functional/customers.md [68]
     *   "A worker without the \"manage customers\" permission cannot update a customer's labels."
     */
    public function workerWithoutManageCustomersCannotUpdateCustomerLabels(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-labels-' . uniqid('', true) . '@example.test']);
        $I->loginAsWorker();
        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id . '/additional-info', [
            'labels' => [1, 2],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without manage perm must surface rest_forbidden code on label update');
    }

    /**
     * Spec: Cases/Functional/customers.md [69]
     *   "A worker without the \"manage customers\" permission cannot run customer bulk actions."
     */
    public function workerWithoutManageCustomersCannotRunBulkActions(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-bulk-' . uniqid('', true) . '@example.test']);
        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/customers/do-bulk-action', [
            'action'        => 'delete_customers',
            'customer_ids'  => [(int) $customer->id],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without manage perm must surface rest_forbidden code on bulk action');
    }

    /**
     * Spec: Cases/Functional/customers.md [70]
     *   "A worker without the \"manage customers\" permission cannot list attachable WordPress users."
     */
    public function workerWithoutManageCustomersCannotListAttachableUsers(FunctionalTester $I): void
    {
        $I->loginAsWorker();
        [, $body] = $I->get('/fluent-cart/v2/customers/attachable-user');
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without manage perm must surface rest_forbidden code on attachable list');
    }

    /**
     * Spec: Cases/Functional/customers.md [71]
     *   "A worker without the \"manage customers\" permission cannot attach a WordPress user to a customer."
     */
    public function workerWithoutManageCustomersCannotAttachWpUser(FunctionalTester $I): void
    {
        $userId   = WPUserFactory::create();
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-attach-' . uniqid('', true) . '@example.test']);
        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/attachable-user', [
            'user_id' => (string) $userId,
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without manage perm must surface rest_forbidden code on attach');
    }

    /**
     * Spec: Cases/Functional/customers.md [72]
     *   "A worker without the \"manage customers\" permission cannot detach the WordPress user from a customer."
     */
    public function workerWithoutManageCustomersCannotDetachWpUser(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-detach-' . uniqid('', true) . '@example.test']);
        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/detach-user');
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without manage perm must surface rest_forbidden code on detach');
    }

    /**
     * Spec: Cases/Functional/customers.md [73]
     *   "A worker without the \"manage customers\" permission cannot create a customer address."
     */
    public function workerWithoutManageCustomersCannotCreateAddress(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-cre-addr-' . uniqid('', true) . '@example.test']);
        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type'      => 'billing',
            'name'      => 'Worker Tries',
            'email'     => 'addr@example.test',
            'address_1' => '1 Try',
            'city'      => 'X',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without manage perm must surface rest_forbidden code on address create');
    }

    /**
     * Spec: Cases/Functional/customers.md [74]
     *   "A worker without the \"manage customers\" permission cannot update a customer address."
     */
    public function workerWithoutManageCustomersCannotUpdateAddress(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-upd-addr-' . uniqid('', true) . '@example.test']);
        $address  = (new CustomerAddressFactory())->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1]);
        $I->loginAsWorker();
        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'id'        => (int) $address->id,
            'type'      => 'billing',
            'name'      => 'Worker Tries',
            'email'     => 'upd-addr@example.test',
            'address_1' => '1 Try',
            'city'      => 'X',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without manage perm must surface rest_forbidden code on address update');
    }

    /**
     * Spec: Cases/Functional/customers.md [75]
     *   "A worker without the \"manage customers\" permission cannot promote a customer address to primary."
     */
    public function workerWithoutManageCustomersCannotPromoteAddressToPrimary(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-promote-' . uniqid('', true) . '@example.test']);
        $address  = (new CustomerAddressFactory())->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 0]);
        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address/make-primary', [
            'addressId' => (int) $address->id,
            'type'      => 'billing',
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without manage perm must surface rest_forbidden code on make-primary');
    }

    /**
     * Spec: Cases/Functional/customers.md [76]
     *   "A worker without the \"manage customers\" permission cannot recalculate a customer's lifetime value."
     */
    public function workerWithoutManageCustomersCannotRecalculateLtv(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-ltv-' . uniqid('', true) . '@example.test']);
        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/recalculate-ltv');
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without manage perm must surface rest_forbidden code on recalc-ltv');
    }

    /**
     * Spec: Cases/Functional/customers.md [77]
     *   "A worker without the \"delete customers\" permission cannot delete a customer address."
     */
    public function workerWithoutDeleteCustomersCannotDeleteAddress(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-w-del-addr-' . uniqid('', true) . '@example.test']);
        $factory  = new CustomerAddressFactory();
        $primary    = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1]);
        $secondary  = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 0]);
        $I->loginAsWorker();
        [, $body] = $I->delete('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'address' => ['id' => (int) $secondary->id],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without delete perm must surface rest_forbidden code on address delete');
    }

    // -----------------------------------------------------------------------
    // Items 78-83: Worker CAN perform view actions (default worker has view)
    // -----------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/customers.md [78]
     *   "Worker can list customers."
     */
    public function workerCanListCustomers(FunctionalTester $I): void
    {
        $seeded = (new CustomerFactory())->create(['email' => 'worker-list-seed-' . uniqid('', true) . '@example.test']);
        $I->loginAsWorker();
        [, $body] = $I->get('/fluent-cart/v2/customers');
        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['customers']['data'] ?? []), 'id'));
        Assert::assertContains((int) $seeded->id, $ids, 'worker list response must include the seeded customer (proves real data, not stub)');
    }

    /**
     * Spec: Cases/Functional/customers.md [79]
     *   "Worker can open a single customer."
     */
    public function workerCanOpenSingleCustomer(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'worker-view-single-' . uniqid('', true) . '@example.test']);
        $I->loginAsWorker();
        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id);
        $I->seeResponseCodeIs(200);
        Assert::assertSame((int) $customer->id, (int) ($body['customer']['id'] ?? 0), 'worker view must return the requested customer payload');
    }

    /**
     * Spec: Cases/Functional/customers.md [80]
     *   "Worker can view a customer's lifetime statistics."
     */
    public function workerCanViewCustomerLifetimeStatistics(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'worker-stats-' . uniqid('', true) . '@example.test']);
        $I->loginAsWorker();
        [, $body] = $I->get('/fluent-cart/v2/customers/get-stats/' . (int) $customer->id);
        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('widgets', $body, 'worker stats response must include the widgets payload');
    }

    /**
     * Spec: Cases/Functional/customers.md [81]
     *   "Worker can view a customer's orders."
     */
    public function workerCanViewCustomerOrders(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'worker-orders-' . uniqid('', true) . '@example.test']);
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'WORKER-1']);
        $I->loginAsWorker();
        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/orders');
        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['orders']['data'] ?? []), 'id'));
        Assert::assertContains((int) $order->id, $ids, 'worker per-customer orders must include the seeded order');
    }

    /**
     * Spec: Cases/Functional/customers.md [82]
     *   "Worker can fetch a customer's full order history with line items."
     */
    public function workerCanFetchFullOrderHistoryWithLineItems(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'worker-history-' . uniqid('', true) . '@example.test']);
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'WORKER-HIST']);
        $I->loginAsWorker();
        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/order');
        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['data']['data'] ?? []), 'id'));
        Assert::assertContains((int) $order->id, $ids, 'worker full history must include the seeded order');
    }

    /**
     * Spec: Cases/Functional/customers.md [83]
     *   "Worker can list a customer's addresses."
     */
    public function workerCanListCustomerAddresses(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'worker-addr-' . uniqid('', true) . '@example.test']);
        $address  = (new CustomerAddressFactory())->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1]);
        $I->loginAsWorker();
        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type' => 'billing',
        ]);
        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['addresses'] ?? []), 'id'));
        Assert::assertContains((int) $address->id, $ids, 'worker address list must include the seeded address');
    }

    // -----------------------------------------------------------------------
    // Items 84-89: Accountant CAN perform view actions
    // -----------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/customers.md [84]
     *   "Accountant can list customers."
     */
    public function accountantCanListCustomers(FunctionalTester $I): void
    {
        $seeded = (new CustomerFactory())->create(['email' => 'acc-list-seed-' . uniqid('', true) . '@example.test']);
        $I->loginAsAccountant();
        [, $body] = $I->get('/fluent-cart/v2/customers');
        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['customers']['data'] ?? []), 'id'));
        Assert::assertContains((int) $seeded->id, $ids, 'accountant list response must include the seeded customer');
    }

    /**
     * Spec: Cases/Functional/customers.md [85]
     *   "Accountant can open a single customer."
     */
    public function accountantCanOpenSingleCustomer(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-view-single-' . uniqid('', true) . '@example.test']);
        $I->loginAsAccountant();
        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id);
        $I->seeResponseCodeIs(200);
        Assert::assertSame((int) $customer->id, (int) ($body['customer']['id'] ?? 0), 'accountant view must return the requested customer payload');
    }

    /**
     * Spec: Cases/Functional/customers.md [86]
     *   "Accountant can view a customer's lifetime statistics."
     */
    public function accountantCanViewCustomerLifetimeStatistics(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-stats-' . uniqid('', true) . '@example.test']);
        $I->loginAsAccountant();
        [, $body] = $I->get('/fluent-cart/v2/customers/get-stats/' . (int) $customer->id);
        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('widgets', $body, 'accountant stats response must include widgets payload');
    }

    /**
     * Spec: Cases/Functional/customers.md [87]
     *   "Accountant can view a customer's orders."
     */
    public function accountantCanViewCustomerOrders(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-orders-' . uniqid('', true) . '@example.test']);
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'ACC-1']);
        $I->loginAsAccountant();
        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/orders');
        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['orders']['data'] ?? []), 'id'));
        Assert::assertContains((int) $order->id, $ids, 'accountant per-customer orders must include the seeded order');
    }

    /**
     * Spec: Cases/Functional/customers.md [88]
     *   "Accountant can fetch a customer's full order history with line items."
     */
    public function accountantCanFetchFullOrderHistoryWithLineItems(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-history-' . uniqid('', true) . '@example.test']);
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'ACC-HIST']);
        $I->loginAsAccountant();
        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/order');
        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['data']['data'] ?? []), 'id'));
        Assert::assertContains((int) $order->id, $ids, 'accountant full history must include the seeded order');
    }

    /**
     * Spec: Cases/Functional/customers.md [89]
     *   "Accountant can list a customer's addresses."
     */
    public function accountantCanListCustomerAddresses(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-addr-' . uniqid('', true) . '@example.test']);
        $address  = (new CustomerAddressFactory())->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1]);
        $I->loginAsAccountant();
        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type' => 'billing',
        ]);
        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['addresses'] ?? []), 'id'));
        Assert::assertContains((int) $address->id, $ids, 'accountant address list must include the seeded address');
    }

    // -----------------------------------------------------------------------
    // Items 90-101: Accountant CANNOT perform manage/delete actions
    // -----------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/customers.md [90]
     *   "Accountant cannot create a customer."
     */
    public function accountantCannotCreateCustomer(FunctionalTester $I): void
    {
        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/customers', [
            'full_name' => 'Acc Tries',
            'email'     => 'acc-create-' . uniqid('', true) . '@example.test',
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant create must surface rest_forbidden');
    }

    /**
     * Spec: Cases/Functional/customers.md [91]
     *   "Accountant cannot update a customer."
     */
    public function accountantCannotUpdateCustomer(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-update-' . uniqid('', true) . '@example.test']);
        $I->loginAsAccountant();
        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'id'        => (int) $customer->id,
            'full_name' => 'Renamed',
            'email'     => $customer->email,
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant update must surface rest_forbidden');
    }

    /**
     * Spec: Cases/Functional/customers.md [92]
     *   "Accountant cannot update a customer's labels."
     */
    public function accountantCannotUpdateCustomerLabels(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-labels-' . uniqid('', true) . '@example.test']);
        $I->loginAsAccountant();
        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id . '/additional-info', [
            'labels' => [1, 2],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant label update must surface rest_forbidden');
    }

    /**
     * Spec: Cases/Functional/customers.md [93]
     *   "Accountant cannot run customer bulk actions."
     */
    public function accountantCannotRunCustomerBulkActions(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-bulk-' . uniqid('', true) . '@example.test']);
        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/customers/do-bulk-action', [
            'action'        => 'delete_customers',
            'customer_ids'  => [(int) $customer->id],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant bulk action must surface rest_forbidden');
    }

    /**
     * Spec: Cases/Functional/customers.md [94]
     *   "Accountant cannot list attachable WordPress users."
     */
    public function accountantCannotListAttachableWpUsers(FunctionalTester $I): void
    {
        $I->loginAsAccountant();
        [, $body] = $I->get('/fluent-cart/v2/customers/attachable-user');
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant attachable list must surface rest_forbidden');
    }

    /**
     * Spec: Cases/Functional/customers.md [95]
     *   "Accountant cannot attach a WordPress user to a customer."
     */
    public function accountantCannotAttachWpUserToCustomer(FunctionalTester $I): void
    {
        $userId   = WPUserFactory::create();
        $customer = (new CustomerFactory())->create(['email' => 'acc-attach-' . uniqid('', true) . '@example.test']);
        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/attachable-user', [
            'user_id' => (string) $userId,
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant attach must surface rest_forbidden');
    }

    /**
     * Spec: Cases/Functional/customers.md [96]
     *   "Accountant cannot detach the WordPress user from a customer."
     */
    public function accountantCannotDetachWpUserFromCustomer(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-detach-' . uniqid('', true) . '@example.test']);
        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/detach-user');
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant detach must surface rest_forbidden');
    }

    /**
     * Spec: Cases/Functional/customers.md [97]
     *   "Accountant cannot create a customer address."
     */
    public function accountantCannotCreateCustomerAddress(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-addr-cre-' . uniqid('', true) . '@example.test']);
        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type'      => 'billing',
            'name'      => 'Acc Tries',
            'email'     => 'acc-addr@example.test',
            'address_1' => '1 Try',
            'city'      => 'X',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant address create must surface rest_forbidden');
    }

    /**
     * Spec: Cases/Functional/customers.md [98]
     *   "Accountant cannot update a customer address."
     */
    public function accountantCannotUpdateCustomerAddress(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-addr-upd-' . uniqid('', true) . '@example.test']);
        $address  = (new CustomerAddressFactory())->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1]);
        $I->loginAsAccountant();
        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'id'        => (int) $address->id,
            'type'      => 'billing',
            'name'      => 'Acc Tries',
            'email'     => 'acc-addr-upd@example.test',
            'address_1' => '1 Try',
            'city'      => 'X',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant address update must surface rest_forbidden');
    }

    /**
     * Spec: Cases/Functional/customers.md [99]
     *   "Accountant cannot promote a customer address to primary."
     */
    public function accountantCannotPromoteAddressToPrimary(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-addr-prom-' . uniqid('', true) . '@example.test']);
        $address  = (new CustomerAddressFactory())->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 0]);
        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address/make-primary', [
            'addressId' => (int) $address->id,
            'type'      => 'billing',
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant promote must surface rest_forbidden');
    }

    /**
     * Spec: Cases/Functional/customers.md [100]
     *   "Accountant cannot recalculate a customer's lifetime value."
     */
    public function accountantCannotRecalculateLifetimeValue(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-ltv-' . uniqid('', true) . '@example.test']);
        $I->loginAsAccountant();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/recalculate-ltv');
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant recalc-ltv must surface rest_forbidden');
    }

    /**
     * Spec: Cases/Functional/customers.md [101]
     *   "Accountant cannot delete a customer address."
     */
    public function accountantCannotDeleteCustomerAddress(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'acc-addr-del-' . uniqid('', true) . '@example.test']);
        $factory  = new CustomerAddressFactory();
        $primary  = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1]);
        $other    = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 0]);
        $I->loginAsAccountant();
        [, $body] = $I->delete('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'address' => ['id' => (int) $other->id],
        ]);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant address delete must surface rest_forbidden');
    }

    // -----------------------------------------------------------------------
    // Items 102-119: Store manager CAN perform all customer actions
    // -----------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/customers.md [102]
     *   "Store manager can list customers."
     */
    public function storeManagerCanListCustomers(FunctionalTester $I): void
    {
        $seeded = (new CustomerFactory())->create(['email' => 'sm-list-seed-' . uniqid('', true) . '@example.test']);
        $I->loginAsManager();
        [, $body] = $I->get('/fluent-cart/v2/customers');
        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['customers']['data'] ?? []), 'id'));
        Assert::assertContains((int) $seeded->id, $ids, 'store manager list must include the seeded customer');
    }

    /**
     * Spec: Cases/Functional/customers.md [103]
     *   "Store manager can open a single customer."
     */
    public function storeManagerCanOpenSingleCustomer(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-view-' . uniqid('', true) . '@example.test']);
        $I->loginAsManager();
        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id);
        $I->seeResponseCodeIs(200);
        Assert::assertSame((int) $customer->id, (int) ($body['customer']['id'] ?? 0), 'store manager view must return the requested customer payload');
    }

    /**
     * Spec: Cases/Functional/customers.md [104]
     *   "Store manager can view a customer's lifetime statistics."
     */
    public function storeManagerCanViewCustomerLifetimeStatistics(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-stats-' . uniqid('', true) . '@example.test']);
        $I->loginAsManager();
        [, $body] = $I->get('/fluent-cart/v2/customers/get-stats/' . (int) $customer->id);
        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('widgets', $body, 'store manager stats response must include widgets payload');
    }

    /**
     * Spec: Cases/Functional/customers.md [105]
     *   "Store manager can list a customer's orders."
     */
    public function storeManagerCanListCustomerOrders(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-orders-' . uniqid('', true) . '@example.test']);
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'SM-1']);
        $I->loginAsManager();
        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/orders');
        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['orders']['data'] ?? []), 'id'));
        Assert::assertContains((int) $order->id, $ids, 'store manager per-customer orders must include the seeded order');
    }

    /**
     * Spec: Cases/Functional/customers.md [106]
     *   "Store manager can fetch a customer's full order history with line items."
     */
    public function storeManagerCanFetchFullOrderHistoryWithLineItems(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-history-' . uniqid('', true) . '@example.test']);
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id, 'invoice_no' => 'SM-HIST']);
        $I->loginAsManager();
        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/order');
        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['data']['data'] ?? []), 'id'));
        Assert::assertContains((int) $order->id, $ids, 'store manager full history must include the seeded order');
    }

    /**
     * Spec: Cases/Functional/customers.md [107]
     *   "Store manager can list a customer's addresses."
     */
    public function storeManagerCanListCustomerAddresses(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-addr-list-' . uniqid('', true) . '@example.test']);
        $address  = (new CustomerAddressFactory())->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1]);
        $I->loginAsManager();
        [, $body] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type' => 'billing',
        ]);
        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['addresses'] ?? []), 'id'));
        Assert::assertContains((int) $address->id, $ids, 'store manager address list must include the seeded address');
    }

    /**
     * Spec: Cases/Functional/customers.md [108]
     *   "Store manager can list WordPress users that can be attached to customers."
     */
    public function storeManagerCanListAttachableWpUsers(FunctionalTester $I): void
    {
        $unattached = WPUserFactory::create();
        $I->loginAsManager();
        [, $body] = $I->get('/fluent-cart/v2/customers/attachable-user');
        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column((array) ($body['users'] ?? []), 'ID'));
        Assert::assertContains($unattached, $ids, 'store manager attachable list must include unattached WP users');
    }

    /**
     * Spec: Cases/Functional/customers.md [109]
     *   "Store manager can create a customer."
     */
    public function storeManagerCanCreateCustomer(FunctionalTester $I): void
    {
        $email = 'sm-create-' . uniqid('', true) . '@example.test';
        $I->loginAsManager();
        [, $body] = $I->post('/fluent-cart/v2/customers', [
            'full_name' => 'Manager Created',
            'email'     => $email,
        ]);
        $I->seeResponseCodeIs(200);
        global $wpdb;
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers WHERE email = %s", $email));
        Assert::assertSame(1, $exists, 'store manager create must persist the customer row');
    }

    /**
     * Spec: Cases/Functional/customers.md [110]
     *   "Store manager can update a customer."
     */
    public function storeManagerCanUpdateCustomer(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-update-' . uniqid('', true) . '@example.test', 'first_name' => 'Old', 'last_name' => 'Name']);
        $I->loginAsManager();
        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id, [
            'id'        => (int) $customer->id,
            'full_name' => 'Manager Updated',
            'email'     => $customer->email,
        ]);
        $I->seeResponseCodeIs(200);
        global $wpdb;
        $first = $wpdb->get_var($wpdb->prepare("SELECT first_name FROM {$wpdb->prefix}fct_customers WHERE id = %d", $customer->id));
        Assert::assertSame('Manager', (string) $first, 'store manager update must persist the new first_name');
    }

    /**
     * Spec: Cases/Functional/customers.md [111]
     *   "Store manager can update a customer's labels."
     */
    public function storeManagerCanUpdateCustomerLabels(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-labels-' . uniqid('', true) . '@example.test']);
        $label = \FluentCart\App\Models\Label::query()->create(['value' => ['title' => 'PermLabel', 'color' => '#000']]);
        $I->loginAsManager();
        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id . '/additional-info', [
            'labels' => [(int) $label->id],
        ]);
        $I->seeResponseCodeIs(200);
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_label_relationships WHERE labelable_id = %d AND label_id = %d",
            $customer->id,
            $label->id
        ));
        Assert::assertSame(1, $count, 'store manager label update must persist the relationship row');
    }

    /**
     * Spec: Cases/Functional/customers.md [112]
     *   "Store manager can run customer bulk actions."
     */
    public function storeManagerCanRunCustomerBulkActions(FunctionalTester $I): void
    {
        $target = (new CustomerFactory())->create(['email' => 'sm-bulk-' . uniqid('', true) . '@example.test', 'status' => 'active']);
        $I->loginAsManager();
        [, $body] = $I->post('/fluent-cart/v2/customers/do-bulk-action', [
            'action'       => 'change_customer_status',
            'customer_ids' => [(int) $target->id],
            'new_status'   => 'inactive',
        ]);
        $I->seeResponseCodeIs(200);
        global $wpdb;
        $statusVal = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}fct_customers WHERE id = %d", $target->id));
        Assert::assertSame('inactive', (string) $statusVal, 'store manager bulk action must persist the status change');
    }

    /**
     * Spec: Cases/Functional/customers.md [113]
     *   "Store manager can attach a WordPress user to a customer."
     */
    public function storeManagerCanAttachWpUserToCustomer(FunctionalTester $I): void
    {
        $userId   = WPUserFactory::create();
        $customer = (new CustomerFactory())->create(['email' => 'sm-attach-' . uniqid('', true) . '@example.test', 'user_id' => null]);
        $I->loginAsManager();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/attachable-user', [
            'user_id' => (string) $userId,
        ]);
        $I->seeResponseCodeIs(200);
        global $wpdb;
        $linked = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}fct_customers WHERE id = %d", $customer->id));
        Assert::assertSame($userId, $linked, 'store manager attach must persist the linked user_id');
    }

    /**
     * Spec: Cases/Functional/customers.md [114]
     *   "Store manager can detach the WordPress user from a customer."
     */
    public function storeManagerCanDetachWpUserFromCustomer(FunctionalTester $I): void
    {
        $userId   = WPUserFactory::create();
        $customer = (new CustomerFactory())->create(['email' => 'sm-detach-' . uniqid('', true) . '@example.test', 'user_id' => $userId]);
        $I->loginAsManager();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/detach-user');
        $I->seeResponseCodeIs(200);
        global $wpdb;
        $linked = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}fct_customers WHERE id = %d", $customer->id));
        Assert::assertSame(0, (int) $linked, 'store manager detach must clear the linked user_id (persisted as 0 in this build)');
    }

    /**
     * Spec: Cases/Functional/customers.md [115]
     *   "Store manager can create a customer address."
     */
    public function storeManagerCanCreateCustomerAddress(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-addr-cre-' . uniqid('', true) . '@example.test']);
        $I->loginAsManager();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'type'      => 'billing',
            'name'      => 'Manager Created',
            'email'     => 'sm-addr-cre@example.test',
            'address_1' => '1 Manager St',
            'city'      => 'X',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
        ]);
        $I->seeResponseCodeIs(200);
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_customer_addresses WHERE customer_id = %d AND address_1 = %s",
            $customer->id,
            '1 Manager St'
        ));
        Assert::assertSame(1, $count, 'store manager address create must persist the row');
    }

    /**
     * Spec: Cases/Functional/customers.md [116]
     *   "Store manager can update a customer address."
     */
    public function storeManagerCanUpdateCustomerAddress(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-addr-upd-' . uniqid('', true) . '@example.test']);
        $address  = (new CustomerAddressFactory())->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1, 'address_1' => '1 OldMgr', 'city' => 'OldCity']);
        $I->loginAsManager();
        [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'id'        => (int) $address->id,
            'type'      => 'billing',
            'name'      => 'Manager Updated',
            'email'     => 'sm-addr-upd@example.test',
            'address_1' => '1 NewMgrStreet',
            'city'      => 'NewMgrCity',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
        ]);
        $I->seeResponseCodeIs(200);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT address_1, city FROM {$wpdb->prefix}fct_customer_addresses WHERE id = %d", $address->id), ARRAY_A);
        Assert::assertSame('1 NewMgrStreet', (string) $row['address_1'], 'store manager address update must persist the new street');
        Assert::assertSame('NewMgrCity', (string) $row['city'], 'store manager address update must persist the new city');
    }

    /**
     * Spec: Cases/Functional/customers.md [117]
     *   "Store manager can promote a customer address to primary."
     */
    public function storeManagerCanPromoteAddressToPrimary(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-addr-prom-' . uniqid('', true) . '@example.test']);
        $factory  = new CustomerAddressFactory();
        $primary  = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1]);
        $other    = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 0]);
        $I->loginAsManager();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/address/make-primary', [
            'addressId' => (int) $other->id,
            'type'      => 'billing',
        ]);
        $I->seeResponseCodeIs(200);
        global $wpdb;
        $newPrimaryFlag = (int) $wpdb->get_var($wpdb->prepare("SELECT is_primary FROM {$wpdb->prefix}fct_customer_addresses WHERE id = %d", $other->id));
        Assert::assertSame(1, $newPrimaryFlag, 'store manager promote must mark the target address primary');
    }

    /**
     * Spec: Cases/Functional/customers.md [118]
     *   "Store manager can recalculate a customer's lifetime value."
     */
    public function storeManagerCanRecalculateLifetimeValue(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-ltv-' . uniqid('', true) . '@example.test', 'ltv' => 0, 'purchase_count' => 0]);
        (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_status' => \FluentCart\App\Helpers\Status::PAYMENT_PAID,
            'total_paid'     => \FluentCart\App\Helpers\Helper::toCent(75),
            'total_refund'   => 0,
            'invoice_no'     => 'SM-LTV-1',
        ]);
        // Stale ltv proves the recalculation actually ran.
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_customers', ['ltv' => 1, 'purchase_count' => 99], ['id' => $customer->id]);

        $I->loginAsManager();
        [, $body] = $I->post('/fluent-cart/v2/customers/' . (int) $customer->id . '/recalculate-ltv');
        $I->seeResponseCodeIs(200);
        Assert::assertSame((int) $customer->id, (int) ($body['customer']['id'] ?? 0), 'store manager recalc-ltv must return the customer payload');

        $row = $wpdb->get_row($wpdb->prepare("SELECT ltv, purchase_count FROM {$wpdb->prefix}fct_customers WHERE id = %d", $customer->id), ARRAY_A);
        Assert::assertSame(
            \FluentCart\App\Helpers\Helper::toCent(75),
            (int) $row['ltv'],
            'store manager recalc-ltv must persist the recalculated ltv (sum of total_paid - total_refund across paid orders, in cents)'
        );
        Assert::assertSame(1, (int) $row['purchase_count'], 'store manager recalc-ltv must persist the recalculated purchase_count');
    }

    /**
     * Spec: Cases/Functional/customers.md [119]
     *   "Store manager can delete a customer address."
     */
    public function storeManagerCanDeleteCustomerAddress(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'sm-addr-del-' . uniqid('', true) . '@example.test']);
        $factory  = new CustomerAddressFactory();
        $primary  = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 1]);
        $other    = $factory->create((int) $customer->id, ['type' => 'billing', 'is_primary' => 0]);
        $I->loginAsManager();
        [, $body] = $I->delete('/fluent-cart/v2/customers/' . (int) $customer->id . '/address', [
            'address' => ['id' => (int) $other->id],
        ]);
        $I->seeResponseCodeIs(200);
        global $wpdb;
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fct_customer_addresses WHERE id = %d", $other->id));
        Assert::assertSame(0, $exists, 'store manager address delete must remove the row');
    }

    // -----------------------------------------------------------------------
    // Items 120-121: Logged-out visitor / customer cannot access admin endpoints
    // -----------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/customers.md [120]
     *   "A logged-out visitor is forbidden from accessing admin customer endpoints."
     */
    public function loggedOutVisitorIsForbiddenFromAccessingAdminCustomerEndpoints(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'guest-deny-' . uniqid('', true) . '@example.test']);
        $I->logout();

        [$listStatus, $listBody] = $I->get('/fluent-cart/v2/customers');
        Assert::assertSame(401, $listStatus, 'guest list-customers must be 401 unauthorized. Body: ' . wp_json_encode($listBody));
        Assert::assertSame('rest_forbidden', $listBody['code'] ?? '', 'guest list-customers denial must surface rest_forbidden code');

        [$viewStatus, $viewBody] = $I->get('/fluent-cart/v2/customers/' . (int) $customer->id);
        Assert::assertSame(401, $viewStatus, 'guest view-customer must be 401 unauthorized. Body: ' . wp_json_encode($viewBody));
        Assert::assertSame('rest_forbidden', $viewBody['code'] ?? '', 'guest view-customer denial must surface rest_forbidden code');
    }

    /**
     * Spec: Cases/Functional/customers.md [121]
     *   "A customer is forbidden from accessing admin customer endpoints."
     */
    public function customerSubjectIsForbiddenFromAccessingAdminCustomerEndpoints(FunctionalTester $I): void
    {
        $target = (new CustomerFactory())->create(['email' => 'cust-deny-' . uniqid('', true) . '@example.test']);
        $I->loginAs('customer');

        [, $listBody] = $I->get('/fluent-cart/v2/customers');
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $listBody['code'] ?? '', 'customer subject list-customers denial must surface rest_forbidden code');

        [, $viewBody] = $I->get('/fluent-cart/v2/customers/' . (int) $target->id);
        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $viewBody['code'] ?? '', 'customer subject view-customer denial must surface rest_forbidden code');
    }
}
