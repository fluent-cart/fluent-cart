import {onMounted, onBeforeUnmount, nextTick} from 'vue';

export function useSearchShortcut() {
    let lastKeyCode = null;

    onMounted(() => {
        document.addEventListener("keydown", search);
    });

    onBeforeUnmount(() => {
        lastKeyCode = null;
        document.removeEventListener("keydown", search);
    });

    let onSearchCallback;

    function search(event) {
        if ((event.ctrlKey || event.metaKey) && (!event.shiftKey) && (event.keyCode === 75)) {
            event.preventDefault();
            nextTick(() => {
                let input = document.getElementById('fct-global-search-input');
                if (input) {
                    input.focus();
                }
            });
            if (typeof onSearchCallback === 'function') {
                onSearchCallback();
            }
        }
    }

    function onSearch(onSearch) {
        onSearchCallback = onSearch;
    }

    return {onSearch};
}
