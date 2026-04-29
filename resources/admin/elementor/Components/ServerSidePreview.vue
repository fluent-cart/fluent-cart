<script setup>
import useElementor from "../mixins/useElementor.js";
import {addQueryArgs} from '@wordpress/url';
import {ref, watch, nextTick} from "vue";

const props = defineProps({
  block: {
    type: String,
    required: true
  },
  attributes: {
    type: Object,
    required: true
  }
})

const elementor = useElementor();
const iframeRef = ref(null);
const isLoading = ref(false);
const currentAttributes = ref({...props.attributes});

const getAttributes = () => {
  return elementor.getSettings();
}

const getSrc = (attributes = props.attributes) => {
  return window.location.origin + addQueryArgs('', {
    'fluent-cart': 'block-render',
    block: props.block,
    context: 'edit',
    attributes: attributes,
  })
}

// Method 1: Post message to iframe (recommended)
const updateIframeData = async (newAttributes) => {
  if (iframeRef.value && iframeRef.value.contentWindow) {
    try {
      // Send new data to iframe
      iframeRef.value.contentWindow.postMessage({
        type: 'UPDATE_ATTRIBUTES',
        attributes: newAttributes,
        block: props.block
      }, window.location.origin);
    } catch (error) {
      console.error('Failed to update iframe via postMessage:', error);
      // Fallback to reload if postMessage fails
      await reloadWithLoading();
    }
  }
}

// Method 2: Smooth reload with loading state
const reloadWithLoading = async () => {
  if (!iframeRef.value) return;

  isLoading.value = true;

  // Store current scroll position if needed
  const scrollData = await getIframeScrollPosition();

  // Update src
  iframeRef.value.src = getSrc();

  // Wait for load
  await new Promise((resolve) => {
    const onLoad = () => {
      iframeRef.value.removeEventListener('load', onLoad);
      resolve();
    };
    iframeRef.value.addEventListener('load', onLoad);
  });

  // Restore scroll position
  if (scrollData) {
    await restoreIframeScrollPosition(scrollData);
  }

  isLoading.value = false;
}

// Method 3: Dual iframe approach for seamless transition
const createSeamlessUpdate = async (newAttributes) => {
  const container = iframeRef.value.parentElement;

  // Create new iframe
  const newIframe = document.createElement('iframe');
  newIframe.src = getSrc(newAttributes);
  newIframe.style.cssText = iframeRef.value.style.cssText;
  newIframe.style.position = 'absolute';
  newIframe.style.top = '0';
  newIframe.style.left = '0';
  newIframe.style.opacity = '0';
  newIframe.style.transition = 'opacity 0.3s ease';

  container.appendChild(newIframe);

  // Wait for new iframe to load
  await new Promise((resolve) => {
    newIframe.onload = resolve;
  });

  // Fade transition
  newIframe.style.opacity = '1';
  iframeRef.value.style.opacity = '0';

  setTimeout(() => {
    container.removeChild(iframeRef.value);
    iframeRef.value = newIframe;
    newIframe.style.position = 'static';
    newIframe.style.transition = '';
  }, 300);
}

// Helper functions
const getIframeScrollPosition = async () => {
  try {
    if (iframeRef.value?.contentWindow) {
      return {
        scrollTop: iframeRef.value.contentDocument.documentElement.scrollTop,
        scrollLeft: iframeRef.value.contentDocument.documentElement.scrollLeft
      };
    }
  } catch (error) {
    // Cross-origin restrictions
    return null;
  }
}

const restoreIframeScrollPosition = async (scrollData) => {
  try {
    await nextTick();
    if (iframeRef.value?.contentWindow && scrollData) {
      iframeRef.value.contentWindow.scrollTo(scrollData.scrollLeft, scrollData.scrollTop);
    }
  } catch (error) {
    // Ignore cross-origin errors
  }
}

// Watch for attribute changes
watch(() => props.attributes, async (newAttributes, oldAttributes) => {
  // Avoid unnecessary updates
  if (JSON.stringify(newAttributes) === JSON.stringify(oldAttributes)) {
    return;
  }

  currentAttributes.value = {...newAttributes};

  // Choose your preferred method:

  // Method 1: PostMessage (best for preserving state)
  await updateIframeData(newAttributes);

  // Method 2: Smooth reload (fallback)
  // await reloadWithLoading();

  // Method 3: Seamless transition (best UX but more complex)
  // await createSeamlessUpdate(newAttributes);

}, { deep: true });

// Listen for messages from iframe
const handleMessage = (event) => {
  if (event.origin !== window.location.origin) return;

  if (event.data.type === 'IFRAME_READY') {
    // Iframe is ready to receive updates
    console.log('Iframe ready');
  } else if (event.data.type === 'UPDATE_COMPLETE') {
    // Iframe has finished updating
    isLoading.value = false;
  }
}

// Setup message listener
if (typeof window !== 'undefined') {
  window.addEventListener('message', handleMessage);
}

// Cleanup
const cleanup = () => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('message', handleMessage);
  }
}

// Vue 3 lifecycle
import { onUnmounted } from 'vue';
onUnmounted(cleanup);

</script>

<template>
  <div style="position: relative; width: 100%; height: 600px;">
    <!-- Loading overlay -->
    <div
        v-if="isLoading"
        style="
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 20;
      "
    >
      <div>Loading...</div>
    </div>

    <iframe
        class="nuhel"
        ref="iframeRef"
        :src="getSrc()"
        style="width: 100%; height: 100%; border: none; transition: opacity 0.3s ease;"
    ></iframe>

    <!-- Transparent overlay -->
    <div
        style="
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        cursor: pointer;
        background: transparent;
        z-index: 10;
      "
    ></div>
  </div>
</template>

<style scoped>
.nuhel {
  transition: opacity 0.3s ease;
}
</style>