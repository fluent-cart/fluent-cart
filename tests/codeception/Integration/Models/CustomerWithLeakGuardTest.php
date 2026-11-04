<?php

namespace Tests\Integration\Models;

use FluentCart\App\Http\Controllers\CustomerController;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\UserRoleFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\Traits\InvokesPrivateMethods;

/**
 * Security regression cover for the customer `with` leak.
 *
 * Two independent guards:
 *   1. User::$hidden — the WordPress `users` credential columns never serialize,
 *      whichever path loads them (including the hardcoded
 *      `Customer::query()->with('wpUser')` in CustomerController::setAttachableUser).
 *   2. CustomerController::resolveEagerLoads() — GET /customers/{id} only
 *      eager-loads allowlisted relations, and permission-gated ones require the
 *      permission they imply.
 *
 * The Functional suite runs with Pro absent, so PermissionManager grants every
 * permission to a `manage_options` user and none to anyone else. That is exactly
 * the two ends of the gate this test needs.
 */
class CustomerWithLeakGuardTest extends DatabaseTestCase
{
    use InvokesPrivateMethods;

    /**
     * Columns of the WordPress `users` table that must never reach a client.
     */
    private const CREDENTIAL_COLUMNS = ['user_pass', 'user_activation_key'];

    // =========================================================================
    // Guard 1: User::$hidden
    // =========================================================================

    public function test_user_model_to_array_omits_credential_columns(): void
    {
        $userId = WPUserFactory::create(['user_pass' => 'a-real-secret-value']);
        $user   = User::query()->find($userId);

        $this->assertNotNull($user, 'seeded WP user must be loadable through the User model');

        $asArray = $user->toArray();

        foreach (self::CREDENTIAL_COLUMNS as $column) {
            $this->assertArrayNotHasKey(
                $column,
                $asArray,
                sprintf('User::toArray() must not expose the `%s` column', $column)
            );
        }

        $this->assertArrayHasKey('user_email', $asArray, 'non-sensitive columns must still serialize');
        $this->assertArrayHasKey('display_name', $asArray, 'non-sensitive columns must still serialize');
    }

    public function test_user_model_to_json_omits_credential_columns(): void
    {
        $userId = WPUserFactory::create(['user_pass' => 'a-real-secret-value']);
        $user   = User::query()->find($userId);

        $json = $user->toJson();

        foreach (self::CREDENTIAL_COLUMNS as $column) {
            $this->assertStringNotContainsString(
                $column,
                $json,
                sprintf('User::toJson() must not expose the `%s` column', $column)
            );
        }
    }

    /**
     * $hidden is a serialization filter (HidesAttributes + getArrayableItems), not
     * an access filter (HasAttributes::getAttribute never consults it). Internal
     * reads therefore keep working — this pins that so the guard is not mistaken
     * for something it is not.
     */
    public function test_hidden_columns_are_still_readable_by_direct_property_access(): void
    {
        $userId = WPUserFactory::create(['user_pass' => 'a-real-secret-value']);
        $user   = User::query()->find($userId);

        $this->assertNotEmpty(
            $user->user_pass,
            'direct property access must still read a hidden column — $hidden only filters serialization'
        );
    }

    /**
     * The eager-loaded relation is serialized through relationsToArray(), which
     * runs the same hidden filter. This is the path CustomerController's hardcoded
     * with('wpUser') takes.
     */
    public function test_eager_loaded_wp_user_relation_serializes_without_credentials(): void
    {
        $customer = (new CustomerFactory())->createWithUser(
            ['email' => 'hidden-relation-' . uniqid('', true) . '@example.test'],
            ['user_pass' => 'a-real-secret-value']
        );

        $loaded = Customer::query()->with('wpUser')->find($customer->id);
        $json   = json_encode($loaded->toArray());

        $this->assertStringContainsString('wp_user', $json, 'the relation must still be present');

        foreach (self::CREDENTIAL_COLUMNS as $column) {
            $this->assertStringNotContainsString(
                $column,
                $json,
                sprintf('an eager-loaded wpUser relation must not expose the `%s` column', $column)
            );
        }
    }

    // =========================================================================
    // Guard 2: CustomerController::resolveEagerLoads()
    // =========================================================================

