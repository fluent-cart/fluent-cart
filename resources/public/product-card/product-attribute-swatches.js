// A swatch block's variants, parsed once from data-variants.
function variantsOf(rows) {
    if (!rows.__fctVariants) {
        try {
            rows.__fctVariants = JSON.parse(rows.dataset.variants || '[]');
        } catch (e) {
            rows.__fctVariants = [];
        }
    }
    return rows.__fctVariants;
}

/**
 * The variant whose values match the selected swatch in every row, so picking M then Red
 * lands on the red M variant rather than the first red one. Null when no variant has them all.
 */
export function matchVariant(rows) {
    if (!rows || !rows.dataset.variants) {
        return null;
    }
    variantsOf(rows);

    const chosen = [];
    rows.querySelectorAll('[data-fct-card-swatches]').forEach((row) => {
        const pressed = row.querySelector('[data-fct-card-swatch][aria-pressed="true"]');
        if (pressed) {
            chosen.push([row.dataset.groupId, pressed.dataset.termId]);
        }
    });

    return rows.__fctVariants.find((variant) => chosen.every(([groupId, termId]) => {
        return String((variant.terms || {})[groupId]) === String(termId);
    })) || null;
}

// The swatch's place and size in the row from its layout box, which ignores transforms
// such as the press squeeze still easing back when the click lands.
function boxInRow(row, swatch) {
    let left = 0;
    let top = 0;
    for (let node = swatch; node && node !== row; node = node.offsetParent) {
        left += node.offsetLeft;
        top += node.offsetTop;
    }
    return {left, top, width: swatch.offsetWidth, height: swatch.offsetHeight};
}

// Puts the row's highlight on a swatch; a swatch not shown (behind "+N") hides it.
function placeIndicator(row, indicator, swatch) {
    const shown = !!swatch && swatch.offsetWidth > 0;
    indicator.style.opacity = shown ? '' : '0';
    if (shown) {
        const box = boxInRow(row, swatch);
        indicator.style.width = box.width + 'px';
        indicator.style.height = box.height + 'px';
        indicator.style.transform = `translate(${box.left}px, ${box.top}px)`;
    }
}

/**
 * Slides the row's selected look from the old pick to the new one, like the editor's segmented tabs.
 * The highlight sits in the row, not the option list, whose layout rules count their children. It takes
 * the chosen swatch's own fill, ring and corners, so it matches every card layout. It is made on a
 * row's first change, so untouched cards keep the swatch's own pressed style.
 */
export function slideSelection(row, from, to, look = null) {
    if (!row || from === to) {
        return;
    }
    let indicator = row.__fctSwatchIndicator;
    if (!indicator) {
        if (!from || !to || !look || typeof ResizeObserver === 'undefined') {
            return;
        }
        indicator = row.__fctSwatchIndicator = document.createElement('span');
        indicator.className = 'fct-card-swatch-indicator';
        indicator.setAttribute('aria-hidden', 'true');
        Object.assign(indicator.style, look);
        row.prepend(indicator);
        row.classList.add('has-swatch-indicator');
        placeIndicator(row, indicator, from);
        // A row that wraps differently (resize, list view) moves the highlight without a slide. A size
        // change that leaves the swatch in place (a longer name in the heading) keeps a running slide.
        // The swatches are watched too: one can change size while the row keeps its own (a late web font).
        const observer = new ResizeObserver(() => {
            const pressed = row.querySelector('[data-fct-card-swatch][aria-pressed="true"]');
            const target = pressed && pressed.offsetWidth > 0 ? boxInRow(row, pressed) : null;
            if (target && indicator.style.transform === `translate(${target.left}px, ${target.top}px)`
                && indicator.style.width === target.width + 'px' && indicator.style.height === target.height + 'px') {
                return;
            }
            row.classList.remove('is-swatch-sliding');
            placeIndicator(row, indicator, pressed);
        });
        observer.observe(row);
        row.querySelectorAll('[data-fct-card-swatch]').forEach((item) => observer.observe(item));
    }
    // On every pick the old swatch takes its resting look at once: fading its own fill (first pick) or
    // border would trail behind the highlight. Reading a box commits that, and on the first pick the
    // highlight's start, so only the move below animates.
    if (from) {
        from.style.transition = 'none';
        from.getBoundingClientRect();
        from.style.transition = '';
    }
    row.classList.add('is-swatch-sliding');
    placeIndicator(row, indicator, to);
}

