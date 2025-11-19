<script setup>
import ListItem from "@/elementor/VariationSelector/ListItem.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  model: {
    type: Object,
    required: true
  },
  product: {
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
  <div class="fct-collapsible-list-item-inner">
    <ListItem
        :product="product"
        :model="model"
        :variant="product.variants[0]"
        :showCollapseButton="product.variants.length > 1"
        @updateSelectedVariations="(variant, checked) => {
          emit('onVariationSelectionUpdated', variant, checked)
        }"
    />
  </div>
</template>

<style scoped>

</style>
