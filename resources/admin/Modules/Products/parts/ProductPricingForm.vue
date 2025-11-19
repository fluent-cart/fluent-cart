<script setup>
import {computed, nextTick, onMounted, ref, watch} from "vue";
import Attachments from "@/Bits/Components/Attachment/Attachments.vue";
import {getMargin, getProfit} from "@/Bits//productService";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import Animation from "@/Bits/Components/Animation.vue";
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
const inputSubscriptionTimesRef = ref()
const emit = defineEmits(['createOrUpdateVariant', 'closeModal']);
const keyboardShortcuts = useKeyboardShortcuts();
const changes_made = ref(0);

const tempSignupValue = ref(0);

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

onMounted(() => {

  setTimeout(() => {

    if (props.product.detail?.variation_type === 'simple') {
      if (Array.isArray(props.product.variants) && props.product.variants.length === 0) {
        variant.value = props.productEditModel.addDummyVariant();
        if (props.product.detail?.variation_type === 'simple') {
          variant.value.variation_title = props.product.post_title;
        }
      } else {
        variant.value = props.product.variants[0];
      }
    } else if (props.modeType === 'create') {
      variant.value = props.productEditModel.addDummyVariant();
    } else if (props.modeType === 'update') {
      const parseVariant = JSON.stringify(props.product.variants[props.index])
      variant.value = JSON.parse(parseVariant)
    } else if (props.modeType === 'duplicate') {
      const parseVariant = JSON.stringify(props.product.variants[props.index])
      variant.value = JSON.parse(parseVariant)
      variant.value['serial_index'] = props.productEditModel.variantsLength() + 1
      delete variant.value['id'];
    }

    //console.log(variant.other_info.times, 'shamim');
    totalPrice.value = variant.value.item_price * (variant?.value?.other_info?.times ?? 1);


  }, 100)
})

const hasSubscriptionPayment = () => {
  return variant?.value?.other_info && ['subscription'].includes(variant?.value?.other_info?.payment_type)
}

const handleVariantSave = async (variant, index) => {
  try {
    if (props.modeType === 'duplicate') {
      index = props.productEditModel.variantsLength()
      variant.rowId = variant.rowId + 1;
    }
    let result = await props.productEditModel.createOrUpdatePricing(variant);
    variant.id = result.data.id;
    props.productEditModel.afterCreatingOrUpdatingPricing(index, variant)
    emit('createOrUpdateVariant');
    changes_made.value = 0;
  } catch (error) {
    console.log(error);
    changes_made.value = 0;
  }
};

const hasPro = AppConfig.get('app_config.isProActive');

watch(variant, () => {
  changes_made.value++;
}, {deep: true})

keyboardShortcuts.bind(['mod+s'], (event) => {
  event.preventDefault();
  if (changes_made.value) {
    handleVariantSave(variant.value, props.index)
  }
});

</script>

<template>
  <div class="fct-product-pricing-form-wrap">
    <el-form label-position="top" require-asterisk-position="right">
      <div class="fct-admin-input-wrapper" v-if="product.detail?.variation_type === 'simple_variations'">
        <el-row :gutter="15">
          <el-col :lg="16">
            <el-form-item required class="has-tooltip-and-required">
              <LabelHint :title="translate('Title')"
                         placement="bottom"
                         :content="translate('Name this pricing variant (e.g., size, type, color) for clear customer selection.')"
                         style="margin-bottom: 8px;"
              />
              <el-input
                  :class="productEditModel.hasValidationError(`${props.fieldKey}.variation_title`) ? 'is-error' : ''"
                  :id="`${props.fieldKey}.variation_title`"
                  :placeholder="translate('Title')" type="text" v-model="variant.variation_title"
                  @input="value => {productEditModel.updatePricingValue('variation_title', value, props.fieldKey, variant, modeType)}">
              </el-input>
              <ValidationError :validation-errors="productEditModel.validationErrors"
                               :field-key="`${props.fieldKey}.variation_title`"/>
            </el-form-item>
          </el-col>
          <el-col :lg="8">
            <el-form-item required class="has-tooltip-and-required">
              <LabelHint
                  :title="translate('Type')"
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
                      <img :src="appVars?.asset_url + 'images/crown.svg'" alt="pro feature" class="pro-feature-icon">
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
                       <img :src="appVars?.asset_url + 'images/crown.svg'" alt="pro feature" class="pro-feature-icon">
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
                      v-model.number="variant.item_cost"
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
              <attachments
                  :multiple=true
                  :attachments="variant.media"
                  @mediaUploaded="value => {productEditModel.updatePricingOtherValue('media', value, props.fieldKey, variant, modeType)}"
                  @removeImage="value => {
                  variant.media.splice(value, 1)
                  if(modeType === 'add') {
                    productEditModel.setHasChange()
                  }
                }"
              />
            </el-form-item>
          </div><!-- .fct-admin-input-wrapper -->
        </el-col>
      </el-row>

    </el-form>

    <span class="dialog-footer" v-if="modeType !=='add'">
      <el-button @click="(() => {
        emit('closeModal')
      })">
        {{ translate('Cancel') }}
      </el-button>
      
      <el-button
          :disabled="productEditModel.saving"
          @click="() => handleVariantSave(variant, index)"
          type="primary"
      >
        {{ typeof variant.id === 'undefined' ? translate('Save') : translate('Update') }}
      </el-button>
    </span>
  </div>
</template>
