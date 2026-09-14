import {describe, it, expect, vi} from 'vitest';
import {
    FLUENT_PLAYER_TAB_NAME,
    MAX_VIDEOS,
    normalizeMediaIds,
    normalizeVideoDraft,
    seedFluentPlayerDraft,
    commitFluentPlayerDraft,
    fluentPlayerGalleryTab,
    fluentPlayerPreviews,
    fluentPlayerItems,
    applyFluentPlayerOrder,
    removeFluentPlayerMedia,
    registerFluentPlayerGalleryTab,
} from '@/Modules/Products/FluentPlayer/fluentPlayerGalleryTab';
import {GALLERY_TABS_FILTER, galleryTabBadge} from '@/Modules/Products/galleryTabs';

const Comp = {name: 'Tab', render: () => null};
const activeConfig = {active: true, restUrl: 'https://x.test/wp-json/fluent-player/v2'};

describe('normalizeMediaIds', () => {
    it('accepts arrays and comma strings, dropping junk and duplicates', () => {
        expect(normalizeMediaIds([3, '4', 3, 0, -1, 'x'])).toEqual([3, 4]);
        expect(normalizeMediaIds('5, 6,5,,abc')).toEqual([5, 6]);
        expect(normalizeMediaIds('')).toEqual([]);
        expect(normalizeMediaIds(undefined)).toEqual([]);
    });

    it('caps the list at the same limit as ProductVideoSettings::MAX_VIDEOS', () => {
        expect(MAX_VIDEOS).toBe(20);
        const ids = Array.from({length: MAX_VIDEOS + 5}, (_, i) => i + 1);
        expect(normalizeMediaIds(ids)).toEqual(ids.slice(0, MAX_VIDEOS));
    });
});

describe('normalizeVideoDraft', () => {
    it('keeps a position for every id, in the same order', () => {
        expect(normalizeVideoDraft({media_ids: [3, 4], positions: [0, 2]})).toEqual({media_ids: [3, 4], positions: [0, 2]});
        expect(normalizeVideoDraft({media_ids: '3,4', positions: '0,'})).toEqual({media_ids: [3, 4], positions: [0, null]});
    });

    it('drops the position of an id it drops', () => {
        expect(normalizeVideoDraft({media_ids: [3, 3, 4], positions: [0, 9, 2]})).toEqual({media_ids: [3, 4], positions: [0, 2]});
        expect(normalizeVideoDraft({media_ids: ['x', 5], positions: [1, 2]})).toEqual({media_ids: [5], positions: [2]});
    });

    it('treats a missing or negative position as "after every image"', () => {
        expect(normalizeVideoDraft({media_ids: [3, 4]})).toEqual({media_ids: [3, 4], positions: [null, null]});
        expect(normalizeVideoDraft({media_ids: [3], positions: [-2]})).toEqual({media_ids: [3], positions: [null]});
    });
});

describe('seedFluentPlayerDraft', () => {
    it('reads the stored list, the string form, and the legacy single id', () => {
        expect(seedFluentPlayerDraft({detail: {other_info: {fluent_player_video: {media_ids: [1, 2], positions: [0, 1]}}}})).toEqual({media_ids: [1, 2], positions: [0, 1]});
        expect(seedFluentPlayerDraft({detail: {other_info: {fluent_player_video: {media_ids: '2,3'}}}})).toEqual({media_ids: [2, 3], positions: [null, null]});
        expect(seedFluentPlayerDraft({detail: {other_info: {fluent_player_video: {media_id: 9}}}})).toEqual({media_ids: [9], positions: [null]});
        expect(seedFluentPlayerDraft({detail: {other_info: {}}})).toEqual({media_ids: [], positions: []});
        expect(seedFluentPlayerDraft(null)).toEqual({media_ids: [], positions: []});
    });
});

