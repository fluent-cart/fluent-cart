window.addEventListener("load", (event) => {


    console.log(window.gutenSettings,
        window.gutenContext)
    if (typeof wp !== "undefined" && wp.editPost) {
        wp.editPost.initializeEditor(
            "gutenberg-editor",
            "fluent-products",
            window.post_id,
            window.gutenSettings,
            window.gutenContext
        );

        // Listen to editor changes
        wp.data.subscribe(() => {
            const content = wp.data.select("core/editor").getEditedPostContent();
            window.parent.postMessage(
                {
                    type: 'getuenbergContentChanged',
                    content: content
                },
                '*'
            );
        });
    }else{
        console.log("Not loaded")
    }
});