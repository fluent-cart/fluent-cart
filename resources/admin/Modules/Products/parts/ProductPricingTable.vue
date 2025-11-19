<script setup>
import {onMounted, computed} from "vue";
import ProductPricingActions from "./ProductPricingActions.vue";
import Attachments from "@/Bits/Components/Attachment/Attachments.vue";
import { VueDraggableNext } from 'vue-draggable-next';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  product: Object,
  productEditModel: Object,
})

onMounted(() => {
  setTimeout( () => {
    const numberInputs = document.querySelectorAll(
        ".el-input__wrapper input[type='number']"
    );

    numberInputs.forEach((numberInput) => {
      numberInput.addEventListener("wheel", (event) => {
        if (document.activeElement === numberInput) {
          event.preventDefault();
        }
      });
    });

    props.product.variants.filter(variant => {
      const mediaArray = variant.media ? (variant.media.meta_value || []) : [];
      return variant.media = mediaArray;
    });
  }, 50)
})

const dragOptions = computed(() => {
  return {
    animation: 600,
    ghostClass: 'ghost'
  }
})
</script>

<template>
  <div class="fct-product-pricing-table-wrap">

    <!-- draggable table start -->
    <div class="fct-table-draggable hide-on-mobile">
      <table>
        <colgroup>
          <col>
          <col width="50">
          <col width="300">
          <col>
          <col>
          <col>
        </colgroup>
        <thead>
            <tr>
              <th></th>
              <th>{{$t('Image')}}</th>
              <th>{{$t('Title')}}</th>
              <th>{{$t('Price')}}</th>
              <th>{{$t('Compare at price')}}</th>
              <th class="is-right">{{$t('Action')}}</th>
            </tr>
        </thead>
        <VueDraggableNext
            v-bind="dragOptions"
            :list="product.variants"
            item-key="id"
            @end="(evt) => {
              productEditModel.updateVariantSerialIndexes(product.variants);
              // return productEditModel.setHasChange(true)
            }"
            tag="tbody"
            handle=".fct-drag-handle"
        >
          <tr v-for="(variant, index) in product.variants" :key="variant.id">
            <td>
              <span class="fct-drag-handle drag-icon" v-if="product.variants.length > 1">
                <DynamicIcon name="ReorderDotsVertical"/>
              </span>
            </td>
            <td>
              <div class="fct-product-pricing-table-item">
                <div class="media relative">
                  <div class="absolute top-0 left-0 w-full h-full z-10 rounded opacity-0">
                    <attachments
                      :previewImage="false"
                      :showList=false
                      :multiple=true
                      :showDeleteButton="false"
                      :attachments="variant?.media"
                      @mediaUploaded="value => {productEditModel.onUploadPricingMedia('media', index, value)}"
                    />
                  </div>

                  <div v-if="typeof variant?.media != 'undefined' && variant?.media?.length > 0" class="absolute top-0 left-0 w-full h-full z-0 rounded">
                    <img :src='variant.media[0]?.url' :alt="variant.media[0]?.title" class="!object-contain rounded border border-solid border-gray-outline dark:border-dark-400"/>
                  </div>
                  <img v-else :src="appVars.asset_url + 'images/empty-image.svg'" alt="No Image"/>
                </div>
              </div>
            </td>
            <td>
              <div class="fct-product-pricing-table-item">
                <el-input
                    :class="productEditModel.hasValidationError(`variants.${index}.variation_title`) ? 'is-error' : ''"
                    :id="`variants.${index}.variation_title`"
                    :placeholder="$t('Title')" type="text" v-model="variant.variation_title" @input="value => {productEditModel.onChangePricing('variation_title', index,value)}" :disabled="product?.detail?.variation_type === 'simple'"
                    @focus="productEditModel.clearValidationError(`variants.${index}.variation_title`)">
                </el-input>

                <span v-if="variant.other_info?.installment === 'yes'" class="fct-variant-badge">
                  {{variant.other_info.times}} {{translate('Installment')}}
                </span>

              </div>
            </td>
            <td>
              <div class="fct-product-pricing-table-item">
                <el-input
                    :placeholder="$t('Price')"
                    :class="productEditModel.hasValidationError(`variants.${index}.item_price`) ? 'is-error' : ''"
                    :id="`variants.${index}.item_price`"
                    type="number" :min="1" v-model.number="variant.item_price"
                    @input="value => {productEditModel.onChangePricing('item_price', index,value)}"
                    :disabled="variant.expanded"
                    @focus="productEditModel.clearValidationError(`variants.${index}.item_price`)">
                  <template #prefix>
                    <span v-html="appVars.shop.currency_sign"></span>
                  </template>
                </el-input>

                <span v-if="variant.other_info?.payment_type === 'subscription'" class="fct-variant-badge">
                  {{variant.other_info.repeat_interval}}
                </span>
              </div>
            </td>
            <td>
              <div class="fct-product-pricing-table-item">
                <el-input
                    :placeholder="$t('Compare price')"
                    :class="productEditModel.hasValidationError(`variants.${index}.compare_price`) ? 'is-error' : ''"
                    :id="`variants.${index}.compare_price`"
                    type="number" :min="1" v-model.number="variant.compare_price"
                    :disabled="variant.expanded"
                    @input="value => {productEditModel.onChangePricing('compare_price', index,value)}"
                    @focus="productEditModel.clearValidationError(`variants.${index}.compare_price`)">
                  <template #prefix>
                    <span v-html="appVars.shop.currency_sign"></span>
                  </template>
                </el-input>
              </div>
            </td>
            <td class="is-right">
              <div class="fct-product-pricing-table-item">
                <ProductPricingActions
                  :modeType="'action'"
                  :index="index"
                  :variant="variant"
                  :product="product"
                  :productEditModel="productEditModel"
                />
              </div>
            </td>
          </tr>
        </VueDraggableNext>
      </table>
    </div>
    <!-- draggable table end -->

    <!-- mobile view -->
    <div class="fct-table-draggable fct-product-pricing-table-mobile-wrap">
      <VueDraggableNext
        v-bind="dragOptions"
        :list="product.variants"
        item-key="id"
        @end="(evt) => {
          //console.log('Drag event:', evt)
          productEditModel.updateVariantSerialIndexes(product.variants);
          // return productEditModel.setHasChange(true)
        }"
        handle=".fct-drag-handle"
      >
        <div 
          v-for="(variant, index) in product.variants" 
          :key="variant.id" 
          class="fct-product-pricing-table-mobile-row"
        >

        <span class="fct-drag-handle drag-icon" v-if="product.variants.length > 1">
          <DynamicIcon name="ReorderDotsVertical"/>
        </span>
        <div class="fct-product-pricing-table-mobile-item-inner">
          <div class="fct-product-pricing-table-item">
          <div class="media relative">
            <div class="absolute top-0 left-0 w-full h-full z-10 rounded opacity-0">
              <attachments
                  :previewImage="false"
                  :showList=false
                  :multiple=true
                  :showDeleteButton="false"
                  :attachments="variant?.media"
                  @mediaUploaded="value => {productEditModel.onUploadPricingMedia('media', index, value)}"
              />
            </div>

            <div v-if="typeof variant?.media != 'undefined' && variant?.media?.length > 0" class="absolute top-0 left-0 w-full h-full z-0 rounded">
              <img :src='variant.media[0]?.url' :alt="variant.media[0]?.title" class="!object-contain rounded border border-solid border-gray-outline dark:border-dark-400"/>
            </div>
            <img v-else :src="appVars.asset_url + 'images/empty-image.svg'" alt="No Image"/>
          </div>

          <div class="fct-product-pricing-table-item-content">
            <div class="title">
              {{variant.variation_title}}
              <span v-if="variant.other_info?.installment === 'yes'" class="fct-variant-badge">
                {{variant.other_info.times}} {{translate('Installment')}}
              </span>
            </div>
            <ul>
              <li>
                <div class="compare-price" v-if="variant.compare_price">
                  <span v-html="appVars.shop.currency_sign"></span>
                  {{variant.compare_price}}
                </div>
                <div class="price">
                  <span v-html="appVars.shop.currency_sign"></span>
                  {{variant.item_price}}
                </div>
              </li>

              <li v-if="variant.other_info?.payment_type === 'subscription'" class="capitalize">
                {{variant.other_info.repeat_interval}}
              </li>
            </ul>
          </div>
        </div>



        <div class="fct-product-pricing-table-item-action">
          <ProductPricingActions
              :modeType="'action'"
              :index="index"
              :variant="variant"
              :product="product"
              :productEditModel="productEditModel"
          />
        </div>
        </div>
      </div>
      </VueDraggableNext>
    
    </div>

    <!-- mobile view -->

    <ProductPricingActions
      :modeType="'add'"
      :product="product"
      :productEditModel="productEditModel"
    />
  </div>

</template>


<style>
  .ghost {
    opacity: 0.5;
  }
  .sortable-chosen.ghost .drag-icon {
    cursor: grabbing !important;
  }
</style>
