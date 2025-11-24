<template>
  <div v-if="!iconMode" class="fct-media-button" @click="openMediaFrame">
    <DynamicIcon :name="icon"/>
    <span v-if="title">{{ title }}</span>
  </div>
  <div v-else @click="openMediaFrame">
    <DynamicIcon name="Edit"/>
  </div>
</template>

<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {onMounted, watch} from "vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  multiple: false,
  iconMode: false,
  attachments: {
    type: Array,
    default: () => [],
  },
  title: {
    default: translate('Add Media')
  },
  action_title: {
    default: translate('Use This Media')
  },
  icon: {
    default: 'GalleryAdd'
  },
  libraryType: {
    default: 'image'
  }
})

let preSelectedIds = [];

let mediaFrame = null;
const emit = defineEmits(['onMediaSelected'])

const openMediaFrame = () => {
  if (mediaFrame == null) {
    return
  }
  mediaFrame.open();
}

defineExpose({
  openMediaFrame
})

onMounted(() => {

  if (!typeof window.wp.media === 'function') {
    return
  }


  // Override the default media modal with the custom class
  wp.media.view.Modal = wp.media.view.Modal.extend({
    className: 'wp-fluent-media-modal', // Add your custom class here
  });

  setUpPreSelectedIds();
  mediaFrame = window.wp.media({
    title: translate('Select or Upload Media'),
    button: {
      text: props.action_title
    },
    library: {
      type: props.libraryType
    },
    multiple: props.multiple ? 'add' : false,  // Set to true to allow multiple files to be selected
    is_button: props.is_button,
  });

  setPreselected();
  listenForMediaChange();

})

const isNumeric = (value) => {
  return /^\d+$/.test(value);
}

const setUpPreSelectedIds = () => {
  preSelectedIds = [];
  if (Array.isArray(props.attachments) && props.attachments.length > 0) {
    Object.values(props.attachments).forEach((attachment, index) => {
      if (isNumeric(attachment['id'])) {
        preSelectedIds.push(attachment['id'])
      }
    })
  }
}

const setPreselected = () => {
  mediaFrame.on('open', function () {
    let selection = mediaFrame.state().get('selection');
    preSelectedIds.forEach(function (id) {
      let attachment = window.wp.media.attachment(id);
      if (attachment) {
        selection.add(attachment)
      }
    }); // would be probably a good idea to check if it is indeed a non-empty array
  });
}

const listenForMediaChange = () => {
  mediaFrame.on('select', function () {
    const attachments = mediaFrame.state().get('selection').toJSON()
    emit('onMediaSelected', attachments)
  })
}

watch(() => props.attachments, (first, second) => {
  setUpPreSelectedIds();
});
</script>
