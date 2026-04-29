<template>
  <div :class="`fct-bundle-products ${showBundleProducts ? 'show-all' : ''}`" v-if="bundleItems.length">
    <h4 class="fct-bundle-products-title">
      {{$t('Bundle Of')}}:
    </h4>

    <div class="fct-bundle-products-list">
      <p v-for="(bundleItem, i) in bundleItems.slice(0, 2)" :key="i">
        {{ bundleItem[titleKey] }}
      </p>

      <div class="fct-bundle-products-more">
        <div class="fct-bundle-products-more-list">
          <p v-for="(bundleItem, i) in bundleItems.slice(2)" :key="`more-${i}`">
            {{ bundleItem[titleKey] }}
          </p>
        </div>
      </div>
    </div>

    <a v-if="bundleItems.length > 2" href="#" class="fct-see-more-btn" @click.prevent="toggleBundleProducts">
      <span class="see-more-text">
          {{$t('See More')}}
          <DynamicIcon name="ChevronDown"/>
      </span>
      <span class="see-less-text">
          {{$t('See Less')}}
          <DynamicIcon name="ChevronUp"/>
      </span>
    </a>
  </div>
</template>

<script type="text/babel">
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

export default {
  name: 'BundleProducts',
  components: {
    DynamicIcon
  },
  props: {
    product: {
      type: Object,
      default: () => ({})
    },
    titleKey: {
      type: String,
      default: 'title'
    }
  },
  data() {
    return {
      showBundleProducts: false
    }
  },
  computed: {
    bundleItems() {
      return this.product?.bundle_items ?? [];
    }
  },
  methods: {
    toggleBundleProducts() {
      this.showBundleProducts = !this.showBundleProducts;
    }
  }

}
</script>
