<?php

namespace Tests\Functional\Rest\Coupons;

use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Coupon;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/coupons.md → "Updating a coupon" section, items 24-33.
 *
 * Route: PUT /fluent-cart/v2/coupons/{id} (meta: coupons/manage)
 * Controller: CouponsController::update — validates via CouponRequest, normalises
 * start_date/end_date to GMT, dispatches `fluent_cart/coupon_updated`, writes
 * fct_activity log row, returns sendSuccess(['data' => Coupon, 'message' => '...']).
 */
class UpdateCouponCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Build a fully-populated update payload that satisfies CouponRequest::rules()
     * (title, code, type, amount, status, stackable, show_on_checkout all required).
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title'            => 'Renamed Coupon',
            'code'             => 'RENAMED-CODE',
            'type'             => 'fixed',
            'amount'           => 12.34,
            'status'           => 'active',
            'stackable'        => 'yes',
            'show_on_checkout' => 'yes',
            'priority'         => 1,
            'notes'            => '',
            'conditions'       => [],
        ], $overrides);
    }

    /**
     * Spec: Cases/Functional/coupons.md [24]
     *   "Admin can rename a coupon."
     */
    public function adminCanRenameCoupon(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $coupon = (new CouponFactory())->create(['title' => 'Original Title', 'code' => 'RENAME-ME']);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . (int) $coupon->id,
            $this->payload(['title' => 'New Title', 'code' => 'RENAME-ME'])
        );

        Assert::assertSame(200, $status, 'rename must return 200. Body: ' . wp_json_encode($body));

        $persistedTitle = (string) Coupon::query()->where('id', (int) $coupon->id)->value('title');
        Assert::assertSame('New Title', $persistedTitle, 'rename must persist the new title to fct_coupons.title');
    }

    /**
     * Spec: Cases/Functional/coupons.md [25]
     *   "Admin can change the discount amount of an existing coupon."
     */
    public function adminCanChangeDiscountAmountOfExistingCoupon(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $coupon = (new CouponFactory())->create([
            'title'  => 'Amount Change Coupon',
            'code'   => 'AMOUNT-CHANGE',
            'type'   => 'fixed',
            'amount' => 1000,
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . (int) $coupon->id,
            $this->payload(['title' => 'Amount Change Coupon', 'code' => 'AMOUNT-CHANGE', 'type' => 'fixed', 'amount' => 25])
        );

        Assert::assertSame(200, $status, 'change-amount must return 200. Body: ' . wp_json_encode($body));

        global $wpdb;
        $persistedAmount = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT amount FROM {$wpdb->prefix}fct_coupons WHERE id = %d",
            (int) $coupon->id
        ));
        Assert::assertSame(
            2500.0,
            $persistedAmount,
            'fixed-type amount must be persisted in cents (Helper::toCent(25)=2500). Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [26]
     *   "Admin can change a coupon's status from active to disabled."
     */
    public function adminCanChangeCouponStatusFromActiveToDisabled(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $coupon = (new CouponFactory())->create([
            'title'  => 'Toggle Status Coupon',
            'code'   => 'TOGGLE-STATUS',
            'status' => 'active',
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . (int) $coupon->id,
            $this->payload(['title' => 'Toggle Status Coupon', 'code' => 'TOGGLE-STATUS', 'status' => 'disabled'])
        );

        Assert::assertSame(200, $status, 'change-status must return 200. Body: ' . wp_json_encode($body));

        $persistedStatus = (string) Coupon::query()->where('id', (int) $coupon->id)->value('status');
        Assert::assertSame('disabled', $persistedStatus, 'change-status must persist disabled to fct_coupons.status');
    }

    /**
     * Spec: Cases/Functional/coupons.md [27]
     *   "Trying to set a coupon to an unknown status is rejected with a clear error."
     */
    public function tryingToSetCouponToUnknownStatusIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $coupon = (new CouponFactory())->create(['title' => 'Status Validate', 'code' => 'STATUS-VALIDATE', 'status' => 'active']);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . (int) $coupon->id,
            $this->payload(['title' => 'Status Validate', 'code' => 'STATUS-VALIDATE', 'status' => 'unknown_status'])
        );

        Assert::assertNotSame(200, $status, 'unknown-status update must not succeed. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('status', (array) $body, 'validation payload must key the failed rule by field name (status). Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('in', (array) ($body['status'] ?? []), 'validation entry for status must surface the "in" rule key. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty((string) ($body['status']['in'] ?? ''), 'in-rule message for status must be non-empty');

        $persistedStatus = (string) Coupon::query()->where('id', (int) $coupon->id)->value('status');
        Assert::assertSame('active', $persistedStatus, 'rejected update must NOT persist the unknown status');
    }

    /**
     * Spec: Cases/Functional/coupons.md [28]
     *   "Updating a coupon to use a code already used by another coupon is rejected with a clear error."
     */
    public function updatingCouponToUseCodeAlreadyUsedByAnotherCouponIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new CouponFactory())->create(['title' => 'Other Coupon', 'code' => 'OTHER-CODE']);
        $target = (new CouponFactory())->create(['title' => 'Target Coupon', 'code' => 'TARGET-CODE']);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . (int) $target->id,
            $this->payload(['title' => 'Target Coupon', 'code' => 'OTHER-CODE'])
        );

        Assert::assertNotSame(200, $status, 'duplicate-code update must not succeed. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('code', (array) $body, 'validation payload must surface a code field error. Body: ' . wp_json_encode($body));
        $codeErrors = (array) ($body['code'] ?? []);
        $allErrorText = strtolower(json_encode($codeErrors));
        Assert::assertStringContainsString(
            'already in use',
            $allErrorText,
            'duplicate-code error must explicitly say the code is already in use. Body: ' . wp_json_encode($body)
        );

        $persistedCode = (string) Coupon::query()->where('id', (int) $target->id)->value('code');
        Assert::assertSame('TARGET-CODE', $persistedCode, 'rejected update must NOT persist the conflicting code');
    }

    /**
     * Spec: Cases/Functional/coupons.md [137]
     *   "Updating a coupon to a code that differs from another coupon's code only by surrounding whitespace is rejected as a duplicate, without leaking SQL."
     *
     * Same ordering bug as case 135, on the update leg of the shared closure:
     * the raw " OTHER" passes the uniqueness query, sanitize() trims it, and
     * the UPDATE hits the fct_coupons UNIQUE index uncaught.
     */
    public function updatingCouponWithLeadingSpaceDuplicateCodeIsRejectedCleanly(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new CouponFactory())->create(['title' => 'Other Coupon', 'code' => 'WS-OTHER']);
        $target = (new CouponFactory())->create(['title' => 'Target Coupon', 'code' => 'WS-TARGET']);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . (int) $target->id,
            $this->payload(['title' => 'Target Coupon', 'code' => ' WS-OTHER'])
        );

        Assert::assertNotSame(200, $status, 'leading-space duplicate code must not update. Body: ' . wp_json_encode($body));
        $rawBody = strtolower(wp_json_encode($body));
        Assert::assertStringNotContainsString('fct_coupons', $rawBody, 'the response must not leak the coupons table name');
        Assert::assertStringContainsString(
            'already in use',
            strtolower(json_encode((array) ($body['code'] ?? []))),
            'the rejection must be the normal duplicate-code validation message. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            'WS-TARGET',
            (string) Coupon::query()->where('id', (int) $target->id)->value('code'),
            'the rejected update must NOT persist the conflicting code'
        );
        Assert::assertSame(
            1,
            Coupon::query()->where('code', 'WS-OTHER')->count(),
            'the other coupon must remain the only row with its code'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [29]
     *   "Saving a coupon without changing its code does not falsely report the code as duplicated."
     */
    public function savingCouponWithoutChangingItsCodeDoesNotFalselyReportCodeDuplicated(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $coupon = (new CouponFactory())->create(['title' => 'Self Code', 'code' => 'SELF-CODE']);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . (int) $coupon->id,
            $this->payload(['title' => 'Self Code Renamed', 'code' => 'SELF-CODE'])
        );

        Assert::assertSame(200, $status, 'self-code update must succeed (uniqueness check must exclude same row). Body: ' . wp_json_encode($body));

        $persisted = (array) Coupon::query()->where('id', (int) $coupon->id)->first()->toArray();
        Assert::assertSame('SELF-CODE', (string) $persisted['code'], 'self-code update must keep the original code');
        Assert::assertSame('Self Code Renamed', (string) $persisted['title'], 'self-code update must persist the new title');
    }

    /**
     * Spec: Cases/Functional/coupons.md [30]
     *   "Updating a coupon so its end date is earlier than its start date is rejected with a clear error."
     */
    public function updatingCouponSoEndDateIsEarlierThanStartDateIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $coupon = (new CouponFactory())->create(['title' => 'Date Range Coupon', 'code' => 'DATE-RANGE']);
        $startBefore = (string) Coupon::query()->where('id', (int) $coupon->id)->value('start_date');
        $endBefore   = (string) Coupon::query()->where('id', (int) $coupon->id)->value('end_date');

        [$status, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . (int) $coupon->id,
            $this->payload([
                'title'      => 'Date Range Coupon',
                'code'       => 'DATE-RANGE',
                'start_date' => '2024-12-31 00:00:00',
                'end_date'   => '2024-01-01 00:00:00',
            ])
        );

        Assert::assertNotSame(200, $status, 'end-before-start update must not succeed. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('end_date', (array) $body, 'validation payload must surface an end_date field error. Body: ' . wp_json_encode($body));
        $endDateErrorText = strtolower(json_encode((array) ($body['end_date'] ?? [])));
        Assert::assertStringContainsString(
            'after the start date',
            $endDateErrorText,
            'end-before-start error must explicitly mention end-must-be-after-start. Body: ' . wp_json_encode($body)
        );

        $startAfter = (string) Coupon::query()->where('id', (int) $coupon->id)->value('start_date');
        $endAfter   = (string) Coupon::query()->where('id', (int) $coupon->id)->value('end_date');
        Assert::assertSame($startBefore, $startAfter, 'rejected end-before-start update must NOT mutate fct_coupons.start_date');
        Assert::assertSame($endBefore, $endAfter, 'rejected end-before-start update must NOT mutate fct_coupons.end_date');
    }

    /**
     * Spec: Cases/Functional/coupons.md [31]
     *   "Updating a coupon triggers a \"coupon updated\" notification that internal listeners can react to."
     */
    public function updatingCouponTriggersCouponUpdatedNotificationThatInternalListenersCanReactTo(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $coupon = (new CouponFactory())->create(['title' => 'Notify Coupon', 'code' => 'NOTIFY-COUPON']);

        $observed = ['fired' => false, 'payload' => null];
        $listener = static function ($payload) use (&$observed) {
            $observed['fired']   = true;
            $observed['payload'] = $payload;
        };
        add_action('fluent_cart/coupon_updated', $listener, 10, 1);

        try {
            [$status, $body] = $I->put(
                '/fluent-cart/v2/coupons/' . (int) $coupon->id,
                $this->payload(['title' => 'Notify Coupon Renamed', 'code' => 'NOTIFY-COUPON'])
            );

            Assert::assertSame(200, $status, 'update must return 200 so the listener gate is reached. Body: ' . wp_json_encode($body));
            Assert::assertTrue($observed['fired'], 'fluent_cart/coupon_updated action must fire after a successful coupon update');
            Assert::assertIsArray($observed['payload'], 'listener payload must be an array carrying data + coupon');
            Assert::assertArrayHasKey('data', (array) $observed['payload'], 'listener payload must include the sanitised data key');
            Assert::assertArrayHasKey('coupon', (array) $observed['payload'], 'listener payload must include the updated coupon key');

            $couponPayload = $observed['payload']['coupon'];
            $observedCouponId = $couponPayload instanceof Coupon
                ? (int) $couponPayload->id
                : (int) ($couponPayload['id'] ?? 0);
            Assert::assertSame(
                (int) $coupon->id,
                $observedCouponId,
                'listener coupon payload id must match the updated coupon id'
            );
            $dataPayload = (array) $observed['payload']['data'];
            Assert::assertSame(
                'Notify Coupon Renamed',
                (string) ($dataPayload['title'] ?? ''),
                'listener data payload must carry the new title submitted by the request'
            );
            Assert::assertSame(
                'NOTIFY-COUPON',
                (string) ($dataPayload['code'] ?? ''),
                'listener data payload must carry the (unchanged) code submitted by the request'
            );
        } finally {
            remove_action('fluent_cart/coupon_updated', $listener, 10);
        }
    }

    /**
     * Spec: Cases/Functional/coupons.md [32]
     *   "Updating a coupon writes an entry in the activity log so admins can see when and by whom it was changed."
     */
    public function updatingCouponWritesEntryInActivityLog(FunctionalTester $I): void
    {
        $userId = $I->loginAsSuperAdmin();
        $coupon = (new CouponFactory())->create(['title' => 'Activity Coupon', 'code' => 'ACTIVITY-COUPON']);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . (int) $coupon->id,
            $this->payload(['title' => 'Activity Coupon Renamed', 'code' => 'ACTIVITY-COUPON'])
        );

        Assert::assertSame(200, $status, 'update must return 200 so the activity-log branch runs. Body: ' . wp_json_encode($body));

        $logs = Activity::query()
            ->where('module_type', Coupon::class)
            ->where('module_id', (int) $coupon->id)
            ->get()
            ->toArray();

        Assert::assertNotEmpty($logs, 'fct_activity must contain at least one row for the updated coupon');
        $titles = array_map(static function ($row) { return (string) ($row['title'] ?? ''); }, $logs);
        Assert::assertContains('Coupon Updated', $titles, 'activity log must include a "Coupon Updated" title row');

        $updatedRow = null;
        foreach ($logs as $row) {
            if ((string) ($row['title'] ?? '') === 'Coupon Updated') {
                $updatedRow = $row;
                break;
            }
        }
        Assert::assertNotNull($updatedRow, 'must find the Coupon Updated activity row');
        Assert::assertSame((int) $userId, (int) $updatedRow['user_id'], 'activity row must record the acting admin user_id');
        Assert::assertNotEmpty((string) $updatedRow['content'], 'activity row content (who/what) must be non-empty');

        $createdAt = (string) ($updatedRow['created_at'] ?? '');
        Assert::assertNotEmpty($createdAt, 'activity row must record a created_at timestamp so admins can see when the change happened');
        $createdAtTs = strtotime($createdAt);
        Assert::assertNotFalse($createdAtTs, 'activity row created_at must parse as a valid timestamp ("' . $createdAt . '")');
        $now = time();
        Assert::assertGreaterThanOrEqual(
            $now - 120,
            $createdAtTs,
            'activity row created_at must be no older than two minutes ago (just after the dispatched update)'
        );
        Assert::assertLessThanOrEqual(
            $now,
            $createdAtTs,
            'activity row created_at must NOT be in the future relative to the test wall clock'
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [33]
     *   "Updating a coupon that does not exist returns a clear \"not found\" error."
     */
    public function updatingCouponThatDoesNotExistReturnsNotFoundError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $missingId = 999999;

        [$status, $body] = $I->put(
            '/fluent-cart/v2/coupons/' . $missingId,
            $this->payload(['title' => 'Ghost Coupon', 'code' => 'GHOST-CODE'])
        );

        Assert::assertSame(404, $status, 'update of a non-existent coupon must return 404 not-found. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            404,
            (int) ($body['code'] ?? 0),
            'not-found error body must surface code=404. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsStringIgnoringCase(
            'not found',
            (string) ($body['message'] ?? ''),
            'not-found error message must explicitly identify the coupon as not found. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/coupons.md [152]
     *   "Updating a coupon without a notes field keeps its existing notes."
     */
    public function updatingCouponWithoutNotesKeepsExistingNotes(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $coupon = (new CouponFactory())->create(['code' => 'KEEP-NOTES', 'notes' => 'Internal: partner promo']);

        $payload = $this->payload(['title' => 'Keep Notes', 'code' => 'KEEP-NOTES']);
        unset($payload['notes']);

        [$status, $body] = $I->put('/fluent-cart/v2/coupons/' . (int) $coupon->id, $payload);

        Assert::assertSame(200, $status, 'update without notes must return 200, not a 500. Body: ' . wp_json_encode($body));
        $row = Coupon::query()->find((int) $coupon->id);
        Assert::assertSame('Keep Notes', (string) $row->title, 'the rest of the update must still apply');
        Assert::assertSame('Internal: partner promo', (string) $row->notes, 'omitted notes must leave the stored notes untouched');
    }
}
