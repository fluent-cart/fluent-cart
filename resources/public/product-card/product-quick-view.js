const CARD = '[data-fct-product-card], .fct-product-card';

// The card's buy control: Add To Cart, Buy Now, View Options or the sold-out button.
function buyControl(card) {
    return Array.from(card.querySelectorAll('.fct-product-view-button, .fluent-cart-add-to-cart-button'))
        .find((element) => !element.closest('[data-fct-card-quick-view]')) || null;
}

function placeBesideButton(slot, card) {
    let row = card.querySelector('.fct-card-buy-row');
    if (!row) {
        const button = buyControl(card);
        if (!button) {
            return false;
        }
        row = document.createElement('div');
        row.className = 'fct-card-buy-row';
        button.before(row);
        row.append(button);
    }
    if (slot.parentElement !== row) row.append(slot);
    return true;
}

function placeOnImage(slot, card) {
    const image = card.querySelector('.fct-product-card-image-wrap');
    if (!image) {
        return false;
    }
    // Beside the image link, never inside it: a button inside a link is not allowed. The image
    // block is reused, since list view stretches it and sizes the link to its height.
    let media = image.parentElement.matches('.fct-product-image-wrap, .fct-product-image-block, .fct-card-media') ? image.parentElement : null;
    if (!media) {
        media = document.createElement('div');
        image.before(media);
        media.append(image);
    }
    media.classList.add('fct-card-media');
    if (slot.parentElement !== media) media.append(slot);
    return true;
}

/** Moves each Quick View block under scope to the place its Position setting names. */
export function placeQuickViews(scope) {
    const slots = scope.matches && scope.matches('[data-fct-card-quick-view]')
        ? [scope]
        : scope.querySelectorAll('[data-fct-card-quick-view]');

    slots.forEach((slot) => {
        const card = slot.closest(CARD);
        if (!card) {
            return;
        }
        // The card still shows View Options, which already opens quick view.
        const button = buyControl(card);
        if (button && button.hasAttribute('data-fluent-cart-single-product-card-view-button')) {
            slot.hidden = true;
            return;
        }
        const placed = slot.dataset.position === 'image'
            ? placeOnImage(slot, card) : placeBesideButton(slot, card);
        slot.hidden = !placed;
    });
}
