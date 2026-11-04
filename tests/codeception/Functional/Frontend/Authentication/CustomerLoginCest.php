<?php

namespace Tests\Functional\Frontend\Authentication;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/authentication.md → "Customer login" section, items 1-3.
 */
class CustomerLoginCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->logout();
    }

    /**
     * Spec: Cases/Functional/authentication.md [1]
     *   "A logged-out customer can sign in with valid credentials and becomes the current WordPress user."
     */
    public function loggedOutCustomerCanSignInWithValidCredentials(FunctionalTester $I): void
    {
        $userId = WPUserFactory::create([
            'user_login' => 'customer_login_success',
            'user_email' => 'customer-login-success@example.test',
            'user_pass'  => 'ValidPassword123!',
        ]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/user/login', [
            'user_login' => 'customer_login_success',
            'password'   => 'ValidPassword123!',
            'remember_me' => 'on',
        ], true);

        Assert::assertSame(200, $status, 'valid customer login must return HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertTrue((bool) ($body['success'] ?? false), 'valid customer login response must report success');
        Assert::assertSame('Login successful', (string) ($body['data']['message'] ?? ''), 'valid customer login response must confirm authentication');
        Assert::assertStringContainsString('#/profile', (string) ($body['data']['redirect_url'] ?? ''), 'valid customer login response must point to the customer profile');
        Assert::assertSame($userId, get_current_user_id(), 'valid customer login must establish the submitted WordPress user as current');
    }

    /**
     * Spec: Cases/Functional/authentication.md [2]
     *   "A logged-out customer with an incorrect password is rejected and remains logged out."
     */
    public function loggedOutCustomerWithIncorrectPasswordIsRejected(FunctionalTester $I): void
    {
        WPUserFactory::create([
            'user_login' => 'customer_login_failure',
            'user_email' => 'customer-login-failure@example.test',
            'user_pass'  => 'ValidPassword123!',
        ]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/user/login', [
            'user_login' => 'customer_login_failure',
            'password'   => 'IncorrectPassword123!',
        ], true);

        Assert::assertSame(401, $status, 'incorrect customer password must return HTTP 401. Body: ' . wp_json_encode($body));
        Assert::assertFalse((bool) ($body['success'] ?? true), 'incorrect customer password response must report failure');
        Assert::assertSame('login_failed', (string) ($body['data']['code'] ?? ''), 'incorrect customer password response must identify login failure');
        Assert::assertSame(0, get_current_user_id(), 'incorrect customer password must leave the request logged out');
    }

    /**
     * Spec: Cases/Functional/authentication.md [3]
     *   "A logged-out customer cannot submit the login form without a valid security token."
     */
    public function loggedOutCustomerWithoutSecurityTokenIsRejected(FunctionalTester $I): void
    {
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/user/login', [
            'user_login' => 'untrusted_login',
            'password'   => 'UntrustedPassword123!',
        ]);

        Assert::assertSame(403, $status, 'customer login without a security token must return HTTP 403. Body: ' . wp_json_encode($body));
        Assert::assertSame('invalid_nonce', (string) ($body['code'] ?? ''), 'missing-token login response must identify the invalid nonce');
        Assert::assertSame(0, get_current_user_id(), 'missing-token login rejection must leave the request logged out');
    }
}
