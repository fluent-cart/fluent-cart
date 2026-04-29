<script setup>
import {onMounted, onUnmounted, computed, ref, nextTick} from "vue";
import ProductPricingActions from "./ProductPricingActions.vue";
import ProductPricingForm from "./ProductPricingForm.vue";
import BulkMediaPicker from "@/Bits/Components/Attachment/BulkMediaPicker.vue";
import { VueDraggableNext } from 'vue-draggable-next';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import Clipboard from "@/utils/Clipboard";
import VariantNavSummary from "@/Modules/Products/parts/VariantNavSummary.vue";
import VariantNavList from "@/Modules/Products/parts/VariantNavList.vue";
import VariantNavMobile from "@/Modules/Products/parts/VariantNavMobile.vue";
import PriceInput from "@/Bits/Components/Inputs/PriceInput.vue";

const props = defineProps({
  product: Object,
  productEditModel: Object,
})

const desktopDrawerWidth = 1040;
const viewportWidth = ref(typeof window !== 'undefined' ? window.innerWidth : desktopDrawerWidth);
const adminMenuWidth = ref(0);

const compactVariantNavThreshold = 1060;

const getAdminMenuWidth = () => {
  if (typeof window === 'undefined') {
    return 0;
  }

  const adminMenuWrap = document.querySelector('#adminmenuwrap');

  if (!adminMenuWrap) {
    return 0;
  }

  const adminMenuStyle = window.getComputedStyle(adminMenuWrap);

  if (adminMenuStyle.display === 'none' || adminMenuStyle.visibility === 'hidden') {
    return 0;
  }

  return Math.round(adminMenuWrap.getBoundingClientRect().width);
};

const syncViewportWidth = () => {
  if (typeof window === 'undefined') {
    return;
  }

  viewportWidth.value = window.innerWidth;
  adminMenuWidth.value = getAdminMenuWidth();
};

