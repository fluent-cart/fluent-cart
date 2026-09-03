<script setup>
import Str from "@/utils/support/Str";
import {formatNumber} from "@/Bits/productService";
import {getVariantLabel} from "@/utils/variantLabel";

const props = defineProps({
  variant: {
    type: Object,
    required: true
  },
  // Optional canonical group order (group_ids from attribute_config).
  // Forwarded to getVariantLabel so the join order matches the variant
  // table's grouping rather than the server's arbitrary attr_map order.
  groupOrder: {
    type: Array,
    default: null
  }
})

</script>

<template>
  <div v-if="variant" class="fct-downloadable-variation-selector">
    <span>
      {{ getVariantLabel(variant, groupOrder) }}
    </span>

    <span class="price-info">
      <span v-if="variant.other_info?.repeat_interval">
        {{ Str.capitalize(variant.other_info.repeat_interval) }}
      </span>
      <span>
        <!-- item_price is CENTS and formatNumber() takes cents. The `* 100`
             that used to be here was correct only while ProductRoute.formatPricing
             pre-divided variants to dollars; it has not since PR 2615. -->
        {{ formatNumber(variant.item_price, true) }}
      </span>
    </span>
  </div>
</template>

<style scoped>

</style>
