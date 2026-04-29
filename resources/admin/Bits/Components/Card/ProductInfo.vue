<template>
  <div class="fct-product-info-card w-full">
    <div v-if="!hideImage" class="fct-product-info-media">
        <span>
          <img
              class="block dark:hidden"
              :src="getImage(product?.detail)"
              :alt="product?.post_title"
          />

          <img
              class="hidden dark:block"
              :src="getImage(product?.detail,true)"
              :alt="product?.post_title"
          />
        </span>
    </div>

    <div class="fct-product-info-details">
      <div class="fct-product-info-name">
        <span class="product-name">
          <span class="product-name-inner">{{ product?.post_title || translate('No Name') }}</span>
          <a v-if="!hideViewIcon" class="view-link right-1" target="_blank" :href="product?.view_url" @click.stop>
            <DynamicIcon class="w-5 h-5" name="Eye"/>
          </a>
        </span>

        <span v-if="!hideProductId" class="product-id">
          #{{ translateNumber(product?.ID) }}
        </span>

        <span v-if="product?.variation_title" class="product-sub-name">
           &#8211; {{ product?.variation_title }}
        </span>
      </div>

    </div>
  </div>
</template>

<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Arr from "@/utils/support/Arr";
import Image from "@/utils/support/Image";
import translate, {translateNumber} from "@/utils/translator/Translator";

export default {
  components: {
    DynamicIcon
  },
  props: {
    product: {
      type: Object,
    },
    hideProductId: {
      type: Boolean,
      default: false
    },
    hideViewIcon: {
      type: Boolean,
      default: false
    },
    hideImage: {
      type: Boolean,
      default: false
    }
  },
  data() {
    return {}
  },
  methods: {
    translate,
    translateNumber,
    getImage(detail, dark = false) {
      return Arr.get(detail, 'featured_media.url') ?? Image.emptyImage(dark);
    }
  }
}
</script>

