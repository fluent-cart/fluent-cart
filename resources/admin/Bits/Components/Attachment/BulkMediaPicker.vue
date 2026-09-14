<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { VueDraggableNext as draggable } from 'vue-draggable-next';
import MediaButton from '@/Bits/Components/Buttons/MediaButton.vue';
import translate from '@/utils/translator/Translator';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Asset from "@/utils/support/Asset";
import { mergeGalleryItems, splitGalleryOrder, reconcileGalleryItems, insertGalleryImage, moveGalleryEntry, normalizeGalleryPosition } from '@/Modules/Products/galleryTabs';

// Fallback thumbnail for media whose image is missing or fails to load, so a
// broken url shows a placeholder instead of an endless loading spinner.
const defaultThumb = Asset.getUrl('images/placeholder.svg');
const onThumbError = (e) => {
  if (e.target && e.target.src !== defaultThumb) {
    e.target.src = defaultThumb;
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
  // Featured mode: shows first image prominently + "+N images" count tag for extras
  featured: { type: Boolean, default: false },
  // Square thumbnails in compact mode (product/variant images) instead of the
  // default circular avatar-stack.
  square: { type: Boolean, default: false },
  // Show "Apply to all variants" checkbox in modal footer (advanced variation context)
  showApplyToAll: { type: Boolean, default: false },
  // Initial checked state for the "Apply to all" checkbox (group row defaults to true)
  defaultApplyToAll: { type: Boolean, default: false },
  // Number of currently-selected variants. When > 0 the "Apply to all"
  // label shows the count, e.g. "Apply to all (3)". Display-only — it does
  // not change which variants the save targets.
  selectedCount: { type: Number, default: 0 },
  // An `extra-tabs` consumer still has work in flight (e.g. a video being
  // created): Save is disabled and the dialog refuses to close until it ends,
  // otherwise the result would land after the draft was committed and lost.
  busy: { type: Boolean, default: false },
  // Extra items an `extra-tabs` consumer wants in the full-mode preview grid:
  // [{ id, url, title, kind, position }] — `kind: 'video'` adds a play badge,
  // `position` is the number of images the item sits after (null = last).
  extraPreviews: { type: Array, default: () => [] },
  // The same shape, but built from the consumer's *draft*: these are shown in
  // the dialog's Gallery grid and can be dragged and removed next to the images.
  extraItems: { type: Array, default: () => [] },
  // The consumer is still resolving that first set. The grid holds still until
  // it lands: those items carry positions measured against the images as they
  // are now, so editing first would apply them to a list they never saw, and
  // the same edit would land them differently depending on network timing.
  extraItemsLoading: { type: Boolean, default: false },
});

// `open` / `save` let `extra-tabs` slot consumers seed and commit their own
// drafts; `extra-reorder` / `extra-remove` report what the Gallery grid did to
// their items.
const emit = defineEmits(['update:modelValue', 'change', 'open', 'save', 'extra-reorder', 'extra-remove']);

const showModal = ref(false);
const modalImages = ref([]);
const activeTab = ref('gallery');
const pasteUrl = ref('');
const applyToAll = ref(false);

const dialogTitle = computed(() => props.title || translate('Manage Media'));

// "Apply to all" label, with the selected-variant count appended when any
// variants are selected. Display-only — apply scope is unchanged.
const applyToAllLabel = computed(() => {
  if (props.selectedCount > 0) {
    /* translators: %1$s: number of selected variants */
    return translate('Apply to all (%1$s)', props.selectedCount);
  }
  return translate('Apply to all');
});

// Only pass real WP media attachments (id > 0) to MediaButton for pre-selection
const wpAttachments = computed(() => modalImages.value.filter(i => i.id > 0));

// URL-only images from CSV import (id === 0 or falsy)
const importedUrlImages = computed(() => modalImages.value.filter(i => !i.id));

const hasUrlTab = computed(() => props.showUrlTab);

// Compact mode only counts media that actually has an image url. A variant
// whose media has no usable url falls through to the add button — no image
// shows the add CTA, not a placeholder.
const mediaItems = computed(() => Array.isArray(props.modelValue) ? props.modelValue : []);

const compactMedia = computed(() => mediaItems.value.filter(m => m && m.url));

const PREVIEW_LIMIT = 4;

const asImageEntry = (image) => ({ kind: 'image', id: image.id, url: image.url, title: image.title || '', image });

