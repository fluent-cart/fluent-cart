import {describe, expect, it} from 'vitest';

import {
    REVIEW_URL,
    STORAGE_KEY,
    dismissReviewPrompt,
    isReviewPromptDismissed,
} from '../../resources/admin/Pages/Dashboard/reviewPrompt.js';

/**
 * The dashboard feedback card is browser-only. Once dismissed, it must stay
 * gone in that browser, including across plugin updates, so the key is fixed
 * rather than version-scoped. Blocked storage must never break the dashboard.
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

describe('dashboard review prompt', () => {
    it('shows until the admin dismisses it', () => {
        const storage = fakeStorage();

        expect(isReviewPromptDismissed(storage)).toBe(false);
    });

    it('stays dismissed once dismissed', () => {
        const storage = fakeStorage();

        expect(dismissReviewPrompt(storage)).toBe(true);
        expect(isReviewPromptDismissed(storage)).toBe(true);
    });

    it('uses a fixed, version-free storage key so updates do not bring it back', () => {
        const storage = fakeStorage();
        dismissReviewPrompt(storage);

        expect(STORAGE_KEY).toBe('fct_review_prompt_hidden');
        expect(storage.getItem('fct_review_prompt_hidden')).toBe('yes');
    });

    it('comes back when the dismissal is cleared', () => {
        const storage = fakeStorage();
        dismissReviewPrompt(storage);
        storage.removeItem(STORAGE_KEY);

        expect(isReviewPromptDismissed(storage)).toBe(false);
    });

    it('does not throw when storage is blocked', () => {
        const storage = blockedStorage();

        expect(isReviewPromptDismissed(storage)).toBe(false);
        expect(dismissReviewPrompt(storage)).toBe(false);
    });

    it('does not throw when there is no storage at all', () => {
        expect(isReviewPromptDismissed(null)).toBe(false);
        expect(dismissReviewPrompt(null)).toBe(false);
    });

    it('links to the FluentCart review form on WordPress.org', () => {
        expect(REVIEW_URL).toBe('https://wordpress.org/support/plugin/fluent-cart/reviews/#new-post');
    });
});
