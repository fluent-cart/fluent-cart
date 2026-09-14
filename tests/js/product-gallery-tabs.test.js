import {describe, it, expect, vi, afterEach} from 'vitest';
import {
    GALLERY_TABS_FILTER,
    resolveGalleryTabs,
    seedGalleryDrafts,
    commitGalleryDrafts,
    galleryTabBadge,
    setTabBusy,
    anyTabBusy,
    resolveGalleryPreviews,
    resolveGalleryItems,
    applyGalleryOrder,
    removeGalleryItem,
    mergeGalleryItems,
    splitGalleryOrder,
    reconcileGalleryItems,
    insertGalleryItem,
    insertGalleryImage,
    moveGalleryEntry,
    normalizeGalleryPosition,
} from '@/Modules/Products/galleryTabs';

const hooksReturning = (value) => ({applyFilters: vi.fn(() => value)});
const Comp = {name: 'Dummy', render: () => null};

describe('resolveGalleryTabs', () => {
    it('runs the filter with the context and keeps well-formed tabs in order', () => {
        const hooks = hooksReturning([
            {name: 'one', label: 'One', component: Comp, seed: () => 1, commit: () => {}, badge: () => 3, props: {a: 1}},
            {name: 'two', label: 'Two', component: Comp},
        ]);
        const context = {product: {ID: 1}};

        const tabs = resolveGalleryTabs(hooks, context);

        expect(hooks.applyFilters).toHaveBeenCalledWith(GALLERY_TABS_FILTER, [], context);
        expect(tabs.map(t => t.name)).toEqual(['one', 'two']);
        expect(tabs[0].props).toEqual({a: 1});
        expect(tabs[1].props).toEqual({});
        expect(tabs[1].seed).toBeNull();
        expect(tabs[1].commit).toBeNull();
        expect(tabs[1].badge).toBeNull();
    });

    it('drops malformed entries and duplicate names', () => {
        const hooks = hooksReturning([
            null,
            'string',
            {name: '', label: 'No name', component: Comp},
            {name: 'x', label: 'No component'},
            {name: 'x', component: Comp},
            {name: 'ok', label: 'Ok', component: Comp},
            {name: 'ok', label: 'Duplicate', component: Comp},
        ]);

        const tabs = resolveGalleryTabs(hooks, {});

        expect(tabs).toHaveLength(1);
        expect(tabs[0].label).toBe('Ok');
    });

    it('returns an empty list when hooks are missing or return junk', () => {
        expect(resolveGalleryTabs(null, {})).toEqual([]);
        expect(resolveGalleryTabs({}, {})).toEqual([]);
        expect(resolveGalleryTabs(hooksReturning('nope'), {})).toEqual([]);
    });

    it('reports a throwing filter on the console instead of hiding it', () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        expect(resolveGalleryTabs({applyFilters: () => { throw new Error('boom'); }}, {})).toEqual([]);
        expect(error).toHaveBeenCalledTimes(1);
        expect(error.mock.calls[0][0]).toContain(GALLERY_TABS_FILTER);
        error.mockRestore();
    });
});

describe('drafts', () => {
    const tabs = resolveGalleryTabs(hooksReturning([
        {name: 'seeded', label: 'S', component: Comp, seed: (product) => ({id: product.ID}), commit: vi.fn(), badge: (d) => (d ? 1 : 0)},
        {name: 'plain', label: 'P', component: Comp},
    ]), {});

    it('seeds one draft per tab (null when the tab has no seed)', () => {
        expect(seedGalleryDrafts(tabs, {ID: 7})).toEqual({seeded: {id: 7}, plain: null});
    });

    it('commits each draft to its own tab with the context', () => {
        const drafts = {seeded: {id: 7}, plain: null};
        const context = {product: {ID: 7}};
        commitGalleryDrafts(tabs, drafts, context);
        expect(tabs[0].commit).toHaveBeenCalledWith({id: 7}, context);
        expect(() => commitGalleryDrafts(tabs, null, context)).not.toThrow();
    });

    it('renders a badge only for non-empty values', () => {
        expect(galleryTabBadge(tabs[0], {id: 7})).toBe('1');
        expect(galleryTabBadge(tabs[0], null)).toBe('');
        expect(galleryTabBadge(tabs[1], null)).toBe('');
        expect(galleryTabBadge({badge: () => 'new'}, null)).toBe('new');
    });
});

