<?php
/**
 * A self-set, unconfirmed account email must not claim a guest customer.
 *
 * WordPress applies POST /wp/v2/users/me email changes at once, with no inbox proof.
 * With verification "Not required", that address must not link the unlinked guest
 * row that holds it. Untouched accounts still link without friction.
 */

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Services\CustomerIdentity\EmailClaimService;
use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use FluentCart\App\Services\ProductReviewService;

$unconfirmedEmailWithSetting = function ($value, callable $callback) {
    $override = function ($settings) use ($value) {
        $settings = is_array($settings) ? $settings : [];
        if ($value === null) {
            unset($settings['require_customer_email_verification']);
        } else {
            $settings['require_customer_email_verification'] = $value;
        }
        return $settings;
    };
    add_filter('option_fluent_cart_store_settings', $override);
    add_filter('default_option_fluent_cart_store_settings', $override);
    StoreSettings::clearCache();
    CustomerResource::resetCurrentCustomerRuntimeCache();

    try {
        return $callback();
    } finally {
        remove_filter('option_fluent_cart_store_settings', $override);
        remove_filter('default_option_fluent_cart_store_settings', $override);
        StoreSettings::clearCache();
        CustomerResource::resetCurrentCustomerRuntimeCache();
    }
};

$unconfirmedEmailScenario = function (callable $callback) {
    $previousUser = get_current_user_id();
    $guestEmail = FcTest::uniq('fct-guest') . '@fixture.example.test';
    $userIds = [];
    $customerIds = [];

    try {
        $guest = Customer::query()->create([
            'email'      => $guestEmail,
            'first_name' => 'Guest',
            'last_name'  => 'Fixture',
            'user_id'    => null,
        ]);
        $customerIds[] = (int) $guest->id;
        // Guest history worth recovering; an empty guest row gets no confirmation offer.
        CustomerAddresses::query()->create([
            'customer_id' => (int) $guest->id,
            'is_primary'  => 1,
            'type'        => 'billing',
            'name'        => 'Guest Fixture',
            'address_1'   => '22 Checkout Lane',
            'city'        => 'Dhaka',
            'country'     => 'BD',
        ]);

        $makeUser = function ($email) use (&$userIds) {
            $userId = wp_insert_user([
                'user_login' => FcTest::uniq('fctuser'),
                'user_email' => $email,
                'user_pass'  => wp_generate_password(32),
                'role'       => 'subscriber',
            ]);
            if (is_wp_error($userId)) {
                throw new RuntimeException('User fixture create failed: ' . $userId->get_error_message());
            }
            $userIds[] = (int) $userId;
            return (int) $userId;
        };

        return $callback($guest, $guestEmail, $makeUser);
    } finally {
        wp_set_current_user($previousUser);
        CustomerResource::resetCurrentCustomerRuntimeCache();
        if ($userIds) {
            $customerIds = array_merge(
                $customerIds,
                Customer::query()->whereIn('user_id', $userIds)->pluck('id')->map('intval')->toArray()
            );
        }
        $customerIds = array_values(array_unique(array_filter($customerIds)));
        if ($customerIds) {
            CustomerAddresses::query()->whereIn('customer_id', $customerIds)->delete();
            Customer::query()->whereIn('id', $customerIds)->delete();
        }
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        foreach ($userIds as $userId) {
            wp_delete_user($userId);
        }
    }
};

$changeOwnEmailViaRest = function ($userId, $email) {
    wp_set_current_user($userId);
    $request = new WP_REST_Request('POST', '/wp/v2/users/me');
    $request->set_param('email', $email);
    // WordPress mails the old address a change notice; the harness blocks all mail.
    add_filter('send_email_change_email', '__return_false');
    try {
        $response = rest_do_request($request);
    } finally {
        remove_filter('send_email_change_email', '__return_false');
    }
    FcTest::assertSame(200, $response->get_status(), 'WordPress applies the self-service email change at once');
    clean_user_cache($userId);
    CustomerResource::resetCurrentCustomerRuntimeCache();
};

