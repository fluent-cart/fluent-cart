<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import {ElMessageBox} from 'element-plus';
import Rest from '@/utils/http/Rest';
import { restStatus, restErrorMessage } from '@/utils/http/restError';
import Notify from '@/utils/Notify';
import translate from '@/utils/translator/Translator';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import classifyVideoSource, { providerLabel, deriveVideoTitle } from '@/utils/FluentPlayer/classifyVideoSource';
import { MAX_VIDEOS, normalizeVideoDraft, removeFluentPlayerMedia } from '@/Modules/Products/FluentPlayer/fluentPlayerGalleryTab';
import { loadMediaSummaries, normalizeMedia as normalizeMediaSummary } from '@/Modules/Products/FluentPlayer/fluentPlayerMedia';

const props = defineProps({
  modelValue: { type: Object, default: () => ({ media_ids: [] }) },
  config: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue', 'busy']);

const restUrl = computed(() => props.config?.restUrl || '');
const isActive = computed(() => !!props.config?.active);
const isInstalled = computed(() => !!props.config?.installed);
// Installing plugins is a WordPress capability, not a FluentCart permission —
// a store manager may reach this tab without it.
const canInstall = computed(() => !!props.config?.canInstall);
const canAuthor = computed(() => !!props.config?.canAuthor);

const installing = ref(false);
// FluentPlayer only registers its REST routes and this tab's config on the next
// page load, so the admin reloads when they are ready rather than losing the
// product edits the gallery dialog is holding.
const activated = ref(false);
// A reload drops whatever the product editor and this dialog are still holding,
// so it is never taken on the button press alone.
const reloadPage = () => ElMessageBox.confirm(
  translate('Save the product first if you have unsaved changes — reloading discards them.'),
  translate('Reload this page?'),
  {
    confirmButtonText: translate('Reload Page'),
    cancelButtonText: translate('Cancel'),
    type: 'warning',
  }
).then(() => {
  window.location.reload();
}).catch(() => {});
const installFluentPlayer = () => {
  installing.value = true;
  Rest.post('integration/feed/install-plugin', { addon: 'fluent-player' })
    .then((response) => {
      const message = isInstalled.value
        ? translate('FluentPlayer activated successfully.')
        : translate('FluentPlayer installed successfully.');
      Notify.success(response?.message || message);
      activated.value = true;
    })
    .catch((error) => {
      const message = isInstalled.value
        ? translate('Could not activate FluentPlayer.')
        : translate('Could not install FluentPlayer.');
      Notify.error(restErrorMessage(error, message));
    })
    .finally(() => {
      installing.value = false;
    });
};
const draft = computed(() => normalizeVideoDraft(props.modelValue));
const mediaIds = computed(() => draft.value.media_ids);

// A video added here has no place in the gallery order yet (null position), so
// it joins the Gallery grid after the images, where it can be dragged.
const addMediaId = (id) => {
  const mediaId = Number(id) || 0;
  if (!mediaId) return;
  if (mediaIds.value.includes(mediaId)) {
    Notify.info(translate('That video is already attached to this product.'));
    return;
  }
  if (mediaIds.value.length >= MAX_VIDEOS) {
    Notify.info(translate('A product can have at most %1$s videos.', MAX_VIDEOS));
    return;
  }
  emit('update:modelValue', {
    media_ids: [...draft.value.media_ids, mediaId],
    positions: [...draft.value.positions, null],
  });
};

const removeMediaId = (id) => {
  emit('update:modelValue', removeFluentPlayerMedia(draft.value, id));
};

const normalizeMedia = (media) => normalizeMediaSummary(media, translate('Untitled video'));

const missingSummary = (id) => ({ id, title: translate('Video #%1$s (unavailable)', id), poster: '', provider: '', missing: true });

const summaries = ref({});

// One search request for every id not yet known; media FluentPlayer no longer
// returns (deleted) is tagged "Not found" without an error toast.
const loadSummaries = (ids) => {
  const pending = ids.filter(id => !summaries.value[id]);
  if (!pending.length || !restUrl.value) return;

  const loading = {};
  pending.forEach((id) => { loading[id] = { id, title: translate('Loading...'), poster: '', provider: '', loading: true }; });
  summaries.value = { ...summaries.value, ...loading };

  loadMediaSummaries(restUrl.value, pending, translate('Untitled video'))
    .then((found) => {
      const next = { ...summaries.value };
      pending.forEach((id) => { next[id] = found[id] && !found[id].missing ? found[id] : missingSummary(id); });
      summaries.value = next;
    })
    .catch((error) => {
      const next = { ...summaries.value };
      pending.forEach((id) => { next[id] = missingSummary(id); });
      summaries.value = next;
      Notify.error(restErrorMessage(error, translate('Could not load the FluentPlayer videos.')));
    });
};

watch(mediaIds, loadSummaries, { immediate: true });

const attachedVideos = computed(() => mediaIds.value.map(id => summaries.value[id] || { id, title: translate('Video #%1$s', id), poster: '', provider: '' }));

const editUrlFor = (id) => (props.config?.editUrlBase ? props.config.editUrlBase + id : '');

const searchOptions = ref([]);
const searching = ref(false);
const selectedExisting = ref(null);
let searchTimer = null;

const searchMedia = (query) => {
  if (!restUrl.value) return;
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    searching.value = true;
    Rest.get('media/search', { q: query || '', limit: 20, status: 'publish,private' }, restUrl.value)
      .then((response) => {
        const list = Array.isArray(response) ? response : (response?.data || response?.medias || []);
        searchOptions.value = list.map(normalizeMedia).filter(item => item.id > 0);
      })
      .catch((error) => {
        Notify.error(restErrorMessage(error, translate('Could not search FluentPlayer videos.')));
      })
      .finally(() => {
        searching.value = false;
      });
  }, 250);
};