describe('a misbehaving tab cannot take the others down', () => {
    let error;
    afterEach(() => error?.mockRestore());

    const good = {name: 'good', label: 'G', component: Comp, seed: () => 'seeded', commit: vi.fn(), badge: () => 4};
    const bad = {
        name: 'bad', label: 'B', component: Comp,
        seed: () => { throw new Error('seed'); },
        commit: () => { throw new Error('commit'); },
        badge: () => { throw new Error('badge'); },
    };
    const tabs = resolveGalleryTabs(hooksReturning([bad, good]), {});

    it('seeds the remaining tabs and reports the failure', () => {
        error = vi.spyOn(console, 'error').mockImplementation(() => {});
        expect(seedGalleryDrafts(tabs, {ID: 1})).toEqual({bad: null, good: 'seeded'});
        expect(error).toHaveBeenCalledTimes(1);
        expect(error.mock.calls[0][0]).toContain('"bad"');
    });

    it('still commits the remaining tabs when one commit throws', () => {
        error = vi.spyOn(console, 'error').mockImplementation(() => {});
        const context = {product: {ID: 1}};
        expect(() => commitGalleryDrafts(tabs, {bad: null, good: 'seeded'}, context)).not.toThrow();
        expect(good.commit).toHaveBeenCalledWith('seeded', context);
        expect(error).toHaveBeenCalledTimes(1);
    });

    it('renders no badge for a throwing badge callback', () => {
        error = vi.spyOn(console, 'error').mockImplementation(() => {});
        expect(galleryTabBadge(tabs[0], null)).toBe('');
        expect(galleryTabBadge(tabs[1], null)).toBe('4');
        expect(error).toHaveBeenCalledTimes(1);
    });
});

describe('busy tabs', () => {
    it('tracks which tabs have work in flight without mutating the previous map', () => {
        const none = {};
        const one = setTabBusy(none, 'fluent-player', true);
        expect(anyTabBusy(none)).toBe(false);
        expect(anyTabBusy(one)).toBe(true);
        expect(one).toEqual({'fluent-player': true});

        const two = setTabBusy(one, 'other', true);
        expect(anyTabBusy(setTabBusy(two, 'fluent-player', false))).toBe(true);
        expect(anyTabBusy(setTabBusy(setTabBusy(two, 'fluent-player', false), 'other', false))).toBe(false);
        expect(setTabBusy(undefined, 'x', false)).toEqual({});
    });
});

describe('resolveGalleryPreviews', () => {
    it('collects normalized items from every tab in order, sync or async', async () => {
        const tabs = resolveGalleryTabs(hooksReturning([
            {name: 'a', label: 'A', component: Comp, previews: () => [{id: 1, url: 'https://x/a.jpg', title: 'A', kind: 'video'}, {url: ''}, null]},
            {name: 'b', label: 'B', component: Comp},
            {name: 'c', label: 'C', component: Comp, previews: async () => [{url: 'https://x/c.jpg'}]},
        ]), {});
        expect(tabs[1].previews).toBeNull();

        const items = await resolveGalleryPreviews(tabs, {ID: 1});
        expect(items).toEqual([
            {id: 1, url: 'https://x/a.jpg', title: 'A', kind: 'video', position: null},
            {id: 'https://x/c.jpg', url: 'https://x/c.jpg', title: '', kind: 'image', position: null},
        ]);
    });

    it('drops a tab whose previews throw or reject and reports it', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        const tabs = resolveGalleryTabs(hooksReturning([
            {name: 'throws', label: 'T', component: Comp, previews: () => { throw new Error('sync'); }},
            {name: 'rejects', label: 'R', component: Comp, previews: () => Promise.reject(new Error('async'))},
            {name: 'ok', label: 'O', component: Comp, previews: () => [{url: 'https://x/ok.jpg'}]},
        ]), {});
        const items = await resolveGalleryPreviews(tabs, {});
        expect(items.map(i => i.url)).toEqual(['https://x/ok.jpg']);
        expect(error).toHaveBeenCalledTimes(2);
        error.mockRestore();
    });
});

describe('normalizeGalleryPosition', () => {
    it('keeps non-negative integers and reads everything else as "last"', () => {
        expect(normalizeGalleryPosition(0)).toBe(0);
        expect(normalizeGalleryPosition('3')).toBe(3);
        expect(normalizeGalleryPosition(2.7)).toBe(2);
        expect(normalizeGalleryPosition(-1)).toBeNull();
        expect(normalizeGalleryPosition('')).toBeNull();
        expect(normalizeGalleryPosition(null)).toBeNull();
        expect(normalizeGalleryPosition(undefined)).toBeNull();
        expect(normalizeGalleryPosition('abc')).toBeNull();
    });
});

