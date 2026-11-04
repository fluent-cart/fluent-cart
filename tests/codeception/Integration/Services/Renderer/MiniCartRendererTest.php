<?php

namespace Tests\Integration\Services\Renderer;

use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Traits\RendersMiniCart;

/**
 * The mini cart renders four caller-supplied presentation attributes into markup.
 *
 * `button_class` reaches a class="..." attribute; `icon_color`, `price_color` and
 * `product_count_color` reach style="..." attributes. All four arrive verbatim from
 * shortcode/block attributes — ShortCode::parseAttribute() returns them unfiltered —
 * so every one of them has to be escaped at the sink. A Contributor can author a
 * shortcode without holding unfiltered_html, which makes an unescaped sink here a
 * privilege-escalation path rather than a cosmetic bug.
 */
class MiniCartRendererTest extends WPTestCase
{
    use RendersMiniCart;

    public function test_button_class_cannot_break_out_of_the_class_attribute(): void
    {
        $html = $this->renderMiniCartMarkup([
            'button_class' => 'x" onmouseover="alert(document.domain)" data-x="',
        ]);

        $this->assertStringNotContainsString(
            'onmouseover="',
            $html,
            'button_class closed the class attribute and injected a live event handler'
        );
        $this->assertStringContainsString(
            '&quot;',
            $html,
            'the double quotes in button_class should survive as entities inside the class attribute'
        );
    }

    public function test_button_class_cannot_inject_a_script_tag(): void
    {
        $html = $this->renderMiniCartMarkup([
            'button_class' => '"><script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString(
            '<script',
            $html,
            'button_class escaped its attribute and opened a new element'
        );
    }

    public function test_button_class_keeps_ordinary_class_names_intact(): void
    {
        $html = $this->renderMiniCartMarkup([
            'button_class' => 'my-custom-class another_class hover:bg-blue-500',
        ]);

        $this->assertStringContainsString(
            'class="fct-mini-cart-button my-custom-class another_class hover:bg-blue-500"',
            $html,
            'escaping must not mangle legitimate class names, including Tailwind variants'
        );
    }

    /**
     * The three colour attributes are the siblings of button_class and were already
     * escaped. This pins that, so a future edit cannot quietly regress them the way
     * button_class was missed.
     */
    public function test_colour_attributes_cannot_break_out_of_the_style_attribute(): void
    {
        $html = $this->renderMiniCartMarkup([
            'icon_color'          => 'red;" onmouseover="alert(1)',
            'price_color'         => 'red;" onfocus="alert(2)',
            'product_count_color' => 'red;" onblur="alert(3)',
            'show_item_count'     => 'always',
            'show_total_price'    => true,
        ]);

        $this->assertStringNotContainsString('onmouseover="', $html, 'icon_color broke out of its style attribute');
        $this->assertStringNotContainsString('onfocus="', $html, 'price_color broke out of its style attribute');
        $this->assertStringNotContainsString('onblur="', $html, 'product_count_color broke out of its style attribute');
    }
}
