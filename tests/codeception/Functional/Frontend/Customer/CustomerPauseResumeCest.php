<?php

namespace Tests\Functional\Frontend\Customer;

use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Spec: Cases/Functional/subscriptions.md → "Pausing and resuming from the
 *       customer portal" section, items 99-102.
 */
class CustomerPauseResumeCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [99]
     *   "A customer can pause their own active store-billed subscription."
     */
    public function customerCanPauseOwnStoreBilledSubscription(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        $subscription = $this->buildSubscriptionForCustomer($customer->id, [
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'collection_method'      => 'manual',
            'next_billing_date'      => gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS),
            'current_payment_method' => 'offline_payment',
        ]);

        [$status, $body] = $I->post(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid . '/pause',
            []
        );

        Assert::assertSame(200, $status, 'own store-billed pause must return HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('successfully paused', (string) ($body['message'] ?? ''), 'own store-billed pause response must confirm the transition');
        Assert::assertSame(Status::SUBSCRIPTION_PAUSED, (string) $subscription->fresh()->status, 'own store-billed pause must persist paused status');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [100]
     *   "A customer cannot pause another customer's subscription by guessing its identifier."
     */
    public function customerCannotPauseAnotherCustomersSubscription(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);
        $foreign = (new CustomerFactory())->create(['email' => 'foreign-pause@example.test']);
        $subscription = $this->buildSubscriptionForCustomer($foreign->id, [
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'collection_method'      => 'manual',
            'next_billing_date'      => gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS),
            'current_payment_method' => 'offline_payment',
        ]);

        [$status, $body] = $I->post(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid . '/pause',
            []
        );

        Assert::assertSame(422, $status, 'cross-customer pause must return HTTP 422. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('Subscription not found', (string) ($body['message'] ?? ''), 'cross-customer pause rejection must not disclose the foreign subscription');
        Assert::assertSame(Status::SUBSCRIPTION_ACTIVE, (string) $subscription->fresh()->status, 'cross-customer pause rejection must leave the foreign subscription active');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [101]
     *   "A customer can resume their own paused store-billed subscription."
     */
    public function customerCanResumeOwnStoreBilledSubscription(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        $subscription = $this->buildSubscriptionForCustomer($customer->id, [
            'status'                 => Status::SUBSCRIPTION_PAUSED,
            'collection_method'      => 'manual',
            'next_billing_date'      => gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS),
            'current_payment_method' => 'offline_payment',
        ]);

        [$status, $body] = $I->post(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid . '/resume',
            []
        );

        Assert::assertSame(200, $status, 'own store-billed resume must return HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('successfully resumed', (string) ($body['message'] ?? ''), 'own store-billed resume response must confirm the transition');
        Assert::assertSame(Status::SUBSCRIPTION_ACTIVE, (string) $subscription->fresh()->status, 'own store-billed resume must persist active status');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [102]
     *   "A customer cannot resume another customer's subscription by guessing its identifier."
     */
    public function customerCannotResumeAnotherCustomersSubscription(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);
        $foreign = (new CustomerFactory())->create(['email' => 'foreign-resume@example.test']);
        $subscription = $this->buildSubscriptionForCustomer($foreign->id, [
            'status'                 => Status::SUBSCRIPTION_PAUSED,
            'collection_method'      => 'manual',
            'next_billing_date'      => gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS),
            'current_payment_method' => 'offline_payment',
        ]);

        [$status, $body] = $I->post(
            '/fluent-cart/v2/customer-profile/subscriptions/' . (string) $subscription->uuid . '/resume',
            []
        );

        Assert::assertSame(422, $status, 'cross-customer resume must return HTTP 422. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString('Subscription not found', (string) ($body['message'] ?? ''), 'cross-customer resume rejection must not disclose the foreign subscription');
        Assert::assertSame(Status::SUBSCRIPTION_PAUSED, (string) $subscription->fresh()->status, 'cross-customer resume rejection must leave the foreign subscription paused');
    }

}
