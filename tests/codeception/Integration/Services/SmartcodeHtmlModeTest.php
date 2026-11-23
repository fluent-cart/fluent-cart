<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

class SmartcodeHtmlModeTest extends WPTestCase
{
    private function dataForName(string $firstName): array
    {
        $customer = (new CustomerFactory())->create(['first_name' => $firstName, 'last_name' => 'Buyer']);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id])->fresh();

        return ['order' => $order];
    }

    private function attributesOf(string $html, string $tag): array
    {
        $processor = new \WP_HTML_Tag_Processor($html);
        $this->assertTrue($processor->next_tag($tag), "The output must keep the <$tag> tag.");
        $attributes = [];
        foreach ((array) $processor->get_attribute_names_with_prefix('') as $name) {
            $attributes[$name] = $processor->get_attribute($name);
        }

        return $attributes;
    }

    public function test_a_quote_in_a_value_cannot_add_an_attribute(): void
    {
        $data = $this->dataForName('Ann" data-injected="1');

        $html = ShortcodeTemplateBuilder::makeHtml('<a href="https://crm.example/?q={{order.customer.full_name}}">Find</a>', $data);
        $attributes = $this->attributesOf($html, 'a');
        $this->assertSame(['href'], array_keys($attributes), 'A buyer value must stay inside its attribute.');
        $this->assertStringContainsString('data-injected', $attributes['href'], 'The value itself must be kept.');

        $html = ShortcodeTemplateBuilder::makeHtml("<img src=\"x.png\" alt='{{order.customer.full_name}}'>", $this->dataForName("Ann' data-injected='1"));
        $this->assertSame(['src', 'alt'], array_keys($this->attributesOf($html, 'img')), 'Single-quoted attributes must be escaped too.');
    }

    public function test_the_attribute_after_a_quoted_angle_bracket_is_found(): void
    {
        $data = $this->dataForName('Ann" data-injected="1');

        $html = ShortcodeTemplateBuilder::makeHtml('<img title="a > b" alt="{{order.customer.full_name}}">', $data);
        $this->assertSame(['title', 'alt'], array_keys($this->attributesOf($html, 'img')));
    }

    public function test_a_value_cannot_make_a_url_attribute_use_a_script_protocol(): void
    {
        $data = $this->dataForName('javascript:void(0)//');

        $html = ShortcodeTemplateBuilder::makeHtml('<a href="{{order.customer.first_name}}">Go</a>', $data);
        $href = (string) ($this->attributesOf($html, 'a')['href'] ?? '');
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $href);
    }

    public function test_a_duplicate_attribute_cannot_carry_a_raw_value(): void
    {
        $data = $this->dataForName('Ann" data-injected="1');

        $html = ShortcodeTemplateBuilder::makeHtml('<a title="Order" title="{{order.customer.full_name}}">Go</a>', $data);
        $this->assertStringNotContainsString(' data-injected="1"', $html);
        $this->assertSame(['title'], array_keys($this->attributesOf($html, 'a')));
    }

    public function test_an_unquoted_duplicate_attribute_cannot_carry_a_value(): void
    {
        $data = $this->dataForName('Ann data-injected=1');

        $html = ShortcodeTemplateBuilder::makeHtml('<a title=x title={{order.customer.first_name}}>Go</a>', $data);
        $this->assertSame(['title'], array_keys($this->attributesOf($html, 'a')));
    }

    public function test_a_duplicate_attribute_cannot_carry_a_markup_value(): void
    {
        $filter = function ($value, $data) {
            return 'markup.value' === $value ? '<b>x</b>" data-injected="1' : $value;
        };
        add_filter('fluent_cart/smartcode_fallback', $filter, 10, 2);

        $html = ShortcodeTemplateBuilder::makeHtml('<a title="x" title="{{markup.value}}">Go</a><p>{{markup.value}}</p>', []);
        remove_filter('fluent_cart/smartcode_fallback', $filter, 10);

        $this->assertSame(['title'], array_keys($this->attributesOf($html, 'a')));
        $this->assertStringContainsString('<p><b>x</b>" data-injected="1</p>', $html, 'A value in text renders as given.');
    }

    public function test_markers_follow_browser_tokenizing(): void
    {
        $values = [
            'markup.value' => '<b title="x" data-injected="1">Y</b>',
            'link.value'   => '<a title="a > b" href="https://example.com">Click</a>',
            'plain.value'  => 'Alice',
        ];
        $filter = function ($value) use ($values) {
            return $values[$value] ?? $value;
        };
        add_filter('fluent_cart/smartcode_fallback', $filter, 10, 1);

        $html = ShortcodeTemplateBuilder::makeHtml('<!--><a title="safe" title="{{markup.value}}">Go</a>', []);
        $this->assertSame(['title'], array_keys($this->attributesOf($html, 'a')), 'An abruptly closed comment must not hide a tag.');

        $this->assertSame('<div>' . $values['link.value'] . '</div>', ShortcodeTemplateBuilder::makeHtml('<div>{{link.value}}</div>', []), 'A markup value must render intact.');
        $this->assertSame('<textarea><b title="Alice"></textarea>', ShortcodeTemplateBuilder::makeHtml('<textarea><b title="{{plain.value}}"></textarea>', []), 'Textarea content is text.');

        remove_filter('fluent_cart/smartcode_fallback', $filter, 10);
    }

    public function test_a_smartcode_in_an_attribute_name_drops_the_attribute(): void
    {
        $data = $this->dataForName('onclick');

        $html = ShortcodeTemplateBuilder::makeHtml('<p {{order.customer.first_name}}="x">Hi</p>', $data);
        $this->assertSame([], array_keys($this->attributesOf($html, 'p')));
    }

    public function test_text_positions_unknown_codes_and_urls_render_as_before(): void
    {
        $data = $this->dataForName('Ann & Co');
        $order = $data['order'];

        $this->assertSame(
            ShortcodeTemplateBuilder::make('<p>{{order.customer.full_name}}</p>', $data),
            ShortcodeTemplateBuilder::makeHtml('<p>{{order.customer.full_name}}</p>', $data),
            'A value in text position must not change.'
        );
        $this->assertSame('<div data-x=\'{{"a":1}}\'>{{unknown.code}}</div>', ShortcodeTemplateBuilder::makeHtml('<div data-x=\'{{"a":1}}\'>{{unknown.code}}</div>', $data));

        $html = ShortcodeTemplateBuilder::makeHtml('<a href="{{order.customer_order_link}}">View</a><img alt="{{order.customer.full_name}}">', $data);
        $this->assertSame(
            (string) ShortcodeTemplateBuilder::make('{{order.customer_order_link}}', $data),
            $this->attributesOf($html, 'a')['href'],
            'A SmartCode that is a whole URL must still work.'
        );
        $this->assertSame('Ann & Co Buyer', $this->attributesOf($html, 'img')['alt'], 'An ampersand must not be double encoded.');
        $this->assertNotEmpty($order->uuid);
    }

    public function test_a_value_is_not_parsed_again_by_a_second_html_pass(): void
    {
        $data = $this->dataForName('{{order.customer.email}} ##order.customer.email##');
        $template = '<p>{{order.customer.first_name}}</p><img alt="{{order.customer.first_name}}">';

        $html = ShortcodeTemplateBuilder::makeHtml(ShortcodeTemplateBuilder::makeHtml($template, $data), $data);

        $this->assertStringNotContainsString((string) $data['order']->customer->email, $html, 'A SmartCode inside a value must stay literal text.');
        $this->assertSame('{{order.customer.email}} ##order.customer.email##', $this->attributesOf($html, 'img')['alt']);
        $this->assertStringContainsString('&#123;&#123;order.customer.email}}', $html);
    }

    public function test_an_entity_in_a_value_is_not_double_encoded_in_an_attribute(): void
    {
        $html = ShortcodeTemplateBuilder::makeHtml('<img alt="{{order.customer.first_name}}">', $this->dataForName('Tom&#039;s'));

        $this->assertSame("Tom's", $this->attributesOf($html, 'img')['alt']);
    }

    public function test_entity_and_tab_variants_of_a_script_protocol_are_dropped(): void
    {
        foreach (['javascript&#58;void(0)//', "java\tscript:void(0)//", 'java&#09;script:void(0)//', ' JaVaScRiPt:void(0)//'] as $name) {
            $html = ShortcodeTemplateBuilder::makeHtml('<a title="5 > 3" href="{{order.customer.first_name}}">Go</a>', $this->dataForName($name));
            $href = (string) ($this->attributesOf($html, 'a')['href'] ?? '');
            $this->assertDoesNotMatchRegularExpression('/script\s*:/i', preg_replace('/\s+/', '', $href), 'Variant: ' . $name);
        }
    }

    public function test_url_attributes_keep_the_hosts_scheme_and_refuse_script_schemes(): void
    {
        $values = [
            'u.name'   => 'Ada',
            'u.js'     => 'javascript:alert(1)',
            'u.ent'    => 'javascript&amp;#58;alert(1)',
            'u.custom' => 'whatsapp://send?text=x',
            'u.data'   => 'data:text/html,x',
            'u.quote'  => "O'Hara &quot;",
            'u.left'   => 'javascript&#',
            'u.right'  => '58;alert(1)',
        ];
        $filter = function ($value) use ($values) {
            return $values[$value] ?? $value;
        };
        add_filter('fluent_cart/smartcode_fallback', $filter, 10, 1);

        $href = function ($template, $tag = 'a', $attribute = 'href') {
            return $this->attributesOf(ShortcodeTemplateBuilder::makeHtml($template, []), $tag)[$attribute] ?? null;
        };

        $this->assertSame('whatsapp://send?text=Ada', $href('<a href="whatsapp://send?text={{u.name}}">x</a>'), 'A scheme the template wrote must stay.');
        $this->assertSame("https://cal.example/add?d=a%0Ab&n=Ada", $href('<a href="https://cal.example/add?d=a%0Ab&amp;n={{u.name}}">x</a>'), 'Encoded line breaks must stay.');
        $this->assertNull($href('<a href="{{u.custom}}">x</a>'), 'A value cannot bring a scheme WordPress does not allow.');
        $this->assertNull($href('<svg><a xlink:href="{{u.js}}">x</a></svg>', 'a', 'xlink:href'), 'SVG links are URL attributes.');
        $this->assertNull($href('<a ping="{{u.js}}" href="/">x</a>', 'a', 'ping'), 'ping is a URL attribute.');
        $this->assertNull($href('<a href="{{u.ent}}">x</a>'), 'Entities are decoded until stable before the check.');
        $this->assertNull($href('<a href="{{u.left}}{{u.right}}">x</a>'), 'Two values cannot spell an encoded colon together.');
        $this->assertNull($href('<svg><a xlink:href="{{u.ent}}">x</a></svg>', 'a', 'xlink:href'));
        $this->assertSame('data:image/png;base64,Ada', $href('<img src="data:image/png;base64,{{u.name}}">', 'img', 'src'), 'A data: scheme the template wrote stays.');
        $this->assertNull($href('<img src="{{u.data}}">', 'img', 'src'), 'A value cannot bring data:.');

        $html = ShortcodeTemplateBuilder::makeHtml('<a class="c" href="https://x.example/?n={{u.quote}}" id="i">x</a>', []);
        $this->assertSame(['class', 'href', 'id'], array_keys($this->attributesOf($html, 'a')), 'The URL attribute keeps its place and no attribute is added.');
        $this->assertStringContainsString('?n=O&#039;Hara &quot;"', $html, 'Quotes are encoded the way every mail client reads.');

        remove_filter('fluent_cart/smartcode_fallback', $filter, 10);
    }

    public function test_a_character_reference_cannot_form_a_script_scheme(): void
    {
        $variants = ['java&#13;script:alert(1)', 'java&#x0D;script:alert(1)', 'javascript&#58alert(1)', 'java&#9script:alert(1)', 'vb&#13;script:msgbox(1)'];
        $filter = function ($value) use ($variants) {
            return preg_match('/^v(\d)$/', $value, $m) ? $variants[$m[1]] : $value;
        };
        add_filter('fluent_cart/smartcode_fallback', $filter, 10, 1);

        foreach (array_keys($variants) as $index) {
            $html = ShortcodeTemplateBuilder::makeHtml('<a href="{{v' . $index . '}}">x</a><svg><a xlink:href="{{v' . $index . '}}">y</a></svg>', []);
            $tags = new \WP_HTML_Tag_Processor($html);
            while ($tags->next_tag('a')) {
                foreach (['href', 'xlink:href'] as $name) {
                    // get_attribute() decodes as a browser does; browsers also drop control characters.
                    $url = preg_replace('/[\x00-\x20\x7f]+/', '', (string) $tags->get_attribute($name));
                    $this->assertDoesNotMatchRegularExpression('/^(javascript|vbscript):/i', $url, 'Variant: ' . $variants[$index]);
                }
            }
        }

        remove_filter('fluent_cart/smartcode_fallback', $filter, 10);
    }

    public function test_plain_text_mode_keeps_raw_values(): void
    {
        $data = $this->dataForName('Ann "Al"');

        $this->assertSame('Hi Ann "Al" Buyer', ShortcodeTemplateBuilder::make('Hi {{order.customer.full_name}}', $data), 'Subjects and addresses must stay plain text.');
    }
}
