<template>
  <AddressSection
      :profileDetails="profileDetails"
      addressType="shipping"
      :title="$t('Shipping Address')"
      :description="$t('Provide or update the address where your orders will be delivered.')"
      @fetch="$emit('fetch')"
      @set-address-primary="$emit('setAddressPrimary', $event)"
      @delete-address="$emit('deleteAddress', $event)"
  />
</template>

<script>
import AddressSection from './AddressSection.vue';

export default {
  name: "ShippingAddress",
  components: { AddressSection },
  props: ['profileDetails'],
  emits: ['fetch', 'setAddressPrimary', 'deleteAddress']
}
</script>
