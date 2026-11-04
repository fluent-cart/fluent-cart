<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\Subscription;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Subscription::getReactivateUrl() mints the link that a canceled-subscription
 * email asks the customer to click. It used to carry a `_wpnonce` produced by
 * wp_create_nonce(), which binds to the current user and session — but the link
 * is minted in email and webhook contexts where there is no current user, so the
 * token stopped verifying the moment the recipient logged in to act on it.
 *
 * The nonce is gone and the handling side authorizes by subscription ownership
 * instead. These tests pin the resulting URL contract, in particular that no
 * user-bound token creeps back into a link built for a user-less context.
 *
 * canReactivate() is stubbed rather than driven through a real subscription
 * because its first guard is App::isProActive(), which is a defined() check on a
 * constant the Integration suite never sets. Stubbing the guard is the only way
 * to reach the URL construction; the DB is untouched, and the guard itself is
 * pinned separately below.
 */
class SubscriptionReactivateUrlTest extends WPTestCase
{
    public function test_the_reactivation_url_carries_only_the_action_and_subscription_hash(): void
    {
        $subscription = $this->getMockBuilder(Subscription::class)
            ->onlyMethods(['canReactivate'])
            ->getMock();
        $subscription->method('canReactivate')->willReturn(true);
        $subscription->uuid = 'sub-uuid-for-url-contract';

        $url = $subscription->getReactivateUrl();

        $this->assertStringContainsString(
            'fluent-cart=reactivate-subscription',
            $url,
            'the link must still name the reactivation action the handler dispatches on'
        );
        $this->assertStringContainsString(
            'subscription_hash=sub-uuid-for-url-contract',
            $url,
            'the link must still carry the subscription uuid the handler resolves'
        );
    }

    public function test_the_reactivation_url_carries_no_user_bound_nonce(): void
    {
        $subscription = $this->getMockBuilder(Subscription::class)
            ->onlyMethods(['canReactivate'])
            ->getMock();
        $subscription->method('canReactivate')->willReturn(true);
        $subscription->uuid = 'sub-uuid-for-nonce-check';

        $url = $subscription->getReactivateUrl();

        $this->assertStringNotContainsString(
            '_wpnonce',
            $url,
            'a wp_create_nonce token binds to the minting user, who does not exist in the email and webhook contexts that produce this link'
        );
    }

    public function test_no_reactivation_url_is_minted_when_the_subscription_cannot_be_reactivated(): void
    {
        $subscription = $this->getMockBuilder(Subscription::class)
            ->onlyMethods(['canReactivate'])
            ->getMock();
        $subscription->method('canReactivate')->willReturn(false);
        $subscription->uuid = 'sub-uuid-not-reactivatable';

        $url = $subscription->getReactivateUrl();

        $this->assertSame(
            '',
            $url,
            'dropping the nonce must not widen which subscriptions get a link; the canReactivate guard is what decides that'
        );
    }
}
