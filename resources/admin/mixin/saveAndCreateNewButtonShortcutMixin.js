import {onMounted, onBeforeUnmount} from 'vue';

export function saveAndCreateNewButtonShortcutMixin() {
    let lastKeyCode = null;

    onMounted(() => {
        document.addEventListener("keydown", save);
    });

    onBeforeUnmount(() => {
        lastKeyCode = null;
        document.removeEventListener("keydown", save);
    });

    let onSaveCallback;

    function save(event) {
        if ((event.ctrlKey || event.metaKey) && event.shiftKey && (event.keyCode === 83)) {
            event.preventDefault();
            if (typeof onSaveCallback === 'function') {
                onSaveCallback();
            }
        }
    }

    function onSave(onSave) {
        onSaveCallback = onSave;
    }

    return {onSave};
}