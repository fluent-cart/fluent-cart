/**
 * Extension point for the Product Gallery dialog (ProductMedia.vue).
 *
 * Any script loaded on the FluentCart admin can push a tab into the dialog:
 *
 *   window.fluent_cart_admin.hooks.addFilter('fluent_cart_product_gallery_tabs', 'my_plugin', (tabs, context) => {
 *       tabs.push({
 *           name:      'my-tab',                       // unique, used as the el-tab-pane name
 *           label:     'My Tab',                       // tab label
 *           component: MyTabComponent,                 // Vue 3 component; receives v-model (the draft) + `props`
 *           props:     { anything: 'extra' },          // optional extra props for the component
 *           seed:      (product) => ({ ... }),         // optional: build the draft when the dialog opens
 *           commit:    (draft, context) => { ... },    // optional: persist the draft when Save is clicked
 *           badge:     (draft) => 2,                   // optional: shown next to the label when truthy
 *           previews:  (product) => [...],             // optional: items for the Media card preview, see below
 *       });
 *       return tabs;
 *   });
 *
 * `context` is { product, productEditModel } and is passed to the filter and
 * to `commit`. The filter runs every time the dialog opens, so scripts
 * registered after the page loaded still get in. A tab that throws in
 * `seed`, `commit` or `badge` is reported on the console and skipped, so it
 * cannot take the other tabs down with it.
 *
 * `previews(product)` returns (or resolves to) `[{ id, url, title, kind, position }]`;
 * those items are shown in the Media card's thumbnail preview, `kind: 'video'`
 * with a play badge. It is re-run whenever the product's `detail.other_info`
 * changes and after every Save.
 *
 * A tab whose items belong in the Gallery grid itself — draggable next to the
 * images — implements three more callbacks:
 *
 *   items:      (draft) => [{ id, url, title, kind, position }],  // resolves too
 *   applyOrder: (draft, order) => draft,                          // order: [{ id, position }]
 *   removeItem: (draft, id) => draft,
 *
 * `position` is the number of gallery images that precede the item; `null`
 * (or a position past the last image) means "after every image". Both the
 * dialog grid and the Media card preview place items by it, and the storefront
 * gallery strip renders them in the same order.
 *
 * A tab's `component` should be a lazy import wrapped in
 * `galleryTabComponent()` (galleryTabComponent.js) so its pane shows a
 * skeleton, and then a retry, instead of staying blank while the chunk loads.
 *
 * A component with work in flight that will still change its draft (a
 * request whose result is added on completion) must `emit('busy', true)`
 * and `emit('busy', false)` when it ends: while any tab is busy the dialog's
 * Save is disabled and the dialog cannot be closed, so the late result is
 * never committed against a closed dialog.
 */
export const GALLERY_TABS_FILTER = 'fluent_cart_product_gallery_tabs';

const isFn = (value) => typeof value === 'function';

const report = (what, name, error) => {
    console.error(`[FluentCart] Product gallery tab "${name}": ${what} failed`, error);
};

/**
 * Entries without a string name/label or a component are dropped; duplicate names keep the first.
 */
export function resolveGalleryTabs(hooks, context = {}) {
    let raw = [];
    try {
        raw = hooks && isFn(hooks.applyFilters) ? hooks.applyFilters(GALLERY_TABS_FILTER, [], context) : [];
    } catch (e) {
        report('filter', GALLERY_TABS_FILTER, e);
        return [];
    }
    if (!Array.isArray(raw)) {
        return [];
    }

    const seen = new Set();
    const tabs = [];
    raw.forEach((entry) => {
        if (!entry || typeof entry !== 'object') return;
        const { name, label, component } = entry;
        if (typeof name !== 'string' || !name.trim() || typeof label !== 'string' || !component) return;
        if (seen.has(name)) return;
        seen.add(name);
        tabs.push({
            name,
            label,
            component,
            props: entry.props && typeof entry.props === 'object' ? entry.props : {},
            seed: isFn(entry.seed) ? entry.seed : null,
            commit: isFn(entry.commit) ? entry.commit : null,
            badge: isFn(entry.badge) ? entry.badge : null,
            previews: isFn(entry.previews) ? entry.previews : null,
            items: isFn(entry.items) ? entry.items : null,
            applyOrder: isFn(entry.applyOrder) ? entry.applyOrder : null,
            removeItem: isFn(entry.removeItem) ? entry.removeItem : null,
        });
    });

    return tabs;
}