const availableOptions = computed(() => searchOptions.value.filter(item => !mediaIds.value.includes(item.id)));

const useExisting = () => {
  if (!selectedExisting.value) return;
  addMediaId(selectedExisting.value);
  selectedExisting.value = null;
};

const emptyForm = () => ({
  src: '',
  attachment_id: 0,
  attachmentTitle: '',
  provider: '',
  viewType: 'video',
  metaTitle: '',
  metaPoster: '',
  metaProvider: '',
  metaViewType: '',
  metaFailed: false,
});

const KNOWN_PROVIDERS = ['youtube', 'vimeo', 'external', 'wordpress'];
const providerFromMetadata = (meta) => {
  const slug = String(meta?.provider || meta?.provider_name || '').toLowerCase();
  return KNOWN_PROVIDERS.includes(slug) ? slug : '';
};
const form = ref(emptyForm());
const creating = ref(false);

// The new media id is attached when POST media resolves; the dialog must not
// save or close before then (see galleryTabs.js).
watch(creating, (busy) => emit('busy', busy));
const fetchingMetadata = ref(false);
let metadataTimer = null;
let metadataRequestId = 0;
let metadataPromise = null;

// Only the response for the source still in the input may fill the form:
// the admin can replace the URL while an earlier lookup is in flight.
const fetchSourceMetadata = (url) => {
  const source = classifyVideoSource(url);
  if (!source || !restUrl.value) return;

  const requested = url.trim();
  const requestId = ++metadataRequestId;
  const isCurrent = () => requestId === metadataRequestId && form.value.src.trim() === requested;
  fetchingMetadata.value = true;
  metadataPromise = Rest.get('media/metadata', { url: requested }, restUrl.value)
    .then((response) => {
      if (!isCurrent()) return;
      const meta = response?.metaData || response?.data?.metaData || null;
      form.value.metaTitle = meta?.title || '';
      form.value.metaPoster = meta?.thumbnail_url || '';
      form.value.metaProvider = providerFromMetadata(meta);
      form.value.metaViewType = ['video', 'audio'].includes(meta?.media_type) ? meta.media_type : '';
    })
    .catch(() => {
      if (isCurrent()) form.value.metaFailed = true;
    })
    .finally(() => {
      if (requestId === metadataRequestId) {
        fetchingMetadata.value = false;
        metadataPromise = null;
      }
    });
};

