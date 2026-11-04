<?php

namespace Tests\Functional\Rest\Subscriptions;

use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Spec: Cases/Functional/subscriptions.md → "Listing and searching" section, items 1-11.
 */
class ListSubscriptionsCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [2]
     *   "Admin can paginate subscription results and request a specific page size."
     */
    public function adminCanPaginateSubscriptionResultsAtRequestedPageSize(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $createdIds = [];
        for ($i = 0; $i < 4; $i++) {
            $createdIds[] = (int) $this->buildSubscription([
                'item_name' => 'Paginate Probe ' . $i,
            ])->id;
        }
        rsort($createdIds);
        $expectedPage1 = array_slice($createdIds, 0, 2);
        $expectedPage2 = array_slice($createdIds, 2, 2);

        [, $page1] = $I->get('/fluent-cart/v2/subscriptions', ['per_page' => 2, 'page' => 1]);
        $I->seeResponseCodeIs(200);
        Assert::assertSame(1, (int) ($page1['data']['current_page'] ?? 0), 'page 1 response must report current_page=1');
        Assert::assertSame(2, (int) ($page1['data']['per_page'] ?? 0), 'page 1 response must echo back the requested per_page value');
        Assert::assertCount(2, $page1['data']['data'] ?? [], 'page 1 must contain exactly per_page items');

        [, $page2] = $I->get('/fluent-cart/v2/subscriptions', ['per_page' => 2, 'page' => 2]);
        Assert::assertSame(2, (int) ($page2['data']['current_page'] ?? 0), 'page 2 response must report current_page=2');
        Assert::assertCount(2, $page2['data']['data'] ?? [], 'page 2 must contain exactly per_page items');

        $page1Ids = array_map('intval', array_column($page1['data']['data'] ?? [], 'id'));
        $page2Ids = array_map('intval', array_column($page2['data']['data'] ?? [], 'id'));

        foreach ($expectedPage1 as $id) {
            Assert::assertContains($id, $page1Ids, "page 1 must contain seeded subscription id {$id}");
        }
        foreach ($expectedPage2 as $id) {
            Assert::assertContains($id, $page2Ids, "page 2 must contain seeded subscription id {$id}");
        }
        Assert::assertEmpty(
            array_intersect($page1Ids, $page2Ids),
            'page 1 and page 2 must contain disjoint subscriptions'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [3]
     *   "Admin can filter subscriptions by status — active, trialing, pending, intended, paused,
     *    canceled, failing, expiring, or expired — and only subscriptions in that status are returned."
     */
    public function adminCanFilterSubscriptionsByStatus(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $statusMap = [];
        $statuses = [
            Status::SUBSCRIPTION_ACTIVE,
            Status::SUBSCRIPTION_TRIALING,
            Status::SUBSCRIPTION_PENDING,
            Status::SUBSCRIPTION_INTENDED,
            Status::SUBSCRIPTION_PAUSED,
            Status::SUBSCRIPTION_CANCELED,
            Status::SUBSCRIPTION_FAILING,
            Status::SUBSCRIPTION_EXPIRING,
            Status::SUBSCRIPTION_EXPIRED,
        ];

        foreach ($statuses as $status) {
            $statusMap[$status] = (int) $this->buildSubscription([
                'status'    => $status,
                'item_name' => 'Status Probe ' . $status,
            ])->id;
        }

        foreach ($statuses as $target) {
            [, $body] = $I->get('/fluent-cart/v2/subscriptions', ['active_view' => $target]);
            $I->seeResponseCodeIs(200);

            $ids = array_map('intval', array_column($body['data']['data'] ?? [], 'id'));
            Assert::assertContains(
                $statusMap[$target],
                $ids,
                "subscription with status={$target} must appear when filtering by active_view={$target}"
            );

            foreach ($statuses as $other) {
                if ($other === $target) {
                    continue;
                }
                Assert::assertNotContains(
                    $statusMap[$other],
                    $ids,
                    "subscription with status={$other} must be excluded when filtering by active_view={$target}"
                );
            }
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [4]
     *   "Admin can filter subscriptions by billing interval — yearly, half-yearly, quarterly,
     *    monthly, weekly, or daily — and only matching subscriptions are returned."
     */
    public function adminCanFilterSubscriptionsByBillingInterval(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        // billing_interval is exposed as a dedicated filter under the "subscription" provider
        // in SubscriptionFilter::advanceFilterOptions(); exercising the advanced_filters path
        // hits the production filter mechanism rather than the catch-all search column LIKE.
        $intervals = ['yearly', 'half_yearly', 'quarterly', 'monthly', 'weekly', 'daily'];

        $intervalMap = [];
        foreach ($intervals as $interval) {
            $intervalMap[$interval] = (int) $this->buildSubscription([
                'billing_interval' => $interval,
                'item_name'        => 'BillingInterval Probe ' . $interval,
            ])->id;
        }

        foreach ($intervals as $target) {
            $advanced = wp_json_encode([
                [
                    [
                        'source'      => ['subscription', 'billing_interval'],
                        'property'    => 'billing_interval',
                        'operator'    => 'in',
                        'value'       => [$target],
                        'filter_type' => null,
                    ],
                ],
            ]);

            [, $body] = $I->get('/fluent-cart/v2/subscriptions', [
                'filter_type'      => 'advanced',
                'advanced_filters' => $advanced,
            ]);

            $I->seeResponseCodeIs(200);
            $ids = array_map('intval', array_column($body['data']['data'] ?? [], 'id'));
            Assert::assertContains(
                $intervalMap[$target],
                $ids,
                "subscription with billing_interval={$target} must appear when filtering by billing_interval={$target}"
            );

            foreach ($intervals as $other) {
                if ($other === $target) {
                    continue;
                }
                Assert::assertNotContains(
                    $intervalMap[$other],
                    $ids,
                    "subscription with billing_interval={$other} must be excluded when filtering by billing_interval={$target}"
                );
            }
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [5]
     *   "Admin can filter subscriptions by current payment method and only subscriptions on
     *    that payment method are returned."
     */
    public function adminCanFilterSubscriptionsByCurrentPaymentMethod(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        // current_payment_method is exposed as a dedicated filter under both the "subscription"
        // and "transaction" providers in SubscriptionFilter::advanceFilterOptions(); exercise
        // the advanced_filters path so the production filter mechanism is what's under test,
        // not the catch-all search column LIKE.
        $methods = ['stripe', 'paypal', 'offline_payment', 'square'];

        $methodMap = [];
        foreach ($methods as $method) {
            $methodMap[$method] = (int) $this->buildSubscription([
                'current_payment_method' => $method,
                'item_name'              => 'PaymentMethod Probe ' . $method,
            ])->id;
        }

        foreach ($methods as $target) {
            $advanced = wp_json_encode([
                [
                    [
                        'source'      => ['subscription', 'current_payment_method'],
                        'property'    => 'current_payment_method',
                        'operator'    => 'in',
                        'value'       => [$target],
                        'filter_type' => null,
                    ],
                ],
            ]);

            [, $body] = $I->get('/fluent-cart/v2/subscriptions', [
                'filter_type'      => 'advanced',
                'advanced_filters' => $advanced,
            ]);

            $I->seeResponseCodeIs(200);
            $ids = array_map('intval', array_column($body['data']['data'] ?? [], 'id'));
            Assert::assertContains(
                $methodMap[$target],
                $ids,
                "subscription with current_payment_method={$target} must appear when filtering by that method"
            );

            foreach ($methods as $other) {
                if ($other === $target) {
                    continue;
                }
                Assert::assertNotContains(
                    $methodMap[$other],
                    $ids,
                    "subscription with current_payment_method={$other} must be excluded when filtering by current_payment_method={$target}"
                );
            }
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [6]
     *   "Admin can search subscriptions by part of a customer's email and only subscriptions
     *    belonging to matching customers are returned."
     */
    public function adminCanSearchSubscriptionsByPartialCustomerEmail(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();

        // Spec says "part of a customer's email" — any substring (e.g. just the username
        // segment, not necessarily one containing "@") should match. Picking a needle that
        // does NOT contain "@" so the test actually proves substring email-matching, not
        // the narrower "search contains @ → email-join branch" production path.
        $hit = $this->buildSubscription(
            ['item_name' => 'Email Search Hit'],
            ['email' => 'rare-zenith-sub@example.test']
        );
        $miss = $this->buildSubscription(
            ['item_name' => 'Email Search Miss'],
            ['email' => 'unrelated-mailbox-sub@example.test']
        );

        [, $body] = $I->get('/fluent-cart/v2/subscriptions', ['search' => 'rare-zenith-sub']);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column($body['data']['data'] ?? [], 'id'));
        Assert::assertContains((int) $hit->id, $ids, 'subscription belonging to a customer whose email contains the needle must appear');
        Assert::assertNotContains((int) $miss->id, $ids, 'subscription belonging to a customer whose email does not contain the needle must be excluded');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [7]
     *   "Admin can search subscriptions by the gateway subscription identifier and only
     *    matching subscriptions are returned."
     */
    public function adminCanSearchSubscriptionsByVendorSubscriptionId(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $hit = $this->buildSubscription([
            'vendor_subscription_id' => 'sub_VENDOR_NEEDLE_42',
            'item_name'              => 'VendorId Search Hit',
        ]);
        $miss = $this->buildSubscription([
            'vendor_subscription_id' => 'sub_OTHER_HAYSTACK_99',
            'item_name'              => 'VendorId Search Miss',
        ]);

        [, $body] = $I->get('/fluent-cart/v2/subscriptions', ['search' => 'VENDOR_NEEDLE']);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column($body['data']['data'] ?? [], 'id'));
        Assert::assertContains((int) $hit->id, $ids, 'subscription whose vendor_subscription_id contains the search needle must appear');
        Assert::assertNotContains((int) $miss->id, $ids, 'subscription whose vendor_subscription_id does not contain the search needle must be excluded');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [8]
     *   "Searching subscriptions for 'cancelled' (British spelling) returns the same results
     *    as searching for 'canceled'."
     */
    public function searchingCancelledMatchesCanceledStatus(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $canceledRow = $this->buildSubscription([
            'status'    => Status::SUBSCRIPTION_CANCELED,
            'item_name' => 'British Spelling Canceled Row',
        ]);
        $activeRow = $this->buildSubscription([
            'status'    => Status::SUBSCRIPTION_ACTIVE,
            'item_name' => 'British Spelling Active Row',
        ]);

        [, $americanBody] = $I->get('/fluent-cart/v2/subscriptions', ['search' => 'canceled']);
        $I->seeResponseCodeIs(200);
        $americanIds = array_map('intval', array_column($americanBody['data']['data'] ?? [], 'id'));

        [, $britishBody] = $I->get('/fluent-cart/v2/subscriptions', ['search' => 'cancelled']);
        $I->seeResponseCodeIs(200);
        $britishIds = array_map('intval', array_column($britishBody['data']['data'] ?? [], 'id'));

        Assert::assertContains((int) $canceledRow->id, $americanIds, 'American spelling "canceled" must find the canceled subscription');
        Assert::assertContains((int) $canceledRow->id, $britishIds, 'British spelling "cancelled" must also find the canceled subscription');
        Assert::assertNotContains((int) $activeRow->id, $americanIds, 'active subscription must not be returned when searching for canceled status');
        Assert::assertNotContains((int) $activeRow->id, $britishIds, 'active subscription must not be returned when searching for cancelled status');

        sort($americanIds);
        sort($britishIds);
        Assert::assertSame(
            $americanIds,
            $britishIds,
            'British "cancelled" must return the exact same id set as American "canceled"'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [9]
     *   "Searching subscriptions by a value prefixed with the hash mark matches by
     *    subscription identifier."
     */
    public function hashPrefixedSearchMatchesBySubscriptionId(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        // Need a second subscription so the assertion proves we're matching by id substring
        // (not just returning the only row in the table).
        $target = $this->buildSubscription(['item_name' => 'Hash Probe Target']);
        $other  = $this->buildSubscription(['item_name' => 'Hash Probe Other']);

        Assert::assertNotSame((int) $target->id, (int) $other->id, 'fixture sanity: target and other must have different ids');

        [, $body] = $I->get('/fluent-cart/v2/subscriptions', ['search' => '#' . (int) $target->id]);

        $I->seeResponseCodeIs(200);
        $ids = array_map('intval', array_column($body['data']['data'] ?? [], 'id'));

        Assert::assertContains((int) $target->id, $ids, 'subscription whose id matches the hash-prefixed search must appear');

        // Other ids may coincidentally contain the target id as a substring (e.g. target=2, other=12).
        // Strict-equality check: if the OTHER id does NOT literally contain the target id, it must be excluded.
        if (strpos((string) $other->id, (string) $target->id) === false) {
            Assert::assertNotContains(
                (int) $other->id,
                $ids,
                'subscription whose id does not contain the search needle must be excluded from hash-prefixed search'
            );
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [10]
     *   "Asking for a page beyond the available subscription results returns an empty list,
     *    not an error."
     */
    public function pageBeyondAvailableSubscriptionResultsReturnsEmptyList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $this->buildSubscription(['item_name' => 'Page Beyond Probe']);

        [, $body] = $I->get('/fluent-cart/v2/subscriptions', ['per_page' => 5, 'page' => 999]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            [],
            $body['data']['data'] ?? null,
            'page beyond available results must return an empty data array, not an error'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [11]
     *   "Search input that looks like an SQL injection attempt is treated as plain text and
     *    never causes a server error."
     */
    public function sqlInjectionShapedSearchIsTreatedAsPlainTextWithoutServerError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $a = $this->buildSubscription(['item_name' => 'SQLI Probe A']);
        $b = $this->buildSubscription(['item_name' => 'SQLI Probe B']);
        $c = $this->buildSubscription(['item_name' => 'SQLI Probe C']);
        $totalSeeded = 3;

        $payload = "SQLI-X' OR '1'='1";
        [, $body] = $I->get('/fluent-cart/v2/subscriptions', ['search' => $payload]);

        $I->seeResponseCodeIs(200);
        $rows = $body['data']['data'] ?? null;
        Assert::assertIsArray($rows, 'injection-shaped search must still produce a paginator data array');

        $ids = array_map('intval', array_column((array) $rows, 'id'));
        Assert::assertLessThan(
            $totalSeeded,
            count($ids),
            'plain-text search of an injection-shaped payload must not OR-return every seeded subscription; got ' . count($ids) . ' rows of ' . $totalSeeded
        );
        Assert::assertNotContains((int) $a->id, $ids, 'subscription A must not match an injection-shaped payload that does not literally appear in any column');
        Assert::assertNotContains((int) $b->id, $ids, 'subscription B must not match an injection-shaped payload that does not literally appear in any column');
        Assert::assertNotContains((int) $c->id, $ids, 'subscription C must not match an injection-shaped payload that does not literally appear in any column');

        $literalPayload = 'SUBLIT-MARKER-' . uniqid('', true);
        $marked = $this->buildSubscription(['item_name' => $literalPayload]);

        [, $literalBody] = $I->get('/fluent-cart/v2/subscriptions', ['search' => $literalPayload]);
        $I->seeResponseCodeIs(200);
        $literalIds = array_map('intval', array_column((array) ($literalBody['data']['data'] ?? []), 'id'));
        Assert::assertContains(
            (int) $marked->id,
            $literalIds,
            'plain substring search must still locate a subscription whose item_name literally contains it (proves earlier empty result was due to plain-text comparison, not blanket sanitization-rejection)'
        );

        global $wpdb;
        $usersCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
        Assert::assertGreaterThan(0, $usersCount, 'wp_users must still exist after injection probe');
        $subscriptionsCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_subscriptions");
        Assert::assertSame(4, $subscriptionsCount, 'fct_subscriptions must still hold the 4 seeded rows after the injection probe');
    }
}
