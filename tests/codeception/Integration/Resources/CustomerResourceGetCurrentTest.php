<?php

namespace Tests\Integration\Resources;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Models\Customer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;

/**
 * Tests for CustomerResource::getCurrentCustomer().
 *
 * @runTestsInSeparateProcesses
 *
 * Run each test in a fresh process because both Helper::getCurrentUser() and
 * CustomerResource::getCurrentCustomer() cache their results in function static
 * variables in the current codebase.
 */
class CustomerResourceGetCurrentTest extends WPTestCase
{
    // =========================================================================
    // Spec 68: Returns null when user is not logged in
    // =========================================================================

    public function test_returns_null_when_not_logged_in(): void
    {
        wp_set_current_user(0);

        $result = CustomerResource::getCurrentCustomer();

        $this->assertNull($result);
    }

    // =========================================================================
    // Spec 69: Returns existing customer matched by user_id or email
    // =========================================================================

    public function test_returns_existing_customer_for_logged_in_user(): void
    {
        $factory = new CustomerFactory();
        $email = 'gc-exist-' . wp_generate_password(4, false) . '@example.com';
        $userId = static::factory()->user->create([
            'user_email' => $email,
            'role'       => 'subscriber',
        ]);

        $customer = $factory->create([
            'email'   => $email,
            'user_id' => $userId,
        ]);

        wp_set_current_user($userId);

        $result = CustomerResource::getCurrentCustomer();

        $this->assertNotNull($result);
        $this->assertSame($customer->id, $result->id);
        $this->assertSame($email, $result->email);
    }

}