watch(() => form.value.src, (url) => {
  clearTimeout(metadataTimer);
  form.value.metaTitle = '';
  form.value.metaPoster = '';
  form.value.metaProvider = '';
  form.value.metaViewType = '';
  form.value.metaFailed = false;
  if (form.value.provider === 'wordpress') return;
  if (!classifyVideoSource(url)) return;
  metadataTimer = setTimeout(() => fetchSourceMetadata(url), 400);
});

const detected = computed(() => {
  if (form.value.provider === 'wordpress') {
    return { provider: 'wordpress', viewType: form.value.viewType };
  }
  return classifyVideoSource(form.value.src);
});

const detectedLabel = computed(() => detected.value ? providerLabel(detected.value.provider) : '');

const canCreate = computed(() => !!detected.value && !creating.value);

const onSourceInput = () => {
  if (form.value.provider === 'wordpress') {
    form.value.provider = '';
    form.value.attachment_id = 0;
    form.value.attachmentTitle = '';
  }
};

let libraryFrame = null;

const chooseFromLibrary = () => {
  if (typeof window.wp?.media !== 'function') {
    Notify.error(translate('The WordPress media library is not available on this screen.'));
    return;
  }
  if (!libraryFrame) {
    libraryFrame = window.wp.media({
      title: translate('Select or upload a video'),
      button: { text: translate('Use this video') },
      library: { type: 'video' },
      multiple: false,
    });
    // WP opens a fresh frame on the upload pane; land on the library grid instead.
    libraryFrame.on('open', () => {
      try {
        libraryFrame.content.mode('browse');
      } catch (e) {}
    });
    libraryFrame.on('select', () => {
      const attachment = libraryFrame.state().get('selection').first()?.toJSON();
      if (!attachment?.url) return;
      form.value.src = attachment.url;
      form.value.attachment_id = Number(attachment.id) || 0;
      form.value.attachmentTitle = attachment.title || '';
      form.value.provider = 'wordpress';
      form.value.viewType = 'video';
    });
  }
  libraryFrame.open();
};

onBeforeUnmount(() => {
  clearTimeout(searchTimer);
  clearTimeout(metadataTimer);
  if (creating.value) emit('busy', false);
  // The dialog re-creates this component on every open; wp.media frames stay in <body> until detached.
  if (libraryFrame && typeof libraryFrame.detach === 'function') {
    libraryFrame.detach();
  }
  libraryFrame = null;
});

const postNewVideo = () => {
  const source = detected.value;
  if (!source) {
    creating.value = false;
    return;
  }

  // FluentPlayer's own classification (media/metadata) beats the local guess.
  const provider = source.provider === 'wordpress' ? 'wordpress' : (form.value.metaProvider || source.provider);
  const viewType = source.provider === 'wordpress' ? source.viewType : (form.value.metaViewType || source.viewType);
  const settings = {
    title: deriveVideoTitle({ metaTitle: form.value.metaTitle, attachmentTitle: form.value.attachmentTitle, url: form.value.src }, translate('Untitled Media')),
    post_status: 'publish',
    src: form.value.src.trim(),
    provider,
    viewType,
    posterSrc: form.value.metaPoster || '',
    preset_slug: props.config?.defaultPresetSlug || 'course',
    playsInline: true,
    mutedAutoplay: false,
    autoplay: false,
    loadStrategy: 'visible',
    preload: 'metadata',
    aspectRatio: 'default',
  };
  if (provider === 'wordpress' && form.value.attachment_id) {
    settings.attachment_id = form.value.attachment_id;
  }

  Rest.post('media', { settings, tags: [] }, restUrl.value)
    .then((response) => {
      const media = response?.media || response?.data?.media || null;
      const id = Number(media?.ID || media?.id) || 0;
      if (!id) {
        Notify.error(translate('FluentPlayer did not return the new video id.'));
        return;
      }
      Notify.success(response?.message || translate('Video created in FluentPlayer.'));
      form.value = emptyForm();
      addMediaId(id);
    })
    .catch((error) => {
      const status = restStatus(error);
      if (status === 403 || status === 401) {
        Notify.error(translate('Your account is not allowed to create FluentPlayer videos.'));
        return;
      }
      Notify.error(restErrorMessage(error, translate('Could not create the video in FluentPlayer.')));
    })
    .finally(() => {
      creating.value = false;
    });
};

