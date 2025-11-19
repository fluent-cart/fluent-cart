<script setup>
import {ref} from "vue";
import CaretRight from "@/Bits/Components/Icons/CaretRight.vue";
import Str from "@/utils/support/Str";
import {formatNumber} from "@/Bits/productService";
import ItemCheckbox from "@/elementor/VariationSelector/ItemCheckbox.vue";
import translate from "../../utils/translator/Translator";

const props = defineProps({
  model: {
    type: Object,
    required: true
  },
  product: {
    type: Object,
    required: true
  },
  variant: {
    type: Object,
    required: true
  },
  showCollapseButton: {
    type: Boolean,
    default: false
  },
  stock: {
    type: String,
    default: ''
  },
  checked: {
    type: Boolean,
    default: false
  },
  isMultiple: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['toggleCollapse', 'updateSelectedVariations']);
const isOpen = ref(false);
const checked = ref(false)
const productTitle = props.model.getProductTitle(props.product);
const isManagingStock = props.variant.manage_stock?.toString() === '1';
const price = props.variant.item_price;
const available = props.variant.available;
</script>

<template>
  <div class="fct-collapsible-item-inner">

    <div class="fct-collapsible-item">
      <div class="fct-collapsible-content-wrapper">
        <div v-if="showCollapseButton" @click="isOpen = !isOpen" class="content-collapsible-btn"
             :class="{'is-collapsed': isOpen}">
          <CaretRight class="icon w-3 h-3"/>
        </div>
        <div class="content-indent" v-if="!showCollapseButton"></div>
        <div v-if="product.variants.length === 1" class="content-checkbox" style="width: 15px">
          <ItemCheckbox
              :model="model"
              :variant="variant"
              :disabled="product.variants.length > 1"
          />
        </div>
        <div class="content-img">
          <img
              :src="model.getProductThumbnail(product)"
              :alt="productTitle"/>
        </div>

        <div class="content-title">
          <div class="title">{{ productTitle }}</div>
        </div>
      </div>


      <div class="fct-collapsible-stock-wrapper" v-if="product.variants.length === 1">
                                <span v-if="product.detail.stock_availability	 === 'in-stock'">
                                  {{
                                    isManagingStock ?
                                        /* translators: %s - number of items available */
                                        translate('Available %s', available) : translate('Unlimited')
                                  }}
                                </span>
        <span v-else class="text-red-500">
                                  {{ Str.capitalize(product.detail.stock_availability) }}
                                </span>
      </div>

      <div class="fct-collapsible-value-wrapper">
      <span v-if="product?.variants?.length > 1">
        {{
          product.detail.min_price != product.detail.max_price ? formatNumber(product.detail.min_price) + ' - ' + formatNumber(product.detail.max_price) : formatNumber(product.detail.min_price)
        }}
      </span>
        <span v-else> {{ formatNumber(price) }}</span>
      </div>
    </div>

    <div class="fct-collapsible-item-child-container"
         :class="{ 'is-collapsed': isOpen }" v-if="product?.variants.length > 1">
      <ul class="fct-collapsible-item-child-inner">
        <li v-for="(variant, productChildrenIndex) in product.variants" :key="productChildrenIndex">
          <div class="fct-collapsible-item">
            <div class="fct-collapsible-content-wrapper">
              <div class="content-checkbox">
                <ItemCheckbox
                    :model="model"
                    :variant="variant"
                />
              </div>
              <div class="content-img">
                <img
                    :src="model.getVariantThumbnail(variant)"
                    :alt="variant.variation_title	"/>
              </div>
              <div class="content-title">
                <div class="title">{{ variant.variation_title }}</div>
              </div>
            </div>
            <div class="fct-collapsible-stock-wrapper">
                                              <span v-if="variant.stock_status	 == 'in-stock'">
                                                   {{
                                                  variant.manage_stock.toString() !== '1' ? $t('Available %s', variant.available) : $t('Unlimited')
                                                }}
                                              </span>
              <span v-else class="text-red-500">
                                                 {{ Str.capitalize(variant.stock_status) }}
                                             </span>
            </div>
            <div class="fct-collapsible-value-wrapper">
              {{ formatNumber(variant.price) }}
            </div>
          </div><!-- .fct-collapsible-item -->
        </li>
      </ul>
    </div>

  </div>

</template>

<style scoped>

</style>
