<script setup>
import Str from "@/utils/support/Str";
import {formatNumber} from "@/Bits/productService";

const props = defineProps({
  variant: {
    type: Object,
    required: true
  }
})

</script>

<template>
  <div v-if="variant" class="fct-downloadable-variation-selector">
    <span>
      {{ variant.variation_title }}
    </span>

    <span class="price-info">
      <span v-if="variant.other_info?.repeat_interval">
        {{ Str.capitalize(variant.other_info.repeat_interval) }}
      </span>
      <span>
        {{ formatNumber(variant.item_price * 100, true) }}
      </span>
    </span>
  </div>
</template>

<style scoped>

</style>
