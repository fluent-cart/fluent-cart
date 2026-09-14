const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'wav', 'aac', 'ogg', 'oga', 'flac'];

const YOUTUBE_DOMAINS = ['youtube.com', 'youtu.be', 'youtube-nocookie.com'];
const VIMEO_DOMAINS = ['vimeo.com'];

const hostMatches = (host, domains) => domains.some(domain => host === domain || host.endsWith(`.${domain}`));

/**
 * Mirrors FluentPlayer's suffix-based host detection (DynamicMediaSourceResolver)
 * so the admin can show the provider before the media exists; the server's own
 * classification from media/metadata wins when the media is created.
 *
 * @returns {{provider: 'youtube'|'vimeo'|'external', viewType: 'video'|'audio'}|null}
 */
export default function classifyVideoSource(url) {
    if (typeof url !== 'string') {
        return null;
    }

    const trimmed = url.trim();
    if (!/^https?:\/\//i.test(trimmed)) {
        return null;
    }

    let parsed;
    try {
        parsed = new URL(trimmed);
    } catch (e) {
        return null;
    }

    const host = parsed.hostname.toLowerCase();

    if (hostMatches(host, YOUTUBE_DOMAINS)) {
        return {provider: 'youtube', viewType: 'video'};
    }

    if (hostMatches(host, VIMEO_DOMAINS)) {
        return {provider: 'vimeo', viewType: 'video'};
    }

    const extension = parsed.pathname.split('.').pop().toLowerCase();
    const isAudio = parsed.pathname.includes('.') && AUDIO_EXTENSIONS.includes(extension);

    return {provider: 'external', viewType: isAudio ? 'audio' : 'video'};
}

export function providerLabel(provider) {
    const labels = {
        youtube: 'YouTube',
        vimeo: 'Vimeo',
        external: 'External URL',
        wordpress: 'Media Library',
        bunny: 'Bunny Stream',
        bunny_storage: 'Bunny Storage',
        mux: 'Mux',
        mux_stream: 'Mux Live',
        cloudflare_r2: 'Cloudflare R2',
        cloudflare_stream: 'Cloudflare Stream',
        gumlet: 'Gumlet',
        gumlet_live: 'Gumlet Live',
    };

    return labels[provider] || provider || '';
}

// Same URL as FluentPlayer's Helper::getYouTubeFallbackPosterUrl() for a YouTube media without a poster.
export function youtubeThumbnail(url) {
    if (typeof url !== 'string') {
        return '';
    }
    const match = url.match(/(?:youtu\.be\/|[?&]v=|\/embed\/|\/shorts\/|\/live\/)([A-Za-z0-9_-]{11})/);
    return match ? `https://i.ytimg.com/vi/${match[1]}/hqdefault.jpg` : '';
}

export function deriveVideoTitle({ metaTitle = '', attachmentTitle = '', url = '' } = {}, fallback = 'Untitled Media') {
    const clean = (value) => (typeof value === 'string' ? value.trim() : '');
    if (clean(metaTitle)) return clean(metaTitle);
    if (clean(attachmentTitle)) return clean(attachmentTitle);

    try {
        const path = new URL(clean(url)).pathname;
        const file = decodeURIComponent(path.split('/').filter(Boolean).pop() || '');
        const base = file.replace(/\.[a-z0-9]{2,5}$/i, '').replace(/[-_]+/g, ' ').trim();
        if (base) return base;
    } catch (e) {}

    return fallback;
}