const createVideo = () => {
  if (!canCreate.value || !restUrl.value) return;
  creating.value = true;
  if (metadataPromise) {
    metadataPromise.finally(postNewVideo);
  } else {
    postNewVideo();
  }
};
</script>

<template>
  <div class="fct-fp-video" data-fct-fp-video-tab>
    <div v-if="!isActive" class="fct-fp-video__block" data-fct-fp-video-inactive>
      <p class="fct-fp-video__notice">
        {{ activated
          ? translate('FluentPlayer is ready. Save any changes to this product, then reload the page to add videos.')
          : translate('FluentPlayer is required to add videos to this product.') }}
      </p>
      <p v-if="!activated && !canInstall" class="fct-fp-video__notice" data-fct-fp-video-no-install-permission>
        {{ isInstalled
          ? translate('Ask an administrator to activate it for you.')
          : translate('Ask an administrator to install it for you.') }}
      </p>
      <el-button
        v-else-if="activated"
        type="primary"
        data-fct-fp-video-reload
        @click="reloadPage"
      >
        {{ translate('Reload Page') }}
      </el-button>
      <el-button
        v-else
        type="primary"
        :loading="installing"
        :disabled="installing"
        data-fct-fp-video-install
        @click="installFluentPlayer"
      >
        {{ isInstalled ? translate('Activate FluentPlayer') : translate('Install & Activate FluentPlayer') }}
      </el-button>
    </div>

    <template v-else>
    <p v-if="!canAuthor" class="fct-fp-video__notice" data-fct-fp-video-no-permission>
      {{ translate('Your account cannot manage FluentPlayer videos. Ask an administrator to grant the FluentPlayer authoring capability.') }}
    </p>

    <template v-else>
      <div class="fct-fp-video__section">
        <div class="fct-fp-video__block" data-fct-fp-video-create>
          <label class="fct-fp-video__section-title" for="fct-fp-video-src">{{ attachedVideos.length ? translate('Add another video') : translate('Add a video') }}</label>
          <div class="fct-fp-video__source">
            <el-input
              id="fct-fp-video-src"
              v-model="form.src"
              :placeholder="translate('Paste a YouTube, Vimeo or direct video URL')"
              @input="onSourceInput"
            />
            <el-tooltip :content="translate('Choose from the Media Library')" placement="top">
              <el-button class="fct-fp-video__icon-only" data-fct-fp-video-library :aria-label="translate('Choose from the Media Library')" @click="chooseFromLibrary">
                <DynamicIcon name="VideoAdd" />
              </el-button>
            </el-tooltip>
            <el-button type="primary" :loading="creating" :disabled="!canCreate" data-fct-fp-video-create-btn @click="createVideo">
              {{ translate('Add Video') }}
            </el-button>
          </div>
          <span v-if="fetchingMetadata" class="fct-fp-video__hint">{{ translate('Reading the video details...') }}</span>
          <span v-else-if="detectedLabel" class="fct-fp-video__hint" data-fct-fp-video-detected>
            {{ translate('Detected: %1$s', detectedLabel) }}
            <template v-if="form.metaTitle"> — {{ form.metaTitle }}</template>
            <template v-else-if="form.metaFailed"> — {{ translate('details could not be read; the file name will be used as the title') }}</template>
          </span>
          <span v-else-if="form.src" class="fct-fp-video__hint">{{ translate('Enter a full URL starting with http:// or https://') }}</span>
          <span v-else class="fct-fp-video__hint">{{ translate('Title and poster are sourced from YouTube, Vimeo, audio or HLS file or direct video') }}</span>
        </div>

        <div class="fct-fp-video__divider" role="separator">
          <span>{{ translate('OR') }}</span>
        </div>

        <div class="fct-fp-video__block" data-fct-fp-video-existing>
          <span class="fct-fp-video__section-title">{{ translate('Pick an existing FluentPlayer video') }}</span>
          <div class="fct-fp-video__source">
            <el-select
              v-model="selectedExisting"
              filterable
              remote
              clearable
              :remote-method="searchMedia"
              :loading="searching"
              :placeholder="translate('Search videos by title or ID...')"
              class="fct-fp-video__select"
              @visible-change="visible => visible && !searchOptions.length && searchMedia('')"
            >
              <el-option v-for="item in availableOptions" :key="item.id" :label="`#${item.id} — ${item.title}`" :value="item.id" />
            </el-select>
            <el-button type="primary" :disabled="!selectedExisting" data-fct-fp-video-use-existing @click="useExisting">
              {{ translate('Use Video') }}
            </el-button>
          </div>
        </div>
      </div>

      <div v-if="attachedVideos.length" class="fct-fp-video__list" data-fct-fp-video-list>
        <h4 class="fct-fp-video__section-title">
            {{ translate('Videos') }}
        </h4>

        <div 
            v-for="video in attachedVideos" 
            :key="video.id" 
            class="fct-fp-video__card" 
            data-fct-fp-video-selected 
            :data-fct-fp-video-id="video.id" 
            :class="{ 'is-loading': video.loading }" 
            :aria-busy="video.loading ? 'true' : null" :aria-label="video.loading ? translate('Loading...') : null"
        >
          <div class="fct-fp-video__card-thumb">
            <el-skeleton v-if="video.loading" animated>
              <template #template>
                <el-skeleton-item variant="image" class="fct-fp-video__card-thumb-skeleton" />
              </template>
            </el-skeleton>
            <template v-else>
              <img v-if="video.poster" :src="video.poster" :alt="video.title || ''" />
              <div class="fct-fp-video__card-play" aria-hidden="true">
                <DynamicIcon name="PlayCircle" />
              </div>
            </template>
          </div>
          <div class="fct-fp-video__card-body">
            <el-skeleton v-if="video.loading" animated :rows="0" data-fct-fp-video-loading>
              <template #template>
                <div class="fct-fp-video__card-lines">
                  <el-skeleton-item variant="h3" class="fct-fp-video__card-title-skeleton" />
                  <el-skeleton-item variant="text" class="fct-fp-video__card-meta-skeleton" />
                </div>
              </template>
            </el-skeleton>
            <template v-else>
              <span class="fct-fp-video__card-title" data-fct-fp-video-title>{{ video.title }}</span>
              <span class="fct-fp-video__card-meta">
                <span class="fct-fp-video__id">#{{ video.id }}</span>
                <span 
                    v-if="video.provider"
                    class="fct-fp-video__provider"
                >
                    {{ providerLabel(video.provider) }}
                </span>
                <el-tag 
                    v-if="video.missing" 
                    type="danger" 
                    size="small" 
                    data-fct-fp-video-missing
                >
                    {{ translate('Not found') }}
                </el-tag>
                <el-tag 
                    v-else-if="video.trashed" 
                    type="warning" 
                    size="small" 
                    data-fct-fp-video-trashed
                >
                    {{ translate('In trash') }}
                </el-tag>
              </span>
            </template>
          </div>

          <div class="fct-fp-video__card-actions">
            <el-tooltip v-if="editUrlFor(video.id)" :content="translate('Edit in FluentPlayer')" placement="top">
              <a
                :href="editUrlFor(video.id)"
                target="_blank"
                rel="noopener"
                class="fct-fp-video__icon-btn"
                data-fct-fp-video-edit
                :aria-label="translate('Edit in FluentPlayer')"
              >
                <DynamicIcon name="Edit" />
              </a>
            </el-tooltip>
            <el-tooltip :content="translate('Remove video')" placement="top">
              <button
                type="button"
                class="fct-fp-video__icon-btn fct-fp-video__icon-btn--danger"
                data-fct-fp-video-remove
                :aria-label="translate('Remove video')"
                @click="removeMediaId(video.id)"
              >
                <DynamicIcon name="Delete" />
              </button>
            </el-tooltip>
          </div>
        </div>
      </div>
    </template>
    </template>
  </div>
</template>