export function seedGalleryDrafts(tabs, product) {
    const drafts = {};
    tabs.forEach((tab) => {
        drafts[tab.name] = null;
        if (!tab.seed) return;
        try {
            drafts[tab.name] = tab.seed(product);
        } catch (e) {
            report('seed', tab.name, e);
        }
    });
    return drafts;
}

export function commitGalleryDrafts(tabs, drafts, context) {
    tabs.forEach((tab) => {
        if (!tab.commit) return;
        try {
            tab.commit(drafts ? drafts[tab.name] : null, context);
        } catch (e) {
            report('commit', tab.name, e);
        }
    });
}

/**
 * `null` for "after every image"; anything that is not a non-negative integer
 * (a legacy entry with no position, a bad value) is treated the same way.
 */
export function normalizeGalleryPosition(position) {
    const value = Number(position);
    if (position === null || position === undefined || position === '' || !Number.isFinite(value) || value < 0) {
        return null;
    }
    return Math.floor(value);
}

const normalizePreview = (item) => {
    if (!item || typeof item !== 'object' || typeof item.url !== 'string' || !item.url) return null;
    return {
        id: item.id ?? item.url,
        url: item.url,
        title: typeof item.title === 'string' ? item.title : '',
        kind: typeof item.kind === 'string' && item.kind ? item.kind : 'image',
        position: normalizeGalleryPosition(item.position),
    };
};

/**
 * Preview items from every tab, in tab order; a tab that throws or rejects
 * contributes nothing and is reported on the console.
 */
export function resolveGalleryPreviews(tabs, product) {
    const perTab = tabs.map((tab) => {
        if (!tab.previews) return Promise.resolve([]);
        return Promise.resolve()
            .then(() => tab.previews(product))
            .then(items => (Array.isArray(items) ? items.map(normalizePreview).filter(Boolean) : []))
            .catch((e) => {
                report('previews', tab.name, e);
                return [];
            });
    });
    return Promise.all(perTab).then(lists => lists.flat());
}

/**
 * Grid items from every tab, each tagged with the tab that owns it so a
 * reorder or a remove can be routed back. A tab that throws or rejects
 * contributes nothing and is reported on the console.
 */
export function resolveGalleryItems(tabs, drafts) {
    const perTab = tabs.map((tab) => {
        if (!tab.items) return Promise.resolve([]);
        return Promise.resolve()
            .then(() => tab.items(drafts ? drafts[tab.name] : null))
            .then(items => (Array.isArray(items) ? items.map(normalizePreview).filter(Boolean) : []))
            .then(items => items.map(item => ({ ...item, tab: tab.name })))
            .catch((e) => {
                report('items', tab.name, e);
                return [];
            });
    });
    return Promise.all(perTab).then(lists => lists.flat());
}

/**
 * Hands each tab the new positions of its own items. `order` is the full
 * merged list's non-image entries: [{ tab, id, position }].
 *
 * @returns {Object} a new drafts object; drafts of tabs without applyOrder are untouched.
 */
export function applyGalleryOrder(tabs, drafts, order) {
    const next = { ...(drafts || {}) };
    const entries = Array.isArray(order) ? order : [];
    tabs.forEach((tab) => {
        if (!tab.applyOrder) return;
        const mine = entries
            .filter(entry => entry && entry.tab === tab.name)
            .map(entry => ({ id: entry.id, position: normalizeGalleryPosition(entry.position) }));
        try {
            next[tab.name] = tab.applyOrder(next[tab.name], mine);
        } catch (e) {
            report('applyOrder', tab.name, e);
        }
    });
    return next;
}

/**
 * @param {Object} item a grid item as returned by resolveGalleryItems.
 * @returns {Object} a new drafts object; unchanged when the tab cannot remove.
 */
export function removeGalleryItem(tabs, drafts, item) {
    if (!item || item.tab === undefined) return { ...(drafts || {}) };
    const tab = tabs.find(entry => entry.name === item.tab);
    if (!tab || !tab.removeItem) return { ...(drafts || {}) };

    const next = { ...(drafts || {}) };
    try {
        next[tab.name] = tab.removeItem(next[tab.name], item.id);
    } catch (e) {
        report('removeItem', tab.name, e);
    }
    return next;
}

/**
 * One list of gallery images and tab items, ordered as the storefront shows
 * them: an item sits after `position` images, and an item with no position
 * (or one past the last image) goes to the end. Ties keep the item order.
 *
 * @param {Array} images tagged image entries, in gallery order.
 * @param {Array} extras tab items carrying `position`.
 */
