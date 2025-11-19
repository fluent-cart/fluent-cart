<script setup>

import {onBeforeMount, ref} from "vue";
import translate from "@/utils/translator/Translator";
import useElementor from "@/elementor/mixins/useElementor";
import SearchableSelect from "@/elementor/SearchableSelect.vue";

let elementorHelper = null;

const props = defineProps({
  selectedProduct: {
    type: String,
    default: ''
  },
  productTitle: {
    type: String,
    default: ''
  }
});

const defaultProductTitle = ref(props.productTitle);
const selectedProductId = ref(props.selectedProduct);

const reset = () => {
  selectedProductId.value = '';
  defaultProductTitle.value = '';

  setTimeout(() => {
    elementorHelper.setSettings('selected_product', '');
  }, 100)
}


const setDefaultVariant = (selected_product) => {
  selectedProductId.value = selected_product;
  setTimeout(() => {
    elementorHelper.setSettings('selected_product', selectedProductId.value.toString());
  }, 500);
}

const handleProductSelect = (key) => {
  if (key.hasOwnProperty('product')) {
    setDefaultVariant(key.product.ID.toString());
    defaultProductTitle.value = key.product.post_title;
  } else {
    setDefaultVariant('');
  }
}

onBeforeMount(() => {
  elementorHelper = useElementor();
})

</script>

<template>
  <div class="fluent-cart-product-details-button">
    <h3 class="fluent-cart-product-details-title">
      {{ translate('Product Details Button') }}
    </h3>

    <div v-if="selectedProductId" class="fct-selected-product-contents">
      <div class="fct-selected-product-contents-inner"
           :title="defaultProductTitle">
        <div class="product-title">
          <span class="product-id">#{{ selectedProductId }}</span>
          {{ defaultProductTitle }}
        </div>
      </div>

      <el-button class="product-clear-button" @click="reset">
        {{ translate('Reset') }}
      </el-button>
    </div>
    <div v-else>
      <SearchableSelect :value="selectedProductId" :onChange="handleProductSelect" />
    </div>
  </div>
</template>

<style scoped>

</style>
