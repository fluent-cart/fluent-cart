<?php

namespace FluentCart\App\Services\CustomerIdentity;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\Framework\Support\Arr;

/**
 * The customer-portal face of EmailClaimService: a notice offering to confirm
 * the account's address, the landing form a mailed link opens, and the
 * post-redirect-get handling of both submissions.
 *
 * The matching signed-in browser submits valid links automatically with a nonce.
 * A plain GET or a signed-out mail scanner cannot apply the claim.
 */
class EmailClaimPortal
{
    const ACTION_FIELD = 'fct_email_claim_action';

    const NONCE_ACTION = 'fct_email_claim';

    /**
     * Handle a submitted send/confirm form. Returns where to redirect, or null
     * when the request is not ours.
     *
     * @return string|null
     */
    public static function handleSubmission(): ?string
    {
        $action = Arr::get($_POST, static::ACTION_FIELD, '');
        if (!is_string($action) || !in_array($action, ['send', 'confirm'], true)) {
            return null;
        }

        $portal = (new StoreSettings())->getCustomerProfilePage();
        if (!$portal) {
            return null;
        }

        $nonce = Arr::get($_POST, '_wpnonce', '');
        if (!is_user_logged_in() || !is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), static::NONCE_ACTION)) {
            $status = 'invalid';
        } elseif ($action === 'send') {
            $status = EmailClaimService::issue();
        } else {
            $token = Arr::get($_POST, EmailClaimService::QUERY_TOKEN, '');
            $status = is_string($token) ? EmailClaimService::confirm(sanitize_text_field(wp_unslash($token))) : 'invalid';
        }

        return add_query_arg(EmailClaimService::QUERY_STATUS, $status, $portal);
    }

    /**
     * The notice/form HTML for the current request, or '' when there is nothing to show.
     */
    public static function render(): string
    {
        if (!is_user_logged_in()) {
            return '';
        }

        $status = Arr::get($_GET, EmailClaimService::QUERY_STATUS, '');
        $status = is_string($status) ? sanitize_key($status) : '';

        $token = Arr::get($_GET, EmailClaimService::QUERY_TOKEN, '');
        $token = is_string($token) ? sanitize_text_field(wp_unslash($token)) : '';

        $confirmEmail = '';
        if ($token) {
            $claim = EmailClaimService::resolveClaim($token);
            if ($claim['status'] === 'ok') {
                $confirmEmail = $claim['email'];
            } else {
                $status = $claim['status'];
                $token = '';
            }
        }

        $offer = $token ? null : EmailClaimService::getOffer();
        $progress = CustomerRecoveryService::progress(get_current_user_id());
        if (!$token && !EmailVerificationService::isRequired(get_current_user_id())) {
            if (!$offer && in_array($status, ['sent', 'confirmed'], true)) {
                $status = ''; // A previously opened send-link URL is no longer current.
            }
            if (($progress['status'] ?? '') === 'blocked_storage') {
                $status = 'storage_unsupported';
            } elseif (($progress['status'] ?? '') === 'pending') {
                $status = 'recovering';
            } elseif ($status === 'recovering' && ($progress['status'] ?? '') === 'completed') {
                $status = $offer ? 'confirmed' : '';
            } elseif (in_array($status, ['', 'recovering'], true) && in_array($progress['status'] ?? '', ['failed', 'incomplete', 'cancelled'], true)) {
                $status = 'recovery_failed';
            }
        }


        if (!$status && !$offer && !$token) {
            if (!EmailVerificationService::isRequired(get_current_user_id())) {
                return '';
            }
            $status = EmailClaimService::isEnabled() ? 'conflict' : 'disabled';
        }

        $messages = [
            'storage_unsupported' => __('Email confirmation and purchase recovery are unavailable. Please contact the store.', 'fluent-cart'),
            'recovering'    => __('Your email is confirmed. Past purchases are being recovered in the background. You can use your dashboard while recovery finishes.', 'fluent-cart'),
            'recovery_failed' => __('Some past purchases could not be recovered. Request a new confirmation link to retry, or contact the store.', 'fluent-cart'),
            'sent'          => __('Confirmation email sent. Please check your inbox and follow the link to confirm your email address. If you cannot find it, check your spam folder.', 'fluent-cart'),
            'confirmed'     => __('Your email address is confirmed and your purchases are now in this account.', 'fluent-cart'),
            'incomplete'    => __('Your email address is confirmed, but some purchases could not be moved. Please contact the store.', 'fluent-cart'),
            'throttled'     => __('Too many confirmation requests. Please try again in an hour.', 'fluent-cart'),
            'failed'        => __('Your email could not be confirmed. Please try again or contact the store.', 'fluent-cart'),
            'send_failed'   => __('The confirmation email could not be sent. Please try again.', 'fluent-cart'),
            'expired'       => __('This confirmation link has expired. Request a new one below.', 'fluent-cart'),
            'wrong_account' => __('Sign in to the account that requested this confirmation link.', 'fluent-cart'),
            'stale'         => __('Your account has changed since this link was sent. Request a new one below.', 'fluent-cart'),
            'conflict'      => __('This address belongs to another customer account. Please contact the store.', 'fluent-cart'),
            'invalid'       => __('This confirmation link is not valid.', 'fluent-cart'),
            'disabled'      => __('Email confirmation is not available.', 'fluent-cart'),
            'unavailable'   => __('There is nothing to confirm for this account.', 'fluent-cart'),
            'no_portal'     => __('The customer portal is not configured. Please contact the store.', 'fluent-cart'),
        ];

        return (string) App::make('view')->make('frontend.customer.email_claim', [
            'message'       => Arr::get($messages, $status, ''),
            'is_sent'       => $status === 'sent',
            'is_error'      => $status && !in_array($status, ['sent', 'confirmed', 'recovering'], true),
            'offer_email'   => $offer ? $offer['to'] : '',
            'offer_reason'  => $offer ? $offer['reason'] : '',
            'confirm_email' => $confirmEmail,
            'token'         => $token,
            'action_url'    => (new StoreSettings())->getCustomerProfilePage(),
        ]);
    }
}
