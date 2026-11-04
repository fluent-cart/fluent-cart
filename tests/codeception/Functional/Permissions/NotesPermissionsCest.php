<?php

namespace Tests\Functional\Permissions;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\Permission\PermissionManager;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\FunctionalTester;

/**
 * Regression coverage for POST /notes/attach permission gating.
 *
 * The route used to sit behind AdminPolicy + `is_super_admin`, a token only
 * granted to WP users holding `manage_options`. A user carrying the
 * FluentCart `super_admin` shop role (via `_fluent_cart_admin_role` usermeta)
 * without also being a WP Administrator - e.g. a WordPress Editor promoted to
 * FluentCart Super Admin - was rejected with 403 even though that shop role's
 * permission set includes `orders/manage`. The route now uses OrderPolicy
 * with meta permission `orders/manage`, which the shop role check honors.
 */
class NotesPermissionsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    private function makeOrder(int $customerId, string $invoiceSuffix): int
    {
        return (int) (new OrderFactory())->create([
            'customer_id'    => $customerId,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'subtotal'       => 0,
            'total_amount'   => 0,
            'invoice_no'     => 'INV-PERM-NOTES-' . $invoiceSuffix,
        ])->id;
    }

    /**
     * Log in as a WordPress Editor carrying the FluentCart `super_admin` shop
     * role without `manage_options` - the exact scenario the fixed route must
     * accept.
     */
    private function loginAsShopSuperAdminWithoutManageOptions(): int
    {
        $userId = WPUserFactory::create(['role' => 'editor']);
        update_user_meta($userId, '_fluent_cart_admin_role', 'super_admin');

        $wpUser = get_user_by('ID', $userId);
        if ($wpUser && !$wpUser->has_cap(PermissionManager::ADMIN_CAP)) {
            $wpUser->add_cap(PermissionManager::ADMIN_CAP);
        }

        wp_set_current_user($userId);
        Helper::getCurrentUser(true);

        return $userId;
    }

    public function shopSuperAdminWithoutManageOptionsCanAttachOrderNote(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-notes-super-admin@example.test']);
        $orderId  = $this->makeOrder((int) $customer->id, 'SUPERADMIN');

        $userId = $this->loginAsShopSuperAdminWithoutManageOptions();
        Assert::assertFalse(
            user_can($userId, 'manage_options'),
            'precondition: the shop super_admin role must NOT come from manage_options for this regression to be meaningful'
        );

        [$status, $body] = $I->post('/fluent-cart/v2/notes/attach', [
            'order_id' => $orderId,
            'note'     => 'attached by shop super_admin',
        ]);

        Assert::assertSame(
            200,
            $status,
            'shop super_admin (non-WP-admin, e.g. Editor) must be able to attach an order note. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $persistedNote = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT note FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            $orderId
        ));
        Assert::assertSame(
            'attached by shop super_admin',
            $persistedNote,
            'notes/attach must persist the note column. Body: ' . wp_json_encode($body)
        );
    }

    public function workerWithoutManageOrdersPermissionCannotAttachOrderNote(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-notes-worker@example.test']);
        $orderId  = $this->makeOrder((int) $customer->id, 'WORKER');

        $I->loginAsWorker();
        [, $body] = $I->post('/fluent-cart/v2/notes/attach', [
            'order_id' => $orderId,
            'note'     => 'forbidden',
        ]);

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker without orders/manage must surface rest_forbidden on notes/attach');
        Assert::assertNotEmpty($body['message'] ?? '', 'worker denial must include a permission-denied message');
    }

    public function managerWithManageOrdersPermissionCanAttachOrderNote(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'perm-notes-manager@example.test']);
        $orderId  = $this->makeOrder((int) $customer->id, 'MANAGER');

        $I->loginAsManager();
        [$status, $body] = $I->post('/fluent-cart/v2/notes/attach', [
            'order_id' => $orderId,
            'note'     => 'attached by manager',
        ]);

        Assert::assertSame(200, $status, 'manager with orders/manage must be able to attach an order note. Body: ' . wp_json_encode($body));
    }
}