describe('applyFluentPlayerOrder', () => {
    const draft = {media_ids: [7, 8], positions: [null, null]};

    it('rewrites the draft into the order the gallery grid reported', () => {
        expect(applyFluentPlayerOrder(draft, [{id: 8, position: 0}, {id: 7, position: 2}]))
            .toEqual({media_ids: [8, 7], positions: [0, 2]});
    });

    it('keeps an id the grid never showed, with its stored position', () => {
        const withMissing = {media_ids: [7, 8], positions: [1, 3]};
        expect(applyFluentPlayerOrder(withMissing, [{id: 8, position: 0}]))
            .toEqual({media_ids: [8, 7], positions: [0, 1]});
    });

    it('ignores ids that are not attached and duplicate entries', () => {
        expect(applyFluentPlayerOrder(draft, [{id: 99, position: 0}, {id: 7, position: 1}, {id: 7, position: 4}]))
            .toEqual({media_ids: [7, 8], positions: [1, null]});
    });
});

describe('removeFluentPlayerMedia', () => {
    it('drops the id and its position', () => {
        expect(removeFluentPlayerMedia({media_ids: [7, 8, 9], positions: [0, 1, null]}, 8))
            .toEqual({media_ids: [7, 9], positions: [0, null]});
    });

    it('returns the normalized draft when the id is not attached', () => {
        expect(removeFluentPlayerMedia({media_ids: [7], positions: [0]}, 42)).toEqual({media_ids: [7], positions: [0]});
    });
});

describe('commitFluentPlayerDraft', () => {
    it('stages comma-separated ids and positions only when they changed', () => {
        const productEditModel = {onChangeInputField: vi.fn()};
        const product = {detail: {other_info: {fluent_player_video: {media_ids: [1], positions: [null]}}}};

        expect(commitFluentPlayerDraft({media_ids: [1, 2], positions: [0, null]}, {product, productEditModel})).toBe(true);
        expect(productEditModel.onChangeInputField).toHaveBeenCalledWith('fluent_player_video', {media_ids: '1,2', positions: '0,'});

        productEditModel.onChangeInputField.mockClear();
        expect(commitFluentPlayerDraft({media_ids: [1]}, {product, productEditModel})).toBe(false);
        expect(productEditModel.onChangeInputField).not.toHaveBeenCalled();
    });

    it('stages a position change on its own', () => {
        const productEditModel = {onChangeInputField: vi.fn()};
        const product = {detail: {other_info: {fluent_player_video: {media_ids: [1], positions: [2]}}}};

        expect(commitFluentPlayerDraft({media_ids: [1], positions: [0]}, {product, productEditModel})).toBe(true);
        expect(productEditModel.onChangeInputField).toHaveBeenCalledWith('fluent_player_video', {media_ids: '1', positions: '0'});
    });

    it('sends an empty string to clear every video', () => {
        const productEditModel = {onChangeInputField: vi.fn()};
        const product = {detail: {other_info: {fluent_player_video: {media_ids: [1]}}}};
        commitFluentPlayerDraft({media_ids: []}, {product, productEditModel});
        expect(productEditModel.onChangeInputField).toHaveBeenCalledWith('fluent_player_video', {media_ids: '', positions: ''});
    });

    it('is a no-op without an edit model', () => {
        expect(commitFluentPlayerDraft({media_ids: [1]}, {})).toBe(false);
    });
});