// Full-mode preview: images and extra items (videos) in the order the
// storefront gallery shows them, capped with a "+N".
const previewItems = computed(() => mergeGalleryItems(
  mediaItems.value.map(m => ({ id: m.id, url: m.url, title: m.title || '', kind: 'image' })),
  (Array.isArray(props.extraPreviews) ? props.extraPreviews : [])
    .filter(p => p && p.url)
    .map(p => ({ id: p.id, url: p.url, title: p.title || '', kind: p.kind || 'image', position: normalizeGalleryPosition(p.position) })),
));

// The dialog's Gallery grid: images and the extra tabs' items in one draggable
// list. It owns the order while the dialog is open — `modalImages` and the
// consumers' positions are derived from it on every change.
const galleryList = ref([]);

const dialogExtras = computed(() => (Array.isArray(props.extraItems) ? props.extraItems : []).filter(item => item && item.url));

// Read-only while that first set is on its way; Cancel and Escape stay live,
// so the reader is never trapped by a request that does not come back.
const galleryLocked = computed(() => props.extraItemsLoading);

const buildGalleryList = (extras = dialogExtras.value) => {
  galleryList.value = mergeGalleryItems(modalImages.value.map(asImageEntry), extras.map(item => ({ ...item })));
};

// Push the grid's order back out: the images in their new order, and each
// extra item with the number of images now in front of it.
const syncFromGalleryList = () => {
  const { images, order } = splitGalleryOrder(galleryList.value);
  modalImages.value = images.map(entry => entry.image);
  emit('extra-reorder', order.map(item => ({ tab: item.tab, id: item.id, position: item.position })));
};

// The consumer resolves its items asynchronously, so what arrives right after
// the dialog opens is the authoritative list for this session. Until it does,
// the props still describe the previous session — a stale order, or another
// product's items entirely — so the grid opens on the images alone.
let extrasSeeded = false;

// After that, an item added or dropped in an extra tab (a video created there)
// joins or leaves the grid without disturbing the order of what is already in it.
watch(dialogExtras, (items) => {
  if (!showModal.value) return;
  if (!extrasSeeded) {
    extrasSeeded = true;
    buildGalleryList();
    return;
  }

  galleryList.value = reconcileGalleryItems(galleryList.value, items);
});

// Dragging is pointer-only, so every tile also carries move controls. What
// they did is announced here, because the tile itself moving is a change a
// screen reader has no reason to read out.
const liveMessage = ref('');

const entryLabel = (entry) => entry.title || (entry.kind === 'video' ? translate('Video') : translate('Image'));

// Focus follows the item it moved, dropping to the opposite control when the
// move lands on an end and disables the button that was just pressed.
const focusMoveControl = (index, offset) => {
  const scope = document.querySelector('.fct-bulk-media-picker-modal');
  if (!scope) return;
  const control = (direction) => scope.querySelector(`[data-fct-media-move="${direction}"][data-fct-media-move-index="${index}"]`);
  const wanted = control(offset < 0 ? 'earlier' : 'later');
  const target = wanted && !wanted.disabled ? wanted : control(offset < 0 ? 'later' : 'earlier');
  if (target) target.focus();
};

const moveEntry = (index, offset) => {
  if (galleryLocked.value) return;
  const entry = galleryList.value[index];
  const target = index + offset;
  if (!entry || !moveGalleryEntry(galleryList.value, index, target)) return;

  syncFromGalleryList();
  /* translators: %1$s: media title, %2$s: its new position, %3$s: number of items in the gallery */
  liveMessage.value = translate('%1$s moved to position %2$s of %3$s', entryLabel(entry), target + 1, galleryList.value.length);
  nextTick(() => focusMoveControl(target, offset));
};

// The product's featured image is the first *image* in the gallery, whatever
// else the grid holds: a video dragged to the front leads the storefront
// gallery but never becomes the featured image.
const featuredIndex = computed(() => galleryList.value.findIndex(entry => entry.kind === 'image'));

const openModal = () => {
  modalImages.value = JSON.parse(JSON.stringify(mediaItems.value));
  activeTab.value = 'gallery';
  pasteUrl.value = '';
  applyToAll.value = props.defaultApplyToAll;
  showModal.value = true;
  extrasSeeded = false;
  liveMessage.value = '';
  buildGalleryList([]);
  emit('open');
};

const saveAndClose = () => {
  if (props.busy || galleryLocked.value) return;
  // A no-op Save must not emit. The grouped media picker binds a derived
  // aggregate as model-value; re-emitting it unchanged would broadcast the
  // union of every variant's images back onto all of them.
  const changed = JSON.stringify(modalImages.value) !== JSON.stringify(mediaItems.value);
  if (changed || applyToAll.value) {
    emit('update:modelValue', modalImages.value);
    emit('change', modalImages.value, { applyToAll: applyToAll.value });
  }
  emit('save');
  showModal.value = false;
};

