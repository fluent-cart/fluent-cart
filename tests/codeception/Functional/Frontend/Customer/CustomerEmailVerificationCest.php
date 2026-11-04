<?php

namespace Tests\Functional\Frontend\Customer;

use FluentCart\App\Services\CustomerIdentity\EmailClaimService;
use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use FluentCart\App\Hooks\Handlers\ShortCodes\CustomerProfileHandler;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsEmailClaims;
use Tests\Support\Traits\RequiresEmailVerification;

class CustomerEmailVerificationCest
{
    use BuildsEmailClaims;
    use RequiresEmailVerification;

    public function _before(): void
    {
        $this->requireEmailVerification();
        $this->configurePortalPage();
        $this->captureMail();
    }

    public function _after(): void
    {
        $this->releaseMail();
        $this->restoreEmailVerificationDefault();
    }

    public function unverifiedAccountCannotReadOrChangePortalDataUntilConfirmation(FunctionalTester $I): void
    {
        $userId = WPUserFactory::create([], false);
        $customer = (new CustomerFactory())->createForUser($userId, ['first_name' => 'Original']);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        wp_set_current_user($userId);

        foreach (['', '/orders', '/orders/' . $order->uuid, '/downloads', '/subscriptions', '/profile', '/sections'] as $path) {
            [$status] = $I->get('/fluent-cart/v2/customer-profile' . $path);
            Assert::assertSame(403, $status, 'Unverified account must be refused by the API at ' . $path);
        }
        [$status] = $I->post('/fluent-cart/v2/customer-profile/update', ['first_name' => 'Injected', 'last_name' => 'Name']);
        Assert::assertSame(403, $status, 'Unverified account must not bypass the portal through a direct write.');
        Assert::assertSame('Original', $customer->fresh()->first_name, 'Refused requests must leave customer data unchanged.');

        $token = $this->issueClaimAndGetToken();
        Assert::assertNotEmpty($token, 'A real captured email must provide the confirmation link.');
        Assert::assertSame('confirmed', EmailClaimService::confirm($token), 'The account must be able to prove its inbox.');
        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/profile');
        Assert::assertSame(200, $status, 'A confirmed account must regain profile access: ' . wp_json_encode($body));
        [$status] = $I->get('/fluent-cart/v2/customer-profile/orders/' . $order->uuid);
        Assert::assertSame(200, $status, 'The confirmed account must be able to read its own purchase.');
    }

    public function verificationUnlocksTheExistingGuestCustomerWithoutCreatingAnother(FunctionalTester $I): void
    {
        $guest = (new CustomerFactory())->create(['email' => 'same-customer@example.test']);
        $order = (new OrderFactory())->create(['customer_id' => $guest->id]);
        $userId = WPUserFactory::create(['user_email' => $guest->email], false);
        wp_set_current_user($userId);
        foreach (['/orders', '/orders/' . $order->uuid, '/downloads', '/subscriptions', '/profile'] as $path) {
            [$status, $body] = $I->get('/fluent-cart/v2/customer-profile' . $path);
            Assert::assertSame(403, $status, 'Email equality must not allow access before verification.');
            Assert::assertStringNotContainsString($order->uuid, wp_json_encode($body), 'Denied responses must not disclose purchases.');
        }
        ob_start();
        (new CustomerProfileHandler())->renderCustomerAppContainer();
        $html = ob_get_clean();
        Assert::assertStringContainsString('not verified yet', $html, 'The dashboard must require verification.');
        Assert::assertStringNotContainsString('data-fluent-cart-customer-profile-app', $html, 'The dashboard app must stay blocked.');
        Assert::assertSame('confirmed', EmailClaimService::confirm($this->issueClaimAndGetToken()), 'Inbox proof links the existing customer.');
        Assert::assertSame(1, \FluentCart\App\Models\Customer::query()->where('email', $guest->email)->count(), 'There must still be only one customer.');
        Assert::assertSame($userId, (int) $guest->fresh()->user_id, 'The existing row is linked after verification.');
        Assert::assertSame((int) $guest->id, (int) $order->fresh()->customer_id, 'The historical order keeps the same customer ID.');
        [$status] = $I->get('/fluent-cart/v2/customer-profile/orders/' . $order->uuid);
        Assert::assertSame(200, $status, 'The verified account can access the same purchase.');
    }