function setPressed(row, swatch) {
    const from = row.querySelector('[data-fct-card-swatch][aria-pressed="true"]');
    // The chosen look, read while it is still the chosen one; only the row's first change needs it.
    let look = null;
    if (from && swatch && from !== swatch && !row.__fctSwatchIndicator) {
        const style = getComputedStyle(from);
        look = {backgroundColor: style.backgroundColor, boxShadow: style.boxShadow, borderRadius: style.borderRadius};
    }
    row.querySelectorAll('[data-fct-card-swatch]').forEach((item) => {
        item.setAttribute('aria-pressed', item === swatch ? 'true' : 'false');
    });
    slideSelection(row, from, swatch, look);
    const label = row.querySelector('[data-fct-card-swatch-label]');
    if (label) {
        label.textContent = swatch ? (swatch.dataset.label || '') : '';
    }
}

// "Selected: Navy · M" under the rows, when the choice shows below the options: each row's pick, in order.
export function syncSelectedLine(rows) {
    const selected = rows && rows.querySelector('[data-fct-card-swatch-selected-values]');
    if (!selected) {
        return;
    }
    const text = Array.from(rows.querySelectorAll('[data-fct-card-swatches]'))
        .map((item) => item.querySelector('[data-fct-card-swatch][aria-pressed="true"]')?.dataset.label)
        .filter(Boolean)
        .join(' · ');
    // The line is a live region: rewriting the same text would announce it again.
    if (selected.textContent !== text) {
        selected.textContent = text;
    }
}

function syncSwatchRows(card, variant) {
    if (!variant) {
        return;
    }
    card.querySelectorAll('[data-fct-card-swatches]').forEach((row) => {
        const termId = (variant.terms || {})[row.dataset.groupId];
        setPressed(row, row.querySelector(`[data-fct-card-swatch][data-term-id="${CSS.escape(String(termId))}"]`));
    });
    syncSelectedLines(card);
}

// Once per change, after every row is set, so screen readers hear one finished line.
function syncSelectedLines(card) {
    card.querySelectorAll('[data-fct-card-swatch-rows]').forEach((rows) => {
        syncSelectedLine(rows);
        syncAvailability(rows);
    });
}

/**
 * While sold-out values are marked, a value whose combination with the other rows' picks has no
 * in-stock variant (Navy with 250g) shows as sold out too. Unlike a value sold out everywhere it
 * stays pickable: picking it moves the other rows to a combination in stock.
 */
export function syncAvailability(rows) {
    if (!rows || rows.dataset.soldOutDisplay !== 'cross') {
        return;
    }
    const variants = variantsOf(rows);
    const groups = Array.from(rows.querySelectorAll('[data-fct-card-swatches]'));
    const picks = {};
    groups.forEach((group) => {
        const pressed = group.querySelector('[data-fct-card-swatch][aria-pressed="true"]');
        if (pressed) {
            picks[group.dataset.groupId] = String(pressed.dataset.termId);
        }
    });
    groups.forEach((group) => {
        const groupId = group.dataset.groupId;
        // The values each row could take with the other rows' picks: once per row, not per swatch.
        const existing = new Set();
        const inStock = new Set();
        variants.forEach((variant) => {
            const terms = variant.terms || {};
            const fits = Object.entries(picks).every(([otherId, termId]) => otherId === groupId || String(terms[otherId]) === termId);
            if (fits) {
                existing.add(String(terms[groupId]));
                if (variant.in_stock) {
                    inStock.add(String(terms[groupId]));
                }
            }
        });
        group.querySelectorAll('[data-fct-card-swatch]').forEach((swatch) => {
            if (swatch.hasAttribute('data-sold-out')) {
                return;
            }
            const termId = String(swatch.dataset.termId);
            const available = inStock.has(termId);
            const label = swatch.dataset.label || '';
            // "Sold out" when the combination exists but is out of stock; "not available" when the product has none.
            const template = (existing.has(termId) ? rows.dataset.soldOutLabel : rows.dataset.unavailableLabel) || '%s';
            swatch.toggleAttribute('data-unavailable', !available);
            swatch.setAttribute('aria-label', available ? label : template.replace('%s', label));
        });
    });
}

/**
 * No variant has every pressed value (e.g. M then Camel when only L/Camel exists): move the
 * other rows to the first variant with the clicked value, so the card shows what the link opens.
 */
function reconcileRows(rows, clickedRow, swatch) {
    const variant = (rows.__fctVariants || []).find((item) => {
        return String((item.terms || {})[clickedRow.dataset.groupId]) === String(swatch.dataset.termId);
    });
    if (!variant) {
        return null;
    }
    rows.querySelectorAll('[data-fct-card-swatches]').forEach((row) => {
        if (row === clickedRow) {
            return;
        }
        // The value may sit beyond Max Swatches; then nothing in this row is pressed.
        const termId = (variant.terms || {})[row.dataset.groupId];
        setPressed(row, row.querySelector(`[data-fct-card-swatch][data-term-id="${CSS.escape(String(termId))}"]`));
    });
    return variant;
}

