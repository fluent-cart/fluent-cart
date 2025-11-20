<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
/**
 * @var $menuItems
 * @var $profileData
 */
?>

<div id="fct-customer-dashboard-navs-wrap" class="fct-customer-dashboard-navs-wrap" role="navigation" aria-label="<?php esc_attr_e('Customer Dashboard', 'fluent-cart'); ?>">
    <div class="fct-nav-compact-toggle-wrap">
        <button
            type="button"
            aria-label="<?php esc_attr_e('Toggle navigation menu', 'fluent-cart'); ?>"
            id="fct-customer-nav-compact-toggle"
        >
            <svg class="fct-compact-toggle-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                <path d="M12.1329 5.08936L13.115 6.07145L9.88108 9.30539L16.2505 9.30546L16.2504 10.6943L9.88108 10.6943L13.115 13.9282L12.133 14.9103L7.22246 9.99983L12.1329 5.08936ZM4.44482 14.8609V5.13867H5.83371V14.8609H4.44482Z" fill="#565865"/>
            </svg>
        </button>
    </div>

    <?php if($profileData): ?>
    <div class="fct-customer-dashboard-customer-info" role="banner">
        <img src="<?php echo esc_url($profileData['photo']); ?>" alt="<?php echo esc_attr($profileData['full_name']); ?>" />
        <div class="fct-customer-dashboard-customer-info-content">
            <h3><?php echo esc_attr($profileData['full_name']); ?></h3>
            <p><?php echo esc_attr($profileData['email']); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <div id="fct-customer-menu-container">
        <button 
            id="fct-customer-menu-toggle"
            type="button"
            aria-expanded="false"
            aria-controls="fct-customer-menu-holder"
            aria-label="<?php esc_attr_e('Toggle navigation menu', 'fluent-cart'); ?>"
        >
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3 4H21V6H3V4ZM3 11H21V13H3V11ZM3 18H21V20H3V18Z"></path></svg>
        </button>

        <div id="fct-customer-menu-holder" aria-hidden="false">
            <div class="fct-customer-navs-wrap">
                <ul class="fct-customer-navs" role="list">
                    <?php foreach ($menuItems as $itemSlug => $menuItem): ?>
                        <li class="fct-customer-nav-item fct-customer-nav-item-<?php echo esc_attr($itemSlug); ?>" role="listitem">
                            <a
                                class="fct-customer-nav-link <?php echo esc_attr(\FluentCart\Framework\Support\Arr::get($menuItem, 'css_class')); ?>"
                                aria-label="<?php echo esc_attr($menuItem['label']) ?>"
                                href="<?php echo esc_url($menuItem['link']); ?>"
                            >
                                <span class="fct-customer-nav-link-icon">
                                    <?php
                                        if (!empty($menuItem['icon_svg'])) {
                                            echo wp_kses(
                                                    $menuItem['icon_svg'],
                                                    apply_filters('fct_allowed_svg_tags', [])
                                            );
                                        } elseif (!empty($menuItem['icon_url'])) {
                                            echo '<img src="' . esc_url($menuItem['icon_url']) . '" alt="" />';
                                        }
                                    ?>
                                </span>

                                <span class="fct-customer-nav-link-text">
                                   <?php echo esc_html($menuItem['label']); ?>
                                </span>
                            </a>

                            <span class="fct-customer-nav-tooltip">
                                <?php echo esc_html($menuItem['label']); ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Logout Button -->
            <div id="fct-customer-logout-button">
                <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" title="<?php echo esc_attr__('Logout', 'fluent-cart'); ?>" class="fct-customer-logout-btn" aria-label="<?php esc_attr_e('Logout', 'fluent-cart'); ?>">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M5 22C4.44772 22 4 21.5523 4 21V3C4 2.44772 4.44772 2 5 2H19C19.5523 2 20 2.44772 20 3V6H18V4H6V20H18V18H20V21C20 21.5523 19.5523 22 19 22H5ZM18 16V13H11V11H18V8L23 12L18 16Z"></path></svg>

                    <span class="button-text"><?php esc_html_e('Logout', 'fluent-cart'); ?></span>
                </a>
            </div>
        </div>
    </div>
</div>