    public function changedEmailRequiresProofBeforeExistingPurchaseAccess(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $userId = (int) $customer->user_id;
        wp_set_current_user($userId);
        wp_update_user(['ID' => $userId, 'user_email' => 'new-account-email@example.com']);

        [$status] = $I->get('/fluent-cart/v2/customer-profile/orders/' . $order->uuid);
        Assert::assertSame(403, $status, 'Changing the account email must close existing portal access until proof.');
        Assert::assertSame($customer->email, $customer->fresh()->email, 'An unconfirmed account edit must retain the customer contact.');
        Assert::assertSame('confirmed', EmailClaimService::confirm($this->issueClaimAndGetToken()), 'The current account must be able to confirm the new inbox.');
        Assert::assertSame('new-account-email@example.com', $customer->fresh()->email, 'Confirmation must update the customer contact.');
        Assert::assertFalse(EmailVerificationService::isRequired($userId), 'Confirmation must persist verification.');
        [$status] = $I->get('/fluent-cart/v2/customer-profile/orders/' . $order->uuid);
        Assert::assertSame(200, $status, 'The original purchase must remain available to its verified owner.');
    }

    public function manualCustomerEmailChangeBlocksDataUntilTheAccountInboxIsVerifiedAgain(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $userId = (int) $customer->user_id;
        $accountEmail = get_userdata($userId)->user_email;
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        wp_set_current_user($userId);
        [$status] = $I->get('/fluent-cart/v2/customer-profile/orders/' . $order->uuid);
        Assert::assertSame(200, $status, 'The verified owner must initially have purchase access.');

        $customer->update(['email' => 'manual-customer-edit@example.com']);

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/orders/' . $order->uuid);
        Assert::assertSame(403, $status, 'A customer email edit must block direct API access even without a WordPress hook.');
        Assert::assertStringNotContainsString($order->uuid, wp_json_encode($body), 'The denied response must not expose purchase data.');
        ob_start();
        (new CustomerProfileHandler())->renderCustomerAppContainer();
        $html = ob_get_clean();
        Assert::assertStringContainsString('Send confirmation email', $html, 'Manual edits must show the verification form.');
        Assert::assertStringNotContainsString('data-fluent-cart-customer-profile-app', $html, 'The protected dashboard must not render.');
        Assert::assertSame('confirmed', EmailClaimService::confirm($this->issueClaimAndGetToken()), 'Inbox proof must restore normal access.');
        Assert::assertSame($accountEmail, $customer->fresh()->email, 'Confirmation must restore the verified account email on the same customer.');
        Assert::assertSame((int) $customer->id, (int) $order->fresh()->customer_id, 'The existing purchase must keep its customer.');
        [$status] = $I->get('/fluent-cart/v2/customer-profile/orders/' . $order->uuid);
        Assert::assertSame(200, $status, 'The verified account must regain purchase access.');
    }

    public function existingAccountWithoutVerificationMetadataKeepsPortalAccess(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $userId = (int) $customer->user_id;
        delete_user_meta($userId, EmailVerificationService::UNVERIFIED_META_KEY);
        wp_set_current_user($userId);

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/profile');
        Assert::assertSame(200, $status, 'An account predating verification must keep portal access: ' . wp_json_encode($body));
        [$status] = $I->get('/fluent-cart/v2/customer-profile/orders/' . $order->uuid);
        Assert::assertSame(200, $status, 'The account must still read its own purchase.');

        wp_update_user(['ID' => $userId, 'user_email' => 'legacy-portal-moved@example.test']);
        [$status] = $I->get('/fluent-cart/v2/customer-profile/profile');
        Assert::assertSame(403, $status, 'A later email change must still require proof.');
    }
}
