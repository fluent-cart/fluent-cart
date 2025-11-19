<template>
    <div class="fct-transaction-payment-method" role="status">
      <!-- PayPal Payment Method -->
      <template v-if="transaction.payment_method === 'paypal'">
        <div class="flex gap-2 items-center">
          <img :src="`${assetUrl}images/payment-methods/paypal-icon.svg`" :alt="$t('PayPal logo')">
          <span class="text-gray-700 text-sm" :aria-label="$t('Transaction ID:') + transaction.vendor_charge_id || ''">{{ transaction.vendor_charge_id || '' }}</span>
        </div>
      </template>

      <template v-else-if="parseInt(transaction.card_last_4)">
        <div class="flex gap-2 items-center" role="img" :aria-label="$t('Credit card payment method')">
          <img :src="`${assetUrl}images/credit-card/card.svg`" :alt="$t('Credit Card logo')">
          <span v-if="transaction.card_brand" class="text">{{ transaction.card_brand }}</span>
          <span 
            class="text" 
            :aria-label="$t('Credit card ending in') + ' ' + transaction.card_last_4"
          >
            **** {{ transaction.card_last_4 }}
          </span>
        </div>
      </template>

      <span v-else class="text text-capitalize" aria-label="Payment method: {{ transaction.payment_method }}">
        {{ transaction.payment_method }}
    </span>
    </div>
</template>

<script type="text/babel">
export default {
    name: 'TransactionPaymentMethod',
    props: {
        transaction: {
            type: Object,
            required: true
        },
    },
    computed: {
        assetUrl() {
            return this.appVars.assets_path;
        }
    }
}
</script>
