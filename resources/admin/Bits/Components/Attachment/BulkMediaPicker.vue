<script setup>
import { ref, computed } from 'vue';
import { VueDraggableNext as draggable } from 'vue-draggable-next';
import MediaButton from '@/Bits/Components/Buttons/MediaButton.vue';
import translate from '@/utils/translator/Translator';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

// Track which image URLs have failed to load — hides broken icon, shows spinner instead
const failedUrls = ref(new Set());
const onThumbError = (url) => {
  failedUrls.value = new Set([...failedUrls.value, url]);
};
const onThumbLoad = (url) => {
  if (failedUrls.value.has(url)) {
    const s = new Set(failedUrls.value);
    s.delete(url);
    failedUrls.value = s;
  }
};

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  // Show compact avatar-stack inline (for table cells). When false, shows a larger trigger.
  compact: { type: Boolean, default: true },
  // Max thumbnails to show in compact mode
  maxThumbs: { type: Number, default: 3 },
  // Allow multiple image selection
  multiple: { type: Boolean, default: true },
  // Show "Add by URL" tab for pasting external image URLs
  showUrlTab: { type: Boolean, default: true },
  // Dialog title
  title: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'change']);

const showModal = ref(false);
const modalImages = ref([]);
const activeTab = ref('gallery');
const pasteUrl = ref('');

const dialogTitle = computed(() => props.title || translate('Manage Media'));

// Only pass real WP media attachments (id > 0) to MediaButton for pre-selection
const wpAttachments = computed(() => modalImages.value.filter(i => i.id > 0));

// URL-only images from CSV import (id === 0 or falsy)
const importedUrlImages = computed(() => modalImages.value.filter(i => !i.id));

const hasUrlTab = computed(() => props.showUrlTab);

const openModal = () => {
  modalImages.value = JSON.parse(JSON.stringify(props.modelValue || []));
  activeTab.value = 'gallery';
  pasteUrl.value = '';
  showModal.value = true;
};

const saveAndClose = () => {
  emit('update:modelValue', modalImages.value);
  emit('change', modalImages.value);
  showModal.value = false;
};

const cancelModal = () => {
  showModal.value = false;
};

const removeImage = (index) => {
  modalImages.value.splice(index, 1);
};

const onMediaSelected = (selected) => {
  const newImages = selected
    .filter(img => img.url) // skip ghost attachments (e.g. id=0 resolved by WP)
    .map(img => ({
      id: img.id,
      title: img.title,
      url: img.url,
    }));

  if (!props.multiple) {
    // Single mode: replace all
    modalImages.value = newImages.slice(0, 1);
    return;
  }

  // Additive merge: append new, skip URL duplicates
  const existingUrls = new Set(modalImages.value.map(i => i.url));
  for (const img of newImages) {
    if (!existingUrls.has(img.url)) {
      modalImages.value.push(img);
      existingUrls.add(img.url);
    }
  }
};

const addFromUrl = () => {
  const url = pasteUrl.value.trim();
  if (!url) return;
  if (modalImages.value.some(i => i.url === url)) {
    pasteUrl.value = '';
    return;
  }
  const filename = url.split('/').pop().split('?')[0] || 'image';
  modalImages.value.push({ id: 0, url, title: filename });
  pasteUrl.value = '';
};

const removeUrlImage = (url) => {
  const idx = modalImages.value.findIndex(i => i.url === url);
  if (idx !== -1) modalImages.value.splice(idx, 1);
};
</script>