onMounted(() => {
  syncViewportWidth();
  window.addEventListener('resize', syncViewportWidth);

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

onUnmounted(() => {
  if (typeof window === 'undefined') {
    return;
  }

  window.removeEventListener('resize', syncViewportWidth);
});

const dragOptions = computed(() => {
  return {
    animation: 600,
    ghostClass: 'ghost'
  }
})

const showSharedDrawer = ref(false);
const drawerMode = ref('update');
const selectedVariantIndex = ref(null);
const pricingFormRef = ref(null);
const showDirtyDraftDialog = ref(false);
const dirtyDialogSubmitting = ref(false);
const pendingDirtyAction = ref(null);
const isCurrentVariationDirty = ref(false);

const usableViewportWidth = computed(() => {
  return Math.max(viewportWidth.value - adminMenuWidth.value, 360);
});

const useCompactVariantNav = computed(() => {
  return usableViewportWidth.value <= compactVariantNavThreshold;
});

const sharedDrawerSize = computed(() => {
  let drawerGutter = 24;

  if (viewportWidth.value <= 640) {
    drawerGutter = 12;
  } else if (viewportWidth.value <= 900) {
    drawerGutter = 16;
  }

  const maxDrawerWidth = Math.max(usableViewportWidth.value - drawerGutter, 320);
  return `${Math.min(desktopDrawerWidth, maxDrawerWidth)}px`;
});

const getVariantDisplayTitle = (variant, index) => {
  return variant?.variation_title || `${translate('Variation')} ${index + 1}`;
};

const formatVariantPrice = (variant) => {
  const price = variant?.item_price ?? '';
  if (price === '' || price === null || price === undefined) {
    return translate('No price set');
  }

  return `${window.appVars?.shop?.currency_sign || '$'} ${price}`;
};

const resolveEditorIndex = (index) => {
  if (typeof index === 'number' && index >= 0 && index < props.product.variants.length) {
    return index;
  }

  return props.product.variants.length ? 0 : null;
};

const openSharedDrawer = ({modeType = 'update', index = null} = {}) => {
  drawerMode.value = modeType;
  selectedVariantIndex.value = modeType === 'create' ? null : resolveEditorIndex(index);
  isCurrentVariationDirty.value = false;

  props.productEditModel.setValidationErrors({});
  showSharedDrawer.value = true;
};

const closeSharedDrawer = () => {
  isCurrentVariationDirty.value = false;
  props.productEditModel.setValidationErrors({});
  showSharedDrawer.value = false;
};

const resetDirtyDialog = () => {
  showDirtyDraftDialog.value = false;
  dirtyDialogSubmitting.value = false;
  pendingDirtyAction.value = null;
};

const hasDirtyDrafts = () => {
  return pricingFormRef.value?.hasDirtyDrafts?.() ?? false;
};

const applyVariationSelection = (item) => {
  if (!item) {
    return;
  }

  drawerMode.value = item.modeType;
  selectedVariantIndex.value = item.index;
  props.productEditModel.setValidationErrors({});
};

const handleVariantNavCommand = async (command, item) => {
  if (!item || !command) {
    return;
  }

  if (command === 'duplicate') {
    openSharedDrawer({
      modeType: 'duplicate',
      index: item.index
    });
    return;
  }

  if (command === 'delete' && item.variant?.id) {
    props.productEditModel.deletePricing(item.variant.id, item.index)
      .then(async () => {
        await nextTick();
        if (props.product.variants.length > 0) {
          // Reset to update mode on a real remaining variant
          drawerMode.value = 'update';
          // Select nearest valid index (previous or first)
          selectedVariantIndex.value = Math.min(item.index, props.product.variants.length - 1);
          isCurrentVariationDirty.value = false;
          pricingFormRef.value?.loadVariantFromProps?.();
        } else {
          closeSharedDrawer();
        }
      })
      .catch(() => {
        // User cancelled or API failed — no state change
      });
    return;
  }

  if (command === 'copy_variation_id' && item.variant?.id) {
    Clipboard.copy(String(item.variant.id), {
      successMessage: translate('Variation ID copied to clipboard')
    });
    return;
  }

  if (command === 'copy_direct_checkout' && canCopyDirectCheckout(item)) {
    Clipboard.copy(getDirectCheckoutUrl(item), {
      successMessage: translate('Direct checkout link copied to clipboard')
    });
  }
};

const canCopyDirectCheckout = (item) => {
  return !!item?.variant?.id && ['publish', 'private'].includes(props.product?.post_status);
};

const getDirectCheckoutUrl = (item) => {
  return `${AppConfig.get('frontend_url')}=instant_checkout&item_id=${item.variant.id}&quantity=1`;
};

const executePendingDirtyAction = () => {
  const action = pendingDirtyAction.value;
  resetDirtyDialog();

  if (!action) {
    return;
  }

  if (action.type === 'close') {
    if (typeof action.done === 'function') {
      action.done();
      return;
    }

    closeSharedDrawer();
  }
};

const handleContinueEditing = () => {
  resetDirtyDialog();
};

const handleDiscardDirtyDrafts = async () => {
  await pricingFormRef.value?.discardDrafts?.();
  executePendingDirtyAction();
};

const handleSaveDirtyDraft = async () => {
  if (!pricingFormRef.value?.saveCurrentDraft || dirtyDialogSubmitting.value) {
    return;
  }

  dirtyDialogSubmitting.value = true;
  const result = await pricingFormRef.value.saveCurrentDraft();

  if (result) {
    executePendingDirtyAction();
    return;
  }

  dirtyDialogSubmitting.value = false;
  handleContinueEditing();
};

const handleSharedDrawerBeforeClose = (done) => {
  if (!hasDirtyDrafts()) {
    done();
    return;
  }

  pendingDirtyAction.value = {
    type: 'close',
    done
  };
  showDirtyDraftDialog.value = true;
};

const requestCloseSharedDrawer = () => {
  handleSharedDrawerBeforeClose(() => {
    closeSharedDrawer();
  });
};

const handleDirtyStateChange = (isDirty) => {
  isCurrentVariationDirty.value = isDirty;
};

const handleVariantSaved = (payload) => {
  if (!payload) {
    return;
  }

  drawerMode.value = 'update';

  if (typeof payload.index === 'number' && payload.index >= 0) {
    selectedVariantIndex.value = payload.index;
  } else {
    selectedVariantIndex.value = resolveEditorIndex(selectedVariantIndex.value);
  }

  isCurrentVariationDirty.value = false;
  props.productEditModel.setValidationErrors({});
};

const getDuplicateDraftLabel = (variant, index) => {
  const sourceLabel = getVariantDisplayTitle(variant, index);
  return `${translate('Copy of')} ${sourceLabel}`;
};

const variationNavItems = computed(() => {
  const items = (props.product.variants || []).map((variant, index) => ({
    key: variant?.id ?? `variant-${index}`,
    index,
    variant,
    label: getVariantDisplayTitle(variant, index),
    meta: formatVariantPrice(variant),
    modeType: 'update',
    isDraft: false
  }));

  if (showSharedDrawer.value && drawerMode.value === 'create') {
    items.push({
      key: 'draft-variation',
      index: null,
      label: translate('New variation'),
      meta: translate('Unsaved draft'),
      modeType: 'create',
      isDraft: true
    });
  }

  if (showSharedDrawer.value && drawerMode.value === 'duplicate') {
    const sourceVariant = props.product.variants?.[selectedVariantIndex.value];

    items.push({
      key: 'duplicate-variation',
      index: selectedVariantIndex.value,
      variant: sourceVariant,
      label: getDuplicateDraftLabel(sourceVariant, selectedVariantIndex.value ?? 0),
      meta: translate('Unsaved copy'),
      modeType: 'duplicate',
      isDraft: true
    });
  }

  return items;
});

const activeVariationKey = computed(() => {
  if (drawerMode.value === 'create' && selectedVariantIndex.value === null) {
    return 'draft-variation';
  }

  if (drawerMode.value === 'duplicate') {
    return 'duplicate-variation';
  }

  const selectedVariant = props.product.variants?.[selectedVariantIndex.value];
  return selectedVariant?.id ?? `variant-${selectedVariantIndex.value ?? 0}`;
});

const selectVariation = (item) => {
  if (!item) {
    return;
  }

  if (isCurrentVariationDirty.value && activeVariationKey.value !== item.key) {
    return;
  }

  applyVariationSelection(item);
};
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
              <BulkMediaPicker
                v-model="variant.media"
                :compact="true"
                :max-thumbs="1"
                @change="value => productEditModel.onUploadPricingMedia('media', index, value)"
              />
            </td>
            <td>
              <div class="fct-product-pricing-table-item">
                <el-input
                    :class="productEditModel.hasValidationError(`variants.${index}.variation_title`) ? 'is-error' : ''"
                    :id="`variants.${index}.variation_title`"
                    :placeholder="$t('e.g. Small, Medium, Large')" type="text" v-model="variant.variation_title" @input="value => {productEditModel.onChangePricing('variation_title', index,value)}" :disabled="product?.detail?.variation_type === 'simple'"
                    @focus="productEditModel.clearValidationError(`variants.${index}.variation_title`)">
                </el-input>

                <span v-if="variant.other_info?.installment === 'yes'" class="fct-variant-badge">
                  {{variant.other_info.times}} {{translate('Installment')}}
                </span>

              </div>
            </td>
            <td>
              <div class="fct-product-pricing-table-item">
                <PriceInput
                    :placeholder="$t('Price')"
                    :error-class="productEditModel.hasValidationError(`variants.${index}.item_price`) ? 'is-error' : ''"
                    :id="`variants.${index}.item_price`"
                    :model-value="variant.item_price"
                    :disabled="variant.expanded"
                    @update:model-value="value => {
                        variant.item_price = value;
                        productEditModel.onChangePricing('item_price', index, value);
                        productEditModel.clearValidationError(`variants.${index}.item_price`);
                    }"
                />

                <span v-if="variant.other_info?.payment_type === 'subscription'" class="fct-variant-badge">
                  {{variant.other_info.repeat_interval}}
                </span>
              </div>
            </td>
            <td>
              <div class="fct-product-pricing-table-item">
                <PriceInput
                    :placeholder="$t('Compare price')"
                    :error-class="productEditModel.hasValidationError(`variants.${index}.compare_price`) ? 'is-error' : ''"
                    :id="`variants.${index}.compare_price`"
                    :model-value="variant.compare_price"
                    :disabled="variant.expanded"
                    @update:model-value="value => {
                        variant.compare_price = value;
                        productEditModel.onChangePricing('compare_price', index, value);
                        productEditModel.clearValidationError(`variants.${index}.compare_price`);
                    }"
                />
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
                  @open-editor="openSharedDrawer"
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
          <div class="media">
            <BulkMediaPicker
              v-model="variant.media"
              :compact="true"
              :max-thumbs="1"
              @change="value => productEditModel.onUploadPricingMedia('media', index, value)"
            />
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
                  <span>{{ appVars.shop.currency_sign }}</span>
                  {{variant.compare_price}}
                </div>
                <div class="price">
                  <span>{{ appVars.shop.currency_sign }}</span>
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
              @open-editor="openSharedDrawer"
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
      @open-editor="openSharedDrawer"
    />

    <el-drawer
      v-model="showSharedDrawer"
      :title="$t('Pricing')"
      :size="sharedDrawerSize"
      :append-to-body="true"
      :close-on-click-modal="true"
      :close-on-press-escape="false"
      :destroy-on-close="true"
      :before-close="handleSharedDrawerBeforeClose"
      class="fct-shared-variant-drawer"
      @close="closeSharedDrawer"
    >
      <div class="fct-shared-variant-drawer__layout">
        <aside v-if="!useCompactVariantNav" class="fct-shared-variant-nav">
          <!-- Summary -->
          <VariantNavSummary :product="product" />

          <!-- Variant list -->
          <VariantNavList
            :items="variationNavItems"
            :active-key="activeVariationKey"
            :is-dirty="isCurrentVariationDirty"
            :product="product"
            :product-edit-model="productEditModel"
            :can-copy-direct-checkout="canCopyDirectCheckout"
            :is-saving="dirtyDialogSubmitting"
            @select="selectVariation"
            @command="handleVariantNavCommand"
            @save="handleSaveDirtyDraft"
          />
        </aside>

        <div class="fct-shared-variant-drawer__content">
            <!-- Mobile variant nav -->
            <VariantNavMobile
                v-if="useCompactVariantNav"
                :items="variationNavItems"
                :active-key="activeVariationKey"
                :is-dirty="isCurrentVariationDirty"
                @select="selectVariation"
            />

            <!-- Pricing form -->
            <div class="fct-shared-variant-drawer__form">
                <ProductPricingForm
                    ref="pricingFormRef"
                    :index="selectedVariantIndex"
                    :modeType="drawerMode"
                    :fieldKey="'variants'"
                    :product="product"
                    :productEditModel="productEditModel"
                    @createOrUpdateVariant="handleVariantSaved"
                    @closeModal="requestCloseSharedDrawer"
                    @dirtyStateChange="handleDirtyStateChange"
                />
            </div>
        </div>
      </div>
    </el-drawer>

    <el-dialog
      v-model="showDirtyDraftDialog"
      :close-on-click-modal="false"
      :close-on-press-escape="!dirtyDialogSubmitting"
      :show-close="!dirtyDialogSubmitting"
      class="fct-variant-dirty-dialog"
      @close="handleContinueEditing"
    >
      <template #header>
        <div class="fct-variant-dirty-dialog__header">
          <h3>{{ translate('Unsaved changes') }}</h3>
        </div>
      </template>

       <p>
            {{ translate('You have unsaved variation changes. Discard them or save before continuing.') }}
        </p>

      <div class="dialog-footer">
        <div class="fct-btn-group sm">
            <el-button 
                :disabled="dirtyDialogSubmitting" 
                @click="handleContinueEditing" 
                size="small"
            >
            {{ translate('Continue editing') }}
            </el-button>

            <el-button 
                :disabled="dirtyDialogSubmitting" 
                @click="handleDiscardDirtyDrafts" 
                size="small"
            >
            {{ translate('Discard') }}
            </el-button>

            <el-button 
                type="primary" 
                :loading="dirtyDialogSubmitting" 
                @click="handleSaveDirtyDraft" 
                size="small"
            >
            {{ translate('Save') }}
            </el-button>
        </div>
      </div>
    </el-dialog>
  </div>

</template>


