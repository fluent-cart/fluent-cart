<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * Generic Fallback Template for FluentCart Archives mainly for product archives
 *
 */
do_action('fluent_cart/generic_template/rendering');
get_header();
?>
<?php do_action('fluent_cart/generic_template/before_content'); ?>
<div style="width: 100%; max-width: var(--global-content-width); display: block; margin-top: 20px; margin-bottom: 20px;" class="fct-genric-template-wrapper site-container">
    <div id="main" class="site-main">
        <?php do_action('fluent_cart/template/before_content'); ?>
        <?php do_action('fluent_cart/template/main_content'); ?>
        <?php do_action('fluent_cart/template/after_content'); ?>
    </div>
</div>
<?php do_action('fluent_cart/generic_template/after_content'); ?>

<?php get_footer(); ?>
