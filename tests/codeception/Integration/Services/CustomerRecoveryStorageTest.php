<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerMeta;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\CustomerIdentity\CustomerMerger;
use FluentCart\App\Services\CustomerIdentity\CustomerRecoveryService;
use FluentCart\App\Services\CustomerIdentity\EmailClaimService;
use FluentCart\App\Services\CustomerIdentity\EmailClaimPortal;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Traits\BuildsEmailClaims;
use Tests\Support\Traits\RunsCustomerRecovery;
use Tests\Support\Traits\SimulatesRecoveryStorage;
use Tests\Support\Traits\RequiresEmailVerification;

class CustomerRecoveryStorageTest extends WPTestCase
{
    use BuildsEmailClaims;
    use RunsCustomerRecovery;
    use SimulatesRecoveryStorage;
    use RequiresEmailVerification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireEmailVerification();
        $this->configurePortalPage();
        $this->captureMail();
    }

    protected function tearDown(): void
    {
        $this->restoreRecoveryEngine();
        $this->releaseMail();
        remove_all_filters('fluent_cart/customer/recovery_transaction_tables');
        remove_all_actions('fluent_cart/customer_resources_moved');
        wp_set_current_user(0);
        $_GET = [];
        $this->restoreEmailVerificationDefault();
        parent::tearDown();
    }

    /** @dataProvider participatingTables */
    public function test_confirmation_refuses_nontransactional_storage_without_consuming_proof(string $suffix): void
    {
        global $wpdb;
        $target = (new CustomerFactory())->createWithUser();
        $guest = (new CustomerFactory())->create(['email' => $target->email, 'user_id' => null]);
        $order = (new OrderFactory())->create(['customer_id' => $guest->id]);
        wp_set_current_user($target->user_id);
        $token = $this->issueClaimAndGetToken();
        $pending = get_user_meta($target->user_id, EmailClaimService::META_KEY, true);
        $table = in_array($suffix, ['users', 'usermeta', 'options'], true) ? $wpdb->$suffix : $wpdb->prefix . $suffix;
        $this->reportRecoveryEngine($table);

        $this->assertSame('storage_unsupported', EmailClaimService::confirm($token));
        $this->assertSame($pending, get_user_meta($target->user_id, EmailClaimService::META_KEY, true));
        $this->assertSame((int) $guest->id, (int) Order::query()->find($order->id)->customer_id);
        $this->assertSame([], CustomerRecoveryService::progress($target->user_id));
        $this->restoreRecoveryEngine();
        $this->assertSame('confirmed', EmailClaimService::confirm($token), 'The same claim remains usable once storage is supported');
    }

    public function participatingTables(): array
    {
        return [
            ['fct_customers'], ['fct_orders'], ['fct_subscriptions'], ['fct_order_download_permissions'],
            ['fct_carts'], ['fct_customer_addresses'], ['fct_customer_meta'], ['fct_label_relationships'],
            ['fct_activity'], ['users'], ['usermeta'], ['options'],
        ];
    }

    public function test_direct_merge_leaves_resources_and_metadata_untouched_and_does_not_fire_extensions(): void
    {
        global $wpdb;
        $target = (new CustomerFactory())->createWithUser();
        $source = (new CustomerFactory())->create(['user_id' => null]);
        $order = (new OrderFactory())->create(['customer_id' => $source->id]);
        $meta = CustomerMeta::query()->create(['customer_id' => $source->id, 'meta_key' => 'shared', 'meta_value' => 'source']);
        CustomerMeta::query()->create(['customer_id' => $target->id, 'meta_key' => 'shared', 'meta_value' => 'target']);
        $handoffs = 0;
        add_action('fluent_cart/customer_resources_moved', function () use (&$handoffs) { $handoffs++; });
        $this->reportRecoveryEngine($wpdb->prefix . 'fct_customer_meta');

        $this->assertFalse(CustomerMerger::absorb($source, $target));
        $this->assertSame((int) $source->id, (int) Order::query()->find($order->id)->customer_id);
        $this->assertSame('source', CustomerMeta::query()->find($meta->id)->meta_value);
        $this->assertSame(0, $handoffs);
    }

    public function test_worker_rechecks_storage_before_advancing_a_queued_recovery(): void
    {
        global $wpdb;
        $target = (new CustomerFactory())->createWithUser();
        $this->createRecoverySources($target);
        wp_set_current_user($target->user_id);
        $this->assertSame('recovering', EmailClaimService::confirm($this->issueClaimAndGetToken()));
        $before = $this->runNextRecoveryBatch($target->user_id);
        $this->reportRecoveryEngine($wpdb->usermeta);
        $after = $this->runNextRecoveryBatch($target->user_id);
        $this->assertSame('blocked_storage', $after['status']);
        $this->assertSame($before['cursor'], $after['cursor']);
        $this->assertSame($before['processed'], $after['processed']);
        $this->assertSame($before['attempts'], $after['attempts']);
        $this->assertSame(1, Order::query()->where('customer_id', $target->id)->count());
        $this->assertStringContainsString('Please contact the store', EmailClaimPortal::render());
        $this->assertFalse(wp_next_scheduled(CustomerRecoveryService::HOOK, [['user_id' => (int) $target->user_id, 'id' => $after['id']]]));
    }

    public function test_optional_license_table_is_checked_when_present(): void
    {
        global $wpdb;
        $licenseTable = $wpdb->prefix . 'fct_licenses';
        $extraRow = $wpdb->prepare(' UNION ALL SELECT %s AS TABLE_NAME, %s AS ENGINE', $licenseTable, 'MyISAM');
        $filter = function ($query) use ($licenseTable, $extraRow) {
            if (strpos($query, 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES') === 0 && strpos($query, $licenseTable) !== false) {
                return $query . $extraRow;
            }
            return $query;
        };
        add_filter('query', $filter);
        try {
            $this->assertFalse(CustomerMerger::supportsTransactions());
        } finally {
            remove_filter('query', $filter);
        }
    }

    public function test_missing_extension_table_or_unknown_engine_fails_closed(): void
    {
        global $wpdb;
        $this->reportRecoveryEngine($wpdb->prefix . 'fct_orders', '');
        $this->assertFalse(CustomerMerger::supportsTransactions());
        $this->restoreRecoveryEngine();
        $this->assertTrue(CustomerMerger::supportsTransactions());
        add_filter('fluent_cart/customer/recovery_transaction_tables', function ($tables) use ($wpdb) {
            $tables[] = $wpdb->prefix . 'fct_missing_recovery_test_table';
            return $tables;
        });
        $this->assertFalse(CustomerMerger::supportsTransactions());
    }
}
