<script setup>
import Str from "@/utils/support/Str";
import ProductInfo from "@/Bits/Components/Card/ProductInfo.vue";
import {formatNumber} from "@/Bits/productService";
import Badge from "@/Bits/Components/Badge.vue";
import translate from "@/utils/translator/Translator";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import Arr from "@/utils/support/Arr";

const props = defineProps({
  customer: {
    type: Object,
    required: true
  }
})


</script>

<template>
  <div class="fct-global-search-customer-wrap">
    <div class="fct-customer-card">
      <div class="fct-customer-avatar">
          <img :src="customer.photo" alt="avatar"/>
      </div>
      <div class="fct-customer-details">
        <div class="fct-customer-name">
          {{ customer.full_name || $t("No Name") }}
          <span class="fct-customer-id">#{{ customer.id }}</span>
        </div>
        <div class="fct-customer-email">
          {{ customer.email }} <span class="circle"></span> <span class="purchase-count">Purchase: {{ customer.purchase_count }}</span>
        </div>
      </div>
    </div>

    <span class="date">
      <ConvertedTime :date-time="customer.last_purchase_date"/>
    </span>

  </div>
</template>

<style scoped>

</style>
