<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import Gallery from '@/Bits/Components/Attachment/Gallery.vue';
import MediaButton from '@/Bits/Components/Buttons/MediaButton.vue';
import {computed} from 'vue';

const props = defineProps({
  product: Object,
  productEditModel: Object,
})

const normalizeFeaturedVideo = (video) => {
  const normalized = video && typeof video === 'object' ? {...video} : {};

  normalized.url = (normalized.url ?? '').trim();
  normalized.title = normalized.title ?? '';

  if (!normalized.url) {
    return {url: ''};
  }

  if (!normalized.type) {
    normalized.type = detectVideoType(normalized.url);
  }

  return normalized;
};

const updateFeaturedVideo = (video) => {
  const formattedVideo = normalizeFeaturedVideo(video);

  props.product.featured_video = formattedVideo;
  props.productEditModel.updateMedia('featured_video', formattedVideo);
}

const onVideoSelected = (selected) => {
  if (!Array.isArray(selected) || !selected.length) {
    updateFeaturedVideo({});
    return;
  }

  const file = selected[0];
  updateFeaturedVideo({
    id: file.id ?? '',
    title: file.title ?? '',
    url: file.url ?? '',
    type: 'file'
  });
}

const detectVideoType = (url) => {
  if (!url) {
    return 'url';
  }

  return /\.(mp4|mov|webm|ogg)(\?.*)?$/i.test(url) ? 'file' : 'url';
}

const updateVideoUrl = (value) => {
  const url = value ? value.trim() : '';

  const currentVideo = props.product.featured_video || {};

  updateFeaturedVideo({
    ...currentVideo,
    url,
    type: url ? detectVideoType(url) : ''
  });
}

const clearVideo = () => {
  updateFeaturedVideo({});
}

const videoUrl = computed(() => props.product?.featured_video?.url ?? '');
const isFileSource = computed(() => {
  if (!videoUrl.value) {
    return false;
  }

  const type = props.product?.featured_video?.type;
  if (type === 'file') {
    return true;
  }

  return /\.(mp4|mov|webm|ogg)(\?.*)?$/i.test(videoUrl.value);
});

const videoAttachments = computed(() => {
  const video = props.product?.featured_video;
  if (video?.id && video.url) {
    return [{
      id: video.id,
      title: video.title ?? '',
      url: video.url
    }];
  }

  return [];
});
</script>

<template>
  <div class="fct-product-media-wrap">
    <Card.Container>
      <Card.Header :title="$t('Media')" border_bottom title_size="small"></Card.Header>
      <Card.Body>
        <div class="fct-admin-summary-item">
          <Gallery
            :attachments="product.gallery"
            :featured_image_id ="product.featured_image_id"
            @mediaUploaded="value => {
              product.gallery = value
              productEditModel.updateMedia('gallery',value);
              // if (productEditModel.hasChange){
              //     productEditModel.hideAdminProductMenuItems(true);
              // }
            }"
            @removeImage="value => {
              product.gallery.splice(value, 1)
              product.gallery = [...product.gallery];
              productEditModel.updateMedia('gallery',product.gallery);
              // productEditModel.setHasChange(true);
            }"
            @onMediaChange="value => {
              productEditModel.updateMedia('gallery',value);
            }"
          />
        </div>

        <div class="fct-admin-summary-item mt-4">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="fct-admin-summary-item-title">{{ $t('Featured Video') }}</p>
              <p class="fct-admin-summary-item-desc">{{ $t('Use a product video as the featured media on the product page.') }}</p>
            </div>

            <el-button v-if="videoUrl" type="danger" text @click="clearVideo">
              {{ $t('Remove') }}
            </el-button>
          </div>

          <div class="fct-admin-input-wrapper mt-3">
            <el-form label-position="top">
              <el-form-item :label="$t('Video URL')">
                <el-input
                    :model-value="videoUrl"
                    :placeholder="$t('YouTube, Vimeo or direct MP4 URL')"
                    @input="updateVideoUrl"
                />
              </el-form-item>
            </el-form>
          </div>

          <div class="flex items-center gap-3 mt-2">
            <MediaButton
                icon="Video"
                :title="$t('Upload Video')"
                :attachments="videoAttachments"
                :library-type="'video'"
                @onMediaSelected="onVideoSelected"
            />
            <p class="text-xs text-gray-500">{{ $t('Upload a video file or paste a public video link.') }}</p>
          </div>

          <div class="mt-3" v-if="videoUrl">
            <video
                v-if="isFileSource"
                :src="videoUrl"
                controls
                style="width: 100%; border-radius: 8px; max-height: 340px;"
            ></video>
            <iframe
                v-else
                :src="videoUrl"
                allowfullscreen
                style="width: 100%; min-height: 260px; border: 0; border-radius: 8px;"
            ></iframe>
          </div>
        </div>
      </Card.Body>
    </Card.Container>
  </div>
</template>