describe('fluentPlayerGalleryTab / registerFluentPlayerGalleryTab', () => {
    it('describes the tab for the gallery dialog', () => {
        const tab = fluentPlayerGalleryTab(activeConfig, Comp, (s) => `t:${s}`);
        expect(tab.name).toBe(FLUENT_PLAYER_TAB_NAME);
        expect(tab.label).toBe('t:Video');
        expect(tab.component).toBe(Comp);
        expect(tab.props).toEqual({config: activeConfig});
        expect(tab.badge({media_ids: [1, 2, 2]})).toBe(2);
        expect(tab.badge(null)).toBe(0);
    });

    it('hides the count while FluentPlayer is inactive, so the install prompt stands alone', () => {
        const inactive = fluentPlayerGalleryTab({active: false}, Comp);
        expect(inactive.badge({media_ids: [1, 2]})).toBe(0);
        expect(galleryTabBadge(inactive, {media_ids: [1, 2]})).toBe('');
        expect(galleryTabBadge(fluentPlayerGalleryTab(activeConfig, Comp), {media_ids: [1, 2]})).toBe('2');
    });

    it('registers on the gallery-tabs filter whenever a config is given, active or not', () => {
        const filters = {};
        const hooks = {addFilter: vi.fn((name, ns, cb) => { filters[name] = cb; })};

        expect(registerFluentPlayerGalleryTab(hooks, activeConfig, null)).toBe(false);
        expect(registerFluentPlayerGalleryTab(null, activeConfig, Comp)).toBe(false);
        expect(registerFluentPlayerGalleryTab(hooks, null, Comp)).toBe(false);
        expect(hooks.addFilter).not.toHaveBeenCalled();

        expect(registerFluentPlayerGalleryTab(hooks, {active: false}, Comp)).toBe(true);
        hooks.addFilter.mockClear();

        expect(registerFluentPlayerGalleryTab(hooks, activeConfig, Comp)).toBe(true);
        expect(hooks.addFilter).toHaveBeenCalledWith(GALLERY_TABS_FILTER, 'fluent_cart/fluent_player', expect.any(Function));

        const tabs = filters[GALLERY_TABS_FILTER]([{name: 'other', label: 'O', component: Comp}]);
        expect(tabs.map(t => t.name)).toEqual(['other', FLUENT_PLAYER_TAB_NAME]);
        expect(filters[GALLERY_TABS_FILTER](tabs)).toHaveLength(2);
        expect(filters[GALLERY_TABS_FILTER]('junk').map(t => t.name)).toEqual([FLUENT_PLAYER_TAB_NAME]);
    });
});

describe('fluentPlayerPreviews / fluentPlayerItems', () => {
    const product = {detail: {other_info: {fluent_player_video: {media_ids: [7, 9, 8], positions: [0, 1, '']}}}};
    const loader = vi.fn(async (restUrl, ids) => ({
        7: {id: 7, title: 'Seven', poster: 'https://x/7.jpg'},
        8: {id: 8, title: 'Eight', poster: ''},
        9: {id: 9, missing: true},
    }));

    it('maps the stored ids to poster previews in order, skipping missing media', async () => {
        const items = await fluentPlayerPreviews(activeConfig, loader, product);
        expect(loader).toHaveBeenCalledWith(activeConfig.restUrl, [7, 9, 8]);
        expect(items).toEqual([
            {id: 7, url: 'https://x/7.jpg', title: 'Seven', kind: 'video', position: 0},
            {id: 8, url: '', title: 'Eight', kind: 'video', position: null},
        ]);
    });

    it('builds the same items from a draft, so the dialog grid can stage them', async () => {
        const items = await fluentPlayerItems(activeConfig, loader, {media_ids: [8, 7], positions: [2, 0]});
        expect(items).toEqual([
            {id: 8, url: '', title: 'Eight', kind: 'video', position: 2},
            {id: 7, url: 'https://x/7.jpg', title: 'Seven', kind: 'video', position: 0},
        ]);
    });

    it('resolves empty without ids or a loader', async () => {
        expect(await fluentPlayerPreviews(activeConfig, loader, {})).toEqual([]);
        expect(await fluentPlayerPreviews(activeConfig, null, product)).toEqual([]);
    });

    it('is wired into the tab definition only when a loader is given', () => {
        expect(fluentPlayerGalleryTab(activeConfig, Comp).previews).toBeNull();
        expect(fluentPlayerGalleryTab(activeConfig, Comp).items).toBeNull();
        const tab = fluentPlayerGalleryTab(activeConfig, Comp, (s) => s, loader);
        expect(typeof tab.previews).toBe('function');
        expect(typeof tab.items).toBe('function');
        expect(typeof tab.applyOrder).toBe('function');
        expect(typeof tab.removeItem).toBe('function');
    });
});
