<script setup>
import Str from "@/utils/support/Str";
import ProductInfo from "@/Bits/Components/Card/ProductInfo.vue";
import {formatNumber} from "@/Bits/productService";
import Badge from "@/Bits/Components/Badge.vue";
import translate from "@/utils/translator/Translator";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import Arr from "@/utils/support/Arr";

const props = defineProps({
  order: {
    type: Object,
    required: true
  }
})

const getPaymentProviderLogo = (payment_method) => {
  return Arr.get(window, 'fluentCartAdminApp.payment_logos.' + payment_method);
}
</script>

<template>
  <div class="fct-global-search-order-wrap">
    <div class="fct-global-search-order-header">
      <div class="fct-global-search-order-id">
        #{{ order.id }} <span class="items">{{
            /* translators: %s - number of items */
          translate('%s Items', order.order_items.length) }}
      </span>
      </div>
      <div class="fct-global-search-order-header-meta">
        <span class="date"><ConvertedTime :date-time="order.created_at"/></span>
        <span class="circle"></span>
        <span class="total-price" v-html="formatNumber(order.total_amount, true, false, order.currency)"></span>
        <span class="circle"></span>
        <span class="customer-name">{{ order.customer?.full_name || translate('No Name') }}</span>
      </div>
    </div>
    <div class="fct-global-search-order-status-wrapper">
      <Badge :status="order.payment_status" :hide-icon="true"/>

      <img
           v-if="getPaymentProviderLogo(order.payment_method)"
           :src="getPaymentProviderLogo(order.payment_method)"
           :alt="Str.headline(order.payment_method)">
      <el-tag v-else type="info" round size="small" class="capitalize">
        {{ Str.headline(order.payment_method) }}
      </el-tag>
    </div>
  </div>
</template>

<style scoped>

</style>
