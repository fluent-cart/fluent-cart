// Masonry cards sit in an ordinary CSS grid; each card spans as many 1px rows as it is tall,
// so the grid keeps the column count its block or page builder set, and its reading order.
const ROW = 1;
const MAX_DEPTH = 6;
// No card is this tall; a larger span means its height followed the span, so stop there.
const MAX_SPAN = 6000;
const native = typeof CSS !== 'undefined' && CSS.supports?.('grid-template-rows', 'masonry');
const pending = new Set();
let resizer = null;
let frame = 0;

// The product grids: the storefront's, related products, and the editor's preview of the Products block.
const GRIDS = '.fct-products-container, .fct-product-list, .fct-related-products-grid, .shop-app-preview-products';
// A page builder's Masonry Grid switch: the product grid inside staggers its own cards too, not only Masonry layout cards.
const WRAPPER = '[data-fct-masonry]';
const CARD = '.fct-product-card, [data-fct-product-card], .fct-product-block-editor-product-card';
// A card that staggers: any Gutenberg layout with Masonry on, or the page builders' Masonry layout.
export const MASONRY_CARD = '.fct-card-masonry, .fct-card-layout--masonry';

// The grid item that holds a masonry card, and its grid: a product grid, else the nearest ancestor laid out
// as a grid. The editor wraps each card in a one-column grid of its own, so the product grid comes first.
function gridItem(layout) {
    const known = layout.closest(GRIDS);
    if (known) {
        const item = [...known.children].find(child => child.contains(layout));
        if (item) return {grid: known, item};
    }
    let item = layout;
    for (let depth = 0; depth < MAX_DEPTH && item.parentElement; depth++) {
        const parent = item.parentElement;
        if (parent.ownerDocument.defaultView.getComputedStyle(parent).display.includes('grid')) {
            // Only a grid of product cards: other content in it would collapse into the 1px rows.
            const cardsOnly = [...parent.children].every(child => child.matches(CARD) || !!child.querySelector(CARD));
            return cardsOnly ? {grid: parent, item} : null;
        }
        item = parent;
    }
    return null;
}

// Grid settings the script replaces, kept to put back when the grid stops being masonry.
const saved = new WeakMap();
const masonryGrids = new Set();

function setMasonry(grid, on) {
    if (on && !saved.has(grid)) {
        // The grid's own row gap becomes the space under each card; a builder may set it inline or with a strong selector.
        saved.set(grid, {gap: parseFloat(grid.ownerDocument.defaultView.getComputedStyle(grid).rowGap) || 0, rowGap: grid.style.rowGap, autoRows: grid.style.gridAutoRows, templateRows: grid.style.gridTemplateRows});
        if (native) {
            grid.style.gridTemplateRows = 'masonry';
        } else {
            grid.style.rowGap = '0px';
            grid.style.gridAutoRows = ROW + 'px';
        }
    } else if (!on && saved.has(grid)) {
        const before = saved.get(grid);
        grid.style.rowGap = before.rowGap;
        grid.style.gridAutoRows = before.autoRows;
        grid.style.gridTemplateRows = before.templateRows;
        saved.delete(grid);
    }
    grid.classList.toggle('fct-masonry-grid', on);
    if (on) masonryGrids.add(grid); else masonryGrids.delete(grid);
    return on ? saved.get(grid).gap : 0;
}

function layoutGrid(grid) {
    const ownCards = !!grid.closest(WRAPPER);
    const children = [...grid.children];
    const items = children.filter(child => child.matches(CARD) || !!child.querySelector(CARD));
    const hasMasonry = ownCards || !!grid.querySelector(MASONRY_CARD);
    // List view rows stay in one column, with no spans.
    const masonry = hasMasonry && items.length > 0 && items.length === children.length && !grid.closest('.mode-list');
    const gap = setMasonry(grid, masonry);
    // Watched in list view too, and with native masonry, so the view switcher's resize turns masonry on or off.
    items.forEach(item => resizer?.observe(item));
    if (native) return;
    if (!masonry) {
        [...grid.children].forEach(child => { child.style.gridRowEnd = ''; });
        return;
    }
    items.forEach(item => {
        const span = 'span ' + Math.min(MAX_SPAN, Math.max(1, Math.ceil((item.getBoundingClientRect().height + gap) / ROW)));
        // Writing only a changed span keeps the resize observer from firing again for nothing.
        if (item.style.gridRowEnd !== span) item.style.gridRowEnd = span;
    });
}

function flush() {
    frame = 0;
    pending.forEach(layoutGrid);
    pending.clear();
}

function queue(grid) {
    pending.add(grid);
    if (!frame) frame = requestAnimationFrame(flush);
}

export function layoutMasonry(scope) {
    if (!scope?.querySelectorAll) return;
    // Images loading, options changing and the view switcher all change a card's height.
    if (!resizer && typeof ResizeObserver !== 'undefined') {
        resizer = new ResizeObserver(entries => entries.forEach(entry => {
            if (entry.target.parentElement) queue(entry.target.parentElement);
        }));
    }
    const layouts = scope.matches?.(MASONRY_CARD) ? [scope] : scope.querySelectorAll(MASONRY_CARD);
    layouts.forEach(layout => {
        const found = gridItem(layout);
        if (found) queue(found.grid);
    });
    const wrappers = [scope.closest?.(WRAPPER), ...scope.querySelectorAll(WRAPPER)].filter(Boolean);
    wrappers.forEach(wrapper => {
        const grid = wrapper.matches(GRIDS) ? wrapper : wrapper.querySelector(GRIDS);
        if (grid) queue(grid);
    });
    // Cards of another layout replacing the masonry ones turn the grid back to plain rows.
    masonryGrids.forEach(grid => {
        if (!grid.isConnected) masonryGrids.delete(grid);
        else if (grid.contains(scope) || scope.contains(grid)) queue(grid);
    });
}
