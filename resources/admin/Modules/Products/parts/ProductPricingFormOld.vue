<script setup>
import {computed, nextTick, onUnmounted, ref, watch} from "vue";
import BulkMediaPicker from "@/Bits/Components/Attachment/BulkMediaPicker.vue";
import {getMargin, getProfit} from "@/Bits//productService";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import Animation from "@/Bits/Components/Animation.vue";
import translate from "@/utils/translator/Translator";
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";
import AppConfig from "@/utils/Config/AppConfig";
import Rest from "@/utils/http/Rest";
import {ElMessage} from "element-plus";
import PackageDialog from "@/Modules/Shipping/Components/PackageDialog.vue";

const props = defineProps({
  modeType: String,
  index: Number,
  fieldKey: String,
  product: Object,
  productEditModel: Object,
})
const variant = ref({});
const inputSubscriptionTimesRef = ref()
const emit = defineEmits(['createOrUpdateVariant', 'closeModal', 'dirtyStateChange']);
const keyboardShortcuts = useKeyboardShortcuts();
const changes_made = ref(0);
const isLoadingVariant = ref(false);
const variantDrafts = ref({});
const currentDraftKey = ref(null);
const skipDraftPersistOnce = ref(false);

const tempSignupValue = ref(0);
const generatingSku = ref(false);

const generateSku = () => {
  if (generatingSku.value) return;
  const title = props.product.post_title || '';
  if (!title) return;

  const isVariation = props.product.detail?.variation_type === 'simple_variations';
  const variantTitle = isVariation ? (variant.value.variation_title || '') : '';
  const excludeId = variant.value.id || 0;

  generatingSku.value = true;
  Rest.get('products/suggest-sku', {
    title: title,
    variant_title: variantTitle,
    exclude_id: excludeId,
  })
    .then(response => {
      if (response?.sku) {
        variant.value.sku = response.sku;
        props.productEditModel.updatePricingValue('sku', response.sku, props.fieldKey, variant.value, props.modeType);
      }
    })
    .catch((error) => {
      const message = error?.data?.message || translate('Failed to generate SKU.');
      ElMessage({ message, type: 'error' });
    })
    .finally(() => {
      generatingSku.value = false;
    });
};

const changeSetupFee = (value) => {
  props.productEditModel.updatePricingOtherValue('manage_setup_fee', value, props.fieldKey, variant.value, props.modeType);
  if (value === 'no') {
    tempSignupValue.value = Number(variant.value.other_info.signup_fee);
    props.productEditModel.updatePricingOtherValue('signup_fee', '0', props.fieldKey, variant.value, props.modeType);
  } else {
    props.productEditModel.updatePricingOtherValue('signup_fee', tempSignupValue.value, props.fieldKey, variant.value, props.modeType);
  }
}

const getVariationOptions = computed(() => {
  return AppConfig.get('fulfillment_types') ?? {};
})

const subscriptionIntervals = computed(() => {
  return window.fluentCartAdminApp?.subscription_intervals ?? [];
})

const totalPrice = ref();
const updatePrice = (property, value, fieldKey, variant) => {
  props.productEditModel.updatePricingOtherValue(property, value, fieldKey, variant, props.modeType);
}

//This is a fix for ui related issue, when a product is newly created, and came to product edit page,
//its trigger change event from a specific input; this variable used to fix this.
// See:Uses
let isUpdatedOnce = props.product.variants.length > 0;

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
  isUpdatedOnce = props.product.variants.length > 0;
  currentDraftKey.value = getVariantDraftKey();

  const savedDraft = variantDrafts.value[currentDraftKey.value]?.variant;

  if (savedDraft) {
    variant.value = cloneVariant(savedDraft);
    syncVariantDefaults();
    totalPrice.value = variant.value.item_price * (variant?.value?.other_info?.times ?? 1);
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
  totalPrice.value = variant.value.item_price * (variant?.value?.other_info?.times ?? 1);

  await nextTick();
  changes_made.value = 0;
  isLoadingVariant.value = false;
};

const hasSubscriptionPayment = () => {
  return variant?.value?.other_info && ['subscription'].includes(variant?.value?.other_info?.payment_type)
}

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
    console.log(error);
    return null;
  }
};

const handleVariantSave = async () => {
  return saveCurrentVariant();
};

const hasPro = AppConfig.get('app_config.isProActive');

const weightUnit = computed(() => AppConfig.get('shop.weight_unit') || 'kg');
const dimensionUnit = computed(() => AppConfig.get('shop.dimension_unit') || 'cm');

// Package system
const showPackageDialog = ref(false);
const shippingPackages = ref(AppConfig.get('shop.shipping_packages') || []);

const weightUnits = [
  {label: 'kg', value: 'kg'},
  {label: 'g', value: 'g'},
  {label: 'lbs', value: 'lbs'},
  {label: 'oz', value: 'oz'}
];

