<script setup>
import {computed, nextTick, onUnmounted, ref, watch} from "vue";
import VariantTitleMedia from "@/Modules/Products/parts/VariantTitleMedia.vue";
import VariantPrice from "@/Modules/Products/parts/VariantPrice.vue";
import VariantInventory from "@/Modules/Products/parts/VariantInventory.vue";
import ProductShipping from "@/Modules/Products/parts/ProductShipping.vue";
import translate from "@/utils/translator/Translator";
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";
import AppConfig from "@/utils/Config/AppConfig";

const props = defineProps({
  modeType: String,
  index: Number,
  fieldKey: String,
  product: Object,
  productEditModel: Object,
})
const variant = ref({});
const emit = defineEmits(['createOrUpdateVariant', 'closeModal', 'dirtyStateChange']);
const keyboardShortcuts = useKeyboardShortcuts();
const changes_made = ref(0);
const isLoadingVariant = ref(false);
const variantDrafts = ref({});
const currentDraftKey = ref(null);
const skipDraftPersistOnce = ref(false);

const cloneVariant = (sourceVariant) => {
  if (!sourceVariant) {
    return {};
  }

  return JSON.parse(JSON.stringify(sourceVariant));
};

const getVariantDraftKey = (modeType = props.modeType, index = props.index) => {
  if (modeType === 'create') {
    return 'create';
  }

  if (modeType === 'duplicate') {
    const sourceVariant = props.product.variants?.[index];
    return `duplicate-${sourceVariant?.id ?? index ?? 'draft'}`;
  }

  if (props.product.detail?.variation_type === 'simple') {
    return 'simple-0';
  }

  const sourceVariant = props.product.variants?.[index];
  return `update-${sourceVariant?.id ?? index ?? 'draft'}`;
};

const buildDraftEntry = (modeType = props.modeType, index = props.index, draftVariant = variant.value) => {
  return {
    modeType,
    index,
    variant: cloneVariant(draftVariant)
  };
};

const persistCurrentDraft = () => {
  if (skipDraftPersistOnce.value) {
    skipDraftPersistOnce.value = false;
    return;
  }

  if (!currentDraftKey.value || isLoadingVariant.value || !changes_made.value) {
    return;
  }

  variantDrafts.value[currentDraftKey.value] = buildDraftEntry();
};

const syncVariantDefaults = () => {
  if (!variant.value?.other_info) {
    return;
  }

  if (!variant.value.other_info.payment_type) {
    variant.value.other_info.payment_type = 'onetime';
  }

  if (!variant.value.other_info.weight_unit) {
    variant.value.other_info.weight_unit = weightUnit.value;
  }

  if (variant.value.other_info.package_slug === undefined) {
    variant.value.other_info.package_slug = '';
  }
};

const loadVariantFromProps = async () => {
  persistCurrentDraft();

  isLoadingVariant.value = true;
  changes_made.value = 0;
  currentDraftKey.value = getVariantDraftKey();

  const savedDraft = variantDrafts.value[currentDraftKey.value]?.variant;

  if (savedDraft) {
    variant.value = cloneVariant(savedDraft);
    syncVariantDefaults();
    await nextTick();
    changes_made.value = 1;
    isLoadingVariant.value = false;
    return;
  }

  if (props.product.detail?.variation_type === 'simple') {
    if (Array.isArray(props.product.variants) && props.product.variants.length === 0) {
      variant.value = props.productEditModel.addDummyVariant();
      variant.value.variation_title = props.product.post_title;
    } else {
      variant.value = props.product.variants[0];
    }
  } else if (props.modeType === 'create') {
    variant.value = props.productEditModel.addDummyVariant();
  } else if (props.modeType === 'update') {
    variant.value = cloneVariant(props.product.variants?.[props.index]);
  } else if (props.modeType === 'duplicate') {
    variant.value = cloneVariant(props.product.variants?.[props.index]);
    variant.value.serial_index = props.productEditModel.variantsLength() + 1;
    delete variant.value.id;
  } else {
    variant.value = {};
  }

  syncVariantDefaults();

  await nextTick();
  changes_made.value = 0;
  isLoadingVariant.value = false;
};

const resolveDraftSaveIndex = (draftEntry) => {
  if (props.product.detail?.variation_type === 'simple') {
    return 0;
  }

  if (draftEntry.modeType === 'update') {
    const variantId = draftEntry.variant?.id;

    if (variantId) {
      const matchedIndex = props.product.variants.findIndex(item => item?.id === variantId);

      if (matchedIndex !== -1) {
        return matchedIndex;
      }
    }

    return draftEntry.index;
  }

  return null;
};

const saveDraftEntry = async (draftEntry) => {
  const draftVariant = cloneVariant(draftEntry.variant);
  let saveIndex = resolveDraftSaveIndex(draftEntry);

  if (draftEntry.modeType === 'duplicate') {
    saveIndex = props.productEditModel.variantsLength();
    draftVariant.rowId = (draftVariant.rowId ?? saveIndex) + 1;
  }

  const result = await props.productEditModel.createOrUpdatePricing(draftVariant);

  if (!result?.data?.id) {
    return {
      success: false,
      draft: draftEntry
    };
  }

  draftVariant.id = result.data.id;
  props.productEditModel.afterCreatingOrUpdatingPricing(saveIndex, draftVariant);

  return {
    success: true,
    variant: draftVariant,
    index: saveIndex
  };
};

