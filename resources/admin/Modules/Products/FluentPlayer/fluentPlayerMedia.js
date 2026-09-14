import Rest from '@/utils/http/Rest';
import { youtubeThumbnail } from '@/utils/FluentPlayer/classifyVideoSource';

const cache = new Map();

export const normalizeMedia = (media, untitled = 'Untitled video') => ({
    id: Number(media.ID || media.id) || 0,
    title: media.post_title || media.title || untitled,
    poster: media.settings?.posterSrc || youtubeThumbnail(media.settings?.src || ''),
    provider: media.settings?.provider || '',
    trashed: media.post_status === 'trash',
});

/**
 * Summaries for media ids, batched through one `media/search?medias=[...]`
 * request. Found media are cached for the page lifetime; ids FluentPlayer no
 * longer returns come back as `{ missing: true }` and are not cached, so a
 * media created or restored later still resolves.
 *
 * @returns {Promise<Object<number, object>>} id => summary (all requested ids present)
 */
export function loadMediaSummaries(restUrl, ids, untitled = 'Untitled video') {
    const wanted = [...new Set((ids || []).map(Number).filter(n => n > 0))];
    const result = {};
    const pending = [];
    wanted.forEach((id) => {
        if (cache.has(id)) {
            result[id] = cache.get(id);
        } else {
            pending.push(id);
        }
    });
    if (!pending.length || !restUrl) {
        pending.forEach((id) => { result[id] = missingSummary(id); });
        return Promise.resolve(result);
    }

    return Rest.get('media/search', { medias: JSON.stringify(pending), limit: pending.length }, restUrl)
        .then((response) => {
            const list = Array.isArray(response) ? response : (response?.data || response?.medias || []);
            list.forEach((media) => {
                const summary = normalizeMedia(media, untitled);
                if (summary.id) cache.set(summary.id, summary);
            });
            pending.forEach((id) => { result[id] = cache.get(id) || missingSummary(id); });
            return result;
        });
}

export function missingSummary(id) {
    return { id, title: '', poster: '', provider: '', missing: true };
}

export function forgetMediaSummary(id) {
    cache.delete(Number(id));
}
