<script setup>
import {formatNumber} from "@/Bits/productService";

const props = defineProps({
  coupon: {
    type: Object,
    required: true
  }
})


</script>

<template>
  <div class="fct-global-search-coupon-wrap">
    <span>
      {{ coupon.title }}
    </span>
    <span>
      {{ coupon.code }}
    </span>
    <span class="amount">
      {{ formatNumber(coupon.amount, true) }}
    </span>
  </div>
</template>

<style scoped>

</style>
