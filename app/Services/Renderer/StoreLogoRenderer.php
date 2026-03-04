<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class StoreLogoRenderer
{
    protected $storeSettings;

    public function __construct()
    {
        $this->storeSettings = new StoreSettings();
    }

    public function getStoreLogo(): string
    {
        return $this->storeSettings->get('store_logo.url', '');
    }

 
    public function getStoreName(): string
    {
        return $this->storeSettings->get('store_name', get_bloginfo('name'));
    }

    
    public function render(array $atts = []): void
    {
        $defaults = [
            'is_shortcode' => false
        ];

        $atts = wp_parse_args($atts, $defaults);

        $isLink = Helper::toBool(Arr::get($atts, 'is_link', true), true);
        $isShortcode = Helper::toBool(Arr::get($atts, 'is_shortcode', false));

        // Custom logo from block attributes
        $customLogoUrl = Arr::get($atts, 'logo_url', '');
        $atts['logo_url'] = !empty($customLogoUrl) ? $customLogoUrl : $this->getStoreLogo();
        $atts['store_name'] = $this->getStoreName();

        $wrapperAttributes = '';
        if (!$isShortcode) {
            $wrapperAttributes = get_block_wrapper_attributes([
                'class' => 'fct-store-logo-wrapper'
            ]);
        }

        ?>
            <div <?php echo $wrapperAttributes; ?>>
                <?php
                    if ($isLink) {
                        $this->renderWithLink($atts);
                    } else {
                        $this->renderWithoutLink($atts);
                    }
                ?>
            </div>
        <?php
    }

   
     protected function renderWithLink(array $atts): void
    {
        $linkTarget = Arr::get($atts, 'link_target', '_self');
        $rel = $linkTarget === '_blank' ? 'noopener noreferrer' : '';

        ?>
            <a href="<?php echo esc_url(home_url('/')); ?>"
            target="<?php echo esc_attr($linkTarget); ?>"
            rel="<?php echo esc_attr($rel); ?>"
            class="fct-store-logo-link">
                <?php $this->renderLogoContent($atts); ?>
            </a>
        <?php
    }

   
    protected function renderWithoutLink(array $atts): void
    {
        ?>
            <div class="fct-store-logo-without-link">
                <?php $this->renderLogoContent($atts); ?>
            </div>
        <?php
    }

   
    protected function renderLogoContent(array $atts): void
    {
        $logoUrl = Arr::get($atts, 'logo_url', '');
        $storeName = Arr::get($atts, 'store_name', '');

        if (empty($logoUrl)) {
            $this->renderTextFallback($storeName);
            return;
        }

        $this->renderImage($atts);
    }

    protected function renderImage(array $atts): void
    {
        $maxWidth  = absint(Arr::get($atts, 'max_width'));
        $maxHeight = absint(Arr::get($atts, 'max_height'));

        // Fallback to defaults if empty or invalid
        $maxWidth  = $maxWidth > 0 ? $maxWidth : 150;
        $maxHeight = $maxHeight > 0 ? $maxHeight : 70;

        $style = sprintf(
                '--max-width:%dpx; --max-height:%dpx;',
                $maxWidth,
                $maxHeight
        );
        
        ?>
            <img src="<?php echo esc_url($atts['logo_url']); ?>"
                alt="<?php echo esc_attr($atts['store_name']); ?>"
                class="fct-store-logo-img"
                style="<?php echo esc_attr($style); ?>">
        <?php
    }

   
    protected function renderTextFallback(string $storeName): void
    {
        ?>
        <span class="fct-store-logo-text">
            <?php echo esc_html($storeName); ?>
        </span>
        <?php
    }
}
