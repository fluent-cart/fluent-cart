<script setup>
import translate from "@/utils/translator/Translator";
import {ref} from "vue";
import AddProductItemModal from "@/elementor/VariationSelector/AddProductItemModal.vue";

const props = defineProps({
  variantId: {
    type: String,
    default: ''
  },
  variantTitle: {
    type: String,
    default: ''
  },
  variantPrice: {
    type: String,
    default: ''
  }
})

const counter = ref(0);
const defaultVariantId = ref(props.variantId);
const defaultVariantTitle = ref(props.variantTitle);
const defaultVariantPrice = ref(props.variantPrice);

const reset = () => {
  defaultVariantId.value = '';
  defaultVariantTitle.value = '';
  defaultVariantPrice.value = '';

  setTimeout(() => {
    const controlsView = elementor.getPanelView()?.getCurrentPageView();
    const model = controlsView?.model;
    if (model) {
      model.setSetting?.('variant_id', '');
      // Trigger save button update
      elementor?.saver?.setFlagEditorChange?.(true);
    }
  }, 100)
}


const setDefaultVariant = (variantId) => {
  defaultVariantId.value = variantId;
  setTimeout(() => {
    const controlsView = elementor.getPanelView()?.getCurrentPageView();
    const model = controlsView?.model;
    if (model) {
      model.setSetting?.('variant_id', variantId.toString());
      // Trigger save button update
      elementor?.saver?.setFlagEditorChange?.(true);

    }
  }, 100)
}

</script>

<template>
  <div>
    <div class="fluent-cart-add-to-cart-app">
      <h3 class="fluent-cart-add-to-cart-app-title">
        {{ translate('Add To Cart Button') }}
      </h3>

      <div v-if="defaultVariantId" class="fluent-cart-add-to-cart-app-content">
        <div class="fluent-cart-add-to-cart-app-product-title"
             :title="defaultVariantTitle">
        <span class="fluent-cart-add-to-cart-app-product-id">#{{
            defaultVariantId
          }}</span>{{ defaultVariantTitle }}
        </div>
        <div class="fluent-cart-add-to-cart-app-product-price"
             v-html="defaultVariantPrice"></div>

        <el-button class="fluent-cart-add-to-cart-app-reset-button"
                   type="primary" @click="reset">
          {{ translate('Reset') }}
        </el-button>
      </div>

      <AddProductItemModal v-else :scopes="['cartable']" @onVariationSelectionUpdated="(ids, products) => {
        setDefaultVariant(ids[0]);
        let ver = null;
        products.forEach((product) => {
          for (const variant of product.variants) {
            if (variant.id === ids[0]) {
              ver = variant;
              defaultVariantTitle = variant.variation_title;
              defaultVariantPrice = variant.formatted_total;
              return false; // Break the outer forEach loop
            }
          }
        });
      }"/>

    </div>
  </div>
</template>


<style scoped lang="scss">
.fluent-cart-add-to-cart-app {
  border-radius: 8px;
  border: 1px solid #d6dae1;
  background: #fff;
  padding: 20px;

  &-title {
    margin: 0 0 18px 0;
    border-bottom: 1px solid #d6dae1;
    padding-bottom: 12px;
    font-size: 18px;
    font-weight: 500;
  }

  &-product {
    &-title {
      margin-bottom: 4px;
      font-size: 16px;
      font-weight: 500;
    }

    &-id {
      margin-top: 6px;
      margin-right: 4px;
      font-weight: 400;
      color: #565865;
    }

    &-price {
      font-size: 14px;
      font-weight: 400;
      margin-bottom: 16px;
    }
  }

  &-reset-button {
    cursor: pointer;
    border: 1px solid #d6dae1;
    background: #f8f9fc;
    color: #253241;
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 14px;
    font-weight: 500;
  }
}

</style>