const typeIcons = {
  box: '📦',
  envelope: '✉️',
  soft_package: '🛍️'
};

const getSelectedPackage = computed(() => {
  const slug = variant.value?.other_info?.package_slug;
  if (!slug) {
    return shippingPackages.value.find(p => p.is_default) || null;
  }
  return shippingPackages.value.find(p => p.slug === slug) || null;
});

const formatPackageLabel = (pkg) => {
  if (!pkg) return translate('Select a package');
  const dims = pkg.type === 'envelope'
    ? pkg.length + ' × ' + pkg.width + ' ' + pkg.dimension_unit
    : pkg.length + ' × ' + pkg.width + ' × ' + pkg.height + ' ' + pkg.dimension_unit;
  const prefix = pkg.is_default ? translate('Store default') + ' · ' : '';
  return prefix + pkg.name + ' - ' + dims + ', ' + pkg.weight + ' ' + pkg.weight_unit;
};

const onPackageChange = (slug) => {
  if (slug === '__add_new__') {
    // Reset the select value and open dialog
    variant.value.other_info.package_slug = variant.value.other_info.package_slug || '';
    showPackageDialog.value = true;
    return;
  }
  props.productEditModel.updatePricingOtherValue('package_slug', slug, props.fieldKey, variant.value, props.modeType);
};

const onWeightUnitChange = (unit) => {
  props.productEditModel.updatePricingOtherValue('weight_unit', unit, props.fieldKey, variant.value, props.modeType);
};

const onPackageCreated = (packageData) => {
  // If is_default, unset others
  if (packageData.is_default) {
    shippingPackages.value.forEach(p => { p.is_default = false; });
  }
  // Ensure unique slug
  let slug = packageData.slug;
  let counter = 1;
  while (shippingPackages.value.some(p => p.slug === slug)) {
    slug = packageData.slug + '-' + counter;
    counter++;
  }
  packageData.slug = slug;
  shippingPackages.value.push(packageData);

  // Save to backend
  Rest.post('shipping/packages', {packages: shippingPackages.value})
    .then(response => {
      shippingPackages.value = response.packages || shippingPackages.value;
    })
    .catch(() => {});

  // Select the new package
  onPackageChange(packageData.slug);
};

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
  saveCurrentDraft: saveCurrentVariant
});

</script>

