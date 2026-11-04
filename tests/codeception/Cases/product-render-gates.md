# Product render gates

Visibility gates for product display regions — `fluent_cart/product/show_{region}`
plus the `fluent_cart/product/show_section` catch-all, and the before/after block
actions that let an integration render its own markup in a region's place.

A gate returning false OMITS the region's markup. Blanking one of the existing
`*_text` filters only empties a label and leaves a styled button behind, which is
why every sentence below is about a region being absent, not empty.

One hook name serves every surface (shop grid, single product page, carousels,
shortcodes, Bricks, Divi, AJAX fragments); listeners narrow by the payload's
`scope` rather than by hook name.

## Gating regions on the shop product card (Integration)
- [x] 1. A shop product card leaves out the price when the price region is gated off.
- [x] 2. A shop product card leaves out the title when the title region is gated off.
- [x] 3. A shop product card leaves out the image when the image region is gated off.
- [x] 4. A shop product card leaves out the Add to Cart button when that button is gated off.
- [x] 5. Gating off the whole action row removes the buttons inside it.
- [x] 6. The catch-all gate hides every region of a product card at once.
- [x] 7. A gate can be narrowed to the shop card while the single product page keeps the region.
- [x] 8. Hiding the purchase buttons still leaves a variable product card linking through to its options.

## Gating regions on the single product page (Integration)
- [x] 9. The single product page leaves out the price when the price region is gated off.
- [x] 10. The single product page leaves out the quantity picker when it is gated off.
- [x] 11. Gating off the action row on the single product page removes both purchase buttons.
- [x] 12. A listener cannot re-enable one button once the whole action row is gated off.
- [x] 13. Gating off the buy section removes the purchase buttons and the quantity picker together.

## Injecting markup around a region (Integration)
- [x] 14. The card wrapper announces itself before and after rendering, carrying the product.
- [x] 15. The title region announces itself before and after rendering on both surfaces.

## Customising the card wrapper (Integration)
- [x] 16. Custom CSS classes added by a listener reach the product card wrapper.
- [x] 17. A class name containing markup is neutralised before it reaches the card wrapper.

## Knowing which builder asked for the render (Integration)

`scope` says which region of the page; these say which builder is drawing it.
Every product hook payload carries `source` and `source_name`.

Gutenberg is detected from WordPress's own block-render state, so no block
needed wiring. Shortcodes declare themselves in the one closure they all
register through. Anything else reports `unknown` until it opts in with a
single `fluent_cart/product/render_source` filter.

- [x] 18. A render whose caller cannot be identified is reported as unknown rather than guessed at.
- [x] 19. A Gutenberg block reports itself by block name without any block having to say so.
- [x] 20. A shortcode reports itself by name.
- [x] 21. A shortcode rendering inside a block is reported as the shortcode, and the block resumes afterwards.
- [x] 22. A render that throws still leaves the next render correctly labelled.
- [x] 23. A builder core cannot detect can opt in to being reported correctly.
- [x] 24. The injection actions carry the calling builder too, not just the visibility filters.
- [x] 25. A caller that already knows better is not overwritten.
