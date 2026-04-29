<template>
  <div class="fct-gallery-wrap">
    <draggable
        class="fct-gallery-drag-area"
        :list="attachments"
        tag="ul"
        v-bind="dragOptions"
        draggable=".gallery-drag-item"
        @change="onMediaChange"
    >

      <transition-group type="transition" name="flip-list">
        <li class="gallery-drag-item" v-for="(element, elementIndex) in attachments" :key="elementIndex">
          <IconButton
              tag="button"
              size="tiny"
              @click.prevent="removeImageEvent(elementIndex)" :title="$t('Delete media')"
          >
            <DynamicIcon name="Delete"/>
          </IconButton>
          <div class="icon-move" v-if="elementIndex !== 0">
            <svg xmlns="http://www.w3.org/2000/svg" width="8" height="10" viewBox="0 0 8 10" fill="none">
              <rect x="0.333313" width="2.66667" height="2.66667" rx="0.666667" fill="white"/>
              <rect x="0.333313" y="3.6665" width="2.66667" height="2.66667" rx="0.666667" fill="white"/>
              <rect x="0.333313" y="7.3335" width="2.66667" height="2.66667" rx="0.666667" fill="white"/>
              <rect x="5" width="2.66667" height="2.66667" rx="0.666667" fill="white"/>
              <rect x="5.00018" y="3.6665" width="2.66667" height="2.66667" rx="0.666667" fill="white"/>
              <rect x="5.00018" y="7.3335" width="2.66667" height="2.66667" rx="0.666667" fill="white"/>
            </svg>
          </div>
          <el-tag type="primary" v-if="elementIndex === 0">
            {{ $t('Featured') }}

          </el-tag>
          <div class="gallery-drag-item-overlay" @click="showLightbox(elementIndex)"></div>
          <img :src="element.url" :alt="element.title"/>
        </li>
        <li :class="attachments.length < 1 ? 'is-full-media' : ''" :key="attachments.length">
          <MediaButton icon="GalleryAdd" :title="attachments.length < 1 ? $t('Add Media') : ''"
                       :attachments="attachments" @onMediaSelected="onMediaSelected" :multiple='true'></MediaButton>
        </li>
      </transition-group>


    </draggable>
  </div>
  <VueEasyLightbox
      :visible="visible"
      :imgs="lightboxData"
      :index="imageIndex"
      @hide="()=>{
        this.visible = false
      }"
  ></VueEasyLightbox>
</template>

<script>
import {VueDraggableNext} from 'vue-draggable-next'
import MediaButton from "@/Bits/Components/Buttons/MediaButton.vue";
import {ref} from "vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import VueEasyLightbox from "vue-easy-lightbox";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

export default {
  name: "Gallery",
  display: 'Transition',
  components: {
    DynamicIcon,
    draggable: VueDraggableNext,
    MediaButton,
    IconButton,
    VueEasyLightbox
  },
  props: {
    attachments: {
      type: Array,
      default: () => [],
    },
    featured_image_id: {
      type: [String, Number, null],
      default: null,
    }
  },
  data() {
    return {
      visible: false,
      imageIndex: 0,
      lightboxData: [],
      images: ref([])
    };
  },
  emits: ['removeImage', 'mediaUploaded', 'onMediaChange'],
  methods: {
    showLightbox(idx) {
      this.imageIndex = idx;
      this.visible = true;
    },
    removeImageEvent(idx) {
      this.$emit('removeImage', idx);
    },
    onMediaSelected($selected) {
      this.images = $selected.map(image => ({
        id: image.id,
        title: image.title,
        url: image.url,
      }));

      this.$emit('mediaUploaded', this.images)
    },
    onMediaChange(event) {
      this.$emit('onMediaChange', this.attachments)
    },
  },
  computed: {
    dragOptions() {
      return {
        animation: 600
      }
    },
  },
  mounted() {
    if (this.attachments !== undefined) {
      this.attachments.map((val, index) => {
        this.lightboxData.push(val.url);
      })
    }
  },
  watch: {
    attachments: {
      handler: function (newval, oldval) {
        this.lightboxData = [];
        if (Array.isArray(newval) && newval.length > 0) {
          newval.map((val, index) => {
            this.lightboxData.push(val.url)
          })
        }
        return newval
      },
      deep: true
    },
  }
};
</script>
