import {describe, expect, it} from 'vitest';

import {
    STORAGE_KEY,
    isRecommendationsCollapsed,
    setRecommendationsCollapsed,
} from '../../resources/admin/Pages/Dashboard/addonRecommendations.js';

/**
 * The dashboard recommendations card can collapse, and stays collapsed in this browser until it is opened again.
 * Blocked storage must never break the dashboard.
 */

function fakeStorage() {
    const rows = new Map();

    return {
        getItem: key => (rows.has(key) ? rows.get(key) : null),
        setItem: (key, value) => rows.set(key, String(value)),
        removeItem: key => rows.delete(key),
    };
}

function blockedStorage() {
    const fail = () => {
        throw new Error('SecurityError: storage is disabled');
    };

    return {getItem: fail, setItem: fail, removeItem: fail};
}

describe('dashboard recommendations collapse state', () => {
    it('starts open, remembers a collapse and forgets it when opened again', () => {
        const storage = fakeStorage();

        expect(isRecommendationsCollapsed(storage)).toBe(false);

        setRecommendationsCollapsed(true, storage);
        expect(storage.getItem(STORAGE_KEY)).toBe('yes');
        expect(isRecommendationsCollapsed(storage)).toBe(true);

        setRecommendationsCollapsed(false, storage);
        expect(storage.getItem(STORAGE_KEY)).toBe(null);
        expect(isRecommendationsCollapsed(storage)).toBe(false);
    });

    it('treats missing or blocked storage as open and does not throw', () => {
        expect(isRecommendationsCollapsed(null)).toBe(false);
        expect(isRecommendationsCollapsed(blockedStorage())).toBe(false);
        expect(() => setRecommendationsCollapsed(true, blockedStorage())).not.toThrow();
        expect(() => setRecommendationsCollapsed(true, null)).not.toThrow();
    });
});