const cancelModal = () => {
  if (props.busy) return;
  showModal.value = false;
};

// Close icon / Escape go through el-dialog's before-close; the Cancel button through cancelModal.
const onBeforeClose = (done) => {
  if (props.busy) return;
  done();
};

// Removing an image also shifts the extra items that sat behind it, so the
// grid always reports its new order.
const removeGalleryEntry = (index) => {
  if (galleryLocked.value) return;
  const entry = galleryList.value[index];
  if (!entry) return;
  galleryList.value.splice(index, 1);
  if (entry.kind !== 'image') {
    emit('extra-remove', entry);
  }
  syncFromGalleryList();
};

const onMediaSelected = (selected) => {
  if (galleryLocked.value) return;
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
    buildGalleryList();
    return;
  }

  // Additive merge: append new, skip URL duplicates
  const existingUrls = new Set(modalImages.value.map(i => i.url));
  let added = false;
  for (const img of newImages) {
    if (!existingUrls.has(img.url)) {
      insertGalleryImage(galleryList.value, asImageEntry(img));
      existingUrls.add(img.url);
      added = true;
    }
  }
  // The grid moved: images and positions are read back off it, so what the
  // reader sees is what a Save persists.
  if (added) syncFromGalleryList();
};

const addFromUrl = () => {
  if (galleryLocked.value) return;
  const url = pasteUrl.value.trim();
  if (!url) return;
  if (modalImages.value.some(i => i.url === url)) {
    pasteUrl.value = '';
    return;
  }
  const filename = url.split('/').pop().split('?')[0] || 'image';
  const image = { id: 0, url, title: filename };
  if (!props.multiple) {
    // Single mode: a URL add replaces, mirroring onMediaSelected — otherwise
    // the paste-URL path lets the picker exceed one image.
    modalImages.value = [image];
    buildGalleryList();
  } else {
    insertGalleryImage(galleryList.value, asImageEntry(image));
    syncFromGalleryList();
  }
  pasteUrl.value = '';
};

const removeUrlImage = (url) => {
  const idx = galleryList.value.findIndex(entry => entry.kind === 'image' && entry.url === url);
  if (idx !== -1) removeGalleryEntry(idx);
};
</script>

