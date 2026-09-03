export default class AdvancedVariationSelector {
    #container;
    #pricingSection;
    #variationMap;
    #variantData;
    #groupTermMap; // { "groupId": [termId, ...] } — authoritative ownership from data-attribute-config
    #productId;
    #selectorStyle;
    #selectedTerms; // { groupId: termId }
    #groupIds; // ordered list of group IDs
    #primaryGroupId; // group whose term selection drives the gallery image swap
    #termImageMap;   // { termId: imageUrl } — one image per primary-group term

    init(container) {
        this.#container = container;
        this.#pricingSection = container.closest('[data-fluent-cart-product-pricing-section]');
        this.#productId = container.getAttribute('data-product-id');
        this.#selectorStyle = container.getAttribute('data-selector-style') || 'dropdown';

        try {
            this.#variationMap   = JSON.parse(container.getAttribute('data-variation-map') || '{}');
            this.#variantData    = JSON.parse(container.getAttribute('data-variant-data') || '{}');
            this.#groupTermMap   = JSON.parse(container.getAttribute('data-attribute-config') || '{}');
            this.#termImageMap   = JSON.parse(container.getAttribute('data-term-image-map') || '{}');
        } catch (e) {
            this.#variationMap  = {};
            this.#variantData   = {};
            this.#groupTermMap  = {};
            this.#termImageMap  = {};
        }
        this.#primaryGroupId = parseInt(container.getAttribute('data-primary-group-id') || '0', 10);

        this.#selectedTerms = {};
        this.#groupIds = [];

        const selectors = container.querySelectorAll('.fct-attribute-selector');
        selectors.forEach(sel => {
            const groupId = sel.getAttribute('data-group-id');
            if (groupId) {
                this.#groupIds.push(parseInt(groupId, 10));
            }
        });

        this.#setupListeners();
        this.#applyDefaultSelection();

        return this;
    }

    /**
     * On first paint, pre-select the merchant's default variant (if set)
     * so the page lands with a complete selection (Color: Red, Material:
     * Cotton, …) instead of forcing the shopper to click every group
     * before price / stock / cart are populated.
     *
     * Resolution order:
     *   1. data-default-variation-id (ProductDetail.default_variation_id)
     *      reverse-mapped against variationMap to recover its identifier.
     *   2. First IN-STOCK identifier in variationMap. Honours
     *      variantData[id].stock so a default that would otherwise land on
     *      an out-of-stock combination is skipped.
     *   3. First identifier in variationMap (final fallback when every
     *      variant is out of stock — better than leaving the form empty).
     */
    #applyDefaultSelection() {
        if (this.#groupIds.length === 0) return;

        const identifiers = Object.keys(this.#variationMap);
        if (identifiers.length === 0) return;

        let chosenIdentifier = null;

        const defaultVariationId = parseInt(this.#container.getAttribute('data-default-variation-id') || '0', 10);
        if (defaultVariationId) {
            const entry = Object.entries(this.#variationMap).find(([, variantId]) => Number(variantId) === defaultVariationId);
            if (entry) {
                chosenIdentifier = entry[0];
            }
        }

        if (!chosenIdentifier) {
            // No stored default_variation_id — select the first combination by
            // serial_index, regardless of stock (matches the server-side default
            // rule). Previously this picked the first IN-STOCK combination, which
            // diverged from what the server rendered the price/stock/button for,
            // so on load the highlighted variant and the shown stock disagreed.
            let lowestSerial = Infinity;
            identifiers.forEach(id => {
                const serial = this.#variantData[this.#variationMap[id]]?.serial_index ?? Infinity;
                if (serial < lowestSerial) {
                    lowestSerial = serial;
                    chosenIdentifier = id;
                }
            });
            if (!chosenIdentifier) chosenIdentifier = identifiers[0];
        }

        const termIds = chosenIdentifier.split('_').map(Number);

        this.#groupIds.forEach(groupId => {
            const myTermIds = new Set(this.#groupTermMap[String(groupId)] || []);
            const matchedTerm = termIds.find(tid => myTermIds.has(tid));
            if (matchedTerm) {
                this.#selectedTerms[groupId] = matchedTerm;
                this.#applySelectionUI(groupId, matchedTerm);
            }
        });

        this.#onSelectionChange();
    }

    /**
     * Reflect a (groupId, termId) selection in the DOM — both possible UI
     * surfaces (swatch buttons + custom dropdown options) are handled so the
     * default-selection path can drive either renderer style without caring
     * which one was emitted by the PHP renderer.
     */
    #applySelectionUI(groupId, termId) {
        const swatchButton = this.#container.querySelector(
            `.fct-swatch-item[data-group-id="${groupId}"][data-term-id="${termId}"]`
        );
        if (swatchButton) {
            const siblings = this.#container.querySelectorAll(
                `.fct-swatch-item[data-group-id="${groupId}"]`
            );
            // Roving tabindex pattern (WAI-ARIA Authoring Practices for
            // radiogroup): exactly one swatch per group is tabbable at a
            // time — the currently selected one. The rest get tabindex=-1

            
            // so a keyboard user tabs INTO the group, then uses arrows to
            // walk siblings without the Tab key paging through every term.
            siblings.forEach(s => {
                s.classList.remove('selected');
                s.setAttribute('aria-checked', 'false');
                s.setAttribute('tabindex', '-1');
            });
            swatchButton.classList.add('selected');
            swatchButton.setAttribute('aria-checked', 'true');
            swatchButton.setAttribute('tabindex', '0');
        }

        const dropdownOption = this.#container.querySelector(
            `[data-attribute-group="${groupId}"] [data-fct-select-option][data-value="${termId}"]`
        );
        if (dropdownOption) {
            const customSelect = dropdownOption.closest('[data-fct-custom-select]');
            if (customSelect) {
                this.#markOptionSelected(
                    customSelect.querySelectorAll('[data-fct-select-option]'),
                    dropdownOption
                );
                const valueEl = customSelect.querySelector('[data-fct-select-value]');
                if (valueEl) {
                    this.#copyOptionContent(valueEl, dropdownOption);
                }
            }
        }

        // Surface the selected term's title next to the group label
        // (e.g. "Image: Design 2"). Image groups render thumbnail-only pills
        // with no caption, so the chosen value is shown here instead.
        const source = swatchButton || dropdownOption;
        this.#updateSelectedTermTitle(groupId, source ? source.getAttribute('data-term-title') : '');

        // When the primary visual group (color/image) changes, swap the main
        // gallery image to the one associated with the selected term.
        if (this.#primaryGroupId && groupId === this.#primaryGroupId) {
            const imageUrl = this.#termImageMap[termId];
            window.dispatchEvent(new CustomEvent('fluentCartAdvVariationImageChanged', {
                detail: { productId: this.#productId, url: imageUrl || '', termId: termId }
            }));
        }
    }

    /**
     * Write the selected term's title into the group label's
     * `.fct-selected-term-title` span (e.g. "Image: Design 2"). Only image
     * groups render that span, so this is a no-op for other group types. Called
     * from both the default-selection path (#applySelectionUI) and the user
     * swatch click path so the label stays in sync with the chosen pill.
     */
    #updateSelectedTermTitle(groupId, termTitle) {
        if (!termTitle) return;
        const groupSelector = this.#container.querySelector(
            `.fct-attribute-selector[data-group-id="${groupId}"]`
        );
        if (!groupSelector) return;
        const titleEl = groupSelector.querySelector('.fct-selected-term-title');
        if (titleEl) {
            titleEl.textContent = termTitle;
        }
    }

    /**
     * Replace target's contents with a deep clone of source's child nodes.
     * Avoids innerHTML serialize/re-parse — preserves any event listeners,
     * never round-trips through the HTML parser, and satisfies the project's
     * "no innerHTML with dynamic data" rule. Source is always a node our PHP
     * renderer produced (escaped via esc_html/esc_attr/esc_url), so the
     * cloned subtree is safe by construction.
     */
    #copyOptionContent(target, source) {
        target.textContent = '';
        Array.from(source.childNodes).forEach(node => {
            target.appendChild(node.cloneNode(true));
        });
    }

    /**
     * Open / close a custom dropdown. Keeps the visual `is-open` class and the
     * combobox's `aria-expanded` state in lockstep so assistive tech hears the
     * expanded/collapsed change the PHP renderer initialises to "false".
     */
    #setDropdownOpen(customSelect, isOpen) {
        customSelect.classList.toggle('is-open', isOpen);
        customSelect.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    /**
     * Mark one option selected within its listbox. Mirrors the `selected`
     * class onto `aria-selected` so screen readers announce the chosen
     * option — the renderer emits every option as aria-selected="false".
     */
    #markOptionSelected(options, selectedOption) {
        options.forEach(o => {
            const isSelected = o === selectedOption;
            o.classList.toggle('selected', isSelected);
            o.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }

    /**
     * Move keyboard focus to the next non-disabled option, `direction` steps
     * (+1 down / -1 up) from `fromIndex`, wrapping around the list. Disabled
     * options (progressive narrowing) are skipped so arrow keys never land on
     * an unselectable term.
     */
    #focusAdjacentOption(options, fromIndex, direction) {
        const count = options.length;
        for (let step = 1; step <= count; step++) {
            const idx = ((fromIndex + direction * step) % count + count) % count;
            if (!options[idx].classList.contains('disabled')) {
                options[idx].focus();
                return;
            }
        }
    }

    /**
     * Open a dropdown and move focus onto an option — the selected one if it
     * is still enabled, otherwise the first enabled option. Used by the
     * keyboard-open path so a keyboard user lands inside the listbox.
     */
    #openDropdownWithFocus(customSelect, options) {
        this.#setDropdownOpen(customSelect, true);
        const list = Array.from(options);
        const selectedIndex = list.findIndex(o => o.classList.contains('selected'));
        if (selectedIndex >= 0 && !list[selectedIndex].classList.contains('disabled')) {
            list[selectedIndex].focus();
        } else {
            this.#focusAdjacentOption(options, -1, 1);
        }
    }

    #setupListeners() {
        this.#setupCustomDropdownListeners();
        this.#setupSwatchListeners();
    }

    #setupCustomDropdownListeners() {
        const customSelects = this.#container.querySelectorAll('[data-fct-custom-select]');

        document.addEventListener('click', (e) => {
            customSelects.forEach(sel => {
                if (!sel.contains(e.target)) {
                    this.#setDropdownOpen(sel, false);
                }
            });
        });

        customSelects.forEach(customSelect => {
            const trigger = customSelect.querySelector('[data-fct-select-trigger]');
            const options = customSelect.querySelectorAll('[data-fct-select-option]');
            const valueEl = customSelect.querySelector('[data-fct-select-value]');
            const selectorWrap = customSelect.closest('[data-attribute-group]');
            const groupId = selectorWrap ? parseInt(selectorWrap.getAttribute('data-group-id'), 10) : null;

            if (trigger) {
                trigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    customSelects.forEach(s => {
                        if (s !== customSelect) this.#setDropdownOpen(s, false);
                    });
                    this.#setDropdownOpen(customSelect, !customSelect.classList.contains('is-open'));
                });
            }

            options.forEach(option => {
                // Programmatically focusable (not a Tab stop) so arrow-key
                // navigation can move real focus across options while the
                // combobox itself remains the only Tab stop.
                option.setAttribute('tabindex', '-1');

                option.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (option.classList.contains('disabled')) return;

                    const termId = parseInt(option.getAttribute('data-value'), 10);

                    this.#markOptionSelected(options, option);

                    if (valueEl) {
                        this.#copyOptionContent(valueEl, option);
                    }

                    this.#setDropdownOpen(customSelect, false);

                    if (groupId !== null) {
                        this.#selectedTerms[groupId] = termId;

                        if (this.#primaryGroupId && groupId === this.#primaryGroupId) {
                            const imageUrl = this.#termImageMap[termId];
                            window.dispatchEvent(new CustomEvent('fluentCartAdvVariationImageChanged', {
                                detail: { productId: this.#productId, url: imageUrl || '', termId: termId }
                            }));
                        }

                        this.#onSelectionChange();
                    }
                });

                option.addEventListener('keydown', (e) => {
                    const currentIndex = Array.from(options).indexOf(option);
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        e.stopPropagation();
                        this.#focusAdjacentOption(options, currentIndex, 1);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        e.stopPropagation();
                        this.#focusAdjacentOption(options, currentIndex, -1);
                    } else if (e.key === 'Home') {
                        e.preventDefault();
                        e.stopPropagation();
                        this.#focusAdjacentOption(options, -1, 1);
                    } else if (e.key === 'End') {
                        e.preventDefault();
                        e.stopPropagation();
                        this.#focusAdjacentOption(options, options.length, -1);
                    } else if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        e.stopPropagation();
                        option.click();
                        customSelect.focus();
                    } else if (e.key === 'Escape') {
                        e.stopPropagation();
                        this.#setDropdownOpen(customSelect, false);
                        customSelect.focus();
                    }
                });
            });

            customSelect.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    this.#setDropdownOpen(customSelect, false);
                    return;
                }
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    // When an option holds focus its own handler owns these
                    // keys (and stops propagation); only act for the combobox.
                    if (e.target !== customSelect) return;
                    e.preventDefault();
                    customSelects.forEach(s => {
                        if (s !== customSelect) this.#setDropdownOpen(s, false);
                    });
                    this.#openDropdownWithFocus(customSelect, options);
                }
            });

            // Close when focus leaves the dropdown entirely (e.g. Tab away) —
            // the document-click handler only covers pointer dismissal.
            customSelect.addEventListener('focusout', (e) => {
                if (!customSelect.contains(e.relatedTarget)) {
                    this.#setDropdownOpen(customSelect, false);
                }
            });
        });
    }

    #setupSwatchListeners() {
        // Initial roving tabindex — the PHP renderer stamps tabindex=0 on
        // every swatch button so a no-JS user can still operate them. With
        // JS active, only one swatch per group should be tabbable so a
        // keyboard user lands on a single entry per group and arrows from
        // there. #applyDefaultSelection promotes whichever swatch ends up
        // selected to tabindex=0 right after this; the fallback below just
        // makes sure no group ends up with zero tabbable buttons if no
        // default could be resolved.
        this.#groupIds.forEach(groupId => {
            const groupButtons = this.#container.querySelectorAll(
                `.fct-swatch-item[data-group-id="${groupId}"]`
            );
            if (groupButtons.length === 0) return;
            groupButtons.forEach((btn, idx) => {
                btn.setAttribute('tabindex', idx === 0 ? '0' : '-1');
            });
        });

        const buttons = this.#container.querySelectorAll('.fct-swatch-item');
        buttons.forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                if (button.classList.contains('disabled')) return;

                const groupId = parseInt(button.getAttribute('data-group-id'), 10);
                const termId = parseInt(button.getAttribute('data-term-id'), 10);

                // Click on the already-selected swatch is a no-op — a
                // shopper must always have one term per group selected
                // (price / stock / Add to Cart all depend on a complete
                // selection). Deselect was the old behavior; it left the
                // form in a half-resolved state that broke downstream
                // computeds and let the user reach a "no variant chosen"
                // dead-end via a stray click.
                if (this.#selectedTerms[groupId] === termId) return;

                const siblings = this.#container.querySelectorAll(
                    `.fct-swatch-item[data-group-id="${groupId}"]`
                );
                siblings.forEach(s => {
                    s.classList.remove('selected');
                    s.setAttribute('aria-checked', 'false');
                    s.setAttribute('tabindex', '-1');
                });

                this.#selectedTerms[groupId] = termId;
                button.classList.add('selected');
                button.setAttribute('aria-checked', 'true');
                button.setAttribute('tabindex', '0');
                this.#updateSelectedTermTitle(groupId, button.getAttribute('data-term-title'));

                if (this.#primaryGroupId && groupId === this.#primaryGroupId) {
                    const imageUrl = this.#termImageMap[termId];
                    window.dispatchEvent(new CustomEvent('fluentCartAdvVariationImageChanged', {
                        detail: { productId: this.#productId, url: imageUrl || '', termId: termId }
                    }));
                }

                this.#onSelectionChange();
            });

            button.addEventListener('keydown', (e) => {
                // Enter / Space — activate the focused swatch. Click
                // handler above does the toggle + selection-change work.
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    button.click();
                    return;
                }

                // Arrow / Home / End — WAI-ARIA radiogroup navigation.
                // Move focus to the next enabled sibling in the same
                // group and select it as we go (same UX shoppers get on
                // any other radio set), wrapping at the ends. The roving
                // tabindex driven by #applySelectionUI keeps focus inside
                // the group; Tab still pages out of it cleanly.
                const navKey = ['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'].includes(e.key);
                if (!navKey) return;

                e.preventDefault();
                const groupId = button.getAttribute('data-group-id');
                const siblings = Array.from(this.#container.querySelectorAll(
                    `.fct-swatch-item[data-group-id="${groupId}"]:not(.disabled)`
                ));
                if (siblings.length === 0) return;

                const currentIdx = siblings.indexOf(button);
                let nextIdx;
                if (e.key === 'Home') {
                    nextIdx = 0;
                } else if (e.key === 'End') {
                    nextIdx = siblings.length - 1;
                } else {
                    const dir = (e.key === 'ArrowRight' || e.key === 'ArrowDown') ? 1 : -1;
                    nextIdx = (currentIdx + dir + siblings.length) % siblings.length;
                }

                const target = siblings[nextIdx];
                if (target && target !== button) {
                    // Click before focus — the click promotes target to
                    // .selected, so by the time focus lands the
                    // focus-visible:not(.selected) outline rule no longer
                    // matches and the dark focus ring doesn't paint a
                    // stray frame on top of the new selected swatch.
                    target.click();
                    target.focus();
                }
            });
        });
    }

    #onSelectionChange() {
        this.#updateProgressiveNarrowing();
        this.#resolveVariant();
    }

    #buildIdentifier() {
        if (Object.keys(this.#selectedTerms).length !== this.#groupIds.length) {
            return null;
        }

        const termIds = Object.values(this.#selectedTerms).map(Number);
        termIds.sort((a, b) => a - b);
        return termIds.join('_');
    }

    #resolveVariant() {
        const identifier = this.#buildIdentifier();

        if (!identifier) {
            this.#clearVariantDisplay();
            return;
        }

        const variantId = this.#variationMap[identifier];
        if (!variantId) {
            this.#clearVariantDisplay();
            return;
        }

        const variant = this.#variantData[variantId];
        if (!variant) {
            this.#clearVariantDisplay();
            return;
        }

        this.#updatePriceDisplay(variantId, variant);
        this.#updateCartButtons(variantId, variant);
        this.#updateStockBadge(variant);
    }

    #updatePriceDisplay(variantId) {
        window.fluentCartSingleProduct?.selectVariation(variantId);
    }

    #updateCartButtons(variantId, variant) {
        if (!this.#pricingSection) return;

        const addToCartButtons = this.#pricingSection.querySelectorAll('[data-fluent-cart-add-to-cart-button]');
        const buyNowButtons = this.#pricingSection.querySelectorAll('[data-fluent-cart-direct-checkout-button]');
        const outOfStock = variant.stock === 'out-of-stock';
        const isSubscription = variant.payment_type === 'subscription';

        // Mirror SingleProduct.js#updateProductStatus for the simple /
        // simple_variations path:
        //   OOS  → Add to Cart visible, disabled, .out-of-stock,
        //          <span class="text"> swapped to out_of_stock_button_text
        //          ("Not Available") — shown even for subscriptions; Buy Now hidden.
        //   IN   → non-subscription: Add to Cart re-enabled, text reset to
        //          cart_button_text. subscription: Add to Cart hidden
        //          (subscriptions are Buy Now only). Buy Now visible with the
        //          correct instant-checkout href.
        // Same affordance the simple flow renders, just driven by the
        // advanced variant the shopper picked.
        addToCartButtons.forEach(button => {
            button.setAttribute('data-cart-id', variantId);
            const textEl = button.querySelector('.text');

            if (outOfStock) {
                button.setAttribute('disabled', 'disabled');
                button.classList.add('out-of-stock');
                button.classList.remove('is-hidden');
                if (textEl && window.fluentcart_single_product_vars) {
                    textEl.textContent = window.fluentcart_single_product_vars.out_of_stock_button_text;
                }
            } else {
                button.removeAttribute('disabled');
                button.classList.remove('out-of-stock');
                button.classList.toggle('is-hidden', isSubscription);
                if (textEl && window.fluentcart_single_product_vars) {
                    textEl.textContent = window.fluentcart_single_product_vars.cart_button_text;
                }
            }

            const variantTitle = variant.title || '';
            if (variantTitle) {
                const textEl = button.querySelector('.text');
                const buttonText = textEl ? textEl.textContent.trim() : 'Add To Cart';
                button.setAttribute('aria-label', buttonText + ' - ' + variantTitle);
            }
        });

        buyNowButtons.forEach(button => {
            const buttonText = button.textContent.trim();
            const variantTitle = variant.title || '';
            if (variantTitle) {
                button.setAttribute('aria-label', buttonText + ' - ' + variantTitle);
            }

            if (!outOfStock) {
                const quantity = button.getAttribute('data-quantity') || 1;
                const baseUrl = button.getAttribute('data-url') || '';
                button.setAttribute('href', baseUrl + variantId + '&quantity=' + quantity);
                button.setAttribute('data-cart-id', variantId);
                button.classList.remove('is-hidden');
                button.removeAttribute('disabled');
            } else {
                button.removeAttribute('href');
                button.classList.add('is-hidden');
                button.setAttribute('disabled', 'disabled');
            }
        });
    }

    /**
     * Refresh the storefront "Availability" pill (rendered by
     * ProductRenderer::renderStockAvailability) when the shopper picks a
     * different combination. Lives outside #pricingSection — same scope
     * resolution SingleProduct.js#updateProductStatus uses for the simple
     * / simple_variations path, so the advanced selector swaps the same
     * DOM the simple flow does.
     *
     * Three coordinated changes per status flip:
     *   1. badge text — "In Stock" / "Out Of Stock", run through the
     *      single-product var map for translation.
     *   2. badge class — replace `fct_status_badge_<old>` with the new
     *      one so the colour / styling track the status.
     *   3. parent wrapper (.fct-product-stock) class — same status flips
     *      between `in-stock` / `out-of-stock` so any wrapper-level rules
     *      (border colour, etc.) keep up.
     */
    #updateStockBadge(variant) {
        const productScope =
            this.#container.closest('.fct-product-summary') ||
            this.#container.closest('.product-info-block-wrapper') ||
            this.#container.parentElement;
        if (!productScope) return;

        const badge = productScope.querySelector('[data-fluent-cart-product-stock]');
        if (!badge) return;

        const vars = window.fluentcart_single_product_vars || {};
        const inStockStatus = vars.in_stock_status || 'in-stock';
        const outOfStockStatus = vars.out_of_stock_status || 'out-of-stock';
        const status = (variant.stock || inStockStatus) === outOfStockStatus
            ? outOfStockStatus
            : inStockStatus;

        const labelMap = vars.trans || {};
        const titleCased = status
            .replace(/-/g, ' ')
            .replace(/\b\w/g, c => c.toUpperCase());
        // Prefer a per-status custom label (e.g. from the Bricks Product Stock
        // element) when present; otherwise fall back to the generic label map.
        const customText = status === outOfStockStatus
            ? badge.getAttribute('data-out-of-stock-text')
            : badge.getAttribute('data-in-stock-text');
        badge.textContent = customText || labelMap[titleCased] || titleCased;

        badge.className = badge.className.replace(/fct_status_badge_[\w-]+/g, '').trim();
        badge.classList.add('fct_status_badge_' + status);

        const wrapper = badge.closest('.fct-product-stock');
        if (wrapper) {
            // Re-show the badge if a prior unresolved selection hid it (see #clearVariantDisplay).
            wrapper.classList.remove('is-hidden', inStockStatus, outOfStockStatus, 'in-stock', 'out-of-stock');
            wrapper.classList.add(status);
        }
    }

    #clearVariantDisplay() {
        if (!this.#pricingSection) return;

        const addToCartButtons = this.#pricingSection.querySelectorAll('[data-fluent-cart-add-to-cart-button]');
        addToCartButtons.forEach(button => {
            button.setAttribute('data-cart-id', '');
            button.setAttribute('disabled', 'disabled');
        });

        const buyNowButtons = this.#pricingSection.querySelectorAll('[data-fluent-cart-direct-checkout-button]');
        buyNowButtons.forEach(button => {
            button.removeAttribute('href');
            button.classList.add('is-hidden');
        });

        // An unresolved combination (incomplete selection or one with no
        // matching variant) must not leave the previously resolved variant's
        // price and stock on screen — that reads as "this is the price/stock"
        // for a selection the shopper cannot actually buy. Revert to the
        // neutral default: hide any variant-specific price block (falling back
        // to the server-rendered price range) and hide the stock badge until a
        // valid combination resolves again (#updateStockBadge re-shows it).
        const productScope =
            this.#container.closest('.fct-product-summary') ||
            this.#container.closest('.product-info-block-wrapper') ||
            this.#container.parentElement;
        if (productScope) {
            productScope
                .querySelectorAll('.fluent-cart-product-variation-content[data-variation-id]')
                .forEach(el => el.classList.add('is-hidden'));
            productScope
                .querySelectorAll('.fct-price-range')
                .forEach(el => el.classList.remove('is-hidden'));

            const stockWrapper = productScope.querySelector('.fct-product-stock');
            if (stockWrapper) {
                stockWrapper.classList.add('is-hidden');
            }
        }
    }

    /**
     * Progressive narrowing using data-attribute-config for exact group→term ownership.
     * The reference branch used a heuristic (excluding terms present in other selections)
     * which is incorrect for 3+ groups and also skipped custom dropdown options entirely.
     */
    #updateProgressiveNarrowing() {
        this.#groupIds.forEach(groupId => {
            const otherSelections = {};
            for (const [gid, tid] of Object.entries(this.#selectedTerms)) {
                if (parseInt(gid, 10) !== groupId) {
                    otherSelections[parseInt(gid, 10)] = tid;
                }
            }

            if (Object.keys(otherSelections).length === 0) {
                this.#setAllTermsAvailable(groupId);
                return;
            }

            // Authoritative set of term IDs that belong to this group
            const myTermIds = new Set(this.#groupTermMap[String(groupId)] || []);
            const availableTermIds = new Set();

            for (const [identifier] of Object.entries(this.#variationMap)) {
                const termIds = identifier.split('_').map(Number);

                const matchesOther = Object.entries(otherSelections).every(
                    ([, otherTid]) => termIds.includes(otherTid)
                );

                if (matchesOther) {
                    for (const tid of termIds) {
                        if (myTermIds.has(tid)) {
                            availableTermIds.add(tid);
                        }
                    }
                }
            }

            // Update UI — swatches first, then custom dropdowns
            const swatchButtons = this.#container.querySelectorAll(
                `.fct-swatch-item[data-group-id="${groupId}"]`
            );
            if (swatchButtons.length > 0) {
                swatchButtons.forEach(btn => {
                    const termId = parseInt(btn.getAttribute('data-term-id'), 10);
                    if (availableTermIds.has(termId)) {
                        btn.classList.remove('disabled');
                        btn.removeAttribute('aria-disabled');
                    } else {
                        btn.classList.add('disabled');
                        btn.setAttribute('aria-disabled', 'true');
                    }
                });
            } else {
                // Custom dropdown — the selector the PHP renderer actually outputs
                const customOptions = this.#container.querySelectorAll(
                    `[data-attribute-group="${groupId}"] [data-fct-select-option]`
                );
                customOptions.forEach(option => {
                    const termId = parseInt(option.getAttribute('data-value'), 10);
                    const available = availableTermIds.has(termId);
                    option.classList.toggle('disabled', !available);
                    option.toggleAttribute('aria-disabled', !available);
                });
            }
        });
    }

    #setAllTermsAvailable(groupId) {
        const swatchButtons = this.#container.querySelectorAll(
            `.fct-swatch-item[data-group-id="${groupId}"]`
        );
        if (swatchButtons.length > 0) {
            swatchButtons.forEach(btn => {
                btn.classList.remove('disabled');
                btn.removeAttribute('aria-disabled');
            });
        } else {
            const customOptions = this.#container.querySelectorAll(
                `[data-attribute-group="${groupId}"] [data-fct-select-option]`
            );
            customOptions.forEach(option => {
                option.classList.remove('disabled');
                option.removeAttribute('aria-disabled');
            });
        }
    }
}
