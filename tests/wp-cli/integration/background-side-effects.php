<?php
/**
 * Phase 14 side-effect chains and license expiry.
 */

return [
    [
        'id'            => 'automation-license-expiry',
        'name'          => 'License scheduler expires one exact owned license and repeats as a no-op',
        'kind'          => 'behavior',
        'known_failure' => false,
        'requires'      => 'pro',
        'phase'         => 14,
        'run'           => function () {
            $expiredEvents = 0;
            $captureExpired = function ($data) use (&$expiredEvents) {
                $expiredEvents++;
            };

            try {
                $generationClass = FcDomainFixture::licenseHandlerClass();
                $schedulerClass = 'FluentCartPro\\App\\Modules\\Licensing\\Hooks\\Handlers\\LicenseSchedulerHandler';
                $licenseClass = 'FluentCartPro\\App\\Modules\\Licensing\\Models\\License';
                if (
                    $generationClass === null
                    || !class_exists($schedulerClass)
                    || !class_exists($licenseClass)
                ) {
                    FcTest::fail('FluentCart Pro is expected active, but its licensing scheduler is not loaded.');
                    return;
                }

                $cutoff = date(
                    'Y-m-d H:i:s',
                    time() - DAY_IN_SECONDS
                        * FluentCartPro\App\Modules\Licensing\Services\LicenseHelper::getLicenseGracePeriodDays()
                );
                $foreign = $licenseClass::query()
                    ->whereIn('status', ['active', 'inactive'])
                    ->whereNotNull('expiration_date')
                    ->where('expiration_date', '!=', '0000-00-00 00:00:00')
                    ->where('expiration_date', '<=', $cutoff)
                    ->pluck('id')
                    ->toArray();
                if ($foreign !== []) {
                    throw new RuntimeException(
                        'Refusing license expiry; foreign candidate IDs: '
                        . wp_json_encode(array_map('intval', $foreign))
                    );
                }

                $product = FcDomainFixture::product([
                    'payment_type' => 'onetime',
                    'total_stock'  => 10,
                ]);
                $productId = (int) $product['post']->ID;
                $variationId = (int) $product['variation']->id;
                $order = FcDomainFixture::orderWithItem($productId, $variationId, 1);
                FcFixture::productMeta(
                    $productId,
                    'product',
                    'license_settings',
                    [
                        'enabled'    => 'yes',
                        'prefix'     => 'P14-',
                        'variations' => [[
                            'variation_id'     => $variationId,
                            'activation_limit' => 1,
                            'validity'         => ['unit' => 'lifetime', 'value' => 0],
                        ]],
                    ]
                );
                $generator = new $generationClass();
                $generator->maybeGenerateLicensesOnPurchaseSuccess(['order' => $order]);
                $licenses = FcDomainFixture::captureLicensesForOrder((int) $order->id);
                if (count($licenses) !== 1) {
                    throw new RuntimeException('Expected exactly one owned license before expiry.');
                }
                $license = $licenses[0];
                $license->status = 'active';
                $license->expiration_date = '2001-02-03 04:05:06';
                $license->save();

                $candidates = $licenseClass::query()
                    ->whereIn('status', ['active', 'inactive'])
                    ->whereNotNull('expiration_date')
                    ->where('expiration_date', '!=', '0000-00-00 00:00:00')
                    ->where('expiration_date', '<=', $cutoff)
                    ->pluck('id')
                    ->toArray();
                FcTest::assertSame(
                    [(int) $license->id],
                    array_map('intval', $candidates),
                    'license expiry predicate selects only the exact owned license'
                );

                add_action(
                    'fluent_cart/licensing/license_expired',
                    $captureExpired,
                    PHP_INT_MAX,
                    1
                );
                $scheduler = new $schedulerClass();
                $first = $scheduler->expireOldLicenses();
                $stored = $licenseClass::query()->find((int) $license->id);
                FcTest::assertSame(true, $first, 'first license expiry reports work');
                FcTest::assertSame(
                    'expired',
                    $stored ? (string) $stored->status : null,
                    'license scheduler persists terminal expired status'
                );
                FcTest::assertSame(1, $expiredEvents, 'license expiry hook dispatches once');

                $second = $scheduler->expireOldLicenses();
                FcTest::assertSame(false, $second, 'second license expiry pass is a no-op');
                FcTest::assertSame(1, $expiredEvents, 'second pass does not redispatch expiry');
                FcTest::assertSame([], FcTest::sentMails(), 'license expiry sends no mail');
                FcTest::assertSame([], FcTest::externalCalls(), 'license expiry makes no loopback');
            } finally {
                remove_action(
                    'fluent_cart/licensing/license_expired',
                    $captureExpired,
                    PHP_INT_MAX
                );
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
