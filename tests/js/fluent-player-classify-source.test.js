import {describe, it, expect} from 'vitest';
import classifyVideoSource, {providerLabel, youtubeThumbnail, deriveVideoTitle} from '@/utils/FluentPlayer/classifyVideoSource';

describe('classifyVideoSource', () => {
    it('detects YouTube hosts, including short and privacy domains', () => {
        expect(classifyVideoSource('https://www.youtube.com/watch?v=abc123')).toEqual({provider: 'youtube', viewType: 'video'});
        expect(classifyVideoSource('https://youtu.be/abc123')).toEqual({provider: 'youtube', viewType: 'video'});
        expect(classifyVideoSource('https://www.youtube-nocookie.com/embed/abc123')).toEqual({provider: 'youtube', viewType: 'video'});
        expect(classifyVideoSource('https://music.youtube.com/watch?v=abc123')).toEqual({provider: 'youtube', viewType: 'video'}, 'any subdomain, like FluentPlayer');
        expect(classifyVideoSource('https://notyoutube.com/watch?v=abc123')).toEqual({provider: 'external', viewType: 'video'}, 'suffix match needs a dot boundary');
    });

    it('detects Vimeo hosts', () => {
        expect(classifyVideoSource('https://vimeo.com/123456')).toEqual({provider: 'vimeo', viewType: 'video'});
        expect(classifyVideoSource('https://player.vimeo.com/video/123456')).toEqual({provider: 'vimeo', viewType: 'video'});
    });

    it('treats any other http(s) URL as external video, audio by extension', () => {
        expect(classifyVideoSource('https://cdn.example.com/demo.mp4')).toEqual({provider: 'external', viewType: 'video'});
        expect(classifyVideoSource('https://cdn.example.com/stream.m3u8?token=1')).toEqual({provider: 'external', viewType: 'video'});
        expect(classifyVideoSource('https://cdn.example.com/podcast.MP3')).toEqual({provider: 'external', viewType: 'audio'});
    });

    it('rejects non-URLs and non-http schemes', () => {
        expect(classifyVideoSource('')).toBeNull();
        expect(classifyVideoSource('   ')).toBeNull();
        expect(classifyVideoSource('not a url')).toBeNull();
        expect(classifyVideoSource('ftp://example.com/a.mp4')).toBeNull();
        expect(classifyVideoSource(null)).toBeNull();
        expect(classifyVideoSource(42)).toBeNull();
    });

    it('trims surrounding whitespace before parsing', () => {
        expect(classifyVideoSource('  https://youtu.be/abc  ')).toEqual({provider: 'youtube', viewType: 'video'});
    });
});

describe('providerLabel', () => {
    it('maps known providers and falls back to the slug', () => {
        expect(providerLabel('youtube')).toBe('YouTube');
        expect(providerLabel('wordpress')).toBe('Media Library');
        expect(providerLabel('custom_thing')).toBe('custom_thing');
        expect(providerLabel('')).toBe('');
        expect(providerLabel(undefined)).toBe('');
    });
});

describe('youtubeThumbnail', () => {
    it('derives the hqdefault thumbnail for YouTube URL shapes', () => {
        expect(youtubeThumbnail('https://www.youtube.com/watch?v=dQw4w9WgXcQ')).toBe('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
        expect(youtubeThumbnail('https://youtu.be/dQw4w9WgXcQ?t=10')).toBe('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
        expect(youtubeThumbnail('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')).toBe('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
        expect(youtubeThumbnail('https://www.youtube.com/shorts/dQw4w9WgXcQ')).toBe('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
    });

    it('returns an empty string for non-YouTube sources', () => {
        expect(youtubeThumbnail('https://vimeo.com/123456')).toBe('');
        expect(youtubeThumbnail('https://cdn.example.com/demo.mp4')).toBe('');
        expect(youtubeThumbnail('')).toBe('');
        expect(youtubeThumbnail(undefined)).toBe('');
    });
});

describe('deriveVideoTitle', () => {
    it('prefers the source metadata title, then the attachment title', () => {
        expect(deriveVideoTitle({metaTitle: ' Never Gonna Give You Up ', attachmentTitle: 'demo', url: 'https://x.test/a.mp4'})).toBe('Never Gonna Give You Up');
        expect(deriveVideoTitle({metaTitle: '', attachmentTitle: 'Product walkthrough', url: 'https://x.test/a.mp4'})).toBe('Product walkthrough');
    });

    it('falls back to a humanised file name, then the default', () => {
        expect(deriveVideoTitle({url: 'https://cdn.example.com/videos/air-max_demo-v2.MP4?x=1'})).toBe('air max demo v2');
        expect(deriveVideoTitle({url: 'https://cdn.example.com/'})).toBe('Untitled Media');
        expect(deriveVideoTitle({url: 'not a url'})).toBe('Untitled Media');
        expect(deriveVideoTitle({}, 'Fallback')).toBe('Fallback');
        expect(deriveVideoTitle()).toBe('Untitled Media');
    });
});
