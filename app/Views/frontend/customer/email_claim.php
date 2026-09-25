<?php if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
/**
 * @var string $message
 * @var bool   $is_sent
 * @var bool   $is_error
 * @var string $offer_email    Address the account could confirm ('' when none)
 * @var string $offer_reason   'unverified' | 'diverged' | 'recovery' | ''
 * @var string $confirm_email  Address a valid mailed link is about to confirm ('' when none)
 * @var string $token
 * @var string $action_url
 */
?>
<section class="fct-email-claim fct-alert" role="region" aria-label="<?php esc_attr_e('Confirm your email address', 'fluent-cart'); ?>">
    <h2><?php esc_html_e('Confirm your email address', 'fluent-cart'); ?></h2>
    <?php if ($message): ?>
        <p role="<?php echo $is_error ? 'alert' : 'status'; ?>"><?php echo esc_html($message); ?></p>
    <?php endif; ?>

    <?php if ($token && $confirm_email): ?>
        <p>
            <?php
            // translators: %1$s is the email address being confirmed
            printf(esc_html__('Confirm %1$s as the email address for this account. Your contact email will be updated and any purchases made with it will appear here.', 'fluent-cart'), '<strong>' . esc_html($confirm_email) . '</strong>');
            ?>
        </p>
        <form method="post" action="<?php echo esc_url($action_url); ?>" data-auto-confirm>
            <?php wp_nonce_field(\FluentCart\App\Services\CustomerIdentity\EmailClaimPortal::NONCE_ACTION); ?>
            <input type="hidden" name="<?php echo esc_attr(\FluentCart\App\Services\CustomerIdentity\EmailClaimPortal::ACTION_FIELD); ?>" value="confirm">
            <input type="hidden" name="<?php echo esc_attr(\FluentCart\App\Services\CustomerIdentity\EmailClaimService::QUERY_TOKEN); ?>" value="<?php echo esc_attr($token); ?>">
            <button type="submit" class="fct-email-claim-button" data-loading-text="<?php esc_attr_e('Confirming…', 'fluent-cart'); ?>" aria-live="polite"><?php esc_html_e('Confirm email address', 'fluent-cart'); ?></button>
        </form>
    <?php elseif ($offer_email): ?>
        <p>
            <?php
            if ($offer_reason === 'unverified') {
                // translators: %1$s is the account email address.
                printf(esc_html__('Your email address %1$s is not verified yet. Please verify your email before you start using your customer portal.', 'fluent-cart'), '<strong>' . esc_html($offer_email) . '</strong>');
            } elseif ($offer_reason === 'diverged') {
                // translators: %1$s is the account's email address
                printf(esc_html__('Your account and customer email addresses do not match. Confirm %1$s to access your customer portal and update your contact email.', 'fluent-cart'), '<strong>' . esc_html($offer_email) . '</strong>');
            } else {
                // translators: %1$s is the account's email address
                printf(esc_html__('Purchases made with %1$s as a guest are not yet part of this account. Confirm the address to bring them in.', 'fluent-cart'), '<strong>' . esc_html($offer_email) . '</strong>');
            }
            ?>
        </p>
        <form method="post" action="<?php echo esc_url($action_url); ?>">
            <?php wp_nonce_field(\FluentCart\App\Services\CustomerIdentity\EmailClaimPortal::NONCE_ACTION); ?>
            <input type="hidden" name="<?php echo esc_attr(\FluentCart\App\Services\CustomerIdentity\EmailClaimPortal::ACTION_FIELD); ?>" value="send">
            <button type="submit" class="fct-email-claim-button" data-loading-text="<?php esc_attr_e('Sending…', 'fluent-cart'); ?>" aria-live="polite"><?php echo $is_sent ? esc_html__('Resend confirmation email', 'fluent-cart') : esc_html__('Send confirmation email', 'fluent-cart'); ?></button>
        </form>
    <?php endif; ?>
    <p><a href="<?php echo esc_url(wp_logout_url($action_url)); ?>"><?php esc_html_e('Log out', 'fluent-cart'); ?></a></p>
</section>
<script>
(function () {
    const notice = document.currentScript.previousElementSibling;
    notice.querySelectorAll('form').forEach(function (form) {
        const button = form.querySelector('[data-loading-text]');
        if (!button) {
            return;
        }
        const label = button.textContent;
        form.addEventListener('submit', function (event) {
            if (form.getAttribute('aria-busy') === 'true') {
                event.preventDefault();
                return;
            }
            if (event.defaultPrevented) {
                return;
            }
            form.setAttribute('aria-busy', 'true');
            button.disabled = true;
            button.textContent = button.dataset.loadingText;
        });
        // Restore the form when the browser returns to it from its page cache.
        window.addEventListener('pageshow', function (event) {
            if (!event.persisted) {
                return;
            }
            form.removeAttribute('aria-busy');
            button.disabled = false;
            button.textContent = label;
        });
        // Only the server-validated claim form may confirm automatically.
        // Keep the button as a fallback when JavaScript is unavailable.
        if (form.hasAttribute('data-auto-confirm') && typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        }
    });
})();
</script>
