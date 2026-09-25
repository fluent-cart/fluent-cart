// Dashboard feedback card. Browser-only: the dismissal lives in localStorage,
// under a fixed key (unlike utils/Storage, which is version-scoped) so it
// survives plugin updates.

export const REVIEW_URL = 'https://wordpress.org/support/plugin/fluent-cart/reviews/#new-post';
export const STORAGE_KEY = 'fct_review_prompt_hidden';

const defaultStorage = () => {
    try {
        return window.localStorage;
    } catch (e) {
        return null;
    }
};

export const isReviewPromptDismissed = (storage = defaultStorage()) => {
    try {
        return !!storage && storage.getItem(STORAGE_KEY) === 'yes';
    } catch (e) {
        return false;
    }
};

// Returns false when storage is blocked: the card then stays hidden only until the next page load.
export const dismissReviewPrompt = (storage = defaultStorage()) => {
    try {
        if (!storage) {
            return false;
        }
        storage.setItem(STORAGE_KEY, 'yes');
        return true;
    } catch (e) {
        return false;
    }
};
