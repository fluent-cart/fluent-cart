<script setup>
import {onMounted, ref} from "vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ProductPricingForm from "./ProductPricingForm.vue";
import {CopyDocument} from '@element-plus/icons-vue'
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";

const props = defineProps({
  modeType: String,
  index: Number,
  variant: {
    type: Object,
    default: {}
  },
  product: Object,
  productEditModel: Object,
})

const showModal = ref(false)
let index = ref(null)
const minimumPricingCount = ref(1);
const modeType = ref('');
const showLinkCopyModal = ref(false);

onMounted(() => {
  index.value = props.index;
})

/**
 * Handles commands triggered from the dropdown menu.
 *
 * @param {string} command - The command identifier indicating which action to perform.
 */
const actionMenuHandler = (command) => {
  if (command === 'duplicate_pricing') {
    // Call the function to duplicate pricing when the command is 'duplicate_pricing'.
    index.value = null;
    modeType.value = 'duplicate';
    showModal.value = true
  } else if (command === 'delete_variant') {
    // Call the function to delete a variant when the command is 'delete_variant'.
    modeType.value = 'delete';
    props.productEditModel.deletePricing(props.variant.id, props.index);
  }
}
</script>

<template>
  <div class="fct-product-button-and-drawer-wrap">
    <div v-if="props.modeType == 'add'" class="fct-product-add-more-price-wrap text-right pr-6">
      <el-button
        v-if="product.detail.variation_type !== 'simple'"
        text type="primary"
        @click="()=>{
          modeType = 'create'
          showModal = true;
        }"
      >
        <DynamicIcon name="Plus"/>
        {{ product.variants.length > 0 ? $t('Add more') : $t('Add Pricing') }}
      </el-button>
    </div><!-- .fct-product-add-more-price-wrap -->

    <div class="fct-btn-group sm" v-if="props.modeType == 'action'">
      <IconButton class="hide-on-mobile" size="small" tag="button" @click="()=>{
          modeType = 'update';
          showModal = true;
      }">
        <DynamicIcon name="Edit"/>
      </IconButton>

      <el-dropdown class="fct-more-option-wrap" popper-class="fct-dropdown" @command="actionMenuHandler" trigger="click">
        <span class="more-btn">
          <DynamicIcon name="More"/>
        </span>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item class="item-checkbox"
              v-if="product.detail && product.detail.manage_stock == '1'">
              <el-checkbox v-model="variant.manage_stock" @change="(value) => {
                productEditModel.onChangePricing('manage_stock', index, value)
              }" true-value="0" false-value="1">
                {{ $t('Skip inventory') }}
              </el-checkbox>
            </el-dropdown-item>

            <el-dropdown-item class="show-on-mobile" @click="()=>{
                modeType = 'update';
                showModal = true;
            }">
               <DynamicIcon name="Edit"/>
              {{ $t('Edit') }}
            </el-dropdown-item>
            
            <el-dropdown-item command="duplicate_pricing"
              v-if="product.detail && product.detail.variation_type !== 'simple'">
              <DynamicIcon name="Duplicate"/>
              {{ $t('Duplicate') }}
            </el-dropdown-item>

            <el-dropdown-item :disabled="product.post_status !== 'publish' && variant.id != ''">
              <CopyToClipboard
                  v-if="product.post_status === 'publish' && variant.id"
                  class="fct-copy-wrap-inline"
                  :text="appVars?.frontend_url +'=instant_checkout&item_id=' + variant.id + '&quantity=1'"
                  showMode="icon_with_text"
                  :buttonText="$t('Direct Checkout')"
                  :tooltipContent="$t('Share direct checkout link to let customers buy this variation directly.')"
              />
              <template v-else>
                <DynamicIcon name="Copy" />
                {{$t('Direct Checkout')}}
                <LabelHint :content="$t('This product is currently in draft. You can\'t share direct checkout link')"></LabelHint>
              </template>
            </el-dropdown-item>

            <el-dropdown-item command="delete_variant"
              v-if="productEditModel.variantsLength() > minimumPricingCount"
              class="item-destructive">
              <DynamicIcon name="Delete"/>
              {{ $t('Delete') }}
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>

    <el-drawer
        :show-close="true"
        v-model="showModal"
        :title="$t('Pricing')"
        size="500px"
        :append-to-body="true"
        :close-on-click-modal="true"
        :close-on-press-escape="false"
        :destroy-on-close="true"
        @before-close="() => {}"
        class="fct-variant-drawer"
    >
      <ProductPricingForm
        :index="props.index"
        :modeType="modeType"
        :fieldKey="'variants'"
        :product="product"
        :productEditModel="productEditModel"
        @createOrUpdateVariant="showModal = false"
        @closeModal="(() => {
          productEditModel.setValidationErrors({})
          showModal = false
        })">
      </ProductPricingForm>
    </el-drawer>
  </div>
</template>
