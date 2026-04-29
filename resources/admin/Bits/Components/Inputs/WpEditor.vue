<script setup>
import {nextTick, onMounted, ref, watch} from "vue";
import Popover from "@/Modules/Settings/Parts/input-popover-dropdown.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {CircleClose} from "@element-plus/icons-vue";
import {useEditorFileUploader} from '@/mixin/editorFileUploadMixin.js';
import {useEditorResizer} from '@/mixin/editorResizerMixin.js';
import MediaButton from "@/Bits/Components/Buttons/MediaButton.vue";
import {getCurrentInstance} from "vue";
import translate from "@/utils/translator/Translator";
import Theme from "@/utils/Theme";

const selfRef = getCurrentInstance().ctx;

const hasWpEditor = ref(false);
const editor = ref(null);
const selectedTemplate = ref(null);
const {state: uploadingState, init: initUploader, cancelUploading} = useEditorFileUploader();
const {init: initResizer} = useEditorResizer();
const props = defineProps({
  editor_id: {
    type: String,
    default() {
      return (
          "wp_editor_" + Date.now() + parseInt((Math.random() * 1000).toString())
      );
    },
  },
  shortCodes: {
    default: {},
  },
  buttons: {
    default: {},
  },
  templates: {
    default: {},
  },
  emailTemplateEditor: false,
  height: {
    type: Number,
    default() {
      return 250;
    },
  },
  modelValue: {
    type: String,
    default: "",
  },
});


const cursorPos = ref(props.modelValue.length);
const plainContent = ref(props.modelValue);

const editorValue = ref();

onMounted(function () {
  const wpEditor = window.wp.editor;
  if (wpEditor) {
    hasWpEditor.value = true;
    nextTick(function () {
      setTimeout(function () {

        initEditor();
        window.addEventListener("onFluentCartThemeChange", (event) => {
          initEditor();
        }, false);
      }, 200);
    });
  }
});

const emit = defineEmits([
  "update",
  "onEditorReady",
  "getTemplate",
]);

const getTemplate = () => {
  emit("getTemplate", selectedTemplate.value);
};

const clear = () => {
  if (editor.value !== null) {
    editor.value.setContent('');
  } else {
    plainContent.value = '';
    cursorPos.value = 0;
  }
};

const setContent = (content) => {
  if (editor.value !== null) {
    editor.value.setContent(editor.value.getContent() + content);
  } else {
    const part1 = props.modelValue.slice(0, cursorPos.value);
    const part2 = props.modelValue.slice(
        cursorPos.value,
        plainContent.value.length
    );
    plainContent.value = part1 + content + part2;
    cursorPos.value += content.length;
  }
};


defineExpose({clear, setContent});

const initEditor = () => {
  wp.editor.remove(props.editor_id);

  if (typeof window.wp.media === 'function') {
    wp.media.view.Modal = wp.media.view.Modal.extend({
      className: 'wp-fluent-media-modal', // Add your custom class here
    });
  }


  wp.editor.initialize(props.editor_id, {

    mediaButtons: true,
    tinymce: {
      plugins: 'fullscreen,lists,link',
      content_style: Theme.isDark() ? '*{color:white;} body { background: #11171d; }' : '',
      toolbar1: "formatselect,table,bold,italic,bullist,numlist,link,blockquote,alignleft,aligncenter,alignright,underline,strikethrough,forecolor,removeformat,codeformat,outdent,indent,undo,redo,mediaButton",
      setup(ed) {
        setTimeout(() => {
          editor.value = ed;

          ed.on('ObjectResized', function (event) {
            updateEditorValue();
          });

          ed.on('OpenWindow', (e) => {
            const dialog = e.win.$el;
            if (dialog) {
              setTimeout(() => {
                dialog.addClass('fluent-cart');
              })
            }
          });


          jQuery('.wp-media-buttons').css('display', 'none');
          initResizer(ed, props.editor_id, props.height);
          initUploader(ed);
          let textArea = jQuery(`#${props.editor_id}`);

          const container = jQuery(textArea).parents()[0];

          let buttons2 = jQuery(container).find('.ed_button.button.button-small')

          jQuery(buttons2).click(function () {
            setTimeout(function () {
              setContent(jQuery('#' + props.editor_id).val())
              updateEditorValue();
              const $wpLinkModal = jQuery('#wp-link-wrap');
              if ($wpLinkModal.is(':visible')) {
                console.log('Link dialog opened from Text tab');
                $wpLinkModal.addClass('fluent-cart');
              }
            }, 100)

          })
        }, 100);
        emit("onEditorReady", ed);
        ed.on("input cut paste ExecCommand", function (ed, l) {
          updateEditorValue();
        });
        //Handle case If user does any shortcut like: CMD+A and Cmd+X
        ed.on('keyup', (e) => {
          if ((e.key === 'Backspace' || e.key === 'Delete') || (e.key === 'x' && (e.ctrlKey || e.metaKey))) {
            const content = ed.getContent();
            if (content.trim() === '') {
              updateEditorValue();
            }
          }
        });


        ed.addButton('mediaButton', {
          icon: 'image',
          tooltip: translate('Insert Media'),
          onclick: () => {
            jQuery('.button.insert-media.add_media').click();
            // selfRef.$nextTick(() => {
            //   selfRef.$refs.customModal.openMediaFrame();
            // });
          }
        });

      },
    },
    quicktags: true,
  });
};

