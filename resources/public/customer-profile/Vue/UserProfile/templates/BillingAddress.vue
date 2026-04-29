<template>
  <AddressSection
      :profileDetails="profileDetails"
      addressType="billing"
      :title="$t('Billing Address')"
      :description="$t('Provide or update the address where your billing statements and invoices will be sent.')"
      @fetch="$emit('fetch')"
      @setAddressPrimary="$emit('setAddressPrimary', $event)"
      @deleteAddress="$emit('deleteAddress', $event)"
  />
</template>

<script>
import AddressSection from './AddressSection.vue';

export default {
  name: "BillingAddress",
  props: ['profileDetails'],
  emits: ['fetch', 'setAddressPrimary', 'deleteAddress'],
  components: { AddressSection }
}
</script>
