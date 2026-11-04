<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\CustomerMeta;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\CustomerIdentity\CustomerRecoveryService;
use FluentCart\App\Services\CustomerIdentity\EmailClaimService;
use FluentCart\App\Services\CustomerIdentity\EmailClaimPortal;
use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Traits\BuildsEmailClaims;
use Tests\Support\Traits\RunsCustomerRecovery;
use Tests\Support\Traits\RequiresEmailVerification;

class CustomerRecoveryTest extends WPTestCase
{
    use BuildsEmailClaims;
    use RunsCustomerRecovery;
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
        $this->releaseMail();
        remove_all_actions('fluent_cart/customer_resources_moved');
        remove_all_filters('pre_schedule_event');
        wp_set_current_user(0);
        $_GET = [];
        $this->restoreEmailVerificationDefault();
        parent::tearDown();
    }

    public function test_large_recovery_is_queued_and_commits_one_source_per_job(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $sources = $this->createRecoverySources($target);
        wp_set_current_user($target->user_id);
        $this->assertSame('recovering', EmailClaimService::confirm($this->issueClaimAndGetToken()));
        $this->assertFalse(EmailVerificationService::isRequired($target->user_id));
        $this->assertSame(0, Order::query()->where('customer_id', $target->id)->count());
        $this->assertStringContainsString('background', EmailClaimPortal::render());
        $this->assertNull(EmailClaimService::getOffer(), 'Do not offer another claim while recovery is running');
        wp_set_current_user(0);
        $state = $this->runNextRecoveryBatch($target->user_id);
        $this->assertSame(1, $state['processed']);
        $this->assertSame((int) $sources[0]->id, $state['cursor']);
        $this->assertSame(1, Order::query()->where('customer_id', $target->id)->count());
        $this->assertNotFalse(wp_next_scheduled(CustomerRecoveryService::HOOK, [['user_id' => (int) $target->user_id, 'id' => $state['id']]]));
        for ($i = 0; $i < 6; $i++) {
            $state = $this->runNextRecoveryBatch($target->user_id);
        }
        $this->assertSame('completed', $state['status']);
        $this->assertSame(6, $state['processed']);
        $this->assertSame(6, Order::query()->where('customer_id', $target->id)->count());
        $this->assertSame(600, (int) Customer::query()->find($target->id)->ltv);
        wp_set_current_user($target->user_id);
        $_GET = [EmailClaimService::QUERY_STATUS => 'recovering'];
        $this->assertSame('', EmailClaimPortal::render(), 'Completed recovery removes the verification panel');
        $this->assertFalse(wp_next_scheduled(CustomerRecoveryService::HOOK, [['user_id' => (int) $target->user_id, 'id' => $state['id']]]));
    }

    public function test_large_address_and_meta_history_is_bounded_without_model_hydration(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $source = (new CustomerFactory())->create(['email' => $target->email, 'user_id' => null]);
        CustomerMeta::query()->create(['customer_id' => $target->id, 'meta_key' => 'key0', 'meta_value' => 'target']);
        for ($i = 0; $i < 205; $i++) {
            (new CustomerAddressFactory())->create($source->id, ['is_primary' => 1]);
            CustomerMeta::query()->create(['customer_id' => $source->id, 'meta_key' => 'key' . ($i === 204 ? 1 : $i), 'meta_value' => $i === 204 ? 'late' : 'source']);
        }
        wp_set_current_user($target->user_id);
        $this->assertSame('recovering', EmailClaimService::confirm($this->issueClaimAndGetToken()));
        $dispatcher = CustomerMeta::getEventDispatcher();
        CustomerMeta::setEventDispatcher(clone $dispatcher);
        $hydrated = 0;
        CustomerMeta::retrieved(function () use (&$hydrated) { $hydrated++; });
        CustomerAddresses::retrieved(function () use (&$hydrated) { $hydrated++; });
        $handoffs = 0;
        add_action('fluent_cart/customer_resources_moved', function () use (&$handoffs) { $handoffs++; });
        try {
            $state = $this->runNextRecoveryBatch($target->user_id);
            $this->assertSame(105, CustomerMeta::query()->where('customer_id', $source->id)->count());
            $this->assertSame(105, CustomerAddresses::query()->where('customer_id', $source->id)->count());
            $this->assertSame(0, $state['cursor']);
            $this->assertSame(0, $handoffs, 'Extensions run once core resources have moved');
            for ($i = 0; $i < 3; $i++) {
                $state = $this->runNextRecoveryBatch($target->user_id);
            }
            $this->assertSame(0, $hydrated);
            $this->assertSame('completed', $state['status']);
            $this->assertSame(1, $handoffs);
        } finally {
            CustomerMeta::setEventDispatcher($dispatcher);
        }
        $this->assertSame(204, CustomerMeta::query()->where('customer_id', $target->id)->count());
        $this->assertSame('source', CustomerMeta::query()->where('customer_id', $target->id)->where('meta_key', 'key1')->value('meta_value'));
        $this->assertSame('target', CustomerMeta::query()->where('customer_id', $target->id)->where('meta_key', 'key0')->value('meta_value'));
        $this->assertSame(1, CustomerAddresses::query()->where('customer_id', $target->id)->where('is_primary', 1)->count());
    }

    public function test_failed_batch_rolls_back_and_retries_without_repeating_completed_sources(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $sources = $this->createRecoverySources($target);
        wp_set_current_user($target->user_id);
        $this->assertSame('recovering', EmailClaimService::confirm($this->issueClaimAndGetToken()));
        $first = $this->runNextRecoveryBatch($target->user_id);
        $fail = function () { throw new \RuntimeException('Extension unavailable'); };
        add_action('fluent_cart/customer_resources_moved', $fail);
        $failed = $this->runNextRecoveryBatch($target->user_id);
        $this->assertSame($first['cursor'], $failed['cursor']);
        $this->assertSame(1, $failed['attempts']);
        $this->assertSame(1, Order::query()->where('customer_id', $target->id)->count());
        $this->assertSame(1, Order::query()->where('customer_id', $sources[1]->id)->count());
        $this->assertNotFalse(wp_next_scheduled(CustomerRecoveryService::HOOK, [['user_id' => (int) $target->user_id, 'id' => $failed['id']]]));
        remove_action('fluent_cart/customer_resources_moved', $fail);
        $retried = $this->runNextRecoveryBatch($target->user_id);
        $this->assertSame(2, $retried['processed']);
        $this->assertSame(0, $retried['attempts']);
        $this->assertSame(2, Order::query()->where('customer_id', $target->id)->count());
    }

    public function test_retries_stop_and_the_portal_offers_a_fresh_claim(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $this->createRecoverySources($target);
        wp_set_current_user($target->user_id);
        $this->assertSame('recovering', EmailClaimService::confirm($this->issueClaimAndGetToken()));
        add_action('fluent_cart/customer_resources_moved', function () { throw new \RuntimeException('Unavailable'); });
        for ($i = 0; $i < 4; $i++) {
            $state = $this->runNextRecoveryBatch($target->user_id);
        }
        $this->assertSame('failed', $state['status']);
        $this->assertSame(3, $state['attempts']);
        $this->assertSame(0, Order::query()->where('customer_id', $target->id)->count());
        $_GET = [EmailClaimService::QUERY_STATUS => 'recovering'];
        $this->assertStringContainsString('could not be recovered', EmailClaimPortal::render());
        $this->assertNotNull(EmailClaimService::getOffer());
    }

    public function test_manual_email_change_cancels_recovery_before_moving_anything(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $this->createRecoverySources($target);
        wp_set_current_user($target->user_id);
        $this->assertSame('recovering', EmailClaimService::confirm($this->issueClaimAndGetToken()));
        Customer::query()->where('id', $target->id)->update(['email' => 'changed@example.test']);
        $state = $this->runNextRecoveryBatch($target->user_id);
        $this->assertSame('cancelled', $state['status']);
        $this->assertSame(0, Order::query()->where('customer_id', $target->id)->count());
        $this->assertTrue(EmailVerificationService::isRequired($target->user_id));
    }

    public function test_wp_email_change_invalidates_a_queued_job(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $this->createRecoverySources($target);
        wp_set_current_user($target->user_id);
        $this->assertSame('recovering', EmailClaimService::confirm($this->issueClaimAndGetToken()));
        $state = CustomerRecoveryService::progress($target->user_id);
        wp_update_user(['ID' => $target->user_id, 'user_email' => 'changed-account@example.test']);
        do_action(CustomerRecoveryService::HOOK, ['user_id' => (int) $target->user_id, 'id' => $state['id']]);
        $this->assertSame([], CustomerRecoveryService::progress($target->user_id));
        $this->assertSame(0, Order::query()->where('customer_id', $target->id)->count());
    }

    public function test_resources_left_by_an_extension_are_reported_without_an_endless_retry(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $sources = $this->createRecoverySources($target);
        wp_set_current_user($target->user_id);
        $this->assertSame('recovering', EmailClaimService::confirm($this->issueClaimAndGetToken()));
        add_action('fluent_cart/customer_resources_moved', function ($payload) {
            CustomerMeta::query()->create([
                'customer_id' => $payload['from_customer_id'], 'meta_key' => 'extension_pending', 'meta_value' => 'kept'
            ]);
        });
        for ($i = 0; $i < 7; $i++) {
            $state = $this->runNextRecoveryBatch($target->user_id);
        }
        $this->assertSame('incomplete', $state['status']);
        $this->assertSame(6, $state['processed']);
        $this->assertSame(6, Order::query()->where('customer_id', $target->id)->count());
        $this->assertSame(1, CustomerMeta::query()->where('customer_id', $sources[0]->id)->count());
    }

    public function test_a_failed_watchdog_schedule_leaves_a_retryable_failure_instead_of_stuck_progress(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $this->createRecoverySources($target);
        wp_set_current_user($target->user_id);
        $this->assertSame('recovering', EmailClaimService::confirm($this->issueClaimAndGetToken()));
        add_filter('pre_schedule_event', '__return_false');
        $state = $this->runNextRecoveryBatch($target->user_id);
        $this->assertSame('failed', $state['status']);
        $this->assertNotNull(EmailClaimService::getOffer());
        $this->assertSame(0, Order::query()->where('customer_id', $target->id)->count());
    }

    public function test_stale_jobs_and_new_account_collisions_cannot_move_guest_resources(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $this->createRecoverySources($target);
        wp_set_current_user($target->user_id);
        $this->assertSame('recovering', EmailClaimService::confirm($this->issueClaimAndGetToken()));
        $state = CustomerRecoveryService::progress($target->user_id);
        do_action(CustomerRecoveryService::HOOK, ['user_id' => (int) $target->user_id, 'id' => 'old-job']);
        $this->assertSame($state, CustomerRecoveryService::progress($target->user_id));
        $other = (new CustomerFactory())->createWithUser();
        Customer::query()->where('id', $other->id)->update(['email' => $target->email]);
        $state = $this->runNextRecoveryBatch($target->user_id);
        $this->assertSame('cancelled', $state['status']);
        $this->assertSame(0, Order::query()->where('customer_id', $target->id)->count());
        $this->assertSame(0, Order::query()->where('customer_id', $other->id)->count());
    }

    public function test_scheduling_failure_does_not_consume_the_claim(): void
    {
        $target = (new CustomerFactory())->createWithUser();
        $this->createRecoverySources($target);
        wp_set_current_user($target->user_id);
        $token = $this->issueClaimAndGetToken();
        add_filter('pre_schedule_event', '__return_false');
        $this->assertSame('failed', EmailClaimService::confirm($token));
        $this->assertSame([], CustomerRecoveryService::progress($target->user_id));
        $this->assertSame('ok', EmailClaimService::resolveClaim($token)['status']);
        $this->assertSame(0, Order::query()->where('customer_id', $target->id)->count());
    }
}