const saveCurrentVariant = async () => {
  try {
    let currentIndex = props.index;
    const currentVariant = variant.value;

    if (props.modeType === 'duplicate') {
      currentIndex = props.productEditModel.variantsLength();
      currentVariant.rowId = currentVariant.rowId + 1;
    }

    let result = await props.productEditModel.createOrUpdatePricing(currentVariant);
    if (!result?.data?.id) {
      return null;
    }

    currentVariant.id = result.data.id;
    props.productEditModel.afterCreatingOrUpdatingPricing(currentIndex, currentVariant)

    if (currentIndex === undefined || currentIndex === null) {
      currentIndex = props.product.variants.findIndex(item => item?.id === currentVariant.id);

      if (currentIndex === -1) {
        currentIndex = props.product.variants.length - 1;
      }
    }

    if (currentDraftKey.value) {
      delete variantDrafts.value[currentDraftKey.value];
    }

    changes_made.value = 0;
    emit('createOrUpdateVariant', {
      index: currentIndex,
      modeType: props.modeType,
      variant: cloneVariant(currentVariant)
    });

    return {
      index: currentIndex,
      modeType: props.modeType,
      variant: cloneVariant(currentVariant)
    };
  } catch (error) {
    return null;
  }
};

const handleVariantSave = async () => {
  return saveCurrentVariant();
};

const weightUnit = computed(() => AppConfig.get('shop.weight_unit') || 'kg');


const getDirtyDraftEntries = () => {
  const draftEntries = {...variantDrafts.value};

  if (currentDraftKey.value && changes_made.value) {
    draftEntries[currentDraftKey.value] = buildDraftEntry();
  }

  return Object.entries(draftEntries);
};

const hasDirtyDrafts = () => {
  return getDirtyDraftEntries().length > 0;
};

const getDirtyDraftCount = () => {
  return getDirtyDraftEntries().length;
};

const dirtyDraftCount = computed(() => getDirtyDraftEntries().length);

const discardDrafts = async () => {
  skipDraftPersistOnce.value = true;
  variantDrafts.value = {};
  changes_made.value = 0;
  props.productEditModel.setValidationErrors({});
  await loadVariantFromProps();
};

watch(variant, () => {
  if (isLoadingVariant.value) {
    return;
  }

  changes_made.value++;
}, {deep: true})

watch(
  () => [props.modeType, props.index],
  () => {
    loadVariantFromProps();
  },
  {immediate: true}
);

watch(
  dirtyDraftCount,
  (count) => {
    emit('dirtyStateChange', !!count);
  },
  {immediate: true}
);

keyboardShortcuts.bind(['mod+s'], (event) => {
  event.preventDefault();
  if (changes_made.value) {
    handleVariantSave()
  }
});

onUnmounted(() => {
  keyboardShortcuts.unbind('mod+s');
});

defineExpose({
  hasDirtyDrafts,
  getDirtyDraftCount,
  discardDrafts,
  saveCurrentDraft: saveCurrentVariant,
  loadVariantFromProps
});

</script>

<template>
  <div class="fct-product-pricing-form-wrap">
    <div class="fct-product-pricing-form-inner">
        <el-form 
            label-position="top"
            require-asterisk-position="right"
        >
        <!-- Media -->
        <VariantTitleMedia
            :variant="variant"
            :field-key="props.fieldKey"
            :mode-type="modeType"
            :product="product"
            :product-edit-model="productEditModel"
        />

        <!-- Price -->
        <VariantPrice
            :variant="variant"
            :field-key="props.fieldKey"
            :mode-type="modeType"
            :product="product"
            :product-edit-model="productEditModel"
        />

        <!-- Inventory -->
        <VariantInventory
            :variant="variant"
            :field-key="props.fieldKey"
            :mode-type="modeType"
            :product="product"
            :product-edit-model="productEditModel"
        />

        <!-- Package & Product Weight (per variation, stored in other_info) -->
        <ProductShipping
            :variant="variant"
            :field-key="props.fieldKey"
            :mode-type="modeType"
            :product-edit-model="productEditModel"
        />

        </el-form>
    </div>

    <div class="dialog-footer" v-if="modeType !=='add'">
      <div class="fct-btn-group sm">
        <el-button
          v-if="dirtyDraftCount"
          @click="discardDrafts"
      >
        {{ translate('Discard') }}
      </el-button>

      <el-button @click="(() => {
        emit('closeModal')
      })">
        {{ translate('Cancel') }}
      </el-button>
      
      <el-button
          :disabled="productEditModel.saving"
          @click="handleVariantSave"
          type="primary"
      >
        {{ typeof variant.id === 'undefined' ? translate('Save') : translate('Update') }}
      </el-button>
      </div>
    </div>
  </div>
</template>

