<template>
  <AddressSection
      :profileDetails="profileDetails"
      addressType="billing"
      :title="$t('Billing Address')"
      :description="$t('Provide or update the address where your billing statements and invoices will be sent.')"
      @fetch="$emit('fetch')"
      @set-address-primary="$emit('setAddressPrimary', $event)"
      @delete-address="$emit('deleteAddress', $event)"
  />
</template>

<script>
import AddressSection from './AddressSection.vue';

export default {
  name: "BillingAddress",
  components: { AddressSection },
  props: ['profileDetails'],
  emits: ['fetch', 'setAddressPrimary', 'deleteAddress']
}
</script>
