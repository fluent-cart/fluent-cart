<template>
    <div aria-labelledby="payment-info-heading" role="region">
      <h2 id="payment-info-heading" class="sr-only">
        {{ $t('Payment details') }}
      </h2>

      <div
          v-if="data?.payment_info"
          class="fct-customer-payment-info"
          v-html="data?.payment_info"
      ></div>
    </div>
</template>

<script>
export default {
  name: 'PaymentInfo',
  props: {
    data: Object,
  }
}
</script>

