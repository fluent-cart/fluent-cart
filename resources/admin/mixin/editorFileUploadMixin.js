import {reactive} from "vue";

export function useEditorFileUploader() {
    const url = `${fluentCartRestVars.rest.url}/upload-editor-file`;
    const nonce = window.fluentCartRestVars.rest.nonce;
    const state = reactive({
        uploadingFile: false,
        uploadProgress: 0
    });
    let editor = null;
    let uploadXhr;

    function init(ed) {
        editor = ed;
        editor.on("paste", function (event) {
            const file = event.clipboardData.files[0];
            if (file) {
                event.preventDefault();
                handleFilePaste(file);
            }
        });
    }

    function handleFilePaste(file) {
        if (file === null) {
            return;
        }
        state.uploadingFile = true;
        state.uploadProgress = 0;
        uploadFile(getFormData(file));
    }

    function uploadFile(formData) {
        jQuery.ajax({
            type: "POST",
            url: url,
            data: formData,
            contentType: false,
            processData: false,
            xhr: function () {
                uploadXhr = new window.XMLHttpRequest();
                uploadXhr.upload.addEventListener("progress", function (evt) {
                    if (evt.lengthComputable) {
                        state.uploadProgress = (evt.loaded / evt.total) * 100;
                    }
                }, false);
                return uploadXhr;
            },
            error: () => {
                state.uploadingFile = false;
                state.uploadProgress = 0;
            },
            success: (response) => {
                if (response) {
                    let content = `&#160<img src="${response.url}" alt="${response.title}" width="300" height="200" class="alignnone size-medium wp-image-${response.id}"/>&#160`;
                    editor.insertContent(content);
                }
                state.uploadingFile = false;
                state.uploadProgress = 0;
            },
        });
    }

    function cancelUploading(){
        if (uploadXhr != null){
            uploadXhr.abort();
            uploadXhr = null;
        }
    }

    function getFormData(file) {
        const formData = new FormData()
        formData.append("file", file);
        formData.append(
            "X-WP-Nonce",
            nonce
        );
        return formData;
    }

    // expose your properties and methods
    return {
        state,
        init,
        cancelUploading
    };
}