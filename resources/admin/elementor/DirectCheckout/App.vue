<script setup>
import {nextTick, onMounted, ref} from "vue";
import AddProductItemModal from "../VariationSelector/AddProductItemModal.vue";
import Arr from "@/utils/support/Arr";
import translate from "@/utils/translator/Translator";
import useElementor from "../mixins/useElementor.js";
import ServerSidePreview from "@/elementor/Components/ServerSidePreview.vue";

let elementor = null;

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
const productItemModal = ref(null);

const reset = () => {
  defaultVariantId.value = '';
  defaultVariantTitle.value = '';
  defaultVariantPrice.value = '';

  setTimeout(() => {
    elementor.setSettings('variant_id', '');
  }, 100)
}

const setDefaultVariant = (variantId) => {
  defaultVariantId.value = variantId;
  setTimeout(() => {
    elementor.setSettings('variant_id', variantId.toString());
  }, 100)
}

onMounted(() => {
  elementor = useElementor();
})
</script>

<template>

  <ServerSidePreview v-if="false" block="fluent-cart/direct-checkout-button" :attributes="{
    'selected_variant': defaultVariantId
  }"/>
  <div class="fluent-cart-direct-checkout-app">
    <h3 class="fluent-cart-direct-checkout-app-title">
      {{ translate('Direct Checkout Button') }}
    </h3>

    <div v-if="defaultVariantId" class="fluent-cart-direct-checkout-app-content">
      <div class="fluent-cart-direct-checkout-app-product-title"
           :title="defaultVariantTitle">
        <span class="fluent-cart-direct-checkout-app-product-id">#{{
            defaultVariantId
          }}</span>{{ defaultVariantTitle }}
      </div>
      <div class="fluent-cart-direct-checkout-app-product-price"
           v-html="defaultVariantPrice"></div>

      <!--      <p>-->
      <!--        DefaultVariantId : {{ defaultVariantId }} <br>-->
      <!--        {{ defaultVariantTitle }}-->
      <!--      </p>-->
      <el-button class="fluent-cart-direct-checkout-app-reset-button"
                 type="primary" @click="reset">
        {{ translate('Reset') }}
      </el-button>
    </div>


    <AddProductItemModal v-else @onVariationSelectionUpdated="(ids, products) => {
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
</template>

<style scoped lang="scss">
.fluent-cart-direct-checkout-app {
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