<template>
  <div class="fct-product-pricing-form-wrap">
    <el-form label-position="top" require-asterisk-position="right">
      <div class="fct-admin-input-wrapper" v-if="product.detail?.variation_type === 'simple_variations'">
        <el-row :gutter="15">
          <el-col :lg="24">
            <el-form-item required class="has-tooltip-and-required">
              <LabelHint :title="translate('Title')"
                         placement="bottom"
                         :content="translate('Name this pricing variant (e.g., size, type, color) for clear customer selection.')"
                         style="margin-bottom: 8px;"
              />
              <el-input
                  :class="productEditModel.hasValidationError(`${props.fieldKey}.variation_title`) ? 'is-error' : ''"
                  :id="`${props.fieldKey}.variation_title`"
                  :placeholder="translate('e.g. Small, Medium, Large')" type="text" v-model="variant.variation_title"
                  @input="value => {productEditModel.updatePricingValue('variation_title', value, props.fieldKey, variant, modeType)}">
              </el-input>
              <ValidationError :validation-errors="productEditModel.validationErrors"
                               :field-key="`${props.fieldKey}.variation_title`"/>
            </el-form-item>
          </el-col>
          <el-col :lg="12">
            <el-form-item>
              <template #label>
                <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                  <LabelHint :title="translate('SKU')"
                             placement="bottom"
                             :content="translate('Stock Keeping Unit')"
                  />
                  <button
                      type="button"
                      @click="generateSku"
                      :disabled="!product.post_title || generatingSku"
                      class="underline-link-button"
                  >
                    {{ generatingSku ? translate('Generating...') : translate('Generate SKU') }}
                  </button>
                </div>
              </template>
              <el-input
                  :class="productEditModel.hasValidationError(`${props.fieldKey}.sku`) ? 'is-error' : ''"
                  :id="`${props.fieldKey}.sku`"
                  :placeholder="translate('SKU')" type="text" v-model="variant.sku"
                  maxlength="30"
                  show-word-limit
                  @input="value => {productEditModel.updatePricingValue('sku', value, props.fieldKey, variant, modeType)}">
              </el-input>
              <ValidationError :validation-errors="productEditModel.validationErrors"
                               :field-key="`${props.fieldKey}.sku`"/>
            </el-form-item>
          </el-col>
          <el-col :lg="12">
            <el-form-item required class="has-tooltip-and-required">
              <LabelHint
                  :title="translate('Fulfillment Type')"
                  placement="bottom"
                  :content="translate('Choosing the Type will impact your order status change flow upon successful payment. For physical items, the status changes from On-Hold to Processing, while for digital items, it changes from On-Hold to Completed.')"
                  style="margin-bottom: 8px;"
              />
              <el-select v-model="variant.fulfillment_type" :placeholder="translate('Select')">
                <el-option v-for="(fulfilmentType, value) in getVariationOptions" :label="fulfilmentType"
                           :value="value"></el-option>
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>
      </div><!-- .fct-admin-input-wrapper -->

      <div class="fct-select-payment-type-block">
        <div class="payment-type-block-head">
          <div>
            <h4 class="title">{{ translate('Select Payment Term') }}</h4>
            <p class="text">{{ translate('Select your referenced Payment.') }}</p>
          </div>
          <div class="fct-product-variation-select" v-if="variant?.other_info">
            <el-dropdown
                trigger="click"
                @command="(command) => {
                productEditModel.updatePricingOtherValue('payment_type', command, null,  variant, modeType)
                    variant.other_info.repeat_interval = '';
                if(command == 'subscription'){
                  nextTick(() => {
                    variant.other_info.repeat_interval = 'yearly';
                    if(variant.other_info.times == '') {
                      inputSubscriptionTimesRef.focus()
                    }
                  })
                }
              }"
                popper-class="fct-dropdown"
            >
              <el-button plain size="small">
                {{ variant.other_info.payment_type === 'onetime' ? translate('One Time') : translate('Subscription') }}
                <DynamicIcon name="ChevronDown"/>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="onetime"
                                    :class="{ active: variant.other_info.payment_type === 'onetime' }">
                    {{ translate('One Time') }}
                  </el-dropdown-item>
                  <el-dropdown-item command="subscription"
                                    :class="{ active: variant.other_info.payment_type === 'subscription' }">
                    {{ translate('Subscription') }}
                  </el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </div>
        </div><!-- .payment-type-block-head -->
        <div class="payment-type-block-body">
          <el-row :gutter="15">
            <el-col :lg="12">
              <div class="fct-admin-input-wrapper">
                <el-form-item
                    :label="variant?.other_info?.installment === 'yes' ? translate('Installment Price') : translate('Price')"
                    required>
                  <el-input
                      :class="productEditModel.hasValidationError(`${props.fieldKey}.item_price`) ? 'is-error' : ''"
                      :id="`${props.fieldKey}.item_price`"
                      :placeholder="variant?.other_info?.installment === 'yes' ? translate('Installment Price') : translate('Price')"
                      :min="1"
                      v-model.number="variant.item_price"
                      @input="value => {

                              productEditModel.updatePricingValue('item_price',value, props.fieldKey, variant, modeType)
                              totalPrice = value * (variant.other_info.times?? 1);
                            }">
                    <template #prefix>
                      <span v-html="appVars.shop.currency_sign"></span>
                    </template>
                    <template #suffix>
                      <span v-if="variant?.other_info?.installment === 'yes'">x {{ variant?.other_info?.times }}</span>
                    </template>
                  </el-input>

                  <ValidationError :validation-errors="productEditModel.validationErrors"
                                   :field-key="`${props.fieldKey}.item_price`"/>
                </el-form-item>
              </div><!-- .fct-admin-input-wrapper -->
            </el-col>
            <el-col :lg="12">
              <div class="fct-admin-input-wrapper">
                <el-form-item>
                  <template #label>
                    <LabelHint :title="translate('Compare at price')"
                               :content="translate('Set a higher price to show with a strike-through, highlighting a discount for sales')"/>
                  </template>
                  <el-input
                      :class="productEditModel.hasValidationError(`${props.fieldKey}.compare_price`) ? 'is-error' : ''"
                      :id="`${props.fieldKey}.compare_price`"
                      :placeholder="translate('Compare at price')"
                      :min="0"
                      v-model="variant.compare_price"
                      @input="value => {productEditModel.updatePricingValue('compare_price', value, props.fieldKey, variant, modeType)}">
                    <template #prefix>
                      <span v-html="appVars.shop.currency_sign"></span>
                    </template>
                  </el-input>
                  <ValidationError :validation-errors="productEditModel.validationErrors"
                                   :field-key="`${props.fieldKey}.compare_price`"/>
                </el-form-item>
              </div><!-- .fct-admin-input-wrapper -->
            </el-col>
            <template v-if="hasSubscriptionPayment()">
              <el-col :lg="12">
                <div class="fct-admin-input-wrapper">
                  <el-form-item :label="translate('Interval')" required>
                    <el-select class="fct-repeat-payment-every-select"
                               :class="productEditModel.hasValidationError(`${props.fieldKey}.other_info.repeat_interval`) ? 'is-error' : ''"
                               :id="`${props.fieldKey}.other_info.repeat_interval`"
                               v-model="variant.other_info.repeat_interval" :placeholder="translate('Interval')"
                               @change="value => {productEditModel.updatePricingOtherValue('repeat_interval', value, props.fieldKey, variant, modeType);
                                }">
                      <el-option 
                        v-for="interval in subscriptionIntervals" 
                        :key="interval.value"
                        :label="interval.label" 
                        :value="interval.value"/>
                    </el-select>
                    <ValidationError :validation-errors="productEditModel.validationErrors"
                                     :field-key="`${props.fieldKey}.other_info.repeat_interval`"/>
                  </el-form-item>
                </div><!-- .fct-admin-input-wrapper -->
              </el-col>
              <el-col :lg="12">
                <el-form-item class="has-tooltip">
                  <template #label>
                    <LabelHint :title="translate('Trial Days')"
                               :content="translate('Enter the number of days the free trial will last. The trial period cannot exceed 365 days.')"/>
                  </template>
                  <el-input
                      :class="productEditModel.hasValidationError(`${props.fieldKey}.other_info.trial_days`) ? 'is-error' : ''"
                      :id="`${props.fieldKey}.other_info.trial_days`"
                      :placeholder="translate('Trial Days')"
                      type="number"
                      :min="1"
                      :max="365"
                      v-model.number="variant.other_info.trial_days"
                      autofocus ref="inputSubscriptionTimesRef"
                      @input="value => {productEditModel.updatePricingOtherValue('trial_days', value, props.fieldKey, variant, modeType);}"
                  >
                  </el-input>
                  <ValidationError :validation-errors="productEditModel.validationErrors"
                                   :field-key="`${props.fieldKey}.other_info.trial_days`"/>
                </el-form-item>
              </el-col>

              <!--              <el-col :lg="12" v-if="variant.other_info?.installment !== 'yes'">-->
              <!--                <el-form-item>-->
              <!--                  <template #label>-->
              <!--                    <LabelHint :title="$t('Occurrence')" content="keep 0 or empty for unlimited times!"/>-->
              <!--                  </template>-->
              <!--                  <el-input-->
              <!--                      :class="productEditModel.hasValidationError(`${props.fieldKey}.other_info.times`) ? 'is-error' : ''"-->
              <!--                      :id="`${props.fieldKey}.other_info.times`"-->
              <!--                      :placeholder="$t('Occurrence')"-->
              <!--                      type="number"-->
              <!--                      :min="0"-->
              <!--                      v-model.number="variant.other_info.times"-->
              <!--                      @input="value => {productEditModel.updatePricingOtherValue('times', value, props.fieldKey, variant, modeType)}"-->
              <!--                      autofocus ref="inputSubscriptionTimesRef"-->
              <!--                  >-->
              <!--                  </el-input>-->
              <!--                  <ValidationError :validation-errors="productEditModel.validationErrors"-->
              <!--                                   :field-key="`${props.fieldKey}.other_info.times`"/>-->
              <!--                </el-form-item>-->
              <!--              </el-col>-->
              <el-col :lg="24">
                <el-form-item>
                  <!--                  <template #label>-->
                  <!--                    <LabelHint :title="$t('Make subscription as split')" content="It will split the total amount with the interval period."/>-->
                  <!--                  </template>-->
                  <el-checkbox
                      true-value="yes"
                      false-value="no"
                      :label="translate('Enable installment payment')"
                      :class="productEditModel.hasValidationError(`${props.fieldKey}.other_info.installment`) ? 'is-error' : ''"
                      :id="`${props.fieldKey}.other_info.installment`"
                      :placeholder="translate('Occurrence')"
                      type="number"
                      v-model.number="variant.other_info.installment"
                      :disabled="!hasPro"
                      @change="value => {
                        updatePrice('installment', value, props.fieldKey, variant)

                        if(value === 'no'){
                          updatePrice('times', 0, props.fieldKey, variant)
                          return;
                        }
                        if (!variant.other_info.times ){
                          updatePrice('times', 1, props.fieldKey, variant)
                          totalPrice = variant.item_price;
                        }else{
                          totalPrice = variant.item_price * (variant.other_info.times);
                        }

                      }"
                      autofocus ref="inputSubscriptionTimesRef"
                  >
                  </el-checkbox>
                  <template v-if="!hasPro">
                    <el-tooltip
                        popper-class="fct-tooltip">
                      <template #content>
                        {{ translate('This feature is available in pro version only.') }}
                      </template>

                      <DynamicIcon name="Crown" class="fct-pro-icon" style="margin-left: 5px;"/>
                    </el-tooltip>
                  </template>
                  <ValidationError :validation-errors="productEditModel.validationErrors"
                                   :field-key="`${props.fieldKey}.other_info.times`"/>
                </el-form-item>
              </el-col>
              <Animation :visible="variant.other_info?.installment === 'yes'" accordion>
                <el-row :gutter="12">
                  <el-col :lg="12">
                    <el-form-item required class="has-tooltip-and-required">
                      <template #label>
                        <LabelHint :title="translate('Installment Count')"
                                   :content="translate('Number of payments to split the price over the installment period.')"/>
                      </template>
                      <el-input
                          :class="productEditModel.hasValidationError(`${props.fieldKey}.other_info.times`) ? 'is-error' : ''"
                          :id="`${props.fieldKey}.other_info.times`"
                          :placeholder="translate('Installment Count')"
                          type="number"
                          :min="1"
                          v-model.number="variant.other_info.times"
                          @input="value => {
                          updatePrice('times', value, props.fieldKey, variant);
                          totalPrice = variant.item_price *  (variant.other_info.times ?? 1);
                        }"
                          autofocus ref="inputSubscriptionTimesRef"
                      >
                      </el-input>
                      <ValidationError :validation-errors="productEditModel.validationErrors"
                                       :field-key="`${props.fieldKey}.other_info.times`"/>
                    </el-form-item>
                  </el-col>
                  <el-col :lg="12" v-if="variant.other_info?.installment === 'yes'">
                    <el-form-item>
                      <template #label>
                        <LabelHint :title="translate('Total Price')"
                                   :content="translate('Final price after all installments, excluding any fees.')"/>
                      </template>
                      <el-input
                          :class="productEditModel.hasValidationError(`${props.fieldKey}.other_info.times`) ? 'is-error' : ''"
                          :id="`${props.fieldKey}.other_info.times`"
                          type="number"
                          :min="1"
                          disabled
                          v-model="totalPrice"
                          @input="updatePrice"
                          autofocus ref="inputSubscriptionTimesRef"
                      >
                        <template #prefix>
                          <span v-html="appVars.shop.currency_sign"></span>
                        </template>
                      </el-input>
                      <ValidationError :validation-errors="productEditModel.validationErrors"
                                       :field-key="`${props.fieldKey}.other_info.times`"/>
                    </el-form-item>
                  </el-col>
                </el-row>
              </Animation>

            </template>

          </el-row>
        </div><!-- .payment-type-block-body -->

      </div>

      <el-row :gutter="15" style="display: none;">
        <el-col :lg="16">
          <div class="fct-admin-input-wrapper" v-if="variant && variant.other_info">
            <el-form-item :label="translate('Billing summary')">
              <el-input type="text"
                        v-model="variant.other_info.billing_summary"
                        @input="value => {productEditModel.updatePricingOtherValue('billing_summary', value, props.fieldKey, variant, modeType)}"
                        :disabled="true">
                <template #prefix>
                  <span v-html="appVars.shop.currency_sign"></span>
                </template>
              </el-input>
            </el-form-item>
          </div><!-- .fct-admin-input-wrapper -->
        </el-col>
      </el-row>

      <el-row v-if="hasSubscriptionPayment()" :gutter="15">
        <el-col :lg="24">
          <div class="fct-admin-input-wrapper">
            <el-form-item>
              <el-switch :disabled="!hasPro"
                         v-model="variant.other_info.manage_setup_fee" active-value="yes" inactive-value="no"
                         @change="changeSetupFee"
                         :active-text="translate('Setup fee')">
              </el-switch>
              <span v-if="!hasPro">
                <el-tooltip
                    popper-class="fct-tooltip">
                   <template #content>
                        {{ translate('This feature is available in pro version only.') }}
                   </template>

                  <DynamicIcon name="Crown" class="fct-pro-icon" style="margin-left: 5px;"/>
                </el-tooltip>
              </span>
            </el-form-item>
          </div>
        </el-col>

        <Animation :visible="variant?.other_info?.manage_setup_fee == 'yes'" accordion>
          <el-row :gutter="15" class="fct-setup-fee-wrap px-[7.5px]">
            <el-col :lg="12">
              <div class="fct-admin-input-wrapper">
                <el-form-item required class="has-tooltip-and-required">
                  <template #label>
                    <LabelHint :title="translate('Setup fee label')"
                               :content="translate('Name the one-time setup fee (e.g., Initial Setup)')"/>
                  </template>
                  <el-input
                      :class="productEditModel.hasValidationError(`${props.fieldKey}.other_info.signup_fee_name`) ? 'is-error' : ''"
                      :id="`${props.fieldKey}.other_info.signup_fee_name`"
                      :placeholder="translate('Setup fee name')" type="text"
                      v-model="variant.other_info.signup_fee_name"
                      @input="value => {productEditModel.updatePricingOtherValue('signup_fee_name', value, props.fieldKey, variant, modeType)}">
                  </el-input>
                  <ValidationError :validation-errors="productEditModel.validationErrors"
                                   :field-key="`${props.fieldKey}.other_info.signup_fee_name`"/>
                </el-form-item>
              </div><!-- .fct-admin-input-wrapper -->
            </el-col>
            <el-col :lg="12">
              <div class="fct-admin-input-wrapper">
                <el-form-item required class="has-tooltip-and-required">
                  <template #label>
                    <LabelHint :title="translate('Setup fee amount')"
                               :content="translate('Set the one-time setup fee amount (e.g., $50) per order. This fee does not apply to quantity.')"/>
                  </template>
                  <el-input
                      :class="productEditModel.hasValidationError(`${props.fieldKey}.other_info.signup_fee`) ? 'is-error' : ''"
                      :id="`${props.fieldKey}.other_info.signup_fee`"
                      :placeholder="translate('Setup fee amount')"
                      v-model.number="variant.other_info.signup_fee" :min="1"
                      @input="value => {productEditModel.updatePricingOtherValue('signup_fee', value, props.fieldKey, variant, modeType)}">
                    <template #prefix>
                      <span v-html="appVars.shop.currency_sign"></span>
                    </template>
                    <!-- <template #append>
                      <el-select class="fct-repeat-payment-every-select"
                                 :class="productEditModel.hasValidationError(`${props.fieldKey}.other_info.setup_fee_per_item`) ? 'is-error' : ''"
                                 :id="`${props.fieldKey}.other_info.setup_fee_per_item`"
                                 v-model="variant.other_info.setup_fee_per_item" :placeholder="$t('Charge per item')"
                                 @change="value => {
                          productEditModel.updatePricingOtherValue('setup_fee_per_item', value, props.fieldKey, variant, modeType);
                          }">
                        <el-option :label="$t('Per Order')" value="no"/>
                        <el-option :label="$t('Per Qty')" value="yes"/>
                      </el-select>
                    </template> -->
                  </el-input>

                  <ValidationError :validation-errors="productEditModel.validationErrors"
                                   :field-key="`${props.fieldKey}.other_info.signup_fee`"/>
                  <ValidationError :validation-errors="productEditModel.validationErrors"
                                   :field-key="`${props.fieldKey}.other_info.setup_fee_per_item`"/>
                </el-form-item>
              </div><!-- .fct-admin-input-wrapper -->
            </el-col>
          </el-row>
        </Animation>

      </el-row>

      <el-row :gutter="15">
        <el-col :lg="24" v-if="variant.manage_cost">
          <div class="fct-admin-input-wrapper">
            <el-form-item>
              <el-switch v-model="variant.manage_cost" active-value="true" inactive-value="false" @change="value => {
                if(isUpdatedOnce){
                  productEditModel.updatePricingOtherValue('manage_cost', value, props.fieldKey, variant, modeType)
                }
                isUpdatedOnce = true;
              }" :active-text="translate('Calculate profit/cost')">
              </el-switch>
            </el-form-item>
          </div>
        </el-col>

        <Animation :visible="variant.manage_cost === 'true'" accordion>
          <el-row :gutter="15" class="fct-cost-profit-wrap px-[7.5px]">
            <el-col :lg="product.detail?.variation_type === 'simple_variations' ? 12 : 8">
              <div class="fct-admin-input-wrapper">
                <el-form-item>
                  <template #label>
                    <LabelHint :title="translate('Cost per item')" :content="translate('Customers won\'t see this')"/>
                  </template>
                  <el-input
                      :class="productEditModel.hasValidationError(`${props.fieldKey}.item_cost`) ? 'is-error' : ''"
                      :id="`${props.fieldKey}.item_cost`"
                      :placeholder="translate('Cost per item')" :min="0"
                      v-model="variant.item_cost"
                      @change="value => {productEditModel.updatePricingValue('item_cost', value, props.fieldKey, variant, modeType)}">
                    <template #prefix>
                      <span v-html="appVars.shop.currency_sign"></span>
                    </template>
                  </el-input>
                  <ValidationError :validation-errors="productEditModel.validationErrors"
                                   :field-key="`${props.fieldKey}.item_cost`"/>
                </el-form-item>
              </div><!-- .fct-admin-input-wrapper -->
            </el-col>

            <el-col :lg="product.detail?.variation_type === 'simple_variations' ? 6 : 8">
              <div class="fct-admin-input-wrapper">
                <el-form-item :label="translate('Profit')">
                  <el-input disabled
                            :placeholder="getProfit(variant)"/>
                </el-form-item>
              </div><!-- .fct-admin-input-wrapper -->
            </el-col>

            <el-col :lg="product.detail?.variation_type === 'simple_variations' ? 6 : 8">
              <div class="fct-admin-input-wrapper">
                <el-form-item :label="translate('Margin')">
                  <el-input disabled
                            :placeholder="getMargin(variant)"/>
                </el-form-item>
              </div><!-- .fct-admin-input-wrapper -->
            </el-col>
          </el-row>
        </Animation>

        <el-col :lg="24">
          <div class="fct-admin-input-wrapper">

            <el-form-item v-if="variant.other_info && false">
              <el-switch v-model="variant.other_info.purchasable" active-value="yes" inactive-value="no" @change="value => {
                if(isUpdatedOnce){
                  productEditModel.updatePricingOtherValue('purchasable', value, props.fieldKey, variant, modeType)
                }
                isUpdatedOnce = true;
              }" :active-text="translate('Purchasable')">
              </el-switch>
            </el-form-item>
          </div>
        </el-col>

        <!-- <template v-if="product.detail.other_info.use_pricing_table === 'yes' && variant.other_info">
          <el-col :lg="24">
            <div class="fct-admin-input-wrapper">
              <el-form-item :label="$t('Description')">
                <el-input 
                  :class="productEditModel.hasValidationError(`${props.fieldKey}.other_info.description`) ? 'is-error' : ''" 
                  :id="`${props.fieldKey}.other_info.description`"
                  :rows="2"
                  type="textarea"
                  v-model="variant.other_info.description"
                  @input="value => {productEditModel.updatePricingOtherValue('other_info.description', value, props.fieldKey, variant, modeType)}">
                </el-input>
                <ValidationError :validation-errors="productEditModel.validationErrors" :field-key="`${props.fieldKey}.other_info.description`"/>
              </el-form-item>
            </div>
          </el-col>
        </template> -->

        <el-col :lg="24" v-if="product.detail?.variation_type === 'simple_variations'">
          <div class="fct-admin-input-wrapper">
            <el-form-item :label="translate('Image')">
              <BulkMediaPicker
                v-model="variant.media"
                :compact="false"
                @change="value => productEditModel.updatePricingOtherValue('media', value, props.fieldKey, variant, modeType)"
              />
            </el-form-item>
          </div><!-- .fct-admin-input-wrapper -->
        </el-col>
      </el-row>
      <!-- Package & Product Weight (per variation, stored in other_info) -->
      <el-row
        v-if="variant.fulfillment_type === 'physical' && variant.other_info"
        :gutter="10"
        class="mb-2 fct-physical-product-meta-row"
      >
        <el-col :xs="24" :sm="14" :lg="14">
          <el-form-item>
            <template #label>
              {{ translate('Package') }}
            </template>
            <el-select
                v-model="variant.other_info.package_slug"
                :placeholder="translate('Select a package')"
                @change="onPackageChange"
                class="w-full"
                clearable
            >
              <template #prefix>
                <span v-if="getSelectedPackage" class="fct-package-select-icon">{{ typeIcons[getSelectedPackage.type] || '📦' }}</span>
              </template>
              <el-option value="__add_new__" :label="translate('Add new package')">
                <span style="color: #409eff; display: flex; align-items: center; gap: 6px;">
                  + {{ translate('Add new package') }}
                </span>
              </el-option>
              <el-option
                  v-for="pkg in shippingPackages"
                  :key="pkg.slug"
                  :label="formatPackageLabel(pkg)"
                  :value="pkg.slug"
              >
                <span style="display: flex; align-items: center; gap: 8px;">
                  <span>{{ typeIcons[pkg.type] || '📦' }}</span>
                  <span>
                    <span style="font-weight: 500;">{{ pkg.is_default ? translate('Store default') + ' · ' : '' }}{{ pkg.name }}</span>
                    <span style="color: #909399; font-size: 12px; margin-left: 4px;">
                      {{ pkg.type === 'envelope' ? (pkg.length + ' × ' + pkg.width) : (pkg.length + ' × ' + pkg.width + ' × ' + pkg.height) }} {{ pkg.dimension_unit }}, {{ pkg.weight }} {{ pkg.weight_unit }}
                    </span>
                  </span>
                </span>
              </el-option>
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :xs="24" :sm="10" :lg="10">
          <el-form-item>
            <template #label>
              {{ translate('Product weight') }}
            </template>
            <div class="fct-product-weight-input">
              <el-input
                  v-model="variant.other_info.weight"
                  type="number"
                  :min="0"
                  step="0.0001"
                  :placeholder="'0.0'"
                  inputmode="decimal"
                  @input="value => {productEditModel.updatePricingOtherValue('weight', value, props.fieldKey, variant, modeType)}"
                  class="fct-product-weight-number"
              >
                <template #append>
                  <el-select
                      v-model="variant.other_info.weight_unit"
                      @change="onWeightUnitChange"
                      class="fct-product-weight-unit"
                  >
                    <el-option v-for="u in weightUnits" :key="u.value" :label="u.label" :value="u.value" />
                  </el-select>
                </template>
              </el-input>
            </div>
          </el-form-item>
        </el-col>
      </el-row>

      <!-- Package Dialog (triggered from package dropdown "Add new") -->
      <PackageDialog
          v-model="showPackageDialog"
          @save="onPackageCreated"
      />

      <el-row v-if="product.detail?.variation_type === 'simple'" :gutter="10">
        <el-col :lg="12">
          <el-form-item>
             <template #label>
              <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <LabelHint :title="translate('SKU')"
                           placement="bottom"
                           :content="translate('Stock Keeping Unit')"
                />
                <button
                    type="button"
                    @click="generateSku"
                    :disabled="!product.post_title || generatingSku"
                    class="underline-link-button"
                >
                  {{ generatingSku ? translate('Generating...') : translate('Generate SKU') }}
                </button>
              </div>
             </template>
              <el-input
                  :class="productEditModel.hasValidationError(`${props.fieldKey}.sku`) ? 'is-error' : ''"
                  :id="`${props.fieldKey}.sku`"
                  :placeholder="translate('SKU')" type="text" v-model="variant.sku"
                  maxlength="30"
                  show-word-limit
                  @input="value => {productEditModel.updatePricingValue('sku', value, props.fieldKey, variant, modeType)}">
              </el-input>
              <ValidationError :validation-errors="productEditModel.validationErrors"
                               :field-key="`${props.fieldKey}.sku`"/>
            </el-form-item>
        </el-col>
        <el-col :lg="12">
          <el-form-item required class="has-tooltip-and-required">
            <template #label>
              <LabelHint :title="translate('Fulfillment Type')"
                         placement="bottom"
                         :content="translate('Choosing the Type will impact your order status change flow upon successful payment. For physical items, the status changes from On-Hold to Processing, while for digital items, it changes from On-Hold to Completed.')"
              />
            </template>
            <el-select v-model="variant.fulfillment_type" @change="value => {productEditModel.updatePricingValue('fulfillment_type', value, props.fieldKey, variant, modeType)}" :placeholder="translate('Select')">
              <el-option v-for="(fulfilmentType, value) in getVariationOptions" :label="fulfilmentType"
                         :value="value"></el-option>
            </el-select>
          </el-form-item>
        </el-col>
      </el-row>

    </el-form>

    <span class="dialog-footer" v-if="modeType !=='add'">
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
    </span>
  </div>
