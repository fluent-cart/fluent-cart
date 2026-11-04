<?php

namespace Tests\Support\Traits;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Services\CustomerIdentity\EmailClaimService;

/**
 * Fixture builder for the customer email-claim flow (EmailClaimService /
 * EmailClaimPortal): points the store at a portal page, captures outgoing
 * mail instead of sending it, and issues a claim link for a signed-in user,
 * handing back the token the mail carried. Builds state only; no assertions.
 */
trait BuildsEmailClaims
{
    use ConfiguresStoreOrderMode;

    /** @var array<int, array> Every mail wp_mail() was asked to send since captureMail(). */
    protected array $capturedMail = [];

    protected function configurePortalPage(): string
    {
        $pageId = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Account']);
        $this->setStoreSetting('customer_profile_page_id', (string) $pageId);

        return get_permalink($pageId);
    }

    /** Short-circuit wp_mail(); the arguments are kept in $capturedMail. */
    protected function captureMail(): void
    {
        $this->capturedMail = [];
        remove_all_filters('pre_wp_mail');
        add_filter('pre_wp_mail', function ($null, $atts) {
            $this->capturedMail[] = $atts;
            return true;
        }, 10, 2);
    }

    /**
     * Drop mail captured so far. WordPress itself mails an account whose email
     * changed, so fixtures built with wp_update_user() leave a mail behind that
     * a count assertion about the claim link must not see.
     */
    protected function forgetCapturedMail(): void
    {
        $this->capturedMail = [];
    }

    protected function releaseMail(): void
    {
        remove_all_filters('pre_wp_mail');
        $this->capturedMail = [];
    }

    /** Issue a claim for the current user and return the token from the mailed link ('' when none). */
    protected function issueClaimAndGetToken(): string
    {
        CustomerResource::resetCurrentCustomerRuntimeCache();
        $before = count($this->capturedMail);
        EmailClaimService::issue();
        $mail = $this->capturedMail[$before] ?? null;
        if (!$mail || !preg_match('/' . EmailClaimService::QUERY_TOKEN . '=([A-Za-z0-9_-]+)/', (string) $mail['message'], $m)) {
            return '';
        }

        return $m[1];
    }
}
