<template>
  <div class="wp_vue_editor_wrapper relative">
    <div class="fct-editor-action-buttons">
      <MediaButton :multiple="true" @onMediaSelected="onMediaSelected"/>
      <Popover v-if="editorShortcodes.length" :class="{'popover-wrapper-plaintext': !hasWpEditor}"
               :data="editorShortcodes" @command="handleCommand" btnType="info" plain>
        {{ $t('Add ShortCodes')}}
      </Popover>
    </div>
    <textarea v-if="hasWpEditor" class="wp_vue_editor" :id="editor_id" :value="value"></textarea>
    <textarea v-else class="wp_vue_editor wp_vue_editor_plain" v-model="plain_content" @click="updateCursorPos"></textarea>
  </div>
</template>

<script setup>
import colors from '../../../../styles/tailwind/extends/color'
import Popover from './input-popover-dropdown.vue'
import {onMounted, ref} from "vue";
import MediaButton from "@/Bits/Components/Buttons/MediaButton.vue";

defineOptions({
  name: 'wp_editor'
})

const props = defineProps({
  editor_id: {
    type: String,
    default() {
      return 'wp_editor_' + Date.now() + parseInt((Math.random() * 1000).toString());
    }
  },
  value: {
    type: String,
    default() {
      return '';
    }
  },
  editorShortcodes: {
    type: Array,
    default() {
      return []
    }
  },
  height: {
    type: Number,
    default() {
      return 250;
    }
  }
})

const emit = defineEmits([
  'input', '', 'resetTemplate'
])

const hasWpEditor = window.wp.editor;
const showButtonDesigner = ref(false)
const buttonInitiated = ref(false)
const plain_content = ref(props.value)
const cursorPos = ref(props.value.length)
const tinyEditor = ref(null)
let currentEditor;

onMounted(() => {
  window.addEventListener("onFluentCartThemeChange", onThemeChanged, false);
  if (hasWpEditor) {
    initEditor();
  }
})

const initEditor = () => {
  wp.editor.remove(props.editor_id);
  wp.editor.initialize(props.editor_id, {
    mediaButtons: false,
    tinymce: {
      height: props.height,
      toolbar1: 'formatselect,customInsertButton,table,bold,italic,bullist,numlist,link,blockquote,alignleft,aligncenter,alignright,underline,strikethrough,forecolor,removeformat,codeformat,outdent,indent,undo,redo',
      setup(ed) {
        tinyEditor.value = ed;
        ed.on('init', (ed) => {
          applyEditorTheme()
        });

        ed.on('change', function (ed, l) {
          changeContentEvent();
        });
        if (buttonInitiated.value) {
          buttonInitiated.value = true;
          ed.addButton('customInsertButton', {
            text: 'Button',
            classes: 'wpns_editor_btn',
            onclick() {
              showInsertButtonModal(ed);
            }
          });
        }
      }
    },
    quicktags: true
  });

  jQuery('#' + props.editor_id).on('change', function (e) {
    changeContentEvent();
  });
}

const applyEditorTheme = () => {
  if (tinyEditor.value == null) {
    return
  }
  const target_copy = Object.assign({}, tinyEditor.value);
  let iframe = jQuery(target_copy.container).find('iframe')
  const isDarkModeEnabled = jQuery('body').hasClass('dark');
  const body = jQuery(iframe).contents().find('body');
  body.css({
    'background-color': isDarkModeEnabled ? colors.dark["800"] : '#fff',
    'color': isDarkModeEnabled ? colors.neutral["200"] : '#000'
  });
}

const onThemeChanged = (event) => {
  applyEditorTheme()
}

const changeContentEvent = () => {
  let content = wp.editor.getContent(props.editor_id);
  emit('input', content)
}

const handleCommand = (command) => {
  if (hasWpEditor) {
    tinymce.activeEditor.insertContent(command);
  } else {
    const part1 = plain_content.value.slice(0, this.cursorPos);
    const part2 = plain_content.value.slice(cursorPos.value, plain_content.value.length);
    plain_content.value = part1 + command + part2;
    cursorPos.value += command.length;
  }
}

const onMediaSelected = (attachments) => {

  let contents = '';
  attachments.forEach((attachment) => {
    contents += `
    <img class="alignnone size-medium wp-image-${attachment.id}" src="${attachment.url}" alt="" width="300" height="300" />`;
  })
  window.tinymce?.activeEditor?.insertContent(contents);
}

const showInsertButtonModal = (editor) => {
  currentEditor = editor;
  showButtonDesigner.value = true;
}

const insertHtml = (content) => {
  currentEditor.insertContent(content);
}

const updateCursorPos = () => {
  const cursorPos = jQuery('.wp_vue_editor_plain').prop('selectionStart');
  cursorPos.value = cursorPos
}
</script>

<script type="text/babel">

export default {
  watch: {
    plain_content() {
      this.$emit('input', this.plain_content);
    }
  },

}
</script> 
