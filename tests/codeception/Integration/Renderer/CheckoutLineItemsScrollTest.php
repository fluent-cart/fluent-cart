<?php

namespace Tests\Integration\Renderer;

use FluentCart\App\Services\Renderer\CartSummaryRender;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;

/**
 * A long cart scrolls inside the order summary. Checkout JS makes the list
 * focusable only while it scrolls; a focusable box needs a name, so the
 * server names it, and must not add a Tab stop to every cart.
 */
class CheckoutLineItemsScrollTest extends DatabaseTestCase
{
    public function test_the_line_item_list_is_a_named_group_without_a_tab_stop()
    {
        $cart = (new CartFactory())->create([
            'cart_data' => [CartFactory::makeCartItem()],
        ]);

        ob_start();
        (new CartSummaryRender($cart))->renderItemsLists();
        $html = ob_get_clean();

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
        libxml_clear_errors();

        $list = (new \DOMXPath($dom))->query('//div[@class="fct_line_items"]')->item(0);

        $this->assertNotNull($list, 'The list keeps its class; the scroll CSS targets it');
        $this->assertSame('group', $list->getAttribute('role'));
        $this->assertFalse($list->hasAttribute('tabindex'), 'JS adds it only when the list scrolls');
        $this->assertNotSame('', trim($list->getAttribute('aria-label')));
    }
}
