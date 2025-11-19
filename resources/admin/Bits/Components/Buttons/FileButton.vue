<template>
  <el-button type="default" :size="size" @click="openMediaFrame">
    <el-icon class="el-icon--left"><Upload /></el-icon>
    {{title}}
  </el-button>
</template>

<script setup>
import {Upload} from '@element-plus/icons-vue';
import {getCurrentInstance, nextTick, onMounted} from "vue";
const selfRef = getCurrentInstance().ctx;
import translate from "@/utils/translator/Translator";

const props = defineProps({
  multiple:false,
  title:{
    default: translate('Add File')
  },
  action_title:{
    default: translate('Use This File')
  },

  size:{
    default: 'medium'
  },
})

let mediaFrame = null;
const emit = defineEmits(['onFileSelected'])

const openMediaFrame = () => {
  if (mediaFrame == null) {
    return
  }
  mediaFrame.open();
  nextTick(function () {
    jQuery('.media-modal.wp-core-ui').addClass('wp-fluent-media-modal');
  })
}


onMounted(() => {

  if (!typeof window.wp.media === 'function') {
    return
  }


  mediaFrame = window.wp.media({
    title: selfRef.$t('Select or Upload File'),
    button: {
      text: props.action_title
    },
    library: {
      type: ''
    },
    multiple: props.multiple,
  });
  listenForFileChange();

})

const listenForFileChange = () => {
  mediaFrame.on('select', function () {
    const attachments = mediaFrame.state().get('selection').toJSON()
    emit('onFileSelected', attachments)
  })

  nextTick(function () {
    jQuery('.media-modal.wp-core-ui').removeClass('wp-fluent-media-modal');
  })
}
</script>
