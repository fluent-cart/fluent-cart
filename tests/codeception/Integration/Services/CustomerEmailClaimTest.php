<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Models\Customer;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\CustomerIdentity\EmailClaimPortal;
use FluentCart\App\Services\CustomerIdentity\EmailClaimService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\Traits\BuildsEmailClaims;
use Tests\Support\Traits\RequiresEmailVerification;

/**
 * Case 6 of the customer identity binding fix (dev/CUSTOMER_IDENTITY_BINDING_AUDIT.md §6.4).
 *
 * Nothing binds a customer record to an account on an email match any more,
 * so the customer proves the address instead: a signed link is mailed to the
 * address being claimed, and opening it while signed in as the claiming
 * account moves the contact email and absorbs guest records at that address.
 */
class CustomerEmailClaimTest extends WPTestCase
{
    use BuildsEmailClaims;
    use RequiresEmailVerification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireEmailVerification();
        $this->configurePortalPage();
        $this->captureMail();
        CustomerResource::resetCurrentCustomerRuntimeCache();
    }

    protected function tearDown(): void
    {
        $this->releaseMail();
        remove_all_filters('fluent_cart/customer/enable_email_claim');
        remove_all_actions('fluent_cart/customer_resources_moved');
        remove_all_actions('fluent_cart/customer_email_changed');
        wp_set_current_user(0);
        $_GET = [];
        $_POST = [];
        CustomerResource::resetCurrentCustomerRuntimeCache();
        $this->restoreEmailVerificationDefault();
        parent::tearDown();
    }

    public function test_nothing_is_offered_when_the_account_and_its_customer_agree_and_no_guest_records_exist(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        wp_set_current_user($customer->user_id);

        $this->assertNull(EmailClaimService::getOffer(), 'No divergence and no guest history means nothing to confirm');
    }

    public function test_completed_recovery_does_not_offer_empty_retained_guest_records_again(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $guest = (new CustomerFactory())->create(['email' => $customer->email, 'user_id' => null]);
        $order = (new OrderFactory())->create(['customer_id' => $guest->id]);
        wp_set_current_user($customer->user_id);
        $this->assertSame('confirmed', EmailClaimService::confirm($this->issueClaimAndGetToken()), 'Inbox proof recovers the order');
        $this->assertSame((int) $customer->id, (int) Order::query()->find($order->id)->customer_id, 'Purchase moves to the linked customer');
        $this->assertNotNull(Customer::query()->find($guest->id), 'Source remains available for late writes');
        $this->assertNull(EmailClaimService::getOffer(), 'Empty retained customers must not trigger another confirmation');
        $_GET = [];
        $this->assertSame('', EmailClaimPortal::render(), 'The verified dashboard has no confirmation notice');
        $_GET[EmailClaimService::QUERY_STATUS] = 'sent';
        $this->assertSame('', EmailClaimPortal::render(), 'An old send-link URL must not prompt a verified customer again');
        $_GET[EmailClaimService::QUERY_STATUS] = 'confirmed';
        $this->assertSame('', EmailClaimPortal::render(), 'Successful confirmation must show the normal dashboard without the verification panel');
    }

    public function test_a_diverged_contact_email_is_offered_for_confirmation(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        wp_update_user(['ID' => $customer->user_id, 'user_email' => 'moved-' . wp_generate_password(6, false) . '@example.com']);
        wp_set_current_user($customer->user_id);

        $offer = EmailClaimService::getOffer();

        $this->assertSame('diverged', $offer['reason'] ?? null, 'The account moved its email unverified; the record did not');
        $this->assertSame((string) get_userdata($customer->user_id)->user_email, $offer['to'], 'The address to confirm is the account email');
    }

    public function test_guest_purchases_at_the_account_email_are_offered_for_recovery_even_without_a_customer(): void
    {
        $email = 'recover-' . wp_generate_password(6, false) . '@example.com';
        $userId = WPUserFactory::create(['user_email' => $email]);
        $guest = (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);
        (new OrderFactory())->create(['customer_id' => $guest->id]);
        wp_set_current_user($userId);

        $offer = EmailClaimService::getOffer();

        $this->assertSame('recovery', $offer['reason'] ?? null, 'An account with no record can still recover guest history at its address');
        $this->assertNull($offer['customer'], 'There is no record yet');
    }

    public function test_nothing_is_offered_when_another_account_holds_the_address(): void
    {
        $sharedEmail = 'held-' . wp_generate_password(6, false) . '@example.com';
        $claimant = (new CustomerFactory())->createWithUser();
        wp_update_user(['ID' => $claimant->user_id, 'user_email' => $sharedEmail]);
        $otherOwner = WPUserFactory::create();
        (new CustomerFactory())->create(['email' => $sharedEmail, 'user_id' => $otherOwner]);
        wp_set_current_user($claimant->user_id);

        $this->assertNull(EmailClaimService::getOffer(), 'A conflict between two accounts is for staff, not for whoever asks first');
    }

    public function test_the_feature_can_be_turned_off_with_a_filter(): void
    {
        add_filter('fluent_cart/customer/enable_email_claim', '__return_false');
        $customer = (new CustomerFactory())->createWithUser();
        wp_update_user(['ID' => $customer->user_id, 'user_email' => 'off-' . wp_generate_password(6, false) . '@example.com']);
        wp_set_current_user($customer->user_id);

        $this->assertNull(EmailClaimService::getOffer(), 'Disabled means no offer');
        $this->assertSame('unavailable', EmailClaimService::issue(), 'Disabled means no mail');
    }

    public function test_the_confirmation_link_is_mailed_to_the_address_being_claimed(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $newEmail = 'claimed-' . wp_generate_password(6, false) . '@example.com';
        wp_update_user(['ID' => $customer->user_id, 'user_email' => $newEmail]);
        wp_set_current_user($customer->user_id);
        $this->forgetCapturedMail();

        $status = EmailClaimService::issue();

        $this->assertSame('sent', $status, 'The link is issued');
        $this->assertCount(1, $this->capturedMail, 'Exactly one mail goes out');
        $this->assertSame($newEmail, $this->capturedMail[0]['to'], 'The proof is the inbox being claimed, never the old one');
        $this->assertStringContainsString(EmailClaimService::QUERY_TOKEN . '=', (string) $this->capturedMail[0]['message'], 'The mail carries the token');
        $this->assertNotEmpty(get_user_meta($customer->user_id, EmailClaimService::META_KEY, true), 'The pending claim is recorded on the account');
    }

    public function test_confirming_moves_the_contact_email_and_absorbs_guest_records_at_the_new_address(): void
    {
        $customer = (new CustomerFactory())->createWithUser(['first_name' => 'Owner']);
        $newEmail = 'confirmed-' . wp_generate_password(6, false) . '@example.com';
        wp_update_user(['ID' => $customer->user_id, 'user_email' => $newEmail]);
        $guest = (new CustomerFactory())->create(['email' => $newEmail, 'user_id' => null]);
        $guestOrder = (new OrderFactory())->create(['customer_id' => $guest->id]);
        wp_set_current_user($customer->user_id);
        $moved = [];
        add_action('fluent_cart/customer_resources_moved', function ($data) use (&$moved) { $moved[] = $data; });
        $emailChanged = [];
        add_action('fluent_cart/customer_email_changed', function ($data) use (&$emailChanged) { $emailChanged[] = $data; });

        $token = $this->issueClaimAndGetToken();
        $status = EmailClaimService::confirm($token);

        $this->assertSame('confirmed', $status, 'A valid link opened by the signed-in claimant confirms');
        $this->assertSame($newEmail, Customer::query()->find($customer->id)->email, 'The contact email moved onto the proven address');
        $this->assertSame((int) $customer->id, (int) Order::query()->find($guestOrder->id)->customer_id, 'The guest order now belongs to the account');
        $this->assertNotNull(Customer::query()->find($guest->id), 'The source remains available for late writers');
        $this->assertSame([(int) $guest->id, (int) $customer->id], [(int) $moved[0]['from_customer_id'], (int) $moved[0]['to_customer_id']], 'Extensions were told to move their rows');
        $this->assertSame($newEmail, $emailChanged[0]['new_email'] ?? null, 'The email-changed event fires with the new address');
        $this->assertSame($customer->email, $emailChanged[0]['old_customer']->email, 'The old customer retains its pre-change email');
        $this->assertSame($newEmail, $emailChanged[0]['new_customer']->email, 'The new customer exposes the saved email');
        $this->assertNotSame($emailChanged[0]['old_customer'], $emailChanged[0]['new_customer']);
        $this->assertSame((int) $customer->id, (int) $emailChanged[0]['old_customer']->id);
        $this->assertEmpty(get_user_meta($customer->user_id, EmailClaimService::META_KEY, true), 'The link is consumed');
    }

    public function test_confirming_with_no_customer_creates_one_for_the_account_and_recovers_the_guest_history(): void
    {
        $email = 'newbie-' . wp_generate_password(6, false) . '@example.com';
        $userId = WPUserFactory::create(['user_email' => $email, 'first_name' => 'New', 'last_name' => 'Comer']);
        $guest = (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);
        $guestOrder = (new OrderFactory())->create(['customer_id' => $guest->id]);
        wp_set_current_user($userId);

        $token = $this->issueClaimAndGetToken();
        $status = EmailClaimService::confirm($token);

        $this->assertSame('confirmed', $status, 'Recovery confirms');
        $own = Customer::query()->where('user_id', $userId)->first();
        $this->assertNotNull($own, 'The account gets a record of its own');
        $this->assertSame($email, $own->email, 'On the proven address');
        $this->assertSame((int) $own->id, (int) Order::query()->find($guestOrder->id)->customer_id, 'The guest order moved to it');
        $this->assertNotNull(Customer::query()->find($guest->id), 'The source remains available for late writers');
    }

    public function test_confirmation_aggregates_successful_orders_without_hydrating_history(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $guest = (new CustomerFactory())->create(['email' => $customer->email, 'user_id' => null]);
        $factory = new OrderFactory();
        foreach ([
            [$customer->id, Status::PAYMENT_PAID, 1000, 0, '2024-01-01 00:00:00'],
            [$guest->id, Status::PAYMENT_PARTIALLY_REFUNDED, 800, 300, '2024-02-01 00:00:00'],
            [$guest->id, Status::PAYMENT_PARTIALLY_PAID, 200, 400, '2024-03-01 00:00:00'],
            [$guest->id, Status::PAYMENT_PAID, 300, 300, '2024-04-01 00:00:00'],
            [$guest->id, Status::PAYMENT_PENDING, 9000, 0, '2025-01-01 00:00:00'],
            [$guest->id, Status::PAYMENT_REFUNDED, 9000, 9000, '2023-01-01 00:00:00'],
        ] as $row) {
            $factory->create([
                'customer_id' => $row[0], 'payment_status' => $row[1],
                'total_paid' => $row[2], 'total_refund' => $row[3], 'created_at' => $row[4]
            ]);
        }
        $other = (new CustomerFactory())->create();
        $factory->create(['customer_id' => $other->id, 'payment_status' => Status::PAYMENT_PAID, 'total_paid' => 9999]);
        wp_set_current_user($customer->user_id);
        $token = $this->issueClaimAndGetToken();
        $dispatcher = Order::getEventDispatcher();
        Order::setEventDispatcher(clone $dispatcher);
        $hydrated = 0;
        Order::retrieved(function () use (&$hydrated) { $hydrated++; });
        try {
            $this->assertSame('confirmed', EmailClaimService::confirm($token));
            $this->assertSame(0, $hydrated, 'Confirmation must not hydrate historical orders');
        } finally {
            Order::setEventDispatcher($dispatcher);
        }
        $customer = Customer::query()->find($customer->id);
        $this->assertSame(4, (int) $customer->purchase_count);
        $this->assertSame(1500, (int) $customer->ltv);
        $this->assertSame(375, (int) $customer->aov);
        $this->assertSame('2024-01-01 00:00:00', (string) $customer->first_purchase_date);
        $this->assertSame('2024-04-01 00:00:00', (string) $customer->last_purchase_date);
    }

    public function test_a_fresh_claim_recovers_writes_that_finish_after_confirmation(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $guest = (new CustomerFactory())->create(['email' => $customer->email, 'user_id' => null]);
        (new OrderFactory())->create(['customer_id' => $guest->id]);
        wp_set_current_user($customer->user_id);
        $token = $this->issueClaimAndGetToken();
        $this->assertSame('confirmed', EmailClaimService::confirm($token));
        $lateOrder = (new OrderFactory())->create(['customer_id' => $guest->id]);

        $this->assertNotNull(Customer::query()->find($lateOrder->customer_id));
        $this->assertSame('stale', EmailClaimService::confirm($token));
        $this->assertSame('recovery', EmailClaimService::getOffer()['reason']);
        $this->assertSame('confirmed', EmailClaimService::confirm($this->issueClaimAndGetToken()));
        $this->assertSame((int) $customer->id, (int) Order::query()->find($lateOrder->id)->customer_id);
        $this->assertEmpty(Customer::query()->find($guest->id)->user_id);
    }

    public function test_confirmation_clears_stale_statistics_when_no_successful_orders_exist(): void
    {
        $customer = (new CustomerFactory())->createWithUser([
            'purchase_count' => 2, 'ltv' => 900, 'aov' => 450,
            'first_purchase_date' => '2024-01-01 00:00:00', 'last_purchase_date' => '2024-02-01 00:00:00'
        ]);
        $guest = (new CustomerFactory())->create(['email' => $customer->email, 'user_id' => null]);
        (new OrderFactory())->create(['customer_id' => $guest->id, 'payment_status' => Status::PAYMENT_PENDING]);
        (new OrderFactory())->create(['customer_id' => $customer->id, 'payment_status' => Status::PAYMENT_PENDING]);
        wp_set_current_user($customer->user_id);
        $this->assertSame('confirmed', EmailClaimService::confirm($this->issueClaimAndGetToken()));
        $customer = Customer::query()->find($customer->id);
        $this->assertSame(0, (int) $customer->purchase_count);
        $this->assertSame(0, (int) $customer->ltv);
        $this->assertSame(0, (int) $customer->aov);
        $this->assertNull($customer->first_purchase_date);
        $this->assertNull($customer->last_purchase_date);
    }

    public function test_a_used_link_cannot_be_replayed(): void
    {
        $email = 'replay-' . wp_generate_password(6, false) . '@example.com';
        $userId = WPUserFactory::create(['user_email' => $email]);
        $guest = (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);
        (new OrderFactory())->create(['customer_id' => $guest->id]);
        wp_set_current_user($userId);
        $token = $this->issueClaimAndGetToken();
        $this->assertSame('confirmed', EmailClaimService::confirm($token), 'Precondition: first use confirms');
        (new CustomerFactory())->create(['email' => $email, 'user_id' => null]);

        $this->assertSame('stale', EmailClaimService::confirm($token), 'The same link a second time moves nothing');
    }

    public function test_reopening_a_used_link_after_confirmation_explains_instead_of_asking_for_a_new_one(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'reopen-used@example.com'], false);
        (new CustomerFactory())->createForUser($userId);
        wp_set_current_user($userId);
        $token = $this->issueClaimAndGetToken();
        $this->assertSame('confirmed', EmailClaimService::confirm($token), 'Precondition: first use confirms');

        $_GET[EmailClaimService::QUERY_TOKEN] = $token;
        $html = EmailClaimPortal::render();

        $this->assertStringNotContainsString('Request a new one below', $html, 'Nothing can be requested, so the notice must not point at a missing button.');
        $this->assertStringContainsString('already confirmed', $html, 'The customer must learn the link is no longer needed.');
        $this->assertStringNotContainsString('role="alert"', $html, 'A confirmed account reopening its link is not an error.');
    }

    public function test_an_expired_link_with_nothing_left_to_confirm_does_not_ask_for_a_new_one(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'reopen-expired@example.com']);
        wp_set_current_user($userId);
        $_GET[EmailClaimService::QUERY_TOKEN] = EmailClaimService::buildToken(0, $userId, '', 'reopen-expired@example.com', time() - 60, 'expired-nonce');

        $html = EmailClaimPortal::render();

        $this->assertStringNotContainsString('Request a new one below', $html, 'An expired link must not point at a missing button.');
        $this->assertStringContainsString('already confirmed', $html, 'A verified account must be told nothing is needed.');
    }

    public function test_a_superseded_link_still_offers_a_new_one_when_confirmation_is_needed(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'reopen-superseded@example.com'], false);
        (new CustomerFactory())->createForUser($userId);
        wp_set_current_user($userId);
        $old = $this->issueClaimAndGetToken();
        $this->issueClaimAndGetToken();

        $_GET[EmailClaimService::QUERY_TOKEN] = $old;
        $html = EmailClaimPortal::render();

        $this->assertStringContainsString('Request a new one below', $html, 'A replaced link must still point at the resend option.');
        $this->assertStringContainsString('Send confirmation email', $html, 'The resend button must be present.');
    }

    public function test_a_newer_link_supersedes_an_older_one(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        wp_update_user(['ID' => $customer->user_id, 'user_email' => 'newer-' . wp_generate_password(6, false) . '@example.com']);
        wp_set_current_user($customer->user_id);

        $first = $this->issueClaimAndGetToken();
        $second = $this->issueClaimAndGetToken();

        $this->assertSame('stale', EmailClaimService::resolveClaim($first)['status'], 'Only the newest link works');
        $this->assertSame('ok', EmailClaimService::resolveClaim($second)['status'], 'The newest link is live');
    }

    public function test_a_tampered_expired_or_foreign_link_is_refused(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $newEmail = 'refused-' . wp_generate_password(6, false) . '@example.com';
        wp_update_user(['ID' => $customer->user_id, 'user_email' => $newEmail]);
        wp_set_current_user($customer->user_id);
        $token = $this->issueClaimAndGetToken();

        $this->assertSame('invalid', EmailClaimService::resolveClaim(substr($token, 0, -2) . 'xx')['status'], 'A tampered token fails the signature');
        $this->assertSame('invalid', EmailClaimService::resolveClaim('not a token')['status'], 'Garbage is refused');

        $expired = EmailClaimService::buildToken((int) $customer->id, (int) $customer->user_id, $customer->email, $newEmail, time() - 1, 'n');
        $this->assertSame('expired', EmailClaimService::resolveClaim($expired)['status'], 'An expired token is refused before anything else is read');

        wp_set_current_user(WPUserFactory::create());
        $this->assertSame('wrong_account', EmailClaimService::resolveClaim($token)['status'], 'A forwarded link does nothing in another account\'s hands');
        $this->assertSame($customer->email, Customer::query()->find($customer->id)->email, 'Nothing moved');
    }

    public function test_a_link_goes_stale_when_the_account_email_changes_again_before_it_is_opened(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        wp_update_user(['ID' => $customer->user_id, 'user_email' => 'first-' . wp_generate_password(6, false) . '@example.com']);
        wp_set_current_user($customer->user_id);
        $token = $this->issueClaimAndGetToken();
        wp_update_user(['ID' => $customer->user_id, 'user_email' => 'second-' . wp_generate_password(6, false) . '@example.com']);

        $this->assertSame('stale', EmailClaimService::resolveClaim($token)['status'], 'The link described a world that no longer exists');
    }

    public function test_a_link_is_refused_when_another_account_took_the_address_meanwhile(): void
    {
        $email = 'race-' . wp_generate_password(6, false) . '@example.com';
        $customer = (new CustomerFactory())->createWithUser();
        wp_update_user(['ID' => $customer->user_id, 'user_email' => $email]);
        wp_set_current_user($customer->user_id);
        $token = $this->issueClaimAndGetToken();
        (new CustomerFactory())->create(['email' => $email, 'user_id' => WPUserFactory::create()]);

        $this->assertSame('conflict', EmailClaimService::confirm($token), 'Two accounts on one address is for staff');
        $this->assertSame($customer->email, Customer::query()->find($customer->id)->email, 'Nothing moved');
    }

    public function test_the_portal_refuses_a_submission_without_a_valid_nonce(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        wp_set_current_user($customer->user_id);
        $_POST = [EmailClaimPortal::ACTION_FIELD => 'send', '_wpnonce' => 'forged'];

        $redirect = EmailClaimPortal::handleSubmission();

        $this->assertStringContainsString(EmailClaimService::QUERY_STATUS . '=invalid', (string) $redirect, 'A forged form is answered, not acted on');
        $this->assertCount(0, $this->capturedMail, 'No mail goes out');
    }

    public function test_the_portal_sends_the_link_on_a_valid_submission_and_shows_the_confirm_form_for_a_valid_link(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $newEmail = 'portal-' . wp_generate_password(6, false) . '@example.com';
        wp_update_user(['ID' => $customer->user_id, 'user_email' => $newEmail]);
        wp_set_current_user($customer->user_id);
        $this->forgetCapturedMail();
        $_POST = [EmailClaimPortal::ACTION_FIELD => 'send', '_wpnonce' => wp_create_nonce(EmailClaimPortal::NONCE_ACTION)];

        $redirect = EmailClaimPortal::handleSubmission();

        $this->assertStringContainsString(EmailClaimService::QUERY_STATUS . '=sent', (string) $redirect, 'The portal reports the link was sent');
        $this->assertCount(1, $this->capturedMail, 'One mail went out');
        preg_match('/' . EmailClaimService::QUERY_TOKEN . '=([A-Za-z0-9_-]+)/', (string) $this->capturedMail[0]['message'], $m);

        $_GET = [EmailClaimService::QUERY_TOKEN => $m[1]];
        $html = EmailClaimPortal::render();

        $this->assertStringContainsString('value="confirm"', $html, 'A valid link renders a nonce-protected POST form');
        $this->assertStringContainsString('data-auto-confirm>', $html, 'A valid link confirms automatically in the matching signed-in browser');
        $this->assertSame('ok', EmailClaimService::resolveClaim($m[1])['status'], 'Rendering the GET alone does not consume the token');
        $this->assertStringContainsString($newEmail, $html, 'The form names the address being confirmed');
        $this->assertSame($customer->email, Customer::query()->find($customer->id)->email, 'Rendering the form moved nothing');
    }

    public function test_the_portal_renders_nothing_for_a_signed_out_visitor(): void
    {
        wp_set_current_user(0);
        $_GET = [EmailClaimService::QUERY_TOKEN => 'anything'];

        $this->assertSame('', EmailClaimPortal::render(), 'Signed out, there is nothing to show and nothing to leak');
    }
}
