<template>
    <div :class="{ 'fct-full-screen-editor-wrap': showingFullScreen }" style="width: 100%; display: block; position: relative;" v-loading="!editorReady">
        <div class="fct-full-screen-port">
            <div :class="{ 'fct-full-screen-editor': showingFullScreen }">
                <iframe ref="fct_full_editor" id="fbe-iframe" src="https://cart.lab/?fluent-cart=email_editor"
                        style="width:100%;height:700px;border:1px solid #ccd0d4;background:#fff;"
                        sandbox="allow-scripts allow-same-origin allow-forms allow-popups"></iframe>
            </div>
        </div>
    </div>
</template>

<script type="text/babel">
export default {
    name: 'FullGutenbergEditor',
    props: {
        modelValue: {
            default: '',
            type: String
        }
    },
    emits: ['update:modelValue'],
    data() {
        return {
            emailContent: this.modelValue,
            editor: null,
            editorReady: false,
            showingFullScreen: false
        }
    },
    watch: {
        emailContent(value) {
            this.$emit('update:modelValue', value);
        }
    },
    mounted() {
        this.editor = this.$refs.fct_full_editor;
        // Listen for messages from iframe
        window.addEventListener('message', this.handleIframeMessage);
    },
    methods: {
        handleIframeMessage(event) {
            if (event.source !== this.editor.contentWindow) {
                return;
            }

            const action = event.data?.action || '';
            if(!action) {
                return;
            }


            // Check if this is the message we're looking for
            if (action === 'EDITOR_UPDATED' && this.editorReady) {
                this.emailContent = event.data.content;
            } else if(action === 'EDITOR_READY') {

                setTimeout(() => {
                    // Send the initial content to the editor
                    this.editor.contentWindow.postMessage({
                        type: 'UPDATE_EDITOR',
                        data: {
                            content: this.emailContent
                        }
                    }, '*');
                }, 1000);

            } else if(action === 'CONTENT_READY') {
                this.editorReady = true;
            }
        }
    }
}
</script>
