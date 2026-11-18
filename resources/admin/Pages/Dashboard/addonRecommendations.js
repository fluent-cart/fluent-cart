// Dashboard "Recommended for your store" card. Browser-only: the collapsed state lives in localStorage
// under a fixed key, so it survives plugin updates.

export const STORAGE_KEY = 'fct_addon_recommendations_collapsed';

const defaultStorage = () => {
    try {
        return window.localStorage;
    } catch (e) {
        return null;
    }
};

export const isRecommendationsCollapsed = (storage = defaultStorage()) => {
    try {
        return !!storage && storage.getItem(STORAGE_KEY) === 'yes';
    } catch (e) {
        return false;
    }
};

// Blocked storage only means the state resets on the next page load.
export const setRecommendationsCollapsed = (collapsed, storage = defaultStorage()) => {
    try {
        if (!storage) {
            return;
        }
        if (collapsed) {
            storage.setItem(STORAGE_KEY, 'yes');
        } else {
            storage.removeItem(STORAGE_KEY);
        }
    } catch (e) {
        // Ignore: see above.
    }
};
