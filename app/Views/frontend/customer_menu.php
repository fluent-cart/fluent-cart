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
        <span class="fct-customer-dashboard-avatar" aria-hidden="true">
            <?php if (!empty($profileData['photo'])) : ?>
                <img src="<?php echo esc_url($profileData['photo']); ?>" alt="" loading="lazy" onerror="this.remove()" />
            <?php endif; ?>
            <svg class="fct-customer-dashboard-avatar-placeholder" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <path d="M16.6667 17.5V15.8333C16.6667 14.9493 16.3155 14.1014 15.6904 13.4763C15.0653 12.8512 14.2174 12.5 13.3334 12.5H6.66671C5.78265 12.5 4.93481 12.8512 4.30968 13.4763C3.68456 14.1014 3.33337 14.9493 3.33337 15.8333V17.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M10 9.16667C11.8410 9.16667 13.3334 7.67428 13.3334 5.83333C13.3334 3.99238 11.8410 2.5 10 2.5C8.15909 2.5 6.66671 3.99238 6.66671 5.83333C6.66671 7.67428 8.15909 9.16667 10 9.16667Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </span>
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