const updateEditorValue = () => {

  let content = wp.editor.getContent(props.editor_id);
  emit("update", content);
}

const onShortCodeSelected = (code) => {
  if (editor !== null) {
    editor.value.insertContent(code);
  } else {
    const part1 = props.modelValue.slice(0, cursorPos.value);
    const part2 = props.modelValue.slice(
        cursorPos.value,
        plainContent.value.length
    );
    plainContent.value = part1 + code + part2;
    cursorPos.value += code.length;
  }
};

const addButton = (html) => {
  editor.value.insertContent(html);
};

const showPreviewModal = ref(false);
let previewContent = '';
const showPreview = () => {
  setPreviewContent();
  showPreviewModal.value = true;
};

const updateCursorPos = () => {
  const cursorPos = jQuery(".wp_vue_editor_plain").prop("selectionStart");
  cursorPos.value = cursorPos;
};

const setPreviewContent = () => {
  if (hasWpEditor.value) {
    previewContent = wp.editor.getContent(props.editor_id);
  }else{
    previewContent = plainContent.value;
  }
  previewContent = previewContent.trim()

  if(!previewContent){
    previewContent= translate('Nothing to preview') ;
  }
}

/*const onMediaSelected = (attachments) => {
    let contents = '';
    attachments.forEach((attachment) => {
      contents += `
      <img class="alignnone size-medium wp-image-${attachment.id}" src="${attachment.url}" alt="" width="300" height="300" />`;
    })
    window.tinymce?.activeEditor?.insertContent(contents);
}*/

watch(plainContent, async (newContent, oldContent) => {
  editorValue.value = newContent;
  emit("update", newContent);
});
</script>

<template>


  <el-dialog v-model="showPreviewModal" :title="$t('Preview')">
    <div v-html="previewContent"></div>
  </el-dialog>


  <div class="relative w-full">
    <div class="top-1 absolute w-[calc(100%-128px)] left-0 flex gap-2 justify-between pb-0 z-[9]">
      <div class="flex gap-2 flex-row" v-if="Object.keys(shortCodes).length">
        <div class="fct-add-shortcode-button-wrap">
          <Popover
              :data="shortCodes"
              @command="onShortCodeSelected"
              btnType="info"
              btnSize="small"
              plain
          >
            {{ $t("Add ShortCodes") }}
          </Popover>
        </div>
        <slot name="action"></slot>
      </div>

      <div class="flex gap-2 items-center ml-auto">
        <el-tooltip
            v-if="editor !== null"
            effect="dark"
            :content="$t('Preview Template')"
            placement="top"
            popper-class="fct-tooltip"
        >
          <IconButton bg="white" @click.prevent="showPreview" tag="button" size="small">
            <DynamicIcon name="Eye"/>
          </IconButton>
        </el-tooltip>
        <slot name="reset_template"></slot>
      </div>
    </div>

    <div>
      <div
          v-if="hasWpEditor"
          class="w-full"
      >
        <div :class="editor === null ? 'opacity-0 ' : ''" :style="{'min-height': height}">
          <div class="custom-wp-editor-wrapper" v-loading="uploadingState.uploadingFile">
            <div>
               <textarea
                   @input="()=>{
                    updateEditorValue()
                   }"
                   class="wp_vue_editor test"
                   :id="editor_id"
                   :value="modelValue"
               ></textarea>
              <!-- <MediaButton ref="customModal" :multiple="true" @onMediaSelected="onMediaSelected" style="display: none;"/> -->
            </div>
          </div>
          <div v-if="uploadingState.uploadingFile">
            <el-progress
                :text-inside="true"
                :stroke-width="20"
                :percentage="uploadingState.uploadProgress"
            >
            </el-progress>

            <el-button @click="evt => {
          cancelUploading();
        }">
              <el-icon>
                <CircleClose/>
              </el-icon>
            </el-button>
          </div>
        </div>

      </div>
      <div v-else>
        <textarea
            class="wp_vue_editor wp_vue_editor_plain"
            @click="updateCursorPos"
            v-model="plainContent"
        ></textarea>
      </div>

    </div>
  </div>
</template>