// The variant in stock with the clicked value whose every value has a button (none hidden behind +N).
function moveToInStock(rows, clickedRow, swatch) {
    const rowsById = new Map(Array.from(rows.querySelectorAll('[data-fct-card-swatches]'), (row) => [row.dataset.groupId, row]));
    const buttonFor = (groupId, termId) => rowsById.get(groupId)
        ?.querySelector(`[data-fct-card-swatch][data-term-id="${CSS.escape(String(termId))}"]`);
    const variant = variantsOf(rows).find((item) => item.in_stock
        && String((item.terms || {})[clickedRow.dataset.groupId]) === String(swatch.dataset.termId)
        && Array.from(rowsById.keys()).every((groupId) => buttonFor(groupId, (item.terms || {})[groupId])));
    if (!variant) {
        return null;
    }
    rowsById.forEach((row, groupId) => {
        if (row !== clickedRow) {
            setPressed(row, buttonFor(groupId, variant.terms[groupId]));
        }
    });
    return variant;
}

/**
 * What the card button offers for the picked variant. No variant means the rows
 * landed on a combination the product does not sell.
 */
export function cardButtonState(variant, config, complete = true) {
    const labels = config.labels || {};
    if (!complete) {
        return {mode: 'off', label: labels.choose};
    }
    if (!variant) {
        return {mode: 'off', label: labels.unavailable};
    }
    if (!variant.in_stock) {
        return {mode: 'off', label: labels.out_of_stock};
    }
    if (variant.subscription) {
        return config.can_buy ? {mode: 'buy', label: labels.buy} : {mode: 'off', label: labels.unavailable};
    }
    return config.can_add ? {mode: 'add', label: labels.add} : {mode: 'off', label: labels.unavailable};
}

function setButtonText(button, text) {
    const holder = button.querySelector('.fct-button-text') || button;
    holder.textContent = text || '';
}

/**
 * Turns the card's View Options button into the buy button for the picked variant.
 * Without the script, or on a card the rows cannot pin, View Options stays as it is;
 * the Quick View block is the card's quick view from then on.
 */
function setupCardButton(rows) {
    // Kept on the card: a card can hold more than one swatches block, and all of them drive one button.
    const card = rows.closest('[data-fct-product-card], .fct-product-card');
    if (!card) {
        return null;
    }
    if (card.__fctCardButton && card.contains(card.__fctCardButton.current)) {
        return card.__fctCardButton;
    }
    delete card.__fctCardButton;

    let config;
    try {
        config = JSON.parse(rows.dataset.cardButton || '');
    } catch (e) {
        return null;
    }

    const add = card.querySelector('[data-fluent-cart-single-product-card-view-button]:not(.fct-card-quick-view-button, .fct-card-swatch-more)');
    if (!add) {
        return null;
    }

    ['data-fluent-cart-single-product-card-view-button', 'data-product-id', 'data-button-url', 'aria-label'].forEach((name) => {
        add.removeAttribute(name);
    });
    add.classList.add('fct-card-buy-button');
    add.setAttribute('data-is-custom', 'false');

    const price = card.querySelector('.fct-product-card-prices .fct-item-price');
    card.__fctCardButton = {
        config,
        rows,
        add,
        current: add,
        className: add.className,
        price,
        suffix: card.querySelector('.fct-product-card-prices .fct_price_suffix'),
        originalSuffix: card.querySelector('.fct-product-card-prices .fct_price_suffix')?.innerHTML || '',
        originalPrice: price ? Array.from(price.childNodes, (node) => node.cloneNode(true)) : null,
    };
    return card.__fctCardButton;
}

function buyNowLink(parts, variant, label) {
    const link = document.createElement('a');
    link.className = parts.className;
    ['style', 'id', 'title'].forEach((name) => {
        if (parts.add.hasAttribute(name)) {
            link.setAttribute(name, parts.add.getAttribute(name));
        }
    });
    link.href = variant.purchase_url;
    if (parts.config.modal) {
        // Set before it joins the page, where the modal checkout script binds new links.
        link.setAttribute('data-fct-instant-checkout-button', '');
        link.setAttribute('data-enable-modal-checkout', 'yes');
    }
    const text = document.createElement('span');
    text.className = 'fct-button-text';
    text.textContent = label || '';
    link.append(text);
    return link;
}

export function hasCompleteSelection(rows, variant, scope = rows) {
    if (!variant) {
        return Array.from(rows.querySelectorAll('[data-fct-card-swatches]')).every(row => row.querySelector('[data-fct-card-swatch][aria-pressed="true"]'));
    }
    return Array.from(rows.querySelectorAll('[data-fct-card-swatches]')).every(row =>
        Array.from(scope.querySelectorAll('[data-fct-card-swatches]')).some(other =>
            other.dataset.groupId === row.dataset.groupId &&
            String(variant.terms?.[row.dataset.groupId]) === other.querySelector('[data-fct-card-swatch][aria-pressed="true"]')?.dataset.termId
        )
    );
}

function syncCardButton(rows, variant) {
    const parts = setupCardButton(rows);
    if (!parts) {
        return;
    }

    const card = rows.closest('[data-fct-product-card], .fct-product-card');
    const complete = hasCompleteSelection(rows, variant, card);
    if (!complete) {
        variant = null;
    }
    const state = cardButtonState(variant, parts.config, complete);
    if (variant) updateCardMedia(card, rows, variant);
    parts.rows = rows;
    parts.variant = variant;
    let next = parts.add;
    if (state.mode === 'buy') {
        // A fresh link each time, so the modal checkout script binds it exactly once.
        next = buyNowLink(parts, variant, state.label);
    } else {
        const canAdd = state.mode === 'add';
        parts.add.disabled = !canAdd;
        parts.add.toggleAttribute('data-fluent-cart-add-to-cart-button', canAdd);
        if (canAdd) {
            parts.add.setAttribute('data-cart-id', variant.id);
        } else {
            parts.add.removeAttribute('data-cart-id');
        }
        setButtonText(parts.add, state.label);
    }
    if (next !== parts.current) {
        parts.current.replaceWith(next);
        parts.current = next;
    }

    if (parts.price) {
        const suffix = variant && variant.price ? (variant.price_suffix || '') : parts.originalSuffix;
        if (!parts.suffix && suffix) {
            parts.suffix = document.createElement('span');
            parts.suffix.className = 'fct_price_suffix';
            parts.price.after(parts.suffix);
        }
        if (parts.suffix) {
            parts.suffix.innerHTML = suffix;
            parts.suffix.hidden = !suffix;
        }
        if (variant && variant.price) {
            parts.price.textContent = variant.price;
        } else {
            parts.price.replaceChildren(...parts.originalPrice.map((node) => node.cloneNode(true)));
        }
    }
}

/** Sets every card button under scope, e.g. cards just added by pagination or filters. */
export function syncCardButtons(scope) {
    scope = scope.closest?.('[data-fct-product-card], .fct-product-card') || scope;
    const blocks = scope.matches && scope.matches('[data-fct-card-swatch-rows]')
        ? [scope] : scope.querySelectorAll('[data-fct-card-swatch-rows]');
    const seen = new Set();
    blocks.forEach((block) => {
        const card = block.closest('[data-fct-product-card], .fct-product-card');
        if (!card || seen.has(card)) {
            return;
        }
        seen.add(card);
        const rows = card.querySelector('[data-fct-card-swatch-rows][data-card-button]');
        if (!rows) {
            // No card button to sync, but the rows still need their marks.
            syncSelectedLines(card);
            return;
        }
        const existing = card.__fctCardButton;
        if (!existing || !card.contains(existing.current)) {
            const variant = matchVariant(rows);
            if (variant && hasCompleteSelection(rows, variant, card)) {
                syncCardButton(rows, variant);
            }
        } else {
            const price = card.querySelector('.fct-product-card-prices .fct-item-price');
            if (price !== existing.price) {
                existing.price = price;
                existing.originalPrice = price ? Array.from(price.childNodes, node => node.cloneNode(true)) : null;
                existing.suffix = card.querySelector('.fct-product-card-prices .fct_price_suffix');
                existing.originalSuffix = existing.suffix?.innerHTML || '';
                syncCardButton(existing.rows, existing.variant);
            }
            if (existing.variant) updateCardMedia(card, existing.rows, existing.variant);
        }
        syncSwatchRows(card, card.__fctCardButton?.variant);
        // Without a variant syncSwatchRows stops early; the rows still need their state.
        if (!card.__fctCardButton?.variant) {
            syncSelectedLines(card);
        }
    });
}

function updateCardMedia(card, rows, variant, swatch = null) {
    let targetImage = swatch?.dataset.image || '';
    let targetUrl = swatch?.dataset.url || '';
    if (variant && rows.dataset.productUrl) {
        const url = new URL(rows.dataset.productUrl, window.location.href);
        url.searchParams.set('selected', variant.id);
        targetUrl = url.toString();
        targetImage = variant.image || '';
    }

    const image = card.querySelector('.fct-product-card-image');
    if (image) {
        // Keep the card's own picture so a variant without an image can bring it back.
        if (image.dataset.fctOriginalSrc === undefined) {
            image.dataset.fctOriginalSrc = image.getAttribute('src') || '';
            image.dataset.fctOriginalSrcset = image.getAttribute('srcset') || '';
        }
        if (targetImage) {
            image.removeAttribute('srcset');
            if (image.getAttribute('src') !== targetImage) image.src = targetImage;
        } else {
            if (image.getAttribute('src') !== image.dataset.fctOriginalSrc) image.src = image.dataset.fctOriginalSrc;
            if (image.dataset.fctOriginalSrcset) {
                image.setAttribute('srcset', image.dataset.fctOriginalSrcset);
            }
        }
    }

    // The card's links to this product open it with the variant already chosen.
    // Only links to the same product page: a purchase or checkout link stays as it is.
    if (targetUrl) {
        const productPath = new URL(targetUrl, window.location.href).pathname;
        card.querySelectorAll('a.fct-product-card-image-wrap, .fct-product-card-title a, a.fct-product-card-title, a.fct-product-view-button').forEach((link) => {
            if (new URL(link.href, window.location.href).pathname === productPath) {
                if (link.href !== targetUrl) link.href = targetUrl;
            }
        });
    }
}

// One delegated listener, so cards added later by pagination or filtering work too.
export default function initAttributeSwatch(root = document) {
    if (root.__fctAttributeSwatchBound) {
        return;
    }
    root.__fctAttributeSwatchBound = true;

    // A native tooltip turns the hand cursor into an arrow, so a text option gets one only when its label is cut short.
    root.addEventListener('pointerover', (event) => {
        const swatch = event.target.closest?.('[data-fct-card-swatch]');
        const text = swatch?.querySelector('.fct-card-swatch-text');
        if (!text) {
            return;
        }
        if (text.scrollWidth > text.clientWidth) {
            swatch.title = swatch.dataset.label || text.textContent.trim();
        } else {
            swatch.removeAttribute('title');
        }
    });

    root.addEventListener('click', (event) => {
        const swatch = event.target.closest('[data-fct-card-swatch]');
        if (!swatch) {
            return;
        }
        // A crossed-out, sold-out value stays focusable for screen readers but cannot be picked.
        if (swatch.getAttribute('aria-disabled') === 'true') {
            event.preventDefault();
            return;
        }

        const group = swatch.closest('[data-fct-card-swatches]');
        if (!group) {
            return;
        }

        const card = swatch.closest('[data-fct-product-card], .fct-product-card');
        if (!card) {
            return;
        }

        setPressed(group, swatch);

        // Prefer the variant matching every row; without one, the other rows follow this swatch.
        const clickedRows = swatch.closest('[data-fct-card-swatch-rows]');
        const rows = clickedRows.dataset.cardButton ? clickedRows : (card.__fctCardButton?.rows || clickedRows);
        const selectedRow = Array.from(rows.querySelectorAll('[data-fct-card-swatches]'))
            .find((row) => row.dataset.groupId === group.dataset.groupId);
        if (selectedRow && selectedRow !== group) {
            setPressed(selectedRow, selectedRow.querySelector(`[data-fct-card-swatch][data-term-id="${CSS.escape(swatch.dataset.termId)}"]`));
        }
        // Match against the complete block so a secondary row keeps the other choices.
        let variant = matchVariant(rows);
        const previous = card.__fctCardButton?.variant;
        if (previous) {
            const chosen = {...previous.terms, [group.dataset.groupId]: swatch.dataset.termId};
            variant = rows.__fctVariants.find(item => Object.entries(chosen)
                .every(([key, value]) => String(item.terms?.[key]) === String(value))) || null;
        }
        if (!variant || String((variant.terms || {})[group.dataset.groupId]) !== swatch.dataset.termId) {
            variant = reconcileRows(rows, group, swatch);
        } else if (rows.dataset.soldOutDisplay && !variant.in_stock) {
            // Sold-out values are marked, so a pick that lands on a sold-out combination moves the
            // other rows to one in stock with the picked value, but only to values the card shows.
            variant = moveToInStock(rows, group, swatch) || variant;
        }
        syncSwatchRows(card, variant);
        // syncSwatchRows updates the line; without a variant it stops early, but the clicked row still changed.
        if (!variant) {
            syncSelectedLines(card);
        }
        updateCardMedia(card, rows, variant, swatch);

        if (rows && rows.dataset.cardButton) {
            syncCardButton(rows, variant);
        }
    });
}
