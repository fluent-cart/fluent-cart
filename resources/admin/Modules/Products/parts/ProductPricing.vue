<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import {ref} from "vue";
import ProductPricingForm from './ProductPricingForm.vue';
import ProductPricingTable from './ProductPricingTable.vue';
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import translate from "@/utils/translator/Translator";
import Confirmation from "@/utils/Confirmation";

const props = defineProps({
  product: Object,
  productEditModel: Object,
  productDownloadableModel: Object
})

const showLinkCopyModal = ref(false);
const selectedVariationType = ref(props.product?.detail?.variation_type);
const pendingChangeType = ref(null);

const handleVariationChange = (newType) => {

  const variationLength = props.product.variants?.length ?? 0;
  // Show confirmation only if switching from "Simple Variations" to "Simple"
  if (newType === 'simple'
      && props.product.detail.variation_type === 'simple_variations'
      && variationLength > 1
  ) {
    // Store the intended value
    pendingChangeType.value = newType;

    // Temporarily revert to the current value
    selectedVariationType.value = props.product.detail.variation_type;


    Confirmation.confirmDeleteWithInput(
        'proceed',
        translate("Switching to 'Simple' will permanently delete all variations except the first one."),
    ).then(() => {
      confirmChange();
    }).catch(() => {
      // Reset pendingChangeType only if needed
      if (!pendingChangeType.value) {
        pendingChangeType.value = null;
      }
    });
  } else {
    // Directly update if no confirmation is required
    updateVariationType(newType);
  }
};

const confirmChange = () => {
  if (pendingChangeType.value) {
    updateVariationType(pendingChangeType.value);
  }
  pendingChangeType.value = null;
};

const updateVariationType = (newType) => {
  selectedVariationType.value = newType;
  props.productEditModel.updateVariationType('variation_type', newType);
};
</script>

<template>
  <div class="fct-product-pricing-wrap">
    <Card.Container :class="product?.detail?.variation_type === 'simple_variations' ? 'overflow-hidden' : ''">
      <Card.Header :title="$t('Pricing')" border_bottom>
        <template #action>
          <div class="w-[150px] fct-select-gray">
            <el-select
                size="small"
                :class="productEditModel.hasValidationError('detail.variation_type') ? 'is-error' : ''"
                v-if="product.detail"
                v-model="selectedVariationType"
                :placeholder="$t('Select Variation')"
                @change="handleVariationChange"
            >
              <el-option :label="$t('Simple')" value="simple"/>
              <el-option :label="$t('Simple Variations')" value="simple_variations"/>
            </el-select>
          </div>
        </template>
      </Card.Header>
      <Card.Body :class="product?.detail?.variation_type === 'simple_variations' ? 'pb-0' : ''">
        <ProductPricingForm
            v-if="product?.detail?.variation_type === 'simple'"
            :index="0"
            :modeType="'add'"
            :fieldKey="'variants.0'"
            :product="product"
            :productEditModel="productEditModel"
        >
        </ProductPricingForm>

        <CopyToClipboard
            class="fct-copy-wrap-inline"
            v-if="product?.detail?.variation_type === 'simple' && product?.post_status === 'publish' && product?.variants[0]?.id"
            :text="appVars?.frontend_url +'=instant_checkout&item_id=' + product.variants[0].id + '&quantity=1'"
            showMode="icon_with_text"
            placement="top"
            :buttonText="$t('Direct Checkout')"
            :tooltipContent="$t('Share direct checkout link to let customers buy this variation directly.')"
        />

        <ProductPricingTable
            v-if="product?.detail?.variation_type === 'simple_variations'"
            :product="product"
            :productEditModel="productEditModel"
        />
      </Card.Body>
    </Card.Container>

    <!-- Copy direct checkout link modal for simple product -->
    <!--    <el-dialog-->
    <!--      v-model="showLinkCopyModal"-->
    <!--      :title="$t('Copy Direct Checkout Link')"-->
    <!--    >-->
    <!--      <div class="fct-payment-link-block">-->
    <!--        <CopyToClipboard :text="appVars?.checkout_url+product.variants[0].id" tooltipText="Copy Link" placeholder="No payment link available, please generate one!"/>-->
    <!--      </div>-->
    <!--    </el-dialog>-->
  </div>
</template>
