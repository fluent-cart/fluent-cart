import { GALLERY_TABS_FILTER, normalizeGalleryPosition } from '@/Modules/Products/galleryTabs';

export const FLUENT_PLAYER_TAB_NAME = 'fluent-player';

export const OTHER_INFO_KEY = 'fluent_player_video';

// Mirrors ProductVideoSettings::MAX_VIDEOS so the admin never stages more than the server keeps.
export const MAX_VIDEOS = 20;

const toList = (value) => {
    if (Array.isArray(value)) return value;
    if (typeof value === 'string') return value.split(',');
    return [];
};

export function normalizeMediaIds(ids) {
    return normalizeVideoDraft({ media_ids: ids }).media_ids;
}

/**
 * `{ media_ids: int[], positions: (int|null)[] }` with both arrays the same
 * length: positions[i] is how many gallery images precede media_ids[i], and
 * null means "after every image" (which is where videos saved before ordering
 * existed still show up).
 */
export function normalizeVideoDraft(draft) {
    const ids = toList(draft?.media_ids);
    const positions = toList(draft?.positions);

    const mediaIds = [];
    const ordered = [];
    ids.forEach((id, index) => {
        const mediaId = Number(id);
        if (!Number.isFinite(mediaId) || mediaId <= 0) return;
        if (mediaIds.includes(mediaId)) return;
        if (mediaIds.length >= MAX_VIDEOS) return;
        mediaIds.push(mediaId);
        ordered.push(normalizeGalleryPosition(positions[index]));
    });

    return { media_ids: mediaIds, positions: ordered };
}

export function seedFluentPlayerDraft(product) {
    const stored = product?.detail?.other_info?.[OTHER_INFO_KEY];
    if (!stored || typeof stored !== 'object') {
        return { media_ids: [], positions: [] };
    }
    const hasIds = Array.isArray(stored.media_ids) || typeof stored.media_ids === 'string';
    return normalizeVideoDraft({
        media_ids: hasIds ? stored.media_ids : (stored.media_id ? [stored.media_id] : []),
        positions: stored.positions,
    });
}

// Comma-separated strings: an empty JSON array does not survive the product
// update request, so "remove all videos" would never reach the sanitizer. A
// video with no position contributes an empty slot ("0,,2"), which the PHP
// sanitizer reads back as null.
const toWire = (draft) => ({
    media_ids: draft.media_ids.join(','),
    positions: draft.positions.map(position => (position === null ? '' : String(position))).join(','),
});

export function commitFluentPlayerDraft(draft, { product, productEditModel } = {}) {
    if (!productEditModel || typeof productEditModel.onChangeInputField !== 'function') return false;
    const next = normalizeVideoDraft(draft);
    const current = seedFluentPlayerDraft(product);
    if (JSON.stringify(next) === JSON.stringify(current)) return false;
    productEditModel.onChangeInputField(OTHER_INFO_KEY, toWire(next));
    return true;
}

const toGalleryItem = (summary, position) => ({
    id: summary.id,
    url: summary.poster || '',
    title: summary.title || '',
    kind: 'video',
    position,
});

/**
 * Poster + title per attached media, in stored order; media FluentPlayer no
 * longer returns are skipped.
 *
 * @param {(restUrl: string, ids: number[]) => Promise<Object<number, object>>} loadSummaries
 */
function videoItems(config, loadSummaries, draft) {
    const { media_ids: ids, positions } = normalizeVideoDraft(draft);
    if (!ids.length || typeof loadSummaries !== 'function') {
        return Promise.resolve([]);
    }
    return loadSummaries(config?.restUrl || '', ids).then(summaries => ids
        .map((id, index) => [summaries?.[id], positions[index]])
        .filter(([summary]) => summary && !summary.missing)
        .map(([summary, position]) => toGalleryItem(summary, position)));
}

export function fluentPlayerPreviews(config, loadSummaries, product) {
    return videoItems(config, loadSummaries, seedFluentPlayerDraft(product));
}

export function fluentPlayerItems(config, loadSummaries, draft) {
    return videoItems(config, loadSummaries, draft);
}

/**
 * Rewrites the draft into the gallery's order: media_ids follow the grid, and
 * each keeps the position the grid gave it. Ids the grid never showed (a media
 * FluentPlayer no longer returns) keep their stored position and stay attached.
 *
 * @param {Array} order [{ id, position }] in merged-gallery order.
 */
export function applyFluentPlayerOrder(draft, order) {
    const current = normalizeVideoDraft(draft);
    const entries = Array.isArray(order) ? order : [];

    const mediaIds = [];
    const positions = [];
    entries.forEach((entry) => {
        const id = Number(entry?.id);
        const index = current.media_ids.indexOf(id);
        if (index === -1 || mediaIds.includes(id)) return;
        mediaIds.push(id);
        positions.push(normalizeGalleryPosition(entry.position));
    });

    current.media_ids.forEach((id, index) => {
        if (mediaIds.includes(id)) return;
        mediaIds.push(id);
        positions.push(current.positions[index]);
    });

    return { media_ids: mediaIds, positions };
}

export function removeFluentPlayerMedia(draft, id) {
    const current = normalizeVideoDraft(draft);
    const index = current.media_ids.indexOf(Number(id));
    if (index === -1) return current;
    return {
        media_ids: current.media_ids.filter((_, i) => i !== index),
        positions: current.positions.filter((_, i) => i !== index),
    };
}

export function fluentPlayerGalleryTab(config, component, translate = (s) => s, loadSummaries = null) {
    return {
        name: FLUENT_PLAYER_TAB_NAME,
        label: translate('Video'),
        component,
        props: { config },
        seed: seedFluentPlayerDraft,
        commit: commitFluentPlayerDraft,
        // Without FluentPlayer active the tab only offers the install button, so a
        // count of videos it cannot show would just read as an unexplained number.
        badge: (draft) => (config && config.active ? normalizeVideoDraft(draft).media_ids.length : 0),
        previews: loadSummaries ? (product) => fluentPlayerPreviews(config, loadSummaries, product) : null,
        items: loadSummaries ? (draft) => fluentPlayerItems(config, loadSummaries, draft) : null,
        applyOrder: applyFluentPlayerOrder,
        removeItem: removeFluentPlayerMedia,
    };
}

export function registerFluentPlayerGalleryTab(hooks, config, component, translate, loadSummaries = null) {
    if (!hooks || typeof hooks.addFilter !== 'function' || !config || !component) {
        return false;
    }
    hooks.addFilter(GALLERY_TABS_FILTER, 'fluent_cart/fluent_player', (tabs) => {
        const list = Array.isArray(tabs) ? tabs : [];
        if (list.some(tab => tab && tab.name === FLUENT_PLAYER_TAB_NAME)) return list;
        list.push(fluentPlayerGalleryTab(config, component, translate, loadSummaries));
        return list;
    });
    return true;
}
