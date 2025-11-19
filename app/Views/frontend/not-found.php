<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="fct-not-found-container" role="main" aria-labelledby="fct-not-found-title">
    <div class="fct-not-found-content">
        <?php if (!empty($notFoundImg)): ?>
            <img class="fct-not-found-image"
                 src="<?php echo esc_url($notFoundImg ?? ''); ?>"
                 alt="<?php esc_attr_e('Page not found illustration', 'fluent-cart'); ?>"
            >
        <?php endif; ?>

        <?php if (isset($title)): ?>
            <h1 id="fct-not-found-title" class="fct-not-found-title">
                <?php echo wp_kses_post($title); ?>
            </h1>
        <?php endif; ?>

        <?php if (isset($text)): ?>
            <p class="fct-not-found-text">
                <?php echo wp_kses_post($text); ?>
            </p>
        <?php endif; ?>

        <?php if (isset($buttonText)): ?>
            <a
                    href="<?php echo empty($buttonUrl) ? esc_url(home_url()) : esc_url($buttonUrl); ?>"
                    class="fct-not-found-button"
                    role="button"
                    aria-label="<?php
                    printf(
                        /* translators: %s is the button text/destination */
                        esc_attr__('Return to %s', 'fluent-cart'),
                        esc_html($buttonText)
                    );
                    ?>"
            >
                <?php echo esc_html($buttonText); ?>
            </a>
        <?php endif; ?>
    </div>
</div>
