<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  product: Object,
  drawerMode: String,
  isVariationDirty: Boolean,
});

const emit = defineEmits(['open-editor']);

const openEditor = () => {
  emit('open-editor', {
    modeType: 'create'
  });
};

const isDisabled = () => {
  return props.drawerMode === 'create' || props.isVariationDirty;
};
</script>

<template>
  <div class="fct-variant-pricing-add-button-wrap">
    <el-button
      v-if="product.detail?.variation_type !== 'simple'"
      text
      type="primary"
      :disabled="isDisabled()"
      @click="openEditor"
      size="small"
    >
      <DynamicIcon name="Plus" />
      {{ product.variants.length > 0 ? $t('Add more') : $t('Add Pricing') }}
    </el-button>
  </div>
</template>
