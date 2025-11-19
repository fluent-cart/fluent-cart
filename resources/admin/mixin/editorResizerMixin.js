import {reactive} from "vue";

export function useEditorResizer() {
    let editor;
    let container;
    let resizeObserver;
    let minHeight;

    function init(editorInstance, editorId, propsHeight) {
        editor = editorInstance;
        let textArea = jQuery(`#${editorId}`);

        minHeight = propsHeight ?? 250;

        container = jQuery(textArea).parents()[0];
        jQuery(container).css({
            "resize": "vertical",
            "overflow-y": "auto",
            "min-height": `${minHeight}px`,
            "height": `${minHeight}px`  // Set initial height explicitly
        });

        if (!editor || !editor.contentAreaContainer) {
            setTimeout(() => init(editorInstance, editorId, propsHeight), 100);
            return;
        }

        // Set initial height
        updateHeight();
        listenForResize(container);
    }

    function calculateHeight() {
        const containerHeight = container.offsetHeight;
        const toolbarHeight = jQuery(container).find(".mce-toolbar-grp").outerHeight(true) || 0;
        const statusBarHeight = jQuery(container).find(".mce-statusbar").outerHeight(true) || 0;
        const offset = toolbarHeight + statusBarHeight + 10;
        const newHeight = containerHeight - offset;

        // Ensure the content area respects the minimum height minus offset
        return Math.max(minHeight - offset, newHeight);
    }

    function updateHeight() {
        if (!editor || !editor.contentAreaContainer) return;
        const newHeight = calculateHeight();
        const currentHeight = editor.contentAreaContainer.offsetHeight;

        if (Math.abs(newHeight - currentHeight) > 2) {
            editor.contentAreaContainer.style.height = `${newHeight}px`;
            if (editor.theme && editor.theme.resizeTo) {
                editor.theme.resizeTo(null, newHeight);
            }
        }

        // Ensure container doesn't go below minHeight
        const totalContainerHeight = newHeight + calculateOffset();
        if (totalContainerHeight < minHeight) {
            jQuery(container).css("height", `${minHeight}px`);
        }
    }

    function calculateOffset() {
        const toolbarHeight = jQuery(container).find(".mce-toolbar-grp").outerHeight(true) || 0;
        const statusBarHeight = jQuery(container).find(".mce-statusbar").outerHeight(true) || 0;
        return toolbarHeight + statusBarHeight + 10;
    }

    function listenForResize(container) {
        resizeObserver = new ResizeObserver(() => {
            updateHeight();
        });
        resizeObserver.observe(container);
    }

    function destroy() {
        if (resizeObserver && container) {
            resizeObserver.unobserve(container);
        }
    }

    return {
        init,
        destroy,
    };
}