    public function test_wp_user_is_rejected_in_every_input_shape(): void
    {
        wp_set_current_user(UserRoleFactory::createAdmin());

        $shapes = [
            'flat'                => ['wpUser'],
            'column select'       => ['wpUser:ID,user_pass'],
            'prefix lookalike'    => ['wpUserFoo'],
            'dotted nested'       => ['orders.customer.wpUser'],
            'nested array parent' => ['customer' => ['wpUser']],
            'nested array child'  => ['orders' => ['customer.wpUser']],
            'padded'              => [' wpUser '],
        ];

        foreach ($shapes as $label => $with) {
            $resolved = $this->resolve($with);

            $this->assertNotContains(
                'wpUser',
                $resolved,
                sprintf('the "%s" shape must never resolve to the wpUser relation', $label)
            );
            foreach ($resolved as $relation) {
                $this->assertStringNotContainsStringIgnoringCase(
                    'wpuser',
                    $relation,
                    sprintf('the "%s" shape must not resolve to anything naming wpUser', $label)
                );
            }
        }
    }

    public function test_unknown_relations_are_dropped_silently(): void
    {
        wp_set_current_user(UserRoleFactory::createAdmin());

        $resolved = $this->resolve(['thisRelationDoesNotExist', 'billing_address', '', 123, null]);

        $this->assertSame(
            ['billing_address'],
            $resolved,
            'unknown, empty and non-string entries must be dropped without raising'
        );
    }

    public function test_dotted_paths_are_matched_in_full_not_by_first_segment(): void
    {
        wp_set_current_user(UserRoleFactory::createAdmin());

        $this->assertSame(
            [],
            $this->resolve(['orders.customer.wpUser']),
            'a dotted path must be matched whole — matching only the leading `orders` is the leak'
        );
    }

    /**
     * Contract hardened by b4cb8f1ea (#2524, 2026-08-07): every `with` entry is a
     * LITERAL key of CustomerController::allowedWiths() — a `relation:col,col`
     * suffix is not a key, so the whole entry is dropped. The old strip-then-match
     * behavior would let a column select smuggle a narrowed query past the map.
     */
    public function test_column_select_suffix_is_not_a_map_key_and_the_entry_is_dropped(): void
    {
        wp_set_current_user(UserRoleFactory::createAdmin());

        $this->assertSame(
            [],
            $this->resolve(['labels:id,label_id']),
            'a `:col,col` suffixed entry is not a literal allowedWiths() key and must be dropped whole'
        );
    }

    public function test_duplicate_entries_collapse(): void
    {
        wp_set_current_user(UserRoleFactory::createAdmin());

        $this->assertSame(
            ['billing_address'],
            $this->resolve(['billing_address', 'billing_address:id']),
            'the same relation requested twice must be eager-loaded once'
        );
    }

    /**
     * `orders` is deliberately NOT on the post-#2524 map: the customer detail
     * screen pulls its order table from the paginating GET customers/{id}/orders
     * endpoint, and an ungated `orders` key was part of the original leak chain
     * (orders.customer.wpUser). Holding permissions changes nothing for it.
     */
    public function test_gated_relations_resolve_for_a_permission_holder_but_orders_stays_off_the_map(): void
    {
        wp_set_current_user(UserRoleFactory::createAdmin());

        $resolved = $this->resolve(['billing_address', 'orders', 'subscriptions', 'labels']);

        $this->assertSame(
            ['billing_address', 'subscriptions', 'labels'],
            $resolved,
            'a full-permission actor gets the mapped relations, and `orders` — off the allowedWiths() map since #2524 — is dropped even for them'
        );
    }

    /**
     * Post-#2524, EVERY map entry re-checks its own permission bar inside the
     * callback — the address entries repeat the route's customers/view gate rather
     * than assuming the route enforced it. A subscriber holds none, so nothing
     * resolves at all (previously billing_address rode through ungated).
     */
    public function test_every_relation_drops_out_for_an_actor_holding_no_permissions(): void
    {
        wp_set_current_user(UserRoleFactory::createSubscriber());

        $resolved = $this->resolve(['billing_address', 'orders', 'subscriptions', 'labels']);

        $this->assertSame(
            [],
            $resolved,
            'an actor without customers/view (or any other permission) must resolve zero relations — each map callback re-checks its own bar'
        );
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    /**
     * @param mixed $with
     * @return array
     */
    private function resolve($with): array
    {
        return $this->invokePrivateMethod(
            CustomerController::class,
            'resolveEagerLoads',
            [$with],
            new CustomerController()
        );
    }
}