</template>

<style scoped lang="scss">
.fct-product-pricing-form-wrap {
  height: 100%;

  > .el-form {
    padding: 22px 0 28px;
  }

  > .dialog-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 8px;
    padding: 20px 0;
    border-top: 1px solid #e7ebf3;
    background: #ffffff;
  }
}

.fct-select-payment-type-block {
  margin: 4px 0 24px;
  padding: 18px;
  border-radius: 18px;
  border: 1px solid #edf1f7;
  background: linear-gradient(180deg, #fbfcfe 0%, #f6f8fc 100%);
}

.payment-type-block-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 18px;

  .title {
    margin: 0 0 6px;
    color: #172033;
  }

  .text {
    margin: 0;
    color: #607089;
  }
}

.fct-physical-product-meta-row {
  align-items: flex-end;

  :deep(.el-col) {
    display: flex;
  }

  :deep(.el-form-item) {
    width: 100%;
    margin-bottom: 0;
  }
}

.fct-product-weight-input {
  width: 100%;

  .fct-product-weight-number {
    width: 100%;

    :deep(.el-input__wrapper) {
      min-height: 36px;
      height: 36px;
    }

    :deep(input[type='number']) {
      -moz-appearance: textfield;
    }

    :deep(input[type='number']::-webkit-outer-spin-button),
    :deep(input[type='number']::-webkit-inner-spin-button) {
      margin: 0;
      -webkit-appearance: none;
    }

    :deep(.el-input-group__append) {
      display: flex;
      align-items: stretch;
      padding: 0;
      width: 96px;
      height: 36px;
      background: #ffffff;
    }
  }

  .fct-product-weight-unit {
    display: flex;
    width: 100%;

    :deep(.el-select) {
      width: 100%;
    }

    :deep(.el-select__wrapper) {
      width: 100%;
      min-height: 36px;
      height: 100%;
      padding: 0 10px;
      box-shadow: none;
      border-radius: 0 4px 4px 0;
      background: transparent;
    }
  }
}

.fct-package-select-icon {
  font-size: 14px;
  line-height: 1;
}

@media (max-width: 900px) {
  .fct-product-pricing-form-wrap {
    > .el-form {
      padding-top: 18px;
    }
  }

  .payment-type-block-head {
    flex-direction: column;
  }

  .fct-physical-product-meta-row {
    :deep(.el-col) {
      display: block;
    }

    :deep(.el-form-item) {
      margin-bottom: 18px;
    }
  }
}
</style>