export function mergeGalleryItems(images, extras) {
    const imageList = Array.isArray(images) ? images : [];
    const pending = (Array.isArray(extras) ? extras : []).filter(item => item && typeof item === 'object');
    const merged = [];

    const takeUpTo = (imageIndex) => {
        for (let i = 0; i < pending.length; i++) {
            const position = normalizeGalleryPosition(pending[i].position);
            if (position !== null && position <= imageIndex) {
                merged.push(pending.splice(i, 1)[0]);
                i--;
            }
        }
    };

    imageList.forEach((image, index) => {
        takeUpTo(index);
        merged.push(image);
    });
    pending.forEach(item => merged.push(item));

    return merged;
}

/**
 * The inverse of mergeGalleryItems: the images in their new order, and every
 * other entry with the number of images that now precede it.
 */
export function splitGalleryOrder(merged) {
    const images = [];
    const order = [];
    (Array.isArray(merged) ? merged : []).forEach((item) => {
        if (!item || typeof item !== 'object') return;
        if (item.kind === 'image') {
            images.push(item);
            return;
        }
        order.push({ ...item, position: images.length });
    });
    return { images, order };
}

const galleryItemKey = (item) => `${item.tab || ''}:${item.id}`;

/**
 * Fold a fresh set of tab items into a grid the reader has already arranged:
 * items still present keep their place (and pick up new titles/posters), items
 * gone are dropped, and new ones land at their position. The images in `list`
 * are untouched.
 *
 * This is for items arriving *while* the grid is open. A grid being opened is
 * built with mergeGalleryItems instead, so the stored positions win over
 * whatever order the previous session left behind.
 */
export function reconcileGalleryItems(list, extras) {
    const incoming = new Map((Array.isArray(extras) ? extras : [])
        .filter(item => item && typeof item === 'object')
        .map(item => [galleryItemKey(item), item]));

    const kept = [];
    (Array.isArray(list) ? list : []).forEach((entry) => {
        if (!entry || typeof entry !== 'object') return;
        if (entry.kind === 'image') {
            kept.push(entry);
            return;
        }
        const key = galleryItemKey(entry);
        if (!incoming.has(key)) return;
        kept.push({ ...incoming.get(key) });
        incoming.delete(key);
    });

    incoming.forEach(item => insertGalleryItem(kept, { ...item }));

    return kept;
}

/**
 * Moves one grid entry, in place — the keyboard's way of doing what a drag
 * does. A target outside the list is refused rather than clamped: the move
 * buttons are disabled at the ends, and quietly sending an item to the other
 * end would be worse than doing nothing.
 *
 * @returns {boolean} whether the list changed.
 */
export function moveGalleryEntry(list, from, to) {
    if (!Array.isArray(list)) return false;
    if (from === to || from < 0 || to < 0 || from >= list.length || to >= list.length) return false;

    const [entry] = list.splice(from, 1);
    list.splice(to, 0, entry);

    return true;
}

/**
 * A new gallery image joins the images, not the tail: items sitting after the
 * last image are there because their position says "after every image", so
 * they have to stay behind the new one.
 *
 * @returns {Array} the same list, mutated.
 */
export function insertGalleryImage(list, entry) {
    let lastImage = -1;
    (Array.isArray(list) ? list : []).forEach((item, index) => {
        if (item && item.kind === 'image') lastImage = index;
    });
    list.splice(lastImage + 1, 0, entry);
    return list;
}

/**
 * Position p means "after p images", so the item goes before image p + 1; an
 * item with no position (or one past the last image) goes to the end.
 */
export function insertGalleryItem(list, item) {
    const position = normalizeGalleryPosition(item.position);
    if (position !== null) {
        let images = 0;
        for (let i = 0; i < list.length; i++) {
            if (list[i].kind !== 'image') continue;
            if (images === position) {
                list.splice(i, 0, item);
                return list;
            }
            images++;
        }
    }
    list.push(item);
    return list;
}

export function setTabBusy(busyTabs, name, busy) {
    const next = { ...(busyTabs || {}) };
    if (busy) {
        next[name] = true;
    } else {
        delete next[name];
    }
    return next;
}

export function anyTabBusy(busyTabs) {
    return Object.keys(busyTabs || {}).length > 0;
}

export function galleryTabBadge(tab, draft) {
    if (!tab.badge) return '';
    let value;
    try {
        value = tab.badge(draft);
    } catch (e) {
        report('badge', tab.name, e);
        return '';
    }
    return value === 0 || value === null || value === undefined || value === false ? '' : String(value);
}
