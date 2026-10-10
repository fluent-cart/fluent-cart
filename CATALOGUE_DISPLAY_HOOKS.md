# Catalogue display filters

Model-backed renderers can read product, variation and order-item labels
directly, without passing through WordPress's title/content filters. These
optional filters allow extensions to adapt those reads:

| Filter | Arguments |
| --- | --- |
| `fluent_cart/product/display_field` | Original value, field (`post_title`, `post_excerpt`, `post_content`), product model |
| `fluent_cart/variation/display_title` | Original value, variation model |
| `fluent_cart/order_item/display_field` | Original value, field (`post_title`, `title`), order-item model |
| `fluent_cart/cart/display_item` | Item array copied into `CartItemRenderer` |

Without listeners, all values are unchanged. Model accessors do not assign to
stored attributes; the cart filter only receives the renderer's local copy.
Normal escaping remains the responsibility of the existing renderer.

## Example

```php
add_filter('fluent_cart/product/display_field', function ($value, $field, $product) {
    if (is_admin() || wp_doing_cron() || $field !== 'post_title') {
        return $value;
    }

    return my_extension_product_label($product->ID, $value);
}, 10, 3);
```

These are model read filters, not automatically front-end-only filters.
Extensions must decide when to opt in, including REST requests and explicit
recipient-rendering contexts. Filtered reads can also appear in `toArray()`
output. Code that needs canonical values should use `getAttributes()` rather
than a filtered property/serialization. Do not save translated display values
back onto products, cart records or historical order-item snapshots.

## Tests

```sh
bash tests/bin/run-all.sh pure catalogue-display-hooks
```

The cases use real models with in-memory attributes and WordPress filters.
They perform no database writes and cover unfiltered values, filter context,
unchanged raw attributes and the cart renderer's local copy.
