import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {emptyChipState, isChipActive, toggleChip} from '../../resources/public/single-product/review-filter-chips';

/**
 * The review filter chips combine: two star chips show both ratings, and With
 * Photos and Verified narrow on top. "All" clears everything.
 */
const click = (values, minRating = 0) =>
    values.reduce((state, value) => toggleChip(state, value, minRating), emptyChipState());

describe('review filter chips', () => {
    it('combines star chips', () => {
        expect(click(['5', '4']).ratings).toEqual([5, 4]);
    });

    it('keeps the stars high to low whatever order they were picked in', () => {
        expect(click(['3', '5', '4']).ratings).toEqual([5, 4, 3]);
    });

    it('turns a star off when clicked again', () => {
        expect(click(['5', '4', '5']).ratings).toEqual([4]);
    });

    it('adds photos and verified on top of the stars', () => {
        expect(click(['5', 'has_media', 'verified'])).toEqual({ratings: [5], hasMedia: true, verified: true});
    });

    it('toggles photos and verified off', () => {
        expect(click(['has_media', 'has_media', 'verified', 'verified'])).toEqual(emptyChipState());
    });

    it('clears everything on All', () => {
        expect(click(['5', '4', 'has_media', 'all'])).toEqual(emptyChipState());
    });

    it('ignores stars below the list floor and unknown chips', () => {
        const state = click(['5']);
        expect(toggleChip(state, '2', 4)).toBe(state);
        expect(toggleChip(state, 'something-else', 0)).toBe(state);
        expect(toggleChip(state, '7', 0)).toBe(state);
    });

    it('shows All pressed only when nothing else is', () => {
        expect(isChipActive(emptyChipState(), 'all')).toBe(true);
        const state = click(['4', 'has_media']);
        expect(isChipActive(state, 'all')).toBe(false);
        expect(isChipActive(state, '4')).toBe(true);
        expect(isChipActive(state, '5')).toBe(false);
        expect(isChipActive(state, 'has_media')).toBe(true);
        expect(isChipActive(state, 'verified')).toBe(false);
    });
});

describe('the review list request', () => {
    const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
    const JS = fs.readFileSync(path.join(root, 'resources/public/single-product/Reviews.js'), 'utf8');

    it('sends every chosen star as one ratings param', () => {
        expect(JS).toMatch(/params\.set\('ratings', this\.#chips\.ratings\.join\(','\)\)/);
    });

    it('keeps a photos-only list photos-only whatever the chips say', () => {
        expect(JS).toMatch(/if \(this\.#chips\.hasMedia \|\| this\.#onlyWithPhotos\)/);
    });
});
