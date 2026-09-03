<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import {ref} from "vue";
import ProductPricingForm from './VariantForm/ProductPricingForm.vue';
import ProductPricingTable from './ProductPricingTable.vue';
import AdvancedVariationConfig from './AdvancedVariationConfig.vue';
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
  const currentType = props.product.detail.variation_type;
  const variationLength = props.product.variants?.length ?? 0;

  // A product on Advanced Variations cannot be switched back to Simple /
  // Simple Variations — the type dropdown is disabled for advanced products
  // (and the backend rejects the change), so this handler never runs for an
  // advanced-source switch.

  // Switching to Advanced Variations is destructive and irreversible from any
  // source type: the current variant(s) are discarded (replaced once the
  // merchant generates attribute-based combinations) and Advanced Variations is
  // terminal — there is no path back to Simple or Simple Variations. Gate every
  // non-advanced source (both 'simple' and 'simple_variations') behind the same
  // type-to-confirm as the Simple collapse below.
  if (newType === 'advanced_variations' && currentType !== 'advanced_variations') {
    pendingChangeType.value = newType;
    selectedVariationType.value = currentType;

    Confirmation.confirmDeleteWithInput(
        'proceed',
        translate("Switching to Advanced Variations will delete all current variations for this product and cannot be undone. You won't be able to switch back to Simple Variations."),
    ).then(() => {
      confirmChange();
    }).catch(() => {
      if (!pendingChangeType.value) {
        pendingChangeType.value = null;
      }
    });
    return;
  }

  // Switching from Simple Variations to Simple collapses all variants to one
  // and, since a Simple product has no editor control to view or clear a
  // variation image, permanently removes the image on the surviving variant
  // too (see ProductDetailResource::update()). Warn whenever either is about
  // to happen: more than one variation exists, or the surviving (first)
  // variation already has an image attached.
  const firstVariant = props.product.variants?.[0];
  const firstVariantMedia = firstVariant?.media;
  const firstVariantHasImage = Array.isArray(firstVariantMedia)
      ? firstVariantMedia.length > 0
      : Array.isArray(firstVariantMedia?.meta_value) && firstVariantMedia.meta_value.length > 0;

  if (newType === 'simple'
      && currentType === 'simple_variations'
      && (variationLength > 1 || firstVariantHasImage)
  ) {
    pendingChangeType.value = newType;
    selectedVariationType.value = currentType;

    const warningMessage = variationLength > 1
        ? translate("Switching to 'Simple' will permanently delete all variations except the first one, along with any image attached to that variation.")
        : translate("Switching to 'Simple' will permanently delete the image attached to this variation.");

    Confirmation.confirmDeleteWithInput(
        'proceed',
        warningMessage,
    ).then(() => {
      confirmChange();
    }).catch(() => {
      if (!pendingChangeType.value) {
        pendingChangeType.value = null;
      }
    });
  } else {
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
  // Capture the type the server currently has BEFORE the model mutates it, so a
  // rejected switch can roll the dropdown back. Read from detail (not
  // selectedVariationType) because el-select has already pushed newType into
  // selectedVariationType via v-model by the time we get here.
  const previousType = props.product.detail.variation_type;
  selectedVariationType.value = newType;
  const req = props.productEditModel.updateVariationType('variation_type', newType);
  // The model rolls product.detail back in its own catch; here we roll the
  // select value back to match so the dropdown options re-enable.
  Promise.resolve(req).catch(() => {
    selectedVariationType.value = previousType;
  });
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
              <!-- Once the product is on Advanced Variations, every other type is
                   disabled: switching back to Simple / Simple Variations is not
                   allowed (advanced is terminal; the backend rejects it too). -->
              <el-option
                  v-for="(label, value) in appVars.variation_types"
                  :key="value"
                  :label="label"
                  :value="value"
                  :disabled="product?.detail?.variation_type === 'advanced_variations' && value !== 'advanced_variations'"
              />
            </el-select>
          </div>
        </template>
      </Card.Header>
      <Card.Body :class="product?.detail?.variation_type === 'simple_variations' ? 'pb-0' : ''">
        <ProductPricingForm
            v-if="product?.detail?.variation_type === 'simple'"
            class="simple-product-pricing"
            :index="0"
            :modeType="'add'"
            :fieldKey="'variants.0'"
            :product="product"
            :productEditModel="productEditModel"
        >
        </ProductPricingForm>

        <div
            v-if="product?.detail?.variation_type === 'simple' && product?.variants[0]?.id"
            class="fct-simple-product-actions"
        >
            <CopyToClipboard
                class="fct-copy-wrap-inline"
                :text="String(product.variants[0].id)"
                showMode="icon_with_text"
                :buttonText="$t('Copy Variation ID')"
            />

            <CopyToClipboard
                class="fct-copy-wrap-inline mt-3"
                v-if="product?.detail?.variation_type === 'simple' && (product?.post_status === 'publish' || product?.post_status === 'private') && product?.variants[0]?.id"
                :text="appVars?.frontend_url +'=instant_checkout&item_id=' + product.variants[0].id + '&quantity=1'"
                showMode="icon_with_text"
                placement="top"
                :buttonText="$t('Direct Checkout')"
                :tooltipContent="$t('Share direct checkout link to let customers buy this variation directly.')"
            />
        </div>

        <ProductPricingTable
            v-if="product?.detail?.variation_type === 'simple_variations'"
            :product="product"
            :productEditModel="productEditModel"
        />

        <AdvancedVariationConfig
            v-if="product?.detail?.variation_type === 'advanced_variations'"
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