return [
    [
        'id'            => 'customer-unconfirmed-email-no-claim',
        'name'          => 'Not required: a self-set email does not claim the guest customer that holds it',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($unconfirmedEmailWithSetting, $unconfirmedEmailScenario, $changeOwnEmailViaRest) {
            $unconfirmedEmailWithSetting('no', function () use ($unconfirmedEmailScenario, $changeOwnEmailViaRest) {
                $unconfirmedEmailScenario(function ($guest, $guestEmail, $makeUser) use ($changeOwnEmailViaRest) {
                    $userId = $makeUser(FcTest::uniq('fct-attacker') . '@fixture.example.test');
                    $changeOwnEmailViaRest($userId, $guestEmail);

                    $resolved = CustomerResource::getCurrentCustomer(true);

                    FcTest::assert($resolved === null, 'the portal entry resolves no customer for the unconfirmed email');
                    FcTest::assertSame(
                        null,
                        Customer::query()->find($guest->id)->user_id,
                        'the guest customer stays unlinked'
                    );
                    FcTest::assertSame(
                        1,
                        Customer::query()->where('email', $guestEmail)->count(),
                        'no second customer is created on the guest email'
                    );
                });
            });
        },
    ],
    [
        'id'            => 'customer-unconfirmed-email-untouched-account-links',
        'name'          => 'Not required: an account that never changed its email still links its guest customer',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($unconfirmedEmailWithSetting, $unconfirmedEmailScenario) {
            $unconfirmedEmailWithSetting('no', function () use ($unconfirmedEmailScenario) {
                $unconfirmedEmailScenario(function ($guest, $guestEmail, $makeUser) {
                    $userId = $makeUser($guestEmail);
                    wp_set_current_user($userId);

                    $resolved = CustomerResource::getCurrentCustomer(true);

                    FcTest::assertSame((int) $guest->id, $resolved ? (int) $resolved->id : 0, 'the guest customer is resolved');
                    FcTest::assertSame($userId, (int) Customer::query()->find($guest->id)->user_id, 'the guest customer is linked');
                });
            });
        },
    ],
    [
        'id'            => 'customer-unconfirmed-email-password-reset-links',
        'name'          => 'Not required: a password reset proves the email and the guest customer links',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($unconfirmedEmailWithSetting, $unconfirmedEmailScenario, $changeOwnEmailViaRest) {
            $unconfirmedEmailWithSetting('no', function () use ($unconfirmedEmailScenario, $changeOwnEmailViaRest) {
                $unconfirmedEmailScenario(function ($guest, $guestEmail, $makeUser) use ($changeOwnEmailViaRest) {
                    $userId = $makeUser(FcTest::uniq('fct-mover') . '@fixture.example.test');
                    $changeOwnEmailViaRest($userId, $guestEmail);

                    EmailClaimService::confirmInboxProof($userId, $guestEmail);
                    CustomerResource::resetCurrentCustomerRuntimeCache();

                    $resolved = CustomerResource::getCurrentCustomer(true);

                    FcTest::assertSame((int) $guest->id, $resolved ? (int) $resolved->id : 0, 'the guest customer is resolved');
                    FcTest::assertSame($userId, (int) Customer::query()->find($guest->id)->user_id, 'the guest customer is linked');
                });
            });
        },
    ],
    [
        'id'            => 'customer-unconfirmed-email-claim-offer',
        'name'          => 'Not required: an unconfirmed email is offered the inbox confirmation link',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($unconfirmedEmailWithSetting, $unconfirmedEmailScenario, $changeOwnEmailViaRest) {
            $unconfirmedEmailWithSetting('no', function () use ($unconfirmedEmailScenario, $changeOwnEmailViaRest) {
                $unconfirmedEmailScenario(function ($guest, $guestEmail, $makeUser) use ($changeOwnEmailViaRest) {
                    $userId = $makeUser(FcTest::uniq('fct-claimer') . '@fixture.example.test');
                    $changeOwnEmailViaRest($userId, $guestEmail);

                    $offer = EmailClaimService::getOffer();

                    FcTest::assertSame('recovery', $offer ? $offer['reason'] : null, 'the portal offers to confirm the email');
                    FcTest::assertSame($guestEmail, $offer ? $offer['to'] : null, 'the link goes to the guest email');
                });
            });
        },
    ],
    [
        'id'            => 'customer-required-signup-links-guest',
        'name'          => 'Required: a new sign-up gets its guest customer with no confirmation step',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($unconfirmedEmailWithSetting, $unconfirmedEmailScenario) {
            $unconfirmedEmailWithSetting('yes', function () use ($unconfirmedEmailScenario) {
                $unconfirmedEmailScenario(function ($guest, $guestEmail, $makeUser) {
                    // A sign-up form that lets the user pick a password; registration counts as proof.
                    $userId = $makeUser($guestEmail);
                    wp_set_current_user($userId);

                    FcTest::assert(!EmailVerificationService::isRequired($userId), 'the portal is not gated');
                    $resolved = CustomerResource::getCurrentCustomer();

                    FcTest::assertSame((int) $guest->id, $resolved ? (int) $resolved->id : 0, 'the guest customer is resolved');
                    FcTest::assertSame($userId, (int) Customer::query()->find($guest->id)->user_id, 'the guest customer is linked');
                });
            });
        },
    ],
    [
        'id'            => 'customer-required-self-set-email-gated',
        'name'          => 'Required: a self-set email is gated and does not claim the guest customer',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($unconfirmedEmailWithSetting, $unconfirmedEmailScenario, $changeOwnEmailViaRest) {
            $unconfirmedEmailWithSetting('yes', function () use ($unconfirmedEmailScenario, $changeOwnEmailViaRest) {
                $unconfirmedEmailScenario(function ($guest, $guestEmail, $makeUser) use ($changeOwnEmailViaRest) {
                    $userId = $makeUser(FcTest::uniq('fct-attacker') . '@fixture.example.test');
                    $changeOwnEmailViaRest($userId, $guestEmail);

                    FcTest::assert(EmailVerificationService::isRequired($userId), 'the portal is gated until the email is confirmed');
                    FcTest::assert(CustomerResource::getCurrentCustomer() === null, 'no customer is resolved');
                    FcTest::assertSame(null, Customer::query()->find($guest->id)->user_id, 'the guest customer stays unlinked');
                });
            });
        },
    ],
    [
        'id'            => 'customer-fluent-auth-email-proof',
        'name'          => 'A FluentAuth magic link or email code proves the email in both modes',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($unconfirmedEmailWithSetting, $unconfirmedEmailScenario, $changeOwnEmailViaRest) {
            foreach (['yes', 'no'] as $mode) {
                $unconfirmedEmailWithSetting($mode, function () use ($mode, $unconfirmedEmailScenario, $changeOwnEmailViaRest) {
                    $unconfirmedEmailScenario(function ($guest, $guestEmail, $makeUser) use ($mode, $changeOwnEmailViaRest) {
                        $userId = $makeUser(FcTest::uniq('fct-mover') . '@fixture.example.test');
                        $changeOwnEmailViaRest($userId, $guestEmail);

                        // FluentAuth binds the link or code to this address and fires this on sign-in.
                        do_action('fluent_auth/email_verified', get_userdata($userId));
                        CustomerResource::resetCurrentCustomerRuntimeCache();

                        FcTest::assert(!EmailVerificationService::isRequired($userId), $mode . ': the portal is not gated');
                        $resolved = CustomerResource::getCurrentCustomer();
                        FcTest::assertSame((int) $guest->id, $resolved ? (int) $resolved->id : 0, $mode . ': the guest customer is resolved');
                        FcTest::assertSame($userId, (int) Customer::query()->find($guest->id)->user_id, $mode . ': the guest customer is linked');
                    });
                });
            }
        },
    ],
    [
        'id'            => 'customer-email-change-race',
        'name'          => 'A request that lands while WordPress saves a new email cannot claim the guest customer',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($unconfirmedEmailWithSetting, $unconfirmedEmailScenario, $changeOwnEmailViaRest) {
            $unconfirmedEmailWithSetting('no', function () use ($unconfirmedEmailScenario, $changeOwnEmailViaRest) {
                $unconfirmedEmailScenario(function ($guest, $guestEmail, $makeUser) use ($changeOwnEmailViaRest) {
                    $userId = $makeUser(FcTest::uniq('fct-racer') . '@fixture.example.test');

                    // wp_insert_user() stores the email, then writes user meta, then fires
                    // profile_update. A parallel portal request in that gap reads the new email.
                    $probed = false;
                    $raced = null;
                    $parallelRequest = function ($meta, $user) use ($userId, &$probed, &$raced) {
                        if ((int) $user->ID === $userId && !$probed) {
                            $probed = true;
                            clean_user_cache($userId);
                            CustomerResource::resetCurrentCustomerRuntimeCache();
                            $raced = CustomerResource::getCurrentCustomer();
                            CustomerResource::resetCurrentCustomerRuntimeCache();
                        }
                        return $meta;
                    };
                    // Fires after the users row is written and before profile_update.
                    add_filter('insert_user_meta', $parallelRequest, 10, 2);
                    try {
                        $changeOwnEmailViaRest($userId, $guestEmail);
                    } finally {
                        remove_filter('insert_user_meta', $parallelRequest, 10);
                    }

                    FcTest::assert($probed, 'the probe ran inside the save');
                    FcTest::assert($raced === null, 'the parallel request resolves no customer');
                    FcTest::assertSame(null, Customer::query()->find($guest->id)->user_id, 'the guest customer stays unlinked');
                });
            });
        },
    ],
    [
        'id'            => 'customer-unconfirmed-email-reviews',
        'name'          => 'Not required: a self-set email does not read another customer\'s reviews',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($unconfirmedEmailWithSetting, $unconfirmedEmailScenario, $changeOwnEmailViaRest) {
            $unconfirmedEmailWithSetting('no', function () use ($unconfirmedEmailScenario, $changeOwnEmailViaRest) {
                $unconfirmedEmailScenario(function ($guest, $guestEmail, $makeUser) use ($changeOwnEmailViaRest) {
                    $reviewClass = \FluentCart\App\Models\ProductReview::class;
                    $review = $reviewClass::query()->create([
                        'post_id'        => 999001,
                        'reviewer_name'  => 'Victim',
                        'reviewer_email' => $guestEmail,
                        'title'          => 'secret pending review',
                        'review'         => 'private content',
                        'rating'         => 5,
                    ]);
                    $reviewClass::query()->where('id', $review->id)->update(['status' => 'pending']);

                    try {
                        // The attacker has their own customer, then self-sets the guest email.
                        $userId = $makeUser(FcTest::uniq('fct-reviewer') . '@fixture.example.test');
                        $own = \FluentCart\App\Models\Customer::query()->create([
                            'email' => FcTest::uniq('fct-own') . '@fixture.example.test',
                            'user_id' => $userId,
                        ]);
                        $changeOwnEmailViaRest($userId, $guestEmail);

                        $payload = ProductReviewService::getCustomerReviewsPayload($own, ['per_page' => 10, 'page' => 1]);
                        $rows = $payload['reviews']['data'] ?? [];
                        $ids = array_map(function ($r) { return (int) (is_array($r) ? $r['id'] : $r->id); }, $rows);

                        FcTest::assert(!in_array((int) $review->id, $ids, true), 'the victim review is not returned');
                    } finally {
                        $reviewClass::query()->where('id', $review->id)->delete();
                    }
                });
            });
        },
    ],
    [
        'id'            => 'customer-email-verification-default',
        'name'          => 'Customer email verification is required when the store never saved the setting',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($unconfirmedEmailWithSetting) {
            $unconfirmedEmailWithSetting(null, function () {
                FcTest::assert(EmailVerificationService::isEnabled(), 'an absent setting means Required');
                FcTest::assertSame('yes', (new StoreSettings())->get('require_customer_email_verification'), 'the default value is yes');
            });
            $unconfirmedEmailWithSetting('no', function () {
                FcTest::assert(!EmailVerificationService::isEnabled(), 'a saved Not required is respected');
            });
        },
    ],
];
