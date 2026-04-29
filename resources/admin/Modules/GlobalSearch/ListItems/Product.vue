<script setup>
import Str from "@/utils/support/Str";
import ProductInfo from "@/Bits/Components/Card/ProductInfo.vue";
import {formatNumber} from "@/Bits/productService";
import Badge from "@/Bits/Components/Badge.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  product: {
    type: Object,
    required: true
  }
})

const getPrice = (detail) => {
  if (detail.min_price === detail.max_price) {
    return formatNumber(detail.min_price, true);
  }
  return `${formatNumber(detail.min_price, true)} - ${formatNumber(detail.max_price, true)}`;
}

const getBadgeText = (status) => {
  return status === 'publish' ? translate('published')
      : status === 'future' ? translate('scheduled')
          : status
}
</script>

<template>
  <div class="fct-global-search-product-wrap">
    <div class="fct-global-search-product-left-info">
      <ProductInfo :product="product"/>
      <span class="type" v-if="product.detail">
        {{ Str.headline(product.detail.variation_type) }}
        <br>
        {{ Str.headline(product.detail.fulfillment_type) }}
      </span>
    </div>

    <span class="price-and-status" v-if="product.detail">
      {{ getPrice(product.detail) }}
      <Badge :key="product.ID" :status="product.post_status">
        {{ getBadgeText(product.post_status) }}
      </Badge>
    </span>
  </div>
</template>

<style scoped>

</style>
