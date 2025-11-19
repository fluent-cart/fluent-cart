<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {onMounted, ref} from "vue";
import translate from "@/utils/translator/Translator";
import {useRoute} from "vue-router";
import {Loading} from '@element-plus/icons-vue';

const props = defineProps({
  post_id: {
    required: true,
  },
  product: {
    type: Object
  },
  reload: Function,
  productEditModel: Object,
});

const showIframe = ref(false);
const previewLoading = ref(true);
const editorIframeLoading = ref(true);
const baseUrl = ref();

const previewIframeRef = ref(null);
const dialogIframeRef = ref(null);

const route = useRoute();


const initializeBaseUrl = () => {
  const origin = window.location.origin;
  baseUrl.value = origin + '/wp-admin';
};

const handlePreviewLoad = () => {
  setTimeout(() => {
    previewLoading.value = false;
  }, 500);

};

const handleDialogLoad = () => {
  editorIframeLoading.value = false;
  setTimeout(() => {
    //editorIframeLoading.value = false;
  }, 500);
};

const openDialog = () => {
  showIframe.value = true;
  editorIframeLoading.value = true;
};
const closeDialog = () => {
  editorIframeLoading.value = false;
  showIframe.value = false;
  props.productEditModel.data.reloader();
};

const reloadPreview = () => {
  previewLoading.value = true;
  if (previewIframeRef.value) {
    previewIframeRef.value.src = previewIframeRef.value.src.toString();
  }
};

const reloadDialog = () => {
  editorIframeLoading.value = true;
  if (dialogIframeRef.value) {
    dialogIframeRef.value.src = dialogIframeRef.value.src.toString();
  }
};


const contentChanged = (event) => {
  const {type, content} = event.data;

  if (type === 'gutenbergContentChanged') {
    reloadPreview();
  }
}


onMounted(() => {
  initializeBaseUrl();
  window.addEventListener('message', contentChanged);
});
</script>

<template>


  <div id="gt" class="fct-custom-gutenberg-editor-wrap w-full" style="height: 400px; position: relative;">
    <div class="fct-custom-gutenberg-editor-wrap-overlay"></div>

    <!-- Preview iframe loader -->
    <div v-if="previewLoading" class="iframe-loader">
      <span>{{ translate('Loading preview...') }} <el-icon class="is-loading"><Loading/></el-icon></span>
    </div>

    <iframe
        ref="previewIframeRef"
        class="scrollbar-none preview-iframe"
        :src="`${baseUrl}/post.php?post=${post_id}&action=edit&custom-editor=true&is-preview-mode=true`"
        width="100%"
        height="400px"
        @load="handlePreviewLoad"
        :style="{ visibility: previewLoading ? 'hidden' : 'visible' }"
    />
  </div>
  <div class="iframe-controls">
    <el-button @click="openDialog">{{ translate('Show Content Editor') }}</el-button>
  </div>

  <el-dialog
      :append-to-body="true"
      :show-close="false"
      modal-class="fct-custom-gutenberg-editor-dialog"
      width="800px"
      v-model="showIframe"
      @close="closeDialog"
      @open="()=>{
        reloadDialog()
      }"
  >
    <template #header>
      <div class="dialog-header">
        <el-breadcrumb separator="/">
          <el-breadcrumb-item>
            <span class="fct-custom-gutenberg-editor-product-title" @click="closeDialog"> {{ product.post_title }}</span>
          </el-breadcrumb-item>
          <el-breadcrumb-item>Editor</el-breadcrumb-item>
        </el-breadcrumb>
        <el-button v-if="!editorIframeLoading" class="fct-custom-gutenberg-editor-close-btn" @click="closeDialog">
          <DynamicIcon name="Cross"/>
          {{ translate('Close') }}
        </el-button>
      </div>
    </template>

    <div id="gt" class="fct-custom-gutenberg-editor-wrap" style="position: relative;">
      <div v-if="editorIframeLoading" class="iframe-loader">
        <span>{{ translate('Loading editor...') }} <el-icon class="is-loading"><Loading/></el-icon></span>
      </div>

      <iframe
          ref="dialogIframeRef"
          class="dialog-iframe"
          :src="`${baseUrl}/post.php?post=${post_id}&action=edit&custom-editor=true`"
          width="100%"
          height="700px"
          sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-top-navigation"
          @load="handleDialogLoad"
          :style="{ visibility: editorIframeLoading ? 'hidden' : 'visible' }"
      />
    </div>
  </el-dialog>
</template>
<style lang="scss">

</style>
