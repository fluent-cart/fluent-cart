<?php

namespace Tests\Functional\Permissions;

use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Spec: Cases/Functional/subscriptions.md → "Permissions" section, items 65-94.
 *
 * Subscription routes (under /subscriptions and /orders/{order}/subscriptions/{subscription},
 * OrderPolicy) and their meta permissions (see app/Modules/Subscriptions/Http/subscriptions-api.php):
 *   GET  /subscriptions                                              subscriptions/view
 *   GET  /subscriptions/{subscriptionOrderId}                        subscriptions/view
 *   PUT  /orders/{order}/subscriptions/{subscription}/cancel         subscriptions/manage
 *   PUT  /orders/{order}/subscriptions/{subscription}/fetch          subscriptions/manage
 *   POST /orders/{order}/subscriptions/{subscription}/early-payment-link  subscriptions/manage
 *   PUT  /orders/{order}/subscriptions/{subscription}/reactivate     subscriptions/manage
 *   PUT  /orders/{order}/subscriptions/{subscription}/pause          subscriptions/manage
 *   PUT  /orders/{order}/subscriptions/{subscription}/resume         subscriptions/manage
 *
 * PermissionManager role grants (see app/Services/Permission/PermissionManager.php):
 *   manager     — subscriptions/view, subscriptions/manage, subscriptions/delete
 *   worker      — subscriptions/view
 *   accountant  — subscriptions/view
 *
 * "with view permission" tests rely on the role's default grant (no strip).
 * "without manage permission" tests rely on the role not having it by default (no strip).
 *
 * Reactivate / pause / resume routes are policy-gated by subscriptions/manage but the
 * controller/service guard rejects the automatic-collection fixture with a 422
 * (store-billed-only reactivate; offline_payment lacks pause/resume capability);
 * authorized-but-rejected cases assert NOT 403/401 AND the guard's message.
 *
 * Cancel / fetch / early-payment-link controllers depend on remote-gateway state. The
 * test subscriptions use the offline_payment gateway which has no subscriptions support,
 * so these controllers reliably take a 422 sendError path. Permission cases assert
 * NOT 403/401 to prove the policy gate cleared; success body shape is covered by the
 * action-named Cests under Rest/Subscriptions/.
 */
class SubscriptionsPermissionsCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    // -------------------------------------------------------------------------
    // Items 65-68 — Listing subscriptions
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/subscriptions.md [65]
     *   "A store manager can list subscriptions."
     */
    public function storeManagerCanListSubscriptions(FunctionalTester $I): void
    {
        $subscription = $this->buildSubscription([
            'item_name' => 'Perm Mgr List 65',
        ], ['email' => 'perm-sub-mgr-list@example.test']);

        $I->loginAsManager();
        [$status, $body] = $I->get('/fluent-cart/v2/subscriptions');

        Assert::assertSame(200, $status, 'manager-with-subscriptions/view list must return 200. Body: ' . wp_json_encode($body));
        $rows = (array) ($body['data']['data'] ?? []);
        $ids  = array_map('intval', array_column($rows, 'id'));
        Assert::assertContains((int) $subscription->id, $ids, 'manager-with-subscriptions/view list must include the seeded subscription id');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [66]
     *   "A worker with the \"view subscriptions\" permission can list subscriptions."
     */
    public function workerWithViewSubscriptionsPermissionCanListSubscriptions(FunctionalTester $I): void
    {
        $subscription = $this->buildSubscription([
            'item_name' => 'Perm Worker List 66',
        ], ['email' => 'perm-sub-w-list@example.test']);

        $I->loginAsWorker();
        [$status, $body] = $I->get('/fluent-cart/v2/subscriptions');

        Assert::assertSame(200, $status, 'worker-with-subscriptions/view list must return 200. Body: ' . wp_json_encode($body));
        $rows = (array) ($body['data']['data'] ?? []);
        $ids  = array_map('intval', array_column($rows, 'id'));
        Assert::assertContains((int) $subscription->id, $ids, 'worker-with-subscriptions/view list must include the seeded subscription id');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [67]
     *   "An accountant with the \"view subscriptions\" permission can list subscriptions."
     */
    public function accountantWithViewSubscriptionsPermissionCanListSubscriptions(FunctionalTester $I): void
    {
        $subscription = $this->buildSubscription([
            'item_name' => 'Perm Acct List 67',
        ], ['email' => 'perm-sub-a-list@example.test']);

        $I->loginAsAccountant();
        [$status, $body] = $I->get('/fluent-cart/v2/subscriptions');

        Assert::assertSame(200, $status, 'accountant-with-subscriptions/view list must return 200. Body: ' . wp_json_encode($body));
        $rows = (array) ($body['data']['data'] ?? []);
        $ids  = array_map('intval', array_column($rows, 'id'));
        Assert::assertContains((int) $subscription->id, $ids, 'accountant-with-subscriptions/view list must include the seeded subscription id');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [68]
     *   "A logged-out visitor cannot access the admin subscriptions list."
     */
    public function guestCannotAccessAdminSubscriptionsList(FunctionalTester $I): void
    {
        $I->logout();
        [$status, $body] = $I->get('/fluent-cart/v2/subscriptions');

        Assert::assertSame(401, $status, 'guest list-subscriptions must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest list-subscriptions denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'guest list-subscriptions denial must include a permission-denied message');
    }

    // -------------------------------------------------------------------------
    // Items 69-72 — Viewing a subscription
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/subscriptions.md [69]
     *   "A store manager can open a subscription's detail view."
     */
    public function storeManagerCanOpenSubscriptionDetailView(FunctionalTester $I): void
    {
        $subscription = $this->buildSubscription([
            'item_name' => 'Perm Mgr View 69',
        ], ['email' => 'perm-sub-mgr-view@example.test']);

        $I->loginAsManager();
        [$status, $body] = $I->get('/fluent-cart/v2/subscriptions/' . (int) $subscription->id);

        Assert::assertSame(200, $status, 'manager-with-subscriptions/view detail must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            (int) $subscription->id,
            (int) ($body['subscription']['id'] ?? 0),
            'manager-with-subscriptions/view detail must echo the requested subscription id'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [70]
     *   "A worker with the \"view subscriptions\" permission can open a subscription's detail view."
     */
    public function workerWithViewSubscriptionsPermissionCanOpenSubscriptionDetailView(FunctionalTester $I): void
    {
        $subscription = $this->buildSubscription([
            'item_name' => 'Perm Worker View 70',
        ], ['email' => 'perm-sub-w-view@example.test']);

        $I->loginAsWorker();
        [$status, $body] = $I->get('/fluent-cart/v2/subscriptions/' . (int) $subscription->id);

        Assert::assertSame(200, $status, 'worker-with-subscriptions/view detail must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            (int) $subscription->id,
            (int) ($body['subscription']['id'] ?? 0),
            'worker-with-subscriptions/view detail must echo the requested subscription id'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [71]
     *   "An accountant with the \"view subscriptions\" permission can open a subscription's detail view."
     */
    public function accountantWithViewSubscriptionsPermissionCanOpenSubscriptionDetailView(FunctionalTester $I): void
    {
        $subscription = $this->buildSubscription([
            'item_name' => 'Perm Acct View 71',
        ], ['email' => 'perm-sub-a-view@example.test']);

        $I->loginAsAccountant();
        [$status, $body] = $I->get('/fluent-cart/v2/subscriptions/' . (int) $subscription->id);

        Assert::assertSame(200, $status, 'accountant-with-subscriptions/view detail must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            (int) $subscription->id,
            (int) ($body['subscription']['id'] ?? 0),
            'accountant-with-subscriptions/view detail must echo the requested subscription id'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [72]
     *   "A logged-out visitor cannot open a subscription's detail view through the admin route."
     */
    public function guestCannotOpenSubscriptionDetailViewThroughAdminRoute(FunctionalTester $I): void
    {
        $subscription = $this->buildSubscription([
            'item_name' => 'Perm Guest View 72',
        ], ['email' => 'perm-sub-guest-view@example.test']);

        $I->logout();
        [$status, $body] = $I->get('/fluent-cart/v2/subscriptions/' . (int) $subscription->id);

        Assert::assertSame(401, $status, 'guest view-subscription must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest view-subscription denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'guest view-subscription denial must include a permission-denied message');
    }

    // -------------------------------------------------------------------------
    // Items 73-76 — Re-syncing a subscription from the payment gateway
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/subscriptions.md [73]
     *   "A store manager can re-sync a subscription from the payment gateway."
     */
    public function storeManagerCanResyncSubscriptionFromPaymentGateway(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Mgr Resync 73',
        ], ['email' => 'perm-sub-mgr-resync@example.test']);

        $I->loginAsManager();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/fetch',
            []
        );

        // COD no longer carries a subscriptions module (manual_subscription only), so
        // the controller is reached (permission gate passes) but resync returns the
        // gateway-capability business error — not a REST permission denial.
        Assert::assertSame(422, $status, 'manager-with-subscriptions/view resync must pass the permission gate and return the gateway-capability error for offline_payment. Body: ' . wp_json_encode($body));
        Assert::assertNotSame('rest_forbidden', (string) ($body['code'] ?? ''), 'manager-with-subscriptions/view resync body must NOT carry the REST permission-denial code; controller must have been reached. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'does not support remote resync',
            (string) ($body['message'] ?? ''),
            'manager-with-subscriptions/view resync must surface the invalid_payment_method business error. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [74]
     *   Updated: the fetch route now requires subscriptions/manage — a worker with
     *   only "view subscriptions" is denied (resync writes local subscription state).
     */
    public function workerWithViewSubscriptionsPermissionIsDeniedResync(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Worker Resync 74',
        ], ['email' => 'perm-sub-w-resync@example.test']);

        $I->loginAsWorker();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/fetch',
            []
        );

        // The fetch route was tightened from subscriptions/view to subscriptions/manage
        // (resync writes local subscription state). A view-only worker is now denied.
        Assert::assertSame(403, $status, 'worker with only subscriptions/view must be denied on resync now that the route requires subscriptions/manage. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', (string) ($body['code'] ?? ''), 'worker resync denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [75]
     *   Updated: the fetch route now requires subscriptions/manage — an accountant with
     *   only "view subscriptions" is denied (resync writes local subscription state).
     */
    public function accountantWithViewSubscriptionsPermissionIsDeniedResync(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Acct Resync 75',
        ], ['email' => 'perm-sub-a-resync@example.test']);

        $I->loginAsAccountant();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/fetch',
            []
        );

        // The fetch route was tightened from subscriptions/view to subscriptions/manage
        // (resync writes local subscription state). A view-only accountant is now denied.
        Assert::assertSame(403, $status, 'accountant with only subscriptions/view must be denied on resync now that the route requires subscriptions/manage. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', (string) ($body['code'] ?? ''), 'accountant resync denial must surface rest_forbidden code. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [76]
     *   "A logged-out visitor cannot re-sync a subscription from the payment gateway through the admin route."
     */
    public function guestCannotResyncSubscriptionFromPaymentGatewayThroughAdminRoute(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Guest Resync 76',
        ], ['email' => 'perm-sub-guest-resync@example.test']);

        $I->logout();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/fetch',
            []
        );

        Assert::assertSame(401, $status, 'guest resync-subscription must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest resync-subscription denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'guest resync-subscription denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [78]
     *   "A worker without the \"manage subscriptions\" permission cannot cancel a subscription."
     */
    public function workerWithoutManageSubscriptionsPermissionCannotCancelSubscription(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Worker Cancel 78',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ], ['email' => 'perm-sub-w-cancel@example.test']);

        $I->loginAsWorker();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/cancel',
            ['cancel_reason' => 'Customer requested cancellation']
        );

        Assert::assertSame(403, $status, 'denial must return 403 forbidden. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-subscriptions/manage cancel denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-subscriptions/manage cancel denial must include a permission-denied message');

        global $wpdb;
        $persistedStatus = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
            (int) $subscription->id
        ));
        Assert::assertSame(
            Status::SUBSCRIPTION_ACTIVE,
            $persistedStatus,
            'worker-without-subscriptions/manage cancel denial must NOT mutate fct_subscriptions.status. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [79]
     *   "An accountant without the \"manage subscriptions\" permission cannot cancel a subscription."
     */
    public function accountantWithoutManageSubscriptionsPermissionCannotCancelSubscription(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Acct Cancel 79',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ], ['email' => 'perm-sub-a-cancel@example.test']);

        $I->loginAsAccountant();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/cancel',
            ['cancel_reason' => 'Customer requested cancellation']
        );

        Assert::assertSame(403, $status, 'denial must return 403 forbidden. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-subscriptions/manage cancel denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-subscriptions/manage cancel denial must include a permission-denied message');

        global $wpdb;
        $persistedStatus = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
            (int) $subscription->id
        ));
        Assert::assertSame(
            Status::SUBSCRIPTION_ACTIVE,
            $persistedStatus,
            'accountant-without-subscriptions/manage cancel denial must NOT mutate fct_subscriptions.status. Body: ' . wp_json_encode($body)
        );
    }

    // -------------------------------------------------------------------------
    // Items 80-82 — Generating an early-payment link
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/subscriptions.md [80]
     *   "A store manager can generate an early-payment link."
     */
    public function storeManagerCanGenerateEarlyPaymentLink(FunctionalTester $I): void
    {

        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name'  => 'Perm Mgr Early 80',
            'status'     => Status::SUBSCRIPTION_ACTIVE,
            'bill_times' => 12,
            'bill_count' => 2,
        ], ['email' => 'perm-sub-mgr-early@example.test']);

        $I->loginAsManager();
        [$status, $body] = $I->post(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/early-payment-link',
            []
        );

        Assert::assertSame(200, $status, 'manager-with-subscriptions/manage generate-early-link must succeed for an eligible installment subscription (active, bill_times=12, bill_count=2, parent_order_id matches URL order id). Body: ' . wp_json_encode($body));
        Assert::assertNotSame('rest_forbidden', (string) ($body['code'] ?? ''), 'manager-with-subscriptions/manage generate-early-link body must NOT carry the REST permission-denial code; controller must have been reached. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('payment_url', (array) $body, 'manager-with-subscriptions/manage generate-early-link success body must include the payment_url generated by SubscriptionController::generateEarlyPaymentLink (app/Modules/Subscriptions/Http/Controllers/SubscriptionController.php:196-204). Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'fluent-cart=early-installment-payment',
            (string) ($body['payment_url'] ?? ''),
            'manager-with-subscriptions/manage generate-early-link payment_url must carry the fluent-cart=early-installment-payment query parameter. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            (string) $subscription->uuid,
            (string) ($body['payment_url'] ?? ''),
            'manager-with-subscriptions/manage generate-early-link payment_url must embed the subscription_hash matching the subscription uuid. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [81]
     *   "A worker without the \"manage subscriptions\" permission cannot generate an early-payment link."
     */
    public function workerWithoutManageSubscriptionsPermissionCannotGenerateEarlyPaymentLink(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name'  => 'Perm Worker Early 81',
            'status'     => Status::SUBSCRIPTION_ACTIVE,
            'bill_times' => 12,
            'bill_count' => 2,
        ], ['email' => 'perm-sub-w-early@example.test']);

        $I->loginAsWorker();
        [$status, $body] = $I->post(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/early-payment-link',
            []
        );

        Assert::assertSame(403, $status, 'denial must return 403 forbidden. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-subscriptions/manage generate-early-link denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-subscriptions/manage generate-early-link denial must include a permission-denied message');
        Assert::assertArrayNotHasKey('payment_url', (array) $body, 'worker-without-subscriptions/manage generate-early-link denial must NOT leak a payment_url. Body: ' . wp_json_encode($body));
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [82]
     *   "An accountant without the \"manage subscriptions\" permission cannot generate an early-payment link."
     */
    public function accountantWithoutManageSubscriptionsPermissionCannotGenerateEarlyPaymentLink(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name'  => 'Perm Acct Early 82',
            'status'     => Status::SUBSCRIPTION_ACTIVE,
            'bill_times' => 12,
            'bill_count' => 2,
        ], ['email' => 'perm-sub-a-early@example.test']);

        $I->loginAsAccountant();
        [$status, $body] = $I->post(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/early-payment-link',
            []
        );

        Assert::assertSame(403, $status, 'denial must return 403 forbidden. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-subscriptions/manage generate-early-link denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-subscriptions/manage generate-early-link denial must include a permission-denied message');
        Assert::assertArrayNotHasKey('payment_url', (array) $body, 'accountant-without-subscriptions/manage generate-early-link denial must NOT leak a payment_url. Body: ' . wp_json_encode($body));
    }

    // -------------------------------------------------------------------------
    // Items 83-86 — Reactivating a subscription (not available yet)
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/subscriptions.md [83]
     *   "A store manager is authorized to attempt reactivating a subscription; the automatic-collection request is rejected by the store-billed-only guard."
     */
    public function storeManagerIsAuthorizedToAttemptReactivatingSubscriptionAndRejectedByStoreBilledGuard(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Mgr Reactivate 83',
            'status'    => Status::SUBSCRIPTION_CANCELED,
        ], ['email' => 'perm-sub-mgr-reactivate@example.test']);

        $I->loginAsManager();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/reactivate',
            []
        );

        Assert::assertNotSame(403, $status, 'manager-with-subscriptions/manage reactivate must NOT be rejected by policy gate. Body: ' . wp_json_encode($body));
        Assert::assertNotSame(401, $status, 'manager-with-subscriptions/manage reactivate must NOT be rejected as unauthenticated. Body: ' . wp_json_encode($body));
        Assert::assertSame(422, $status, 'reactivate must surface controller sendError 422 — the automatic fixture fails the store-billed-only guard. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'Only store-billed',
            (string) ($body['message'] ?? ''),
            'reactivate body must carry the store-billed-only guard message (SubscriptionController::reactivateSubscription). Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_CANCELED,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'rejected reactivate must NOT mutate fct_subscriptions.status — the canceled subscription must remain canceled because the guard returns sendError before touching state. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [84]
     *   "A worker without the \"manage subscriptions\" permission cannot attempt to reactivate a subscription."
     */
    public function workerWithoutManageSubscriptionsPermissionCannotAttemptReactivateSubscription(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Worker Reactivate 84',
            'status'    => Status::SUBSCRIPTION_CANCELED,
        ], ['email' => 'perm-sub-w-reactivate@example.test']);

        $I->loginAsWorker();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/reactivate',
            []
        );

        Assert::assertSame(403, $status, 'denial must return 403 forbidden. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-subscriptions/manage reactivate denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-subscriptions/manage reactivate denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [85]
     *   "An accountant without the \"manage subscriptions\" permission cannot attempt to reactivate a subscription."
     */
    public function accountantWithoutManageSubscriptionsPermissionCannotAttemptReactivateSubscription(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Acct Reactivate 85',
            'status'    => Status::SUBSCRIPTION_CANCELED,
        ], ['email' => 'perm-sub-a-reactivate@example.test']);

        $I->loginAsAccountant();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/reactivate',
            []
        );

        Assert::assertSame(403, $status, 'denial must return 403 forbidden. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-subscriptions/manage reactivate denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-subscriptions/manage reactivate denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [86]
     *   "A logged-out visitor cannot attempt to reactivate a subscription through the admin route."
     */
    public function guestCannotAttemptReactivateSubscriptionThroughAdminRoute(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Guest Reactivate 86',
            'status'    => Status::SUBSCRIPTION_CANCELED,
        ], ['email' => 'perm-sub-guest-reactivate@example.test']);

        $I->logout();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/reactivate',
            []
        );

        Assert::assertSame(401, $status, 'guest reactivate-subscription must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest reactivate-subscription denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'guest reactivate-subscription denial must include a permission-denied message');
    }

    // -------------------------------------------------------------------------
    // Items 87-90 — Pausing a subscription (not available yet)
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/subscriptions.md [87]
     *   "A store manager is authorized to attempt pausing a subscription; the request is rejected because the gateway cannot pause it."
     */
    public function storeManagerIsAuthorizedToAttemptPausingSubscriptionAndRejectedByGatewayCapabilityGuard(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Mgr Pause 87',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ], ['email' => 'perm-sub-mgr-pause@example.test']);

        $I->loginAsManager();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/pause',
            []
        );

        Assert::assertNotSame(403, $status, 'manager-with-subscriptions/manage pause must NOT be rejected by policy gate. Body: ' . wp_json_encode($body));
        Assert::assertNotSame(401, $status, 'manager-with-subscriptions/manage pause must NOT be rejected as unauthenticated. Body: ' . wp_json_encode($body));
        Assert::assertSame(422, $status, 'pause must surface sendError 422 — the automatic fixture fails canPause() (offline_payment lacks pause capability). Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'cannot be paused',
            (string) ($body['message'] ?? ''),
            'pause body must carry the cannot_pause guard message (SubscriptionService::pauseSubscription). Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_ACTIVE,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'rejected pause must NOT mutate fct_subscriptions.status — the active subscription must remain active because the guard returns sendError before touching state. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [88]
     *   "A worker without the \"manage subscriptions\" permission cannot attempt to pause a subscription."
     */
    public function workerWithoutManageSubscriptionsPermissionCannotAttemptPauseSubscription(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Worker Pause 88',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ], ['email' => 'perm-sub-w-pause@example.test']);

        $I->loginAsWorker();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/pause',
            []
        );

        Assert::assertSame(403, $status, 'denial must return 403 forbidden. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-subscriptions/manage pause denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-subscriptions/manage pause denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [89]
     *   "An accountant without the \"manage subscriptions\" permission cannot attempt to pause a subscription."
     */
    public function accountantWithoutManageSubscriptionsPermissionCannotAttemptPauseSubscription(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Acct Pause 89',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ], ['email' => 'perm-sub-a-pause@example.test']);

        $I->loginAsAccountant();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/pause',
            []
        );

        Assert::assertSame(403, $status, 'denial must return 403 forbidden. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-subscriptions/manage pause denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-subscriptions/manage pause denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [90]
     *   "A logged-out visitor cannot attempt to pause a subscription through the admin route."
     */
    public function guestCannotAttemptPauseSubscriptionThroughAdminRoute(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Guest Pause 90',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ], ['email' => 'perm-sub-guest-pause@example.test']);

        $I->logout();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/pause',
            []
        );

        Assert::assertSame(401, $status, 'guest pause-subscription must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest pause-subscription denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'guest pause-subscription denial must include a permission-denied message');
    }

    // -------------------------------------------------------------------------
    // Items 91-94 — Resuming a subscription (not available yet)
    // -------------------------------------------------------------------------

    /**
     * Spec: Cases/Functional/subscriptions.md [91]
     *   "A store manager is authorized to attempt resuming a subscription; the request is rejected because the gateway cannot resume it."
     */
    public function storeManagerIsAuthorizedToAttemptResumingSubscriptionAndRejectedByGatewayCapabilityGuard(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Mgr Resume 91',
            'status'    => Status::SUBSCRIPTION_PAUSED,
        ], ['email' => 'perm-sub-mgr-resume@example.test']);

        $I->loginAsManager();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/resume',
            []
        );

        Assert::assertNotSame(403, $status, 'manager-with-subscriptions/manage resume must NOT be rejected by policy gate. Body: ' . wp_json_encode($body));
        Assert::assertNotSame(401, $status, 'manager-with-subscriptions/manage resume must NOT be rejected as unauthenticated. Body: ' . wp_json_encode($body));
        Assert::assertSame(422, $status, 'resume must surface sendError 422 — the automatic fixture fails canResume() (offline_payment lacks resume_subscription capability). Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'cannot be resumed',
            (string) ($body['message'] ?? ''),
            'resume body must carry the cannot_resume guard message (SubscriptionService::resumeSubscription). Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_PAUSED,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'rejected resume must NOT mutate fct_subscriptions.status — the paused subscription must remain paused because the guard returns sendError before touching state. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [92]
     *   "A worker without the \"manage subscriptions\" permission cannot attempt to resume a subscription."
     */
    public function workerWithoutManageSubscriptionsPermissionCannotAttemptResumeSubscription(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Worker Resume 92',
            'status'    => Status::SUBSCRIPTION_PAUSED,
        ], ['email' => 'perm-sub-w-resume@example.test']);

        $I->loginAsWorker();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/resume',
            []
        );

        Assert::assertSame(403, $status, 'denial must return 403 forbidden. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-subscriptions/manage resume denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-subscriptions/manage resume denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [93]
     *   "An accountant without the \"manage subscriptions\" permission cannot attempt to resume a subscription."
     */
    public function accountantWithoutManageSubscriptionsPermissionCannotAttemptResumeSubscription(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Acct Resume 93',
            'status'    => Status::SUBSCRIPTION_PAUSED,
        ], ['email' => 'perm-sub-a-resume@example.test']);

        $I->loginAsAccountant();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/resume',
            []
        );

        Assert::assertSame(403, $status, 'denial must return 403 forbidden. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'accountant-without-subscriptions/manage resume denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'accountant-without-subscriptions/manage resume denial must include a permission-denied message');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [94]
     *   "A logged-out visitor cannot attempt to resume a subscription through the admin route."
     */
    public function guestCannotAttemptResumeSubscriptionThroughAdminRoute(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Perm Guest Resume 94',
            'status'    => Status::SUBSCRIPTION_PAUSED,
        ], ['email' => 'perm-sub-guest-resume@example.test']);

        $I->logout();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/resume',
            []
        );

        Assert::assertSame(401, $status, 'guest resume-subscription must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest resume-subscription denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'guest resume-subscription denial must include a permission-denied message');
    }
}
