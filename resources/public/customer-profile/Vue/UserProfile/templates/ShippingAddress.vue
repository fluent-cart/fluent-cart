<template>
  <AddressSection
      :profileDetails="profileDetails"
      addressType="shipping"
      :title="$t('Shipping Address')"
      :description="$t('Provide or update the address where your orders will be delivered.')"
      @fetch="$emit('fetch')"
      @setAddressPrimary="$emit('setAddressPrimary', $event)"
      @deleteAddress="$emit('deleteAddress', $event)"
  />
</template>

<script>
import AddressSection from './AddressSection.vue';

export default {
  name: "ShippingAddress",
  props: ['profileDetails'],
  emits: ['fetch', 'setAddressPrimary', 'deleteAddress'],
  components: { AddressSection }
}
</script>
