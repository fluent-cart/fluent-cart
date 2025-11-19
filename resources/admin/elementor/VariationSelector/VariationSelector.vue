<script setup>
import ProductListItem from "@/elementor/VariationSelector/ProductListItem.vue";

const props = defineProps({
  model: {
    type: Object,
    required: true
  },
  allowSubscription: {
    type: Boolean,
    default: false
  },
  isMultiple: {
    type: Boolean,
    default: false
  },
  preSelectedVariations: {
    type: Object,
    default: {}
  }
})
const emit = defineEmits(['onVariationSelectionUpdated']);
</script>

<template>
  <ul class="fct-collapsible-list">
    {{ model.data.selectedVariationIds }}
    <li class="fct-collapsible-list-item" v-for="(product, productIndex) in model.data.products" :key="productIndex">
      <ProductListItem
          :model="model"
          :product="product"
          :preSelectedVariations="{}"
          :isMultiple="true"
          :allow_subscription="false"
          @onVariationSelectionUpdated="()=>{
                emit('onVariationSelectionUpdated')
          }"
      />
    </li>
  </ul>
</template>

<style scoped>

</style>
