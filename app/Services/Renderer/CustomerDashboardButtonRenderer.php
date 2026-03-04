<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\TemplateService;
use FluentCart\Framework\Support\Arr;

class CustomerDashboardButtonRenderer
{

    public function render(array $atts = []): void
    {
        $this->renderButton($atts);
    }

    protected function renderButton($atts = []): void
    {
        $displayType = Arr::get($atts, 'display_type', 'button');
        $buttonText = Arr::get($atts, 'button_text', '');
        $isShortcode = Helper::toBool(Arr::get($atts, 'is_shortcode', false));
        $linkTarget = Arr::get($atts, 'link_target', '_self');
        $showIcon = Helper::toBool(Arr::get($atts, 'show_icon', true), true);

        if (empty($buttonText)) {
            $buttonText = __('My Account', 'fluent-cart');
        }

        $class = $displayType === 'button'
            ? 'wp-block-button__link wp-element-button fct-customer-dashboard-btn'
            : 'fct-customer-dashboard-link';

        $linkAtts = [
            'href'       => TemplateService::getCustomerProfileUrl(),
            'class'      => $class,
            'aria-label' => $buttonText,
            'target'     => $linkTarget,
        ];

        if ($linkTarget === '_blank') {
            $linkAtts['rel'] = 'noopener noreferrer';
        }

        if ($isShortcode) {
            ob_start();
            $this->renderAttributes($linkAtts);
            $wrapperAttributes = ob_get_clean();
        } else {
            $wrapperAttributes = get_block_wrapper_attributes($linkAtts);
        }

        ?>
        <a <?php echo
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        $wrapperAttributes; ?> >
            <?php if ($showIcon): ?>
                <span class="fct-customer-dashboard-icon">
                    <?php echo $this->getUserIcon(); ?>
                </span>
            <?php endif; ?>
            <span><?php echo wp_kses_post($buttonText); ?></span>
        </a>
        <?php
    }

    protected function getUserIcon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M20 22H18V20C18 18.3431 16.6569 17 15 17H9C7.34315 17 6 18.3431 6 20V22H4V20C4 17.2386 6.23858 15 9 15H15C17.7614 15 20 17.2386 20 20V22ZM12 13C8.68629 13 6 10.3137 6 7C6 3.68629 8.68629 1 12 1C15.3137 1 18 3.68629 18 7C18 10.3137 15.3137 13 12 13ZM12 11C14.2091 11 16 9.20914 16 7C16 4.79086 14.2091 3 12 3C9.79086 3 8 4.79086 8 7C8 9.20914 9.79086 11 12 11Z"></path></svg>';
    }

    protected function renderAttributes($atts = []): void
    {
        foreach ($atts as $attr => $value) {
            if ($value !== '') {
                echo esc_attr($attr) . '="' . esc_attr((string)$value) . '" ';
            } else {
                echo esc_attr($attr);
            }
        }
    }
}
