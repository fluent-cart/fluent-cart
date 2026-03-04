import { ref, computed, watch } from 'vue';

export function useBulkSelection() {
    const selectedKeys = ref(new Set());
    const lastClickedKey = ref(null);
    const isLinkMode = ref(false);

    /**
     * Core click handler — plain click toggles, Shift+Click range selects.
     *
     * @param {string} key       - The row key being clicked
     * @param {MouseEvent} event - Native mouse event (for modifier detection)
     * @param {string[]} flatKeys - Ordered array of all visible row keys
     */
    const handleRowClick = (key, event, flatKeys) => {
        const isShift = event.shiftKey;

        if (isShift && lastClickedKey.value !== null) {
            // Range select from lastClickedKey to key
            const fromIdx = flatKeys.indexOf(lastClickedKey.value);
            const toIdx = flatKeys.indexOf(key);
            if (fromIdx !== -1 && toIdx !== -1) {
                const start = Math.min(fromIdx, toIdx);
                const end = Math.max(fromIdx, toIdx);
                const next = new Set(selectedKeys.value);
                for (let i = start; i <= end; i++) {
                    next.add(flatKeys[i]);
                }
                selectedKeys.value = next;
            }
            // Don't update lastClickedKey on shift-click to allow extending range
        } else {
            // Toggle this key
            const next = new Set(selectedKeys.value);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            selectedKeys.value = next;
            lastClickedKey.value = key;
        }
    };

    const toggleSelectAll = (allKeys) => {
        if (selectedKeys.value.size === allKeys.length && allKeys.length > 0) {
            selectedKeys.value = new Set();
        } else {
            selectedKeys.value = new Set(allKeys);
        }
    };

    const selectAll = (allKeys) => {
        selectedKeys.value = new Set(allKeys);
    };

    const deselectAll = () => {
        selectedKeys.value = new Set();
        lastClickedKey.value = null;
        isLinkMode.value = false;
    };

    const toggleLinkMode = () => {
        if (isLinkMode.value) {
            isLinkMode.value = false;
        } else if (selectedKeys.value.size >= 2) {
            isLinkMode.value = true;
        }
    };

    // Auto-disable link mode when selection drops below 2
    watch(selectedKeys, (keys) => {
        if (isLinkMode.value && keys.size < 2) {
            isLinkMode.value = false;
        }
    });

    const isSelected = (key) => {
        return selectedKeys.value.has(key);
    };

    const selectedCount = computed(() => selectedKeys.value.size);

    /**
     * Remove keys that no longer exist in the table (e.g. after product removal).
     * @param {string[]} validKeys
     */
    const pruneStaleKeys = (validKeys) => {
        const validSet = new Set(validKeys);
        let changed = false;
        const next = new Set();
        for (const k of selectedKeys.value) {
            if (validSet.has(k)) {
                next.add(k);
            } else {
                changed = true;
            }
        }
        if (changed) {
            selectedKeys.value = next;
        }
        if (lastClickedKey.value && !validSet.has(lastClickedKey.value)) {
            lastClickedKey.value = null;
        }
    };

    return {
        selectedKeys,
        lastClickedKey,
        isLinkMode,
        handleRowClick,
        toggleSelectAll,
        selectAll,
        deselectAll,
        toggleLinkMode,
        isSelected,
        selectedCount,
        pruneStaleKeys,
    };
}
