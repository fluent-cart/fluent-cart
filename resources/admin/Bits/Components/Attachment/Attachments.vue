<template>
  <VueEasyLightbox
      :visible="visible"
      :imgs="lightboxData"
      :index="imageIndex"
      @hide="()=>{
        this.visible = false
      }"
  ></VueEasyLightbox>
  <div class="fct-attachments-wrap" style="position: relative">
    <ul :class="`fct-attachments-list ${multiple ? 'is-multiple' : 'fct-single-attachment'} ${attachments.length > 0 ? 'is-single' : ''}`"
        v-if="attachments">
      <li v-if="showList === true" v-for="(img, idx) in attachments" :key="idx" :class="[mediaInputSize, mediaInputBg]" class="fct-attachment-item">
        <div class="fct-attachment-img-wrap">
          <img v-if="previewImage" :src="img.url" :alt="img.title" @click="()=>{
                      this.imageIndex = idx;
                      this.visible=true;
                  }"/>
          <IconButton size="x-small" v-if="showMediaButtonAsIcon && !multiple">
            <MediaButton  :icon-mode="true" :attachments="attachments" :title="multiple?'':title" @onMediaSelected="onMediaSelected" :multiple='multiple' :icon="icon"></MediaButton>
          </IconButton>
        </div>


        <div class="fct-attachments-button">
          <IconButton v-if="showDeleteButton"  tag="button" bg="danger" outline size="x-small"
                      @click.prevent="removeImageEvent(idx)" :title="translate('Delete media')">
            <Delete v-if="multiple"/>
            <template v-else>
              {{ translate('Remove') }}
            </template>
          </IconButton>
          <IconButton size="x-small" v-if="showMediaButtonAsIcon && multiple">
            <MediaButton  :icon-mode="true" :attachments="attachments" :title="multiple?'':title" @onMediaSelected="onMediaSelected" :multiple='multiple' :icon="icon"/>
          </IconButton>
        </div>



        <!-- <div class="fct-attachments-edit-wrap">
            <MediaButton v-if="type === 'featured'" title="Edit" @onMediaSelected="onMediaSelected" :icon="icon"></MediaButton>
        </div> -->
      </li>
      <li v-if="!showMediaButtonAsIcon" :class="[mediaInputSize, mediaInputBg]">
        <MediaButton :icon-mode="false" :attachments="attachments" :title="multiple?'':title" @onMediaSelected="onMediaSelected" :multiple='multiple' :icon="icon"></MediaButton>
      </li>
    </ul>
    <slot/>
  </div>
</template>

<script>
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import {Delete} from '@element-plus/icons-vue';
import VueEasyLightbox from "vue-easy-lightbox";
import MediaButton from "@/Bits/Components/Buttons/MediaButton.vue";
import {ref} from "vue";
import translate from "@/utils/translator/Translator";

export default {
  name: "Attachments",
  props: {
    multiple: Boolean,
    attachments: {
      type: [Array, Object],
      default: () => [],
    },
    empty_text: String,
    isSingle: Boolean,
    title: String,
    type: String,
    icon: String,
    showList: {
      type: Boolean,
      default: true
    },
    showDeleteButton: {
      type: Boolean,
      default: true
    },
    previewImage: {
      type: Boolean,
      default: true
    },
    mediaInputSize: {
      type: String
    },
    mediaInputBg: {
      type: String
    }
  },
  emits: ['removeImage', 'mediaUploaded'],
  data() {
    return {
      visible: false,
      imageIndex: 0,
      lightboxData: [],
      images: ref([])
    }
  },
  components: {
    MediaButton,
    VueEasyLightbox,
    IconButton,
    Delete
  },

  computed: {
    showMediaButtonAsIcon: function(){
      return !this.multiple && this.attachments.length ===1;
    }
  },
  mounted: function () {
    // Check if attachments is an array and not empty
    if (Array.isArray(this.attachments) && this.attachments.length > 0) {
      this.attachments.forEach((val) => {
        this.lightboxData.push(val.url);
        this.images.push({
          id: val.id,
          url: val.url,
          title: val.title,
        });
      });
    }
  },
  methods: {
    translate,
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
}
</script>
