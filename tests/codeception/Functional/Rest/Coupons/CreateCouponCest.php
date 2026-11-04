<?php

namespace Tests\Functional\Rest\Coupons;

use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Coupon;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/coupons.md → "Creating a coupon" + appendix create-related items,
 * cases 11-23, 103, 104, 105, 106, 107, 108, 109, 110, 113, 114.
 *
 * Route: POST /fluent-cart/v2/coupons (meta: coupons/manage)
 * Controller: CouponsController::create — validates via CouponRequest, normalises
 * start_date/end_date to GMT, creates via CouponResource::create which routes the
 * amount through Helper::toCent for non-percentage types, dispatches
 * `fluent_cart/coupon_created`, writes fct_activity log row.
 */
class CreateCouponCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Build a fully-populated create payload that satisfies CouponRequest::rules()
     * (title, code, type, amount, status, stackable, show_on_checkout all required).
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title'            => 'Created Coupon',
            'code'             => 'CREATED-CODE',
            'type'             => 'fixed',
            'amount'           => 10,
            'status'           => 'active',
            'stackable'        => 'yes',
            'show_on_checkout' => 'yes',
            'priority'         => 1,
            'notes'            => '',
            'conditions'       => [],
        ], $overrides);
    }

    /**
     * Spec: Cases/Functional/coupons.md [11]
     *   "Admin can create a coupon with a valid title, code, type, amount, and status."
     */
    public function adminCanCreateCouponWithValidTitleCodeTypeAmountAndStatus(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'title'  => 'Brand New Coupon',
            'code'   => 'BRAND-NEW',
            'type'   => 'fixed',
            'amount' => 10,
            'status' => 'active',
        ]));

        Assert::assertSame(200, $status, 'create-coupon must return 200. Body: ' . wp_json_encode($body));

        $row = Coupon::query()->where('code', 'BRAND-NEW')->first();
        Assert::assertNotNull($row, 'create must persist a fct_coupons row keyed by the submitted code');
        Assert::assertSame('Brand New Coupon', (string) $row->title, 'persisted title must match the submitted title');
        Assert::assertSame('fixed', (string) $row->type, 'persisted type must match the submitted type');
        Assert::assertSame('active', (string) $row->status, 'persisted status must match the submitted status');
        Assert::assertSame(1000.0, (float) $row->amount, 'fixed-type amount must be persisted in cents (Helper::toCent(10)=1000)');
    }

    /**
     * Spec: Cases/Functional/coupons.md [12]
     *   "Creating a coupon without a title is rejected with a clear validation error."
     */
    public function creatingCouponWithoutTitleIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $payload = $this->payload(['code' => 'NO-TITLE']);
        unset($payload['title']);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $payload);

        Assert::assertNotSame(200, $status, 'create without title must not return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('title', (array) $body, 'validation payload must surface a title field error. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['title'] ?? []), 'validation entry for title must surface the "required" rule key');
        Assert::assertNotEmpty((string) ($body['title']['required'] ?? ''), 'required-rule message for title must be non-empty');

        Assert::assertNull(
            Coupon::query()->where('code', 'NO-TITLE')->first(),
            'rejected without-title submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [13]
     *   "Creating a coupon without a code is rejected with a clear validation error."
     */
    public function creatingCouponWithoutCodeIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $payload = $this->payload(['title' => 'No Code']);
        unset($payload['code']);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $payload);

        Assert::assertNotSame(200, $status, 'create without code must not return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('code', (array) $body, 'validation payload must surface a code field error. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['code'] ?? []), 'validation entry for code must surface the "required" rule key');
        Assert::assertNotEmpty((string) ($body['code']['required'] ?? ''), 'required-rule message for code must be non-empty');

        Assert::assertSame(
            0,
            Coupon::query()->where('title', 'No Code')->count(),
            'rejected without-code submission must NOT persist a fct_coupons row keyed by the submitted title'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [14]
     *   "Creating a coupon without a type is rejected with a clear validation error."
     */
    public function creatingCouponWithoutTypeIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $payload = $this->payload(['code' => 'NO-TYPE']);
        unset($payload['type']);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $payload);

        Assert::assertNotSame(200, $status, 'create without type must not return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('type', (array) $body, 'validation payload must surface a type field error. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['type'] ?? []), 'validation entry for type must surface the "required" rule key');
        Assert::assertNotEmpty((string) ($body['type']['required'] ?? ''), 'required-rule message for type must be non-empty');

        Assert::assertNull(
            Coupon::query()->where('code', 'NO-TYPE')->first(),
            'rejected without-type submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [15]
     *   "Creating a coupon without an amount is rejected with a clear validation error."
     */
    public function creatingCouponWithoutAmountIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $payload = $this->payload(['code' => 'NO-AMOUNT']);
        unset($payload['amount']);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $payload);

        Assert::assertNotSame(200, $status, 'create without amount must not return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('amount', (array) $body, 'validation payload must surface an amount field error. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('required', (array) ($body['amount'] ?? []), 'validation entry for amount must surface the "required" rule key');
        Assert::assertNotEmpty((string) ($body['amount']['required'] ?? ''), 'required-rule message for amount must be non-empty');

        Assert::assertNull(
            Coupon::query()->where('code', 'NO-AMOUNT')->first(),
            'rejected without-amount submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [16]
     *   "Creating a percentage coupon with an amount greater than 100 is rejected with a clear error."
     */
    public function creatingPercentageCouponWithAmountGreaterThan100IsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'   => 'PCT-OVER-100',
            'type'   => 'percentage',
            'amount' => 150,
        ]));

        Assert::assertNotSame(200, $status, 'create percentage>100 must not return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('amount', (array) $body, 'validation payload must surface an amount field error. Body: ' . wp_json_encode($body));
        $amountErrorText = strtolower(json_encode((array) ($body['amount'] ?? [])));
        Assert::assertStringContainsString(
            'should not be greater than 100',
            $amountErrorText,
            'percentage>100 error must explicitly say the amount should not exceed 100. Body: ' . wp_json_encode($body)
        );

        Assert::assertNull(
            Coupon::query()->where('code', 'PCT-OVER-100')->first(),
            'rejected percentage>100 submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [17]
     *   "Creating a coupon with a code already used by another coupon is rejected with a clear error."
     */
    public function creatingCouponWithCodeAlreadyUsedIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new CouponFactory())->create(['title' => 'Existing', 'code' => 'TAKEN-CODE']);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'title' => 'Duplicate Attempt',
            'code'  => 'TAKEN-CODE',
        ]));

        Assert::assertNotSame(200, $status, 'create with duplicate code must not return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('code', (array) $body, 'validation payload must surface a code field error. Body: ' . wp_json_encode($body));
        $codeErrorText = strtolower(json_encode((array) ($body['code'] ?? [])));
        Assert::assertStringContainsString(
            'already in use',
            $codeErrorText,
            'duplicate-code error must explicitly say the code is already in use. Body: ' . wp_json_encode($body)
        );

        Assert::assertSame(
            1,
            Coupon::query()->where('code', 'TAKEN-CODE')->count(),
            'duplicate-code rejection must NOT create a second coupon with the same code'
        );
        Assert::assertSame(
            'Existing',
            (string) Coupon::query()->where('code', 'TAKEN-CODE')->value('title'),
            'rejected duplicate-code create must NOT mutate the existing coupon title to the rejected payload title'
        );
        Assert::assertSame(
            0,
            Coupon::query()->where('title', 'Duplicate Attempt')->count(),
            'rejected duplicate-code create must NOT persist a fct_coupons row keyed by the submitted title'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [18]
     *   "Creating a coupon whose end date is earlier than its start date is rejected with a clear error."
     */
    public function creatingCouponWhoseEndDateIsEarlierThanStartDateIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'BAD-DATES',
            'start_date' => '2025-12-31 00:00:00',
            'end_date'   => '2025-01-01 00:00:00',
        ]));

        Assert::assertNotSame(200, $status, 'create with end<start must not return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('end_date', (array) $body, 'validation payload must surface an end_date field error. Body: ' . wp_json_encode($body));
        $endDateErrorText = strtolower(json_encode((array) ($body['end_date'] ?? [])));
        Assert::assertStringContainsString(
            'after the start date',
            $endDateErrorText,
            'end-before-start error must explicitly mention end-must-be-after-start. Body: ' . wp_json_encode($body)
        );

        Assert::assertNull(
            Coupon::query()->where('code', 'BAD-DATES')->first(),
            'rejected end-before-start submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [19]
     *   "Creating a coupon whose maximum total uses is less than its maximum uses per customer is rejected with a clear error."
     */
    public function creatingCouponWhoseMaxUsesIsLessThanMaxPerCustomerIsRejected(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'MAX-USES-TOO-LOW',
            'conditions' => [
                'max_uses'         => 2,
                'max_per_customer' => 5,
            ],
        ]));

        Assert::assertNotSame(200, $status, 'create with max_uses<max_per_customer must not return 200. Body: ' . wp_json_encode($body));
        $bodyText = strtolower(json_encode((array) $body));
        Assert::assertStringContainsString(
            'max uses must be greater than or equal to max per customer',
            $bodyText,
            'rejection error must explicitly mention max-uses must be >= max-per-customer. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [20]
     *   "A fixed-amount coupon's discount value is stored consistently (e.g. $12.34 round-trips correctly)."
     */
    public function fixedAmountCouponDiscountValueIsStoredConsistently(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'   => 'ROUND-TRIP-12-34',
            'type'   => 'fixed',
            'amount' => 12.34,
        ]));

        Assert::assertSame(200, $status, 'fixed-amount $12.34 create must return 200. Body: ' . wp_json_encode($body));

        global $wpdb;
        $persistedAmount = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT amount FROM {$wpdb->prefix}fct_coupons WHERE code = %s",
            'ROUND-TRIP-12-34'
        ));
        Assert::assertSame(
            1234.0,
            $persistedAmount,
            'fixed-amount $12.34 must be stored as 1234 cents (Helper::toCent applied to non-percentage type). Body: ' . wp_json_encode($body)
        );

        [, $viewBody] = $I->get('/fluent-cart/v2/coupons');
        $rows = (array) ($viewBody['coupons']['data'] ?? []);
        $entry = null;
        foreach ($rows as $row) {
            if (((string) ($row['code'] ?? '')) === 'ROUND-TRIP-12-34') {
                $entry = $row;
                break;
            }
        }
        Assert::assertNotNull($entry, 'newly-created coupon must appear in the listing');
        Assert::assertSame(1234.0, (float) ($entry['amount'] ?? 0), 'listing must echo the same cents value (no double-conversion on read)');
    }

    /**
     * Spec: Cases/Functional/coupons.md [21]
     *   "The coupon's start and end dates are recorded in UTC, regardless of the site's timezone."
     */
    public function couponStartAndEndDatesAreRecordedInUtcRegardlessOfSiteTimezone(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $originalTimezone = (string) get_option('timezone_string', '');
        $originalGmtOffset = get_option('gmt_offset');

        try {
            // Force the site into a non-UTC zone so the "regardless of site timezone" clause
            // is exercised — the storage must still come out as UTC.
            update_option('timezone_string', 'America/Los_Angeles');
            update_option('gmt_offset', 0);

            [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
                'code'       => 'UTC-DATES',
                'start_date' => '2025-01-15 10:00:00+05:00',
                'end_date'   => '2025-12-15 22:00:00+05:00',
            ]));

            Assert::assertSame(200, $status, 'create with TZ-bearing dates must return 200. Body: ' . wp_json_encode($body));
            Assert::assertSame(
                'America/Los_Angeles',
                (string) get_option('timezone_string'),
                'precondition: site timezone must be PST during the dispatch'
            );

            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT start_date, end_date FROM {$wpdb->prefix}fct_coupons WHERE code = %s",
                'UTC-DATES'
            ), ARRAY_A);
            Assert::assertNotEmpty($row, 'fct_coupons row must exist for the seeded code');

            Assert::assertSame(
                '2025-01-15 05:00:00',
                (string) $row['start_date'],
                'start_date must be persisted as 05:00 UTC after offset-conversion from +05:00 — independent of America/Los_Angeles being the site timezone'
            );
            Assert::assertSame(
                '2025-12-15 17:00:00',
                (string) $row['end_date'],
                'end_date must be persisted as 17:00 UTC after offset-conversion from +05:00 — independent of America/Los_Angeles being the site timezone'
            );
        } finally {
            update_option('timezone_string', $originalTimezone);
            update_option('gmt_offset', $originalGmtOffset);
        }
    }

    /**
     * Spec: Cases/Functional/coupons.md [22]
     *   "Creating a coupon triggers a \"coupon created\" notification that internal listeners can react to."
     */
    public function creatingCouponTriggersCouponCreatedNotificationThatInternalListenersCanReactTo(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $observed = ['fired' => false, 'payload' => null];
        $listener = static function ($payload) use (&$observed) {
            $observed['fired']   = true;
            $observed['payload'] = $payload;
        };
        add_action('fluent_cart/coupon_created', $listener, 10, 1);

        try {
            [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
                'title' => 'Notify Created',
                'code'  => 'NOTIFY-CREATED',
            ]));

            Assert::assertSame(200, $status, 'create must return 200 so the listener gate is reached. Body: ' . wp_json_encode($body));
            Assert::assertTrue($observed['fired'], 'fluent_cart/coupon_created action must fire after a successful coupon create');
            Assert::assertIsArray($observed['payload'], 'listener payload must be an array carrying data + coupon');
            Assert::assertArrayHasKey('data', (array) $observed['payload'], 'listener payload must include the sanitised data key');
            Assert::assertArrayHasKey('coupon', (array) $observed['payload'], 'listener payload must include the created coupon key');

            $couponPayload = $observed['payload']['coupon'];
            $observedCouponId = $couponPayload instanceof Coupon
                ? (int) $couponPayload->id
                : (int) ($couponPayload['id'] ?? 0);
            Assert::assertGreaterThan(0, $observedCouponId, 'listener coupon payload id must be the new auto-generated id');

            $dataPayload = (array) $observed['payload']['data'];
            Assert::assertSame(
                'Notify Created',
                (string) ($dataPayload['title'] ?? ''),
                'listener data payload must carry the submitted title'
            );
            Assert::assertSame(
                'NOTIFY-CREATED',
                (string) ($dataPayload['code'] ?? ''),
                'listener data payload must carry the submitted code'
            );
        } finally {
            remove_action('fluent_cart/coupon_created', $listener, 10);
        }
    }

    /**
     * Spec: Cases/Functional/coupons.md [23]
     *   "Creating a coupon writes an entry in the activity log so admins can see when and by whom it was created."
     */
    public function creatingCouponWritesEntryInActivityLog(FunctionalTester $I): void
    {
        $userId = $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'title' => 'Activity Created',
            'code'  => 'ACTIVITY-CREATED',
        ]));

        Assert::assertSame(200, $status, 'create must return 200 so the activity-log branch runs. Body: ' . wp_json_encode($body));

        $createdId = (int) Coupon::query()->where('code', 'ACTIVITY-CREATED')->value('id');
        Assert::assertGreaterThan(0, $createdId, 'precondition: created coupon must have an id');

        $logs = Activity::query()
            ->where('module_type', Coupon::class)
            ->where('module_id', $createdId)
            ->get()
            ->toArray();

        Assert::assertNotEmpty($logs, 'fct_activity must contain at least one row for the newly-created coupon');
        $titles = array_map(static function ($row) { return (string) ($row['title'] ?? ''); }, $logs);
        Assert::assertContains('Coupon Created', $titles, 'activity log must include a "Coupon Created" title row');

        $createdRow = null;
        foreach ($logs as $row) {
            if ((string) ($row['title'] ?? '') === 'Coupon Created') {
                $createdRow = $row;
                break;
            }
        }
        Assert::assertNotNull($createdRow, 'must find the Coupon Created activity row');
        Assert::assertSame((int) $userId, (int) $createdRow['user_id'], 'activity row must record the acting admin user_id');
        Assert::assertNotEmpty((string) $createdRow['content'], 'activity row content (who/what) must be non-empty');

        $createdAtTs = strtotime((string) ($createdRow['created_at'] ?? ''));
        Assert::assertNotFalse($createdAtTs, 'activity row created_at must parse as a valid timestamp');
        $now = time();
        Assert::assertGreaterThanOrEqual(
            $now - 120,
            $createdAtTs,
            'activity row created_at must be no older than two minutes ago'
        );
        Assert::assertLessThanOrEqual(
            $now,
            $createdAtTs,
            'activity row created_at must NOT be in the future'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [103]
     *   "Creating a coupon with a code that contains punctuation or other non-alphanumeric characters preserves the code exactly as entered when the coupon is read back."
     */
    public function creatingCouponWithCodeContainingPunctuationPreservesCodeExactly(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $code = 'HOLI-2025_GIFT@99!';
        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'title' => 'Punctuation Code',
            'code'  => $code,
        ]));

        Assert::assertSame(200, $status, 'create with punctuation code must return 200. Body: ' . wp_json_encode($body));

        $persistedCode = (string) Coupon::query()->where('id', '>', 0)->orderBy('id', 'desc')->value('code');
        Assert::assertSame(
            $code,
            $persistedCode,
            'punctuation-bearing code must be preserved verbatim in fct_coupons.code (no stripping of - _ @ ! digits)'
        );

        $createdId = (int) Coupon::query()->where('code', $code)->value('id');
        Assert::assertGreaterThan(0, $createdId, 'precondition: created coupon must be findable by its punctuation code');

        [, $viewBody] = $I->get('/fluent-cart/v2/coupons/' . $createdId);
        Assert::assertSame(
            $code,
            (string) ($viewBody['coupon']['code'] ?? ''),
            'read-back via /coupons/{id} must echo the punctuation-bearing code verbatim (no transit-time mutation)'
        );

        [, $listBody] = $I->get('/fluent-cart/v2/coupons');
        $listCodes = array_map(static function ($row) { return (string) ($row['code'] ?? ''); }, (array) ($listBody['coupons']['data'] ?? []));
        Assert::assertContains(
            $code,
            $listCodes,
            'read-back via /coupons listing must echo the punctuation-bearing code verbatim'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [104]
     *   "Admin can create a free-shipping coupon, and reading the coupon back confirms it is a free-shipping coupon."
     */
    public function adminCanCreateFreeShippingCouponAndReadBackConfirmsType(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'title' => 'Free Shipping Promo',
            'code'  => 'FREE-SHIP',
            'type'  => 'free_shipping',
        ]));

        Assert::assertSame(200, $status, 'create free_shipping coupon must return 200. Body: ' . wp_json_encode($body));

        $coupon = Coupon::query()->where('code', 'FREE-SHIP')->first();
        Assert::assertNotNull($coupon, 'fct_coupons row must exist for the free-shipping coupon');
        Assert::assertSame('free_shipping', (string) $coupon->type, 'persisted type must be free_shipping');

        [, $viewBody] = $I->get('/fluent-cart/v2/coupons/' . (int) $coupon->id);
        Assert::assertSame(
            'free_shipping',
            (string) ($viewBody['coupon']['type'] ?? ''),
            'read-back via /coupons/{id} must surface type=free_shipping'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [105]
     *   "Admin can create a buy-X-get-Y coupon, and reading the coupon back confirms it is a buy-X-get-Y coupon."
     */
    public function adminCanCreateBuyXGetYCouponAndReadBackConfirmsType(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'title' => 'BOGO Promo',
            'code'  => 'BUY-X-GET-Y',
            'type'  => 'buy_x_get_y',
        ]));

        Assert::assertSame(200, $status, 'create buy_x_get_y coupon must return 200. Body: ' . wp_json_encode($body));

        $coupon = Coupon::query()->where('code', 'BUY-X-GET-Y')->first();
        Assert::assertNotNull($coupon, 'fct_coupons row must exist for the buy-X-get-Y coupon');
        Assert::assertSame('buy_x_get_y', (string) $coupon->type, 'persisted type must be buy_x_get_y');

        [, $viewBody] = $I->get('/fluent-cart/v2/coupons/' . (int) $coupon->id);
        Assert::assertSame(
            'buy_x_get_y',
            (string) ($viewBody['coupon']['type'] ?? ''),
            'read-back via /coupons/{id} must surface type=buy_x_get_y'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [106]
     *   "Creating a coupon with an end date but no start date is rejected with a clear error."
     */
    public function creatingCouponWithEndDateButNoStartDateIsRejected(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();

        $payload = $this->payload([
            'code'     => 'END-NO-START',
            'end_date' => '2025-12-31 23:59:59',
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $payload);

        Assert::assertNotSame(200, $status, 'create end-without-start must not return 200. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('start_date', (array) $body, 'validation payload must surface a start_date field error. Body: ' . wp_json_encode($body));
        // required_with, not the old required_if — the `required_if:end_date,!=,null`
        // form was never parsed by the validator (equality-only) and no-oped.
        Assert::assertArrayHasKey('required_with', (array) ($body['start_date'] ?? []), 'validation entry for start_date must surface the "required_with" rule key');
        Assert::assertNotEmpty((string) ($body['start_date']['required_with'] ?? ''), 'required_with-rule message for start_date must be non-empty');
    }

    /**
     * Spec: Cases/Functional/coupons.md [127]
     *   "Admin can create a coupon without schedule dates even when the form submits
     *    the empty schedule as explicit nulls."
     *
     * The admin form always sends both date keys — AddOrEditCoupon.vue's
     * validateDate() converts an empty schedule into start_date=null /
     * end_date=null rather than omitting the keys. The validator's presence
     * check (Arr::has) treats a null-valued key as present, so the rule chain
     * must tolerate the explicit null.
     */
    public function adminCanCreateCouponWhenFormSendsNullScheduleDates(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'title'      => 'Unscheduled Coupon',
            'code'       => 'NULL-SCHEDULE',
            'start_date' => null,
            'end_date'   => null,
        ]));

        Assert::assertSame(200, $status, 'create with null schedule dates must return 200 — the form sends explicit nulls for an empty schedule. Body: ' . wp_json_encode($body));

        $row = Coupon::query()->where('code', 'NULL-SCHEDULE')->first();
        Assert::assertNotNull($row, 'unscheduled create must persist a fct_coupons row');
        Assert::assertNull($row->start_date, 'unscheduled coupon must persist with a NULL start_date');
        Assert::assertNull($row->end_date, 'unscheduled coupon must persist with a NULL end_date');
    }

    /**
     * Spec: Cases/Functional/coupons.md [128]
     *   "Creating a coupon with a negative minimum spend amount is rejected with a clear error."
     */
    public function creatingCouponWithNegativeMinSpendAmountIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'NEG-MIN-SPEND',
            'conditions' => ['min_purchase_amount' => -100],
        ]));

        Assert::assertNotSame(200, $status, 'create with negative min spend must not return 200. Body: ' . wp_json_encode($body));

        Assert::assertNull(
            Coupon::query()->where('code', 'NEG-MIN-SPEND')->first(),
            'rejected negative-min-spend submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [129]
     *   "Creating a coupon with a negative maximum spend amount is rejected with a clear error."
     */
    public function creatingCouponWithNegativeMaxSpendAmountIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'NEG-MAX-SPEND',
            'conditions' => ['max_purchase_amount' => -50],
        ]));

        Assert::assertNotSame(200, $status, 'create with negative max spend must not return 200. Body: ' . wp_json_encode($body));

        Assert::assertNull(
            Coupon::query()->where('code', 'NEG-MAX-SPEND')->first(),
            'rejected negative-max-spend submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [130]
     *   "Creating a coupon whose minimum spend amount is greater than its maximum
     *    spend amount is rejected with a clear error."
     */
    public function creatingCouponWithMinSpendGreaterThanMaxSpendIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'MIN-OVER-MAX',
            'conditions' => [
                'min_purchase_amount' => 100,
                'max_purchase_amount' => 50,
            ],
        ]));

        Assert::assertNotSame(200, $status, 'create with min spend > max spend must not return 200. Body: ' . wp_json_encode($body));
        $bodyText = strtolower(json_encode((array) $body));
        Assert::assertStringContainsString(
            'max spend amount',
            $bodyText,
            'rejection error must explicitly mention the max spend amount. Body: ' . wp_json_encode($body)
        );

        Assert::assertNull(
            Coupon::query()->where('code', 'MIN-OVER-MAX')->first(),
            'rejected min>max submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [131]
     *   "Creating a coupon with a positive minimum spend amount and a zero (no-limit)
     *    maximum spend amount succeeds."
     *
     * Zero means "no max limit" (the form's placeholder says so), so min > 0 with
     * max = 0 must NOT trip the min<=max cross-check.
     */
    public function creatingCouponWithPositiveMinSpendAndZeroMaxSpendSucceeds(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'MIN-ONLY',
            'conditions' => [
                'min_purchase_amount' => 100,
                'max_purchase_amount' => 0,
            ],
        ]));

        Assert::assertSame(200, $status, 'create with min spend and no-limit (0) max spend must return 200. Body: ' . wp_json_encode($body));

        Assert::assertNotNull(
            Coupon::query()->where('code', 'MIN-ONLY')->first(),
            'min-only submission must persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [109]
     *   "Admin can save a coupon as stackable, and reading the coupon back confirms it is stackable."
     */
    public function adminCanSaveCouponAsStackableAndReadBackConfirms(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'      => 'STACKABLE-YES',
            'stackable' => 'yes',
        ]));

        Assert::assertSame(200, $status, 'create stackable=yes must return 200. Body: ' . wp_json_encode($body));

        $coupon = Coupon::query()->where('code', 'STACKABLE-YES')->first();
        Assert::assertNotNull($coupon, 'fct_coupons row must exist for the stackable coupon');
        Assert::assertSame('yes', (string) $coupon->stackable, 'persisted stackable must be "yes"');

        [, $viewBody] = $I->get('/fluent-cart/v2/coupons/' . (int) $coupon->id);
        Assert::assertSame(
            'yes',
            (string) ($viewBody['coupon']['stackable'] ?? ''),
            'read-back via /coupons/{id} must surface stackable=yes'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [110]
     *   "Admin can save a coupon as non-stackable, and reading the coupon back confirms it is non-stackable."
     */
    public function adminCanSaveCouponAsNonStackableAndReadBackConfirms(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'      => 'STACKABLE-NO',
            'stackable' => 'no',
        ]));

        Assert::assertSame(200, $status, 'create stackable=no must return 200. Body: ' . wp_json_encode($body));

        $coupon = Coupon::query()->where('code', 'STACKABLE-NO')->first();
        Assert::assertNotNull($coupon, 'fct_coupons row must exist for the non-stackable coupon');
        Assert::assertSame('no', (string) $coupon->stackable, 'persisted stackable must be "no"');

        [, $viewBody] = $I->get('/fluent-cart/v2/coupons/' . (int) $coupon->id);
        Assert::assertSame(
            'no',
            (string) ($viewBody['coupon']['stackable'] ?? ''),
            'read-back via /coupons/{id} must surface stackable=no'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [113]
     *   "Admin can create a coupon flagged as recurring so it applies to subsequent renewals, and reading the coupon back confirms the recurring flag."
     */
    public function adminCanCreateCouponFlaggedAsRecurringAndReadBackConfirmsFlag(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'RECURRING-YES',
            'conditions' => [
                'is_recurring' => 'yes',
            ],
        ]));

        Assert::assertSame(200, $status, 'create recurring coupon must return 200. Body: ' . wp_json_encode($body));

        $coupon = Coupon::query()->where('code', 'RECURRING-YES')->first();
        Assert::assertNotNull($coupon, 'fct_coupons row must exist for the recurring coupon');
        $conditions = (array) $coupon->conditions;
        Assert::assertSame(
            'yes',
            (string) ($conditions['is_recurring'] ?? ''),
            'persisted conditions.is_recurring must be "yes"'
        );

        [, $viewBody] = $I->get('/fluent-cart/v2/coupons/' . (int) $coupon->id);
        $viewConditions = (array) ($viewBody['coupon']['conditions'] ?? []);
        Assert::assertSame(
            'yes',
            (string) ($viewConditions['is_recurring'] ?? ''),
            'read-back via /coupons/{id} must surface conditions.is_recurring=yes'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [114]
     *   "Admin can create a coupon with a maximum discount cap, and reading the coupon back returns the same cap value."
     */
    public function adminCanCreateCouponWithMaxDiscountCapAndReadBackReturnsSameValue(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'MAX-CAP',
            'type'       => 'percentage',
            'amount'     => 25,
            'conditions' => [
                'max_discount_amount' => 50.00,
            ],
        ]));

        Assert::assertSame(200, $status, 'create with max_discount_amount must return 200. Body: ' . wp_json_encode($body));

        $coupon = Coupon::query()->where('code', 'MAX-CAP')->first();
        Assert::assertNotNull($coupon, 'fct_coupons row must exist for the max-cap coupon');
        $conditions = (array) $coupon->conditions;
        Assert::assertSame(
            5000,
            (int) ($conditions['max_discount_amount'] ?? 0),
            'persisted conditions.max_discount_amount must be 5000 cents (Helper::toCent(50.00))'
        );

        [, $viewBody] = $I->get('/fluent-cart/v2/coupons/' . (int) $coupon->id);
        $viewConditions = (array) ($viewBody['coupon']['conditions'] ?? []);
        Assert::assertSame(
            5000,
            (int) ($viewConditions['max_discount_amount'] ?? 0),
            'read-back must echo the same cents value (no double-conversion on read)'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [135]
     *   "Creating a coupon whose code differs from an existing code only by surrounding whitespace is rejected as a duplicate, without leaking SQL."
     *
     * The uniqueness closure queried the RAW submitted value while sanitize()
     * trims afterwards — so " TAKEN" passed the duplicate check, was trimmed to
     * "TAKEN", and detonated on the fct_coupons UNIQUE index with a raw wpdb
     * error page (leading space matters to MySQL VARCHAR comparison; trailing
     * does not). Pasting codes from a spreadsheet does this routinely.
     */
    public function creatingCouponWithLeadingSpaceDuplicateCodeIsRejectedCleanly(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new CouponFactory())->create(['title' => 'Existing', 'code' => 'SPACE-TAKEN']);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'title' => 'Whitespace Duplicate',
            'code'  => ' SPACE-TAKEN',
        ]));

        Assert::assertNotSame(200, $status, 'leading-space duplicate code must not return 200. Body: ' . wp_json_encode($body));
        $rawBody = strtolower(wp_json_encode($body));
        Assert::assertStringNotContainsString('insert into', $rawBody, 'the response must not leak raw SQL');
        Assert::assertStringNotContainsString('fct_coupons', $rawBody, 'the response must not leak the coupons table name');
        Assert::assertArrayHasKey('code', (array) $body, 'validation payload must surface a code field error. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'already in use',
            strtolower(json_encode((array) ($body['code'] ?? []))),
            'the rejection must be the normal duplicate-code validation message. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            1,
            Coupon::query()->where('code', 'SPACE-TAKEN')->count(),
            'the whitespace duplicate must NOT create a second row for the same trimmed code'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [136]
     *   "Creating a coupon with surrounding whitespace around a unique code succeeds and stores the trimmed code."
     *
     * Pins the sanitize contract the duplicate check must mirror: what is
     * stored is the trimmed code, so the uniqueness query must compare the
     * trimmed value too.
     */
    public function creatingCouponWithLeadingSpaceUniqueCodeStoresTrimmedCode(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'title' => 'Whitespace Unique',
            'code'  => ' TRIMMED-OK ',
        ]));

        Assert::assertSame(200, $status, 'whitespace around a unique code must not block creation. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            1,
            Coupon::query()->where('code', 'TRIMMED-OK')->count(),
            'the stored code must be the trimmed value'
        );
        Assert::assertSame(
            0,
            Coupon::query()->where('code', ' TRIMMED-OK ')->count(),
            'no row may persist the untrimmed code'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [138]
     *   "Creating a coupon with an unparseable start date string alongside a valid end date is rejected with a validation error, not a 500 that leaks the server file path."
     *
     * The end_date closure compares strtotime() timestamps; 'not a date'
     * evaluates to false (0), which sits below any real end date, so validation
     * passed and DateTime::anyTimeToGmt() threw — a plugin_exception whose
     * data.file disclosed the absolute DateTime.php path. The PR #2535 closure
     * checks is_string only; garbage strings ARE strings.
     */
    public function creatingCouponWithUnparseableStartDateIsRejectedCleanly(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'BAD-START-PAIR',
            'start_date' => 'not a date',
            'end_date'   => '2027-01-01 00:00:00',
        ]));

        Assert::assertNotSame(200, $status, 'unparseable start_date must not create a coupon. Body: ' . wp_json_encode($body));
        $rawBody = strtolower(wp_json_encode($body));
        Assert::assertStringNotContainsString('datetime.php', $rawBody, 'the response must not disclose the DateTime.php server path');
        Assert::assertStringNotContainsString('plugin_exception', $rawBody, 'the rejection must be a validation envelope, not an uncaught exception');
        Assert::assertArrayHasKey('start_date', (array) $body, 'validation payload must surface a start_date field error. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'valid date',
            strtolower(json_encode((array) ($body['start_date'] ?? []))),
            'the start_date error must say the value is not a valid date. Body: ' . wp_json_encode($body)
        );
        Assert::assertNull(
            Coupon::query()->where('code', 'BAD-START-PAIR')->first(),
            'the rejected submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [139]
     *   "Creating a coupon with an unparseable start date string and no end date is rejected with a validation error, not a 500."
     *
     * Without an end date the comparison closure never runs at all, so the
     * garbage string went straight to DateTime::anyTimeToGmt() in the
     * controller ('!empty($data[start_date])' guards empties, not garbage).
     */
    public function creatingCouponWithUnparseableStartDateAloneIsRejectedCleanly(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'BAD-START-ALONE',
            'start_date' => 'definitely not a date',
        ]));

        Assert::assertNotSame(200, $status, 'unparseable start_date without end_date must not create a coupon. Body: ' . wp_json_encode($body));
        $rawBody = strtolower(wp_json_encode($body));
        Assert::assertStringNotContainsString('datetime.php', $rawBody, 'the response must not disclose the DateTime.php server path');
        Assert::assertStringNotContainsString('plugin_exception', $rawBody, 'the rejection must be a validation envelope, not an uncaught exception');
        Assert::assertArrayHasKey('start_date', (array) $body, 'validation payload must surface a start_date field error. Body: ' . wp_json_encode($body));
        Assert::assertNull(
            Coupon::query()->where('code', 'BAD-START-ALONE')->first(),
            'the rejected submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [140]
     *   "Creating a coupon with an unparseable end date string is rejected as an invalid date, not with the misleading end-after-start message."
     *
     * strtotime('garbage') → false → 0 in the comparison, so a garbage end
     * date was reported as "end date must be after the start date" — and with
     * a pre-1970 start date (negative timestamp) it passed validation entirely
     * and crashed in the controller's GMT conversion.
     */
    public function creatingCouponWithUnparseableEndDateIsRejectedAsInvalidDate(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'BAD-END-DATE',
            'start_date' => '2026-08-01 00:00:00',
            'end_date'   => 'not a real date',
        ]));

        Assert::assertNotSame(200, $status, 'unparseable end_date must not create a coupon. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('end_date', (array) $body, 'validation payload must surface an end_date field error. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'valid date',
            strtolower(json_encode((array) ($body['end_date'] ?? []))),
            'the end_date error must say the value is not a valid date, not the misleading end-after-start message. Body: ' . wp_json_encode($body)
        );
        Assert::assertNull(
            Coupon::query()->where('code', 'BAD-END-DATE')->first(),
            'the rejected submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [142]
     *   "Creating a coupon with a negative maximum-uses limit is rejected with a validation error."
     *
     * conditions.max_uses was nullable|numeric with no min:0, so -5 persisted —
     * and because DiscountService's gate is `$useCount && $maxUses && $useCount
     * >= $maxUses`, a negative (truthy) limit permanently reports "max uses
     * exceeded" the moment use_count reaches 1. A silent trap, API-only (the
     * admin form input blocks negatives).
     */
    public function creatingCouponWithNegativeMaxUsesIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'NEG-MAX-USES',
            'conditions' => ['max_uses' => -5],
        ]));

        Assert::assertNotSame(200, $status, 'negative max_uses must not create a coupon. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey(
            'conditions.max_uses',
            (array) $body,
            'validation payload must surface a conditions.max_uses field error. Body: ' . wp_json_encode($body)
        );
        Assert::assertNull(
            Coupon::query()->where('code', 'NEG-MAX-USES')->first(),
            'the rejected submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [143]
     *   "Creating a coupon with a negative per-customer usage limit is rejected with a validation error."
     *
     * Identical gap and identical trap on the per-customer leg: a negative
     * (truthy) max_per_customer forces the login requirement and the customer's
     * usage count is always >= a negative limit once they have used it once.
     */
    public function creatingCouponWithNegativeMaxPerCustomerIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'NEG-PER-CUSTOMER',
            'conditions' => ['max_per_customer' => -3],
        ]));

        Assert::assertNotSame(200, $status, 'negative max_per_customer must not create a coupon. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey(
            'conditions.max_per_customer',
            (array) $body,
            'validation payload must surface a conditions.max_per_customer field error. Body: ' . wp_json_encode($body)
        );
        Assert::assertNull(
            Coupon::query()->where('code', 'NEG-PER-CUSTOMER')->first(),
            'the rejected submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [144]
     *   "Creating a coupon with explicit zero usage limits (meaning unlimited) still succeeds."
     *
     * Control for cases 142-143: 0 means "unlimited" for both limits (the gates
     * are falsy-guarded), so min:0 must not reject it.
     */
    public function creatingCouponWithZeroUsageLimitsSucceeds(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'       => 'ZERO-LIMITS-OK',
            'conditions' => ['max_uses' => 0, 'max_per_customer' => 0],
        ]));

        Assert::assertSame(200, $status, 'explicit zero limits mean unlimited and must be accepted. Body: ' . wp_json_encode($body));
        $row = Coupon::query()->where('code', 'ZERO-LIMITS-OK')->first();
        Assert::assertNotNull($row, 'the coupon must persist');
        Assert::assertSame(0, (int) ($row->conditions['max_uses'] ?? -1), 'stored max_uses must be 0 (unlimited)');
        Assert::assertSame(0, (int) ($row->conditions['max_per_customer'] ?? -1), 'stored max_per_customer must be 0 (unlimited)');
    }

    /**
     * Spec: Cases/Functional/coupons.md [145]
     *   "Creating a coupon with a stackable value other than yes/no is rejected with a validation error."
     *
     * stackable was required|sanitizeText|maxLength:50 — any string persisted.
     * Worse than silent "no": the admin path checks `=== 'no'` while the
     * storefront checks `=== 'yes'`, so a bogus value means STACKABLE to one
     * calculator and NON-STACKABLE to the other. status and type already use
     * in: constraints; these two flags were overlooked.
     */
    public function creatingCouponWithBogusStackableValueIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'      => 'BOGUS-STACKABLE',
            'stackable' => 'maybe',
        ]));

        Assert::assertNotSame(200, $status, 'stackable=maybe must not create a coupon. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('stackable', (array) $body, 'validation payload must surface a stackable field error. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('in', (array) ($body['stackable'] ?? []), 'validation entry for stackable must surface the "in" rule key');
        Assert::assertNull(
            Coupon::query()->where('code', 'BOGUS-STACKABLE')->first(),
            'the rejected submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [146]
     *   "Creating a coupon with a show_on_checkout value other than yes/no is rejected with a validation error."
     */
    public function creatingCouponWithBogusShowOnCheckoutValueIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'             => 'BOGUS-SHOW',
            'show_on_checkout' => 'banana',
        ]));

        Assert::assertNotSame(200, $status, 'show_on_checkout=banana must not create a coupon. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('show_on_checkout', (array) $body, 'validation payload must surface a show_on_checkout field error. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('in', (array) ($body['show_on_checkout'] ?? []), 'validation entry for show_on_checkout must surface the "in" rule key');
        Assert::assertNull(
            Coupon::query()->where('code', 'BOGUS-SHOW')->first(),
            'the rejected submission must NOT persist a fct_coupons row'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [147]
     *   "Creating a coupon with both flags explicitly set to no still succeeds."
     *
     * Control for cases 145-146: the enum must accept both legitimate values —
     * 'yes' is exercised by every other create test via the default payload.
     */
    public function creatingCouponWithBothFlagsNoSucceeds(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'             => 'FLAGS-NO-OK',
            'stackable'        => 'no',
            'show_on_checkout' => 'no',
        ]));

        Assert::assertSame(200, $status, 'both flags "no" must be accepted. Body: ' . wp_json_encode($body));
        $row = Coupon::query()->where('code', 'FLAGS-NO-OK')->first();
        Assert::assertNotNull($row, 'the coupon must persist');
        Assert::assertSame('no', (string) $row->stackable, 'stored stackable must be no');
        Assert::assertSame('no', (string) $row->show_on_checkout, 'stored show_on_checkout must be no');
    }

    /**
     * Spec: Cases/Functional/coupons.md [150]
     *   "Creating a coupon without a notes field succeeds and stores empty notes."
     *
     * The admin form always sends notes: '', so only a direct REST client omits
     * it. That NULL hit the NOT NULL column and returned a 500 leaking the SQL.
     */
    public function creatingCouponWithoutNotesSucceeds(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $payload = $this->payload(['code' => 'NO-NOTES', 'type' => 'percentage']);
        unset($payload['notes']);

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $payload);

        Assert::assertSame(200, $status, 'create without notes must return 200, not a 500. Body: ' . wp_json_encode($body));
        Assert::assertSame('Coupon created successfully!', (string) ($body['message'] ?? ''), 'response must be the create success JSON. Body: ' . wp_json_encode($body));
        $row = Coupon::query()->where('code', 'NO-NOTES')->first();
        Assert::assertNotNull($row, 'the coupon must persist');
        Assert::assertSame('', (string) $row->notes, 'omitted notes must be stored as an empty string');
    }

    /**
     * Spec: Cases/Functional/coupons.md [151]
     *   "Creating a coupon with notes explicitly null succeeds and stores empty notes."
     */
    public function creatingCouponWithNullNotesSucceeds(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/coupons', $this->payload([
            'code'  => 'NULL-NOTES',
            'notes' => null,
        ]));

        Assert::assertSame(200, $status, 'create with notes: null must return 200, not a 500. Body: ' . wp_json_encode($body));
        $row = Coupon::query()->where('code', 'NULL-NOTES')->first();
        Assert::assertNotNull($row, 'the coupon must persist');
        Assert::assertSame('', (string) $row->notes, 'null notes must be stored as an empty string');
    }
}