<template>
  <div class="fct-media-picker" :class="{ 'is-compact': compact, 'is-featured': featured, 'is-square': square }" @click="openModal">
    <!-- Featured mode: single prominent image + count tag -->
    <template v-if="featured">
      <button type="button" v-if="modelValue && modelValue.length" class="fmp-featured" :aria-label="translate('Edit media')">
        <img
          :src="modelValue[0].url || defaultThumb"
          :alt="modelValue[0].title || ''"
          class="fmp-featured__image"
          @error="onThumbError"
        />
        <span class="fmp-featured__edit-icon">
          <DynamicIcon name="Edit" />
        </span>
        <el-tag v-if="modelValue.length > 1" class="fmp-featured__count" size="small" round>
          {{ modelValue.length }} {{ $t('images') }}
        </el-tag>
      </button>
      <el-button v-else class="fct-mp-add-featured">
        <DynamicIcon name="GalleryAdd"/>
      </el-button>
    </template>

    <!-- Compact inline: avatar stack (for table cells) -->
    <template v-else-if="compact">
      <div v-if="compactMedia.length" class="fmp-stack" :class="{ 'is-square': square }">
        <span
          v-for="(img, i) in compactMedia.slice(0, maxThumbs)"
          :key="i"
          class="fmp-thumb-wrap"
          :style="{ zIndex: maxThumbs - i }"
        >
          <img
            :src="img.url"
            :alt="img.title || ''"
            class="fmp-thumb"
            @error="onThumbError"
          />
        </span>
        <span v-if="compactMedia.length > maxThumbs" class="fmp-count">+{{ compactMedia.length - maxThumbs }}</span>
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
      <div v-if="previewItems.length" class="fmp-preview-grid">
        <div
          class="fmp-preview-item"
          :class="{ 'is-video': item.kind === 'video' }"
          v-for="(item, i) in previewItems.slice(0, PREVIEW_LIMIT)"
          :key="`${item.kind}-${item.id ?? i}`"
          :data-fct-media-kind="item.kind"
        >
          <img :src="item.url || defaultThumb" :alt="item.title || ''" @error="onThumbError" />
          <span v-if="item.kind === 'video'" class="fmp-preview-play" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
          </span>
          <span v-if="i === PREVIEW_LIMIT - 1 && previewItems.length > PREVIEW_LIMIT" class="fmp-preview-more">+{{ previewItems.length - PREVIEW_LIMIT }}</span>
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
    :close-on-press-escape="!busy"
    :before-close="onBeforeClose"
    @close="cancelModal"
    class="fct-bulk-media-picker-modal"
  >
    <div class="fmp-modal-body">
      <el-tabs v-model="activeTab">
        <el-tab-pane :label="$t('Gallery')" name="gallery">
          <p v-if="galleryLocked" class="fmp-grid-loading" role="status" data-fct-gallery-loading>
            {{ $t('Loading gallery items...') }}
          </p>

          <draggable
            v-if="galleryList.length"
            :list="galleryList"
            class="fmp-grid"
            :class="{ 'is-locked': galleryLocked }"
            tag="div"
            :animation="200"
            :disabled="galleryLocked"
            @end="syncFromGalleryList"
          >
            <div
              class="fmp-grid-item"
              :class="{ 'is-video': element.kind === 'video' }"
              v-for="(element, index) in galleryList"
              :key="`${element.kind}-${element.id ?? ''}-${element.url || index}`"
              :data-fct-media-kind="element.kind"
            >
              <img :src="element.url || defaultThumb" :alt="element.title || ''" @error="onThumbError" />
              <span v-if="element.kind === 'video'" class="fmp-grid-play" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
              </span>
              <el-tag v-if="index === featuredIndex" type="primary" size="small" class="fmp-featured-tag">
                {{ $t('Featured') }}
              </el-tag>
              <el-tag v-else-if="index === 0" type="info" size="small" class="fmp-featured-tag">
                {{ $t('Plays first') }}
              </el-tag>
              <div v-if="galleryList.length > 1" class="fmp-grid-move">
                <button
                  type="button"
                  class="fmp-move-btn"
                  data-fct-media-move="earlier"
                  :data-fct-media-move-index="index"
                  :disabled="galleryLocked || index === 0"
                  :aria-label="translate('Move %1$s earlier', entryLabel(element))"
                  @click.stop="moveEntry(index, -1)"
                >
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <button
                  type="button"
                  class="fmp-move-btn"
                  data-fct-media-move="later"
                  :data-fct-media-move-index="index"
                  :disabled="galleryLocked || index === galleryList.length - 1"
                  :aria-label="translate('Move %1$s later', entryLabel(element))"
                  @click.stop="moveEntry(index, 1)"
                >
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
              </div>
              <button
                type="button"
                class="fmp-remove-btn"
                :disabled="galleryLocked"
                :aria-label="translate('Remove %1$s', entryLabel(element))"
                :title="$t('Remove')"
                @click.stop="removeGalleryEntry(index)"
              >
                &times;
              </button>
            </div>
          </draggable>

          <div v-else class="fmp-empty">
            <p>{{ $t('No media added yet.') }}</p>
          </div>

          <span class="fmp-sr-only" role="status" aria-live="polite" data-fct-gallery-live>{{ liveMessage }}</span>
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
                :disabled="galleryLocked"
                @keyup.enter="addFromUrl"
              />
              <el-button size="small" @click="addFromUrl" :disabled="galleryLocked || !pasteUrl.trim()">{{ $t('Add') }}</el-button>
            </div>
          </div>
        </el-tab-pane>

        <slot name="extra-tabs" :active-tab="activeTab"></slot>
      </el-tabs>
    </div>

    <div class="dialog-footer">
      <div class="fmp-add-media" :class="{ 'is-disabled': galleryLocked }" :aria-disabled="galleryLocked ? 'true' : null">
        <MediaButton
            :attachments="wpAttachments"
            :multiple="multiple"
            @on-media-selected="onMediaSelected"
        />
      </div>

      <div class="fct-media-picker-footer-actions">
        <label v-if="showApplyToAll" class="fct-media-picker-apply-to-group-label" :class="{ 'is-enabled': applyToAll }">
          <el-checkbox
              v-model="applyToAll"
              :aria-label="$t('Apply media to all variants in this group')"
          />
          {{ applyToAllLabel }}
        </label>
        <div class="fct-btn-group sm">
          <el-button :disabled="busy" @click="cancelModal">{{ $t('Cancel') }}</el-button>
          <el-button type="primary" :disabled="busy || galleryLocked" @click="saveAndClose">{{ $t('Save') }}</el-button>
        </div>
      </div>
    </div>
  </el-dialog>
</template>



