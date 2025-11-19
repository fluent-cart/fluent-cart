<script setup>
import { computed } from 'vue';
import Str from '@/utils/support/Str';
import PaymentInfo from "./PaymentInfo.vue";

// Props
defineProps({
  data: Object,
  showPaymentInfo: Boolean
});

// Computed properties
const assetsPath = computed(() => {
  return window.fluentcart_customer_profile_vars.assets_path;
});
</script>

<template>
  <div class="fct-card-info" v-if="data" role="status" :aria-label="$t('Payment method information')">
    <!-- Bank Transfer -->
    <!-- PayPal Payment Method -->
    <template v-if="data?.current_payment_method === 'paypal' || data?.payment_method === 'paypal'">
      <img :src="`${assetsPath}images/payment-methods/paypal-icon.svg`" :alt="$t('PayPal logo')"
        role="presentation">
    </template>


    <!-- Credit Card with Last 4 Digits -->
    <!-- currently card info in possible two formats data?.billing_info?.card?.last_4 or data?.card_last_4, will solve this issue in future -->
    <template v-else-if="data?.billing_info?.details?.last_4 || data?.card_last_4">
      <img :src="`${assetsPath}images/credit-card/card.svg`" :alt="$t('Credit card icon')" role="presentation"
          aria-hidden="true">
      <div 
          class="text"
          :aria-label="$t('Credit card number') + ' ' + (data?.billing_info?.details?.last_4 || data?.card_last_4)"
      >
          **** {{ data?.billing_info?.details?.last_4 || data?.card_last_4 }}
      </div>
    </template>


    <template v-else-if="data?.current_payment_method == 'offline_payment'">
      <div class="text" role="status"
        :aria-label="`${Str.headline(data?.current_payment_method) || Str.headline(data?.payment_method)} payment method`">
        {{
          Str.headline(data?.current_payment_method) || Str.headline(data?.payment_method)
        }}
      </div>
    </template>
  </div>
</template>