<template>
  <div class="fct-media-picker" :class="{ 'is-compact': compact }" @click="openModal">
    <!-- Compact inline: avatar stack (for table cells) -->
    <template v-if="compact">
      <div v-if="modelValue && modelValue.length" class="fmp-stack">
        <span
          v-for="(img, i) in modelValue.slice(0, maxThumbs)"
          :key="i"
          class="fmp-thumb-wrap"
          :style="{ zIndex: maxThumbs - i }"
        >
          <img
            :src="img.url"
            :alt="img.title || ''"
            class="fmp-thumb"
            :class="{ 'is-hidden': failedUrls.has(img.url) }"
            @error="onThumbError(img.url)"
            @load="onThumbLoad(img.url)"
          />
        </span>
        <span v-if="modelValue.length > maxThumbs" class="fmp-count">+{{ modelValue.length - maxThumbs }}</span>
        <span class="fmp-edit-icon">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" width="14" height="14">
            <path d="M11.7282 3.23787C12.3492 2.56506 12.6597 2.22865 12.9896 2.03243C13.7857 1.55896 14.766 1.54423 15.5754 1.99359C15.9108 2.17982 16.2309 2.50676 16.8709 3.16062C17.511 3.81449 17.8311 4.14143 18.0134 4.48409C18.4533 5.31092 18.4388 6.31232 17.9754 7.12558C17.7833 7.46262 17.454 7.7798 16.7953 8.41416L8.95894 15.9619C7.71081 17.1641 7.08675 17.7651 6.3068 18.0698C5.52685 18.3744 4.66942 18.352 2.95455 18.3071L2.72123 18.301C2.19917 18.2874 1.93814 18.2806 1.7864 18.1084C1.63467 17.9362 1.65538 17.6703 1.69681 17.1385L1.71931 16.8497C1.83592 15.3529 1.89423 14.6046 2.1865 13.9318C2.47878 13.2591 2.98294 12.7129 3.99127 11.6204L11.7282 3.23787Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M10.8333 3.33334L16.6666 9.16668" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            <path d="M11.6667 18.3333L18.3334 18.3333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </span>
      </div>

      <el-button v-else class="fct-mp-add-compact">
        <DynamicIcon name="GalleryAdd"/>
      </el-button>
    </template>

    <!-- Full inline: thumbnail grid preview (for product pages, etc.) -->
    <template v-else>
      <div v-if="modelValue && modelValue.length" class="fmp-preview-grid">
        <div class="fmp-preview-item" v-for="(img, i) in modelValue.slice(0, 4)" :key="i">
          <img :src="img.url" :alt="img.title || ''" />
          <span v-if="i === 3 && modelValue.length > 4" class="fmp-preview-more">+{{ modelValue.length - 4 }}</span>
        </div>
      </div>

      <button v-else class="fmp-add-full" type="button">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 25 24" fill="none" width="20" height="20">
          <path d="M9.5 10C10.6046 10 11.5 9.10457 11.5 8C11.5 6.89543 10.6046 6 9.5 6C8.39543 6 7.5 6.89543 7.5 8C7.5 9.10457 8.39543 10 9.5 10Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M13.5 2H9.5C4.5 2 2.5 4 2.5 9V15C2.5 20 4.5 22 9.5 22H15.5C20.5 22 22.5 20 22.5 15V10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M16.25 5H21.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          <path d="M19 7.75V2.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          <path d="M3.16992 18.9501L8.09992 15.6401C8.88992 15.1101 10.0299 15.1701 10.7399 15.7801L11.0699 16.0701C11.8499 16.7401 13.1099 16.7401 13.8899 16.0701L18.0499 12.5001C18.8299 11.8301 20.0899 11.8301 20.8699 12.5001L22.4999 13.9001" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span>{{ $t('Add Media') }}</span>
      </button>
    </template>
  </div>

  <!-- Modal: full gallery management -->
  <el-dialog
    v-if="showModal"
    v-model="showModal"
    :title="dialogTitle"
    width="600px"
    :append-to-body="true"
    :close-on-click-modal="false"
    @close="cancelModal"
    class="fct-bulk-media-picker-modal"
  >
    <div class="fmp-modal-body">
      <el-tabs v-model="activeTab">
        <el-tab-pane :label="$t('Gallery')" name="gallery">
          <draggable
            v-if="modalImages.length"
            :list="modalImages"
            class="fmp-grid"
            tag="div"
            :animation="200"
          >
            <div class="fmp-grid-item" v-for="(element, index) in modalImages" :key="element.url || index">
              <img :src="element.url" :alt="element.title || ''" />
              <el-tag v-if="index === 0" type="primary" size="small" class="fmp-featured-tag">
                {{ $t('Featured') }}
              </el-tag>
              <button type="button" class="fmp-remove-btn" @click.stop="removeImage(index)" :title="$t('Remove')">
                &times;
              </button>
            </div>
          </draggable>

          <div v-else class="fmp-empty">
            <p>{{ $t('No media added yet.') }}</p>
          </div>
        </el-tab-pane>

        <el-tab-pane v-if="hasUrlTab" name="imported-urls">
          <template #label>
            {{ $t('Add by URL') }}
            <el-tag v-if="importedUrlImages.length" size="small" round style="margin-left: 6px;">{{ importedUrlImages.length }}</el-tag>
          </template>

          <div class="fmp-url-list">
            <div class="fmp-url-item" v-for="img in importedUrlImages" :key="img.url">
              <img :src="img.url" :alt="img.title || ''" class="fmp-url-thumb" />
              <div class="fmp-url-info">
                <span class="fmp-url-title">{{ img.title }}</span>
                <span class="fmp-url-text">{{ img.url }}</span>
              </div>
              <button type="button" class="fmp-url-delete" @click.stop="removeUrlImage(img.url)" :title="$t('Remove')">
                &times;
              </button>
            </div>

            <div class="fmp-url-add">
              <el-input
                v-model="pasteUrl"
                :placeholder="$t('Paste image URL...')"
                size="small"
                @keyup.enter="addFromUrl"
              />
              <el-button size="small" @click="addFromUrl" :disabled="!pasteUrl.trim()">{{ $t('Add') }}</el-button>
            </div>
          </div>
        </el-tab-pane>
      </el-tabs>
    </div>

    <div class="dialog-footer">
      <MediaButton
          :attachments="wpAttachments"
          :multiple="multiple"
          @onMediaSelected="onMediaSelected"
      />

      <div class="fct-btn-group sm">
        <el-button @click="cancelModal">{{ $t('Cancel') }}</el-button>
        <el-button type="primary" @click="saveAndClose">{{ $t('Save') }}</el-button>
      </div>
    </div>
  </el-dialog>
</template>