describe('mergeGalleryItems / splitGalleryOrder', () => {
    const images = [
        {kind: 'image', id: 1},
        {kind: 'image', id: 2},
        {kind: 'image', id: 3},
    ];

    it('places an item after the number of images its position names', () => {
        const merged = mergeGalleryItems(images, [
            {kind: 'video', id: 'v0', position: 0},
            {kind: 'video', id: 'v2', position: 2},
        ]);
        expect(merged.map(item => item.id)).toEqual(['v0', 1, 2, 'v2', 3]);
    });

    it('sends items with no position, or one past the last image, to the end', () => {
        const merged = mergeGalleryItems(images, [
            {kind: 'video', id: 'vNull'},
            {kind: 'video', id: 'vFar', position: 99},
        ]);
        expect(merged.map(item => item.id)).toEqual([1, 2, 3, 'vNull', 'vFar']);
    });

    it('keeps the given order when two items share a position', () => {
        const merged = mergeGalleryItems(images, [
            {kind: 'video', id: 'a', position: 1},
            {kind: 'video', id: 'b', position: 1},
        ]);
        expect(merged.map(item => item.id)).toEqual([1, 'a', 'b', 2, 3]);
    });

    it('works with no images at all', () => {
        expect(mergeGalleryItems([], [{kind: 'video', id: 'v', position: 0}]).map(i => i.id)).toEqual(['v']);
        expect(mergeGalleryItems(null, null)).toEqual([]);
    });

    it('reports the images in their new order and each item\'s new position', () => {
        const merged = mergeGalleryItems(images, [{kind: 'video', id: 'v0', position: 0}, {kind: 'video', id: 'v2', position: 2}]);
        const {images: ordered, order} = splitGalleryOrder(merged);
        expect(ordered.map(i => i.id)).toEqual([1, 2, 3]);
        expect(order.map(i => [i.id, i.position])).toEqual([['v0', 0], ['v2', 2]]);
    });

    it('round-trips a reorder: dragging the video to the front gives it position 0', () => {
        const merged = mergeGalleryItems(images, [{kind: 'video', id: 'v', position: null}]);
        const moved = [merged.pop(), ...merged];
        const {order} = splitGalleryOrder(moved);
        expect(order).toEqual([{kind: 'video', id: 'v', position: 0}]);
        expect(mergeGalleryItems(images, order).map(i => i.id)).toEqual(['v', 1, 2, 3]);
    });
});

describe('resolveGalleryItems', () => {
    it('tags every item with the tab that owns it', async () => {
        const tabs = resolveGalleryTabs(hooksReturning([
            {name: 'a', label: 'A', component: Comp, items: (draft) => [{id: draft.id, url: 'https://x/a.jpg', kind: 'video', position: 1}]},
            {name: 'b', label: 'B', component: Comp},
        ]), {});
        expect(tabs[1].items).toBeNull();

        const items = await resolveGalleryItems(tabs, {a: {id: 7}});
        expect(items).toEqual([{id: 7, url: 'https://x/a.jpg', title: '', kind: 'video', position: 1, tab: 'a'}]);
    });

    it('drops a tab whose items throw and reports it', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        const tabs = resolveGalleryTabs(hooksReturning([
            {name: 'bad', label: 'B', component: Comp, items: () => { throw new Error('boom'); }},
        ]), {});
        expect(await resolveGalleryItems(tabs, {})).toEqual([]);
        expect(error).toHaveBeenCalledTimes(1);
        error.mockRestore();
    });
});

describe('applyGalleryOrder / removeGalleryItem', () => {
    const build = (overrides = {}) => resolveGalleryTabs(hooksReturning([
        {
            name: 'a',
            label: 'A',
            component: Comp,
            applyOrder: (draft, order) => ({...draft, order}),
            removeItem: (draft, id) => ({...draft, removed: id}),
            ...overrides,
        },
        {name: 'b', label: 'B', component: Comp},
    ]), {});

    it('hands each tab only its own entries, normalized', () => {
        const tabs = build();
        const next = applyGalleryOrder(tabs, {a: {kept: true}, b: {untouched: true}}, [
            {tab: 'a', id: 1, position: '2'},
            {tab: 'other', id: 2, position: 0},
            {tab: 'a', id: 3, position: -5},
        ]);
        expect(next.a).toEqual({kept: true, order: [{id: 1, position: 2}, {id: 3, position: null}]});
        expect(next.b).toEqual({untouched: true});
    });

    it('keeps the draft when a tab throws while applying the order', () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        const tabs = build({applyOrder: () => { throw new Error('boom'); }});
        expect(applyGalleryOrder(tabs, {a: {kept: true}}, []).a).toEqual({kept: true});
        expect(error).toHaveBeenCalledTimes(1);
        error.mockRestore();
    });

    it('routes a removal back to the owning tab only', () => {
        const tabs = build();
        expect(removeGalleryItem(tabs, {a: {}}, {tab: 'a', id: 9}).a).toEqual({removed: 9});
        expect(removeGalleryItem(tabs, {a: {}}, {tab: 'b', id: 9})).toEqual({a: {}});
        expect(removeGalleryItem(tabs, {a: {}}, null)).toEqual({a: {}});
    });
});

describe('reconcileGalleryItems', () => {
    const image = (id) => ({kind: 'image', id});
    const video = (id, position = null) => ({kind: 'video', tab: 'fluent-player', id, position});

    it('keeps the reader\'s order for items still attached and refreshes their data', () => {
        const list = [video('v1'), image(1), image(2)];
        const next = reconcileGalleryItems(list, [{...video('v1'), title: 'Loaded'}]);
        expect(next.map(i => i.id)).toEqual(['v1', 1, 2]);
        expect(next[0].title).toBe('Loaded');
    });

    it('drops an item the tab no longer has', () => {
        const next = reconcileGalleryItems([image(1), video('v1'), video('v2')], [video('v2')]);
        expect(next.map(i => i.id)).toEqual([1, 'v2']);
    });

    it('lands a newly added item at its position, or at the end without one', () => {
        expect(reconcileGalleryItems([image(1), image(2)], [video('v1', 1)]).map(i => i.id)).toEqual([1, 'v1', 2]);
        expect(reconcileGalleryItems([image(1), image(2)], [video('v2')]).map(i => i.id)).toEqual([1, 2, 'v2']);
    });

    it('does not re-order what is already in the grid when a stale position arrives', () => {
        // The dialog re-resolves its items on open; a grid being opened is
        // built with mergeGalleryItems so the stored positions win. Once open,
        // a late payload must not yank items around under the reader.
        const list = [image(1), video('v1', 1)];
        expect(reconcileGalleryItems(list, [video('v1', 0)]).map(i => i.id)).toEqual([1, 'v1']);
    });

    it('tolerates junk on both sides', () => {
        expect(reconcileGalleryItems(null, null)).toEqual([]);
        expect(reconcileGalleryItems([image(1), null, 'x'], []).map(i => i.id)).toEqual([1]);
    });
});

describe('insertGalleryItem', () => {
    it('puts the item after exactly `position` images', () => {
        const list = [{kind: 'image', id: 1}, {kind: 'video', id: 'a'}, {kind: 'image', id: 2}];
        expect(insertGalleryItem([...list], {kind: 'video', id: 'b', position: 0}).map(i => i.id)).toEqual(['b', 1, 'a', 2]);
        expect(insertGalleryItem([...list], {kind: 'video', id: 'b', position: 1}).map(i => i.id)).toEqual([1, 'a', 'b', 2]);
        expect(insertGalleryItem([...list], {kind: 'video', id: 'b', position: 9}).map(i => i.id)).toEqual([1, 'a', 2, 'b']);
    });
});

describe('insertGalleryImage', () => {
    const image = (id) => ({kind: 'image', id});
    const video = (id) => ({kind: 'video', id});

    it('puts a new image after the last image, in front of the trailing items', () => {
        // A trailing video is there because its position says "after every
        // image" — appending past it would show an order that Save never keeps.
        const list = [image(1), video('v')];
        expect(insertGalleryImage(list, image(2)).map(i => i.id)).toEqual([1, 2, 'v']);
    });

    it('keeps items that sit between images where they are', () => {
        const list = [image(1), video('v'), image(2), video('w')];
        expect(insertGalleryImage(list, image(3)).map(i => i.id)).toEqual([1, 'v', 2, 3, 'w']);
    });

    it('puts the first image ahead of a video-only list', () => {
        expect(insertGalleryImage([video('v')], image(1)).map(i => i.id)).toEqual([1, 'v']);
        expect(insertGalleryImage([], image(1)).map(i => i.id)).toEqual([1]);
    });
});

describe('moveGalleryEntry', () => {
    const list = () => [{id: 'a'}, {id: 'b'}, {id: 'c'}];

    it('moves an entry one slot in either direction', () => {
        const forward = list();
        expect(moveGalleryEntry(forward, 0, 1)).toBe(true);
        expect(forward.map(i => i.id)).toEqual(['b', 'a', 'c']);

        const back = list();
        expect(moveGalleryEntry(back, 2, 1)).toBe(true);
        expect(back.map(i => i.id)).toEqual(['a', 'c', 'b']);
    });

    it('refuses a target off either end instead of wrapping', () => {
        const items = list();
        expect(moveGalleryEntry(items, 0, -1)).toBe(false);
        expect(moveGalleryEntry(items, 2, 3)).toBe(false);
        expect(moveGalleryEntry(items, 1, 1)).toBe(false);
        expect(items.map(i => i.id)).toEqual(['a', 'b', 'c']);
    });

    it('tolerates a missing list', () => {
        expect(moveGalleryEntry(null, 0, 1)).toBe(false);
        expect(moveGalleryEntry([], 0, 1)).toBe(false);
    });
});
