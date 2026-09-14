<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {onBeforeUnmount, onMounted, ref} from "vue";
import translate from "@/utils/translator/Translator";
import {useRoute} from "vue-router";
import {Loading} from '@element-plus/icons-vue';
import AppConfig from '@/utils/Config/AppConfig';

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

// Bumped to force the preview iframe to re-fetch the rendered post.
const previewNonce = ref(0);

let closeHandled = false;

const previewIframeRef = ref(null);
const dialogIframeRef = ref(null);

const route = useRoute();


const initializeBaseUrl = () => {
  baseUrl.value = AppConfig.get('wp_admin_url', window.location.origin + '/wp-admin');
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

  try {
    const iframeDoc = dialogIframeRef.value?.contentDocument;
    if (iframeDoc) {
      iframeDoc.addEventListener('click', (e) => {
        const link = e.target.closest('a[href]');
        if (link && !link.target && !link.getAttribute('onclick')) {
          const href = link.getAttribute('href');
          if (href && (href.startsWith('/') || href.startsWith('http'))) {
            e.preventDefault();
            window.parent.postMessage({ type: 'navigateTo', content: href }, '*');
          }
        }
      }, true);
    }
  } catch (e) {
  }
};

const openDialog = () => {
  showIframe.value = true;
  editorIframeLoading.value = true;
  closeHandled = false;
};

// Both the header button and el-dialog's own `close` event land here, so the
// body is guarded to run once per open/close cycle.
const closeDialog = () => {
  if (closeHandled) {
    return;
  }

  closeHandled = true;
  editorIframeLoading.value = false;
  showIframe.value = false;

  // The preview renders the *saved* post, so it keeps showing the old long
  // description until it is re-fetched. The editor runs under an isolating
  // Document-Isolation-Policy, so the parent cannot inspect it to find out
  // whether anything was saved — refresh unconditionally instead.
  reloadPreview();

  props.productEditModel.data.reloader();
};

const reloadPreview = () => {
  previewLoading.value = true;
  // A changed query param guarantees a real re-fetch; re-assigning the same
  // src can be served from cache or skipped entirely.
  previewNonce.value += 1;
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

  if (type === 'navigateTo') {
    closeDialog();
    window.location.href = content;
  }
}


onMounted(() => {
  initializeBaseUrl();
  window.addEventListener('message', contentChanged);
});

onBeforeUnmount(() => {
  window.removeEventListener('message', contentChanged);
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
        :src="`${baseUrl}/post.php?post=${post_id}&action=edit&custom-editor=true&is-preview-mode=true&fct-preview=${previewNonce}`"
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
          sandbox="allow-same-origin allow-scripts allow-forms allow-popups"
          @load="handleDialogLoad"
          :style="{ visibility: editorIframeLoading ? 'hidden' : 'visible' }"
      />
    </div>
  </el-dialog>
</template>

<style lang="scss" scoped>
</style>
