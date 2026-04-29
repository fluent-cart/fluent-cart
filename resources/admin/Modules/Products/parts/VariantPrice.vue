<script setup>
import {ref, computed, nextTick, watch} from 'vue';
import {getMargin, getProfit} from "@/Bits//productService";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import Animation from "@/Bits/Components/Animation.vue";
import VariantItemCollapse from "@/Modules/Products/parts/VariantItemCollapse.vue";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import SharedVariantItemBox from "@/Modules/Products/parts/SharedVariantItemBox.vue";
import PriceInput from "@/Bits/Components/Inputs/PriceInput.vue";

const props = defineProps({
    variant: Object,
    fieldKey: String,
    modeType: String,
    product: Object,
    productEditModel: Object,
});

const hasPro = AppConfig.get('app_config.isProActive');
const inputSubscriptionTimesRef = ref();
const activeCollapse = ref(['']);
const tempSignupValue = ref(0);

watch(() => props.variant?.id, () => {
    tempSignupValue.value = Number(props.variant?.other_info?.signup_fee) || 0;
});

//This is a fix for ui related issue, when a product is newly created, and came to product edit page,
//its trigger change event from a specific input; this variable used to fix this.
let isUpdatedOnce = props.product.variants.length > 0;

const subscriptionIntervals = computed(() => {
    return window.fluentCartAdminApp?.subscription_intervals ?? [];
});

const hasSubscriptionPayment = () => {
    return props.variant?.other_info && ['subscription'].includes(props.variant?.other_info?.payment_type);
};

const updatePrice = (property, value, fieldKey, variant) => {
    props.productEditModel.updatePricingOtherValue(property, value, fieldKey, variant, props.modeType);
};

const changeSetupFee = (value) => {
    props.productEditModel.updatePricingOtherValue('manage_setup_fee', value, props.fieldKey, props.variant, props.modeType);
    if (value === 'no') {
        tempSignupValue.value = Number(props.variant.other_info.signup_fee);
        props.productEditModel.updatePricingOtherValue('signup_fee', '0', props.fieldKey, props.variant, props.modeType);
    } else {
        props.productEditModel.updatePricingOtherValue('signup_fee', tempSignupValue.value, props.fieldKey, props.variant, props.modeType);
    }
};

const totalPrice = computed(() => {
    const price = Number(props.variant.item_price) || 0;
    const times = Number(props.variant?.other_info?.times) || 1;
    return price * times;
});

const getIntervalShortcode = (interval) => {
    const found = subscriptionIntervals.value.find(i => i.value === interval);
    if (!found?.label && !interval) return '';

    const label = found?.label || interval;
    return label.split(' ').map(word => word.charAt(0).toUpperCase()).join('');
};


</script>

<template>
    <SharedVariantItemBox>
         <template #label>{{ translate('Pricing') }}</template>
         <template #action>
            <div
                class="fct-shared-variant-item-box__switch"
                v-if="variant?.other_info"
            >
                <span class="fct-shared-variant-item-box__hint">
                     {{translate('Subscription') }}
                </span>

                <el-switch
                    size="small"
                    :model-value="variant.other_info.payment_type === 'subscription'"
                    @change="(val) => {
                        const command = val ? 'subscription' : 'onetime';
                        productEditModel.updatePricingOtherValue('payment_type', command, null, variant, modeType);
                        variant.other_info.repeat_interval = '';
                        if (val) {
                            nextTick(() => {
                                variant.other_info.repeat_interval = 'yearly';
                                if (variant.other_info.times == '') {
                                    inputSubscriptionTimesRef.value?.focus();
                                }
                            });
                        }
                    }"
                />
            </div>
         </template>

         <el-form-item>
            <template #label>
                {{ variant?.other_info?.installment === 'yes' ? translate('Installment Price') : translate('Price') }}
            
                <span class="fct-required">*</span>
            </template>

            <div class="fct-admin-input-wrapper">
                <PriceInput
                    :error-class="productEditModel.hasValidationError(`${fieldKey}.item_price`) ? 'is-error' : ''"
                    :id="`${fieldKey}.item_price`"
                    :placeholder="variant?.other_info?.installment === 'yes' ? translate('Installment Price') : translate('Price')"
                    :model-value="variant.item_price"
                    @update:model-value="value => {
                        variant.item_price = value;
                        productEditModel.updatePricingValue('item_price', value, fieldKey, variant, modeType);
                    }"
                >
                    <template #suffix>
                        <span v-if="variant?.other_info?.installment === 'yes'">
                            x {{ variant?.other_info?.times }}
                        </span>
                    </template>
                </PriceInput>

                <ValidationError :validation-errors="productEditModel.validationErrors" :field-key="`${fieldKey}.item_price`"/>
            </div><!-- .fct-admin-input-wrapper-->
        </el-form-item>

        <!-- collapsible area -->
        <VariantItemCollapse v-model="activeCollapse">
            <template #header="{ isOpen }">
                <div v-if="isOpen" class="fct-collapse-heading">
                    {{ translate('Additional display prices') }}
                </div>

                <div v-else class="fct-tag-group">
                    <div class="fct-tag-item">
                        {{ translate('Compare-at') }}
                        <span class="fct-tags-internal">
                            {{ variant?.compare_price ? appVars.shop.currency_sign + variant.compare_price : 0 }}
                        </span>
                    </div>

                    <div class="fct-tag-item" v-if="variant.other_info.payment_type === 'subscription'">
                        {{ translate('Installment') }}
                        <span class="fct-tags-internal">
                            {{variant?.other_info?.installment === 'yes' ? translate('Yes') : translate('No')}}
                        </span>
                    </div>

                    <div class="fct-tag-item" v-if="variant.other_info.payment_type === 'subscription'">
                        {{ translate('Interval') }}
                        <span class="fct-tags-internal">
                            {{ getIntervalShortcode(variant.other_info.repeat_interval) }}
                        </span>
                    </div>

                    <div class="fct-tag-item" v-if="variant.other_info.payment_type === 'subscription'">
                        {{ translate('Trial Days') }}
                        <span class="fct-tags-internal">
                            {{ variant?.other_info?.trial_days ?? 0 }}
                        </span>
                    </div>

                    <div class="fct-tag-item">
                        {{ translate('Cost Per Item') }}
                        <span class="fct-tags-internal">
                            {{ variant?.item_cost ? appVars.shop.currency_sign + variant.item_cost : translate('N/A') }}
                        </span>
                    </div>
                </div>
            </template>

            <div class="fct-shared-variant-pricing-additional">
                <el-row :gutter="15">
                    <el-col :lg="12">
                        <div class="fct-admin-input-wrapper">
                            <el-form-item>
                                <template #label>
                                    <LabelHint
                                        :title="translate('Compare at price')"
                                        :content="translate('Set a higher price to show with a strike-through, highlighting a discount for sales')"
                                    />
                                </template>
                                <PriceInput
                                    :error-class="productEditModel.hasValidationError(`${fieldKey}.compare_price`) ? 'is-error' : ''"
                                    :id="`${fieldKey}.compare_price`"
                                    :placeholder="translate('Compare at price')"
                                    :model-value="variant.compare_price"
                                    @update:model-value="value => {
                                        variant.compare_price = value;
                                        productEditModel.updatePricingValue('compare_price', value, fieldKey, variant, modeType);
                                    }"
                                />

                                <ValidationError
                                    :validation-errors="productEditModel.validationErrors"
                                    :field-key="`${fieldKey}.compare_price`"
                                />
                            </el-form-item>
                        </div><!-- .fct-admin-input-wrapper -->
                    </el-col>

                    <template v-if="hasSubscriptionPayment()">
                        <el-col :lg="12">
                            <div class="fct-admin-input-wrapper">
                                <el-form-item :label="translate('Interval')" required>
                                    <el-select class="fct-repeat-payment-every-select"
                                            :class="productEditModel.hasValidationError(`${fieldKey}.other_info.repeat_interval`) ? 'is-error' : ''"
                                            :id="`${fieldKey}.other_info.repeat_interval`"
                                            v-model="variant.other_info.repeat_interval" :placeholder="translate('Interval')"
                                            @change="value => {productEditModel.updatePricingOtherValue('repeat_interval', value, fieldKey, variant, modeType);
                                                }">
                                        <el-option
                                            v-for="interval in subscriptionIntervals"
                                            :key="interval.value"
                                            :label="interval.label"
                                            :value="interval.value"/>
                                    </el-select>
                                    <ValidationError :validation-errors="productEditModel.validationErrors"
                                                    :field-key="`${fieldKey}.other_info.repeat_interval`"/>
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
                                    :class="productEditModel.hasValidationError(`${fieldKey}.other_info.trial_days`) ? 'is-error' : ''"
                                    :id="`${fieldKey}.other_info.trial_days`"
                                    :placeholder="translate('Trial Days')"
                                    type="number"
                                    :min="1"
                                    :max="365"
                                    v-model.number="variant.other_info.trial_days"
                                    autofocus
                                    @input="value => {productEditModel.updatePricingOtherValue('trial_days', value, fieldKey, variant, modeType);}"
                                >
                                </el-input>

                                <ValidationError :validation-errors="productEditModel.validationErrors"
                                                :field-key="`${fieldKey}.other_info.trial_days`"/>
                            </el-form-item>
                        </el-col>
                    </template>
                </el-row>


                <el-row v-if="hasSubscriptionPayment()">
                    <el-col :lg="24">
                        <el-form-item>
                            <el-checkbox
                                true-value="yes"
                                false-value="no"
                                :label="translate('Enable installment payment')"
                                :class="productEditModel.hasValidationError(`${fieldKey}.other_info.installment`) ? 'is-error' : ''"
                                :id="`${fieldKey}.other_info.installment`"
                                :placeholder="translate('Occurrence')"
                                type="number"
                                v-model.number="variant.other_info.installment"
                                :disabled="!hasPro"
                                @change="value => {
                                    updatePrice('installment', value, fieldKey, variant)

                                    if(value === 'no'){
                                        updatePrice('times', 0, fieldKey, variant)
                                        return;
                                    }
                                    if (!variant.other_info.times ){
                                        updatePrice('times', 1, fieldKey, variant)
                                    }

                                }"
                                autofocus
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
                                            :field-key="`${fieldKey}.other_info.times`"/>
                        </el-form-item>
                    </el-col>

                    <Animation :visible="variant.other_info?.installment === 'yes'" accordion>
                        <el-row :gutter="15">
                            <el-col :lg="12">
                                <el-form-item required class="has-tooltip-and-required">
                                    <template #label>
                                        <LabelHint :title="translate('Installment Count')"
                                                :content="translate('Number of payments to split the price over the installment period.')"/>
                                    </template>
                                    <el-input
                                        :class="productEditModel.hasValidationError(`${fieldKey}.other_info.times`) ? 'is-error' : ''"
                                        :id="`${fieldKey}.other_info.times`"
                                        :placeholder="translate('Installment Count')"
                                        type="number"
                                        :min="1"
                                        v-model.number="variant.other_info.times"
                                        @input="value => {
                                            updatePrice('times', value, fieldKey, variant);
                                        }"
                                        autofocus ref="inputSubscriptionTimesRef"
                                    >
                                    </el-input>
                                    <ValidationError :validation-errors="productEditModel.validationErrors"
                                                    :field-key="`${fieldKey}.other_info.times`"/>
                                </el-form-item>
                            </el-col>
                            <el-col :lg="12" v-if="variant.other_info?.installment === 'yes'">
                                <el-form-item>
                                    <template #label>
                                        <LabelHint :title="translate('Total Price')"
                                                :content="translate('Final price after all installments, excluding any fees.')"/>
                                    </template>
                                    <el-input
                                        :id="`${fieldKey}.total_price`"
                                        type="number"
                                        :min="1"
                                        disabled
                                        :model-value="totalPrice"
                                        autofocus
                                    >
                                        <template #prefix>
                                            <span>{{ appVars.shop.currency_sign }}</span>
                                        </template>
                                    </el-input>

                                    <ValidationError :validation-errors="productEditModel.validationErrors"
                                                    :field-key="`${fieldKey}.other_info.times`"/>
                                </el-form-item>
                            </el-col>
                        </el-row>
                    </Animation>
                </el-row>

                <el-row v-if="hasSubscriptionPayment()" :gutter="15">
                    <el-col :lg="24">
                        <div class="fct-admin-input-wrapper">
                            <el-form-item>
                                <el-switch
                                    size="small"
                                    :disabled="!hasPro"
                                    v-model="variant.other_info.manage_setup_fee" active-value="yes"
                                    inactive-value="no"
                                    @change="changeSetupFee"
                                    :active-text="translate('Setup fee')"
                                >
                                </el-switch>

                                <span v-if="!hasPro">
                                    <el-tooltip popper-class="fct-tooltip">
                                        <template #content>
                                            {{ translate('This feature is available in pro version only.') }}
                                        </template>

                                        <DynamicIcon
                                            name="Crown"
                                            class="fct-pro-icon"
                                            style="margin-left: 5px;"
                                        />
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
                                            :class="productEditModel.hasValidationError(`${fieldKey}.other_info.signup_fee_name`) ? 'is-error' : ''"
                                            :id="`${fieldKey}.other_info.signup_fee_name`"
                                            :placeholder="translate('Setup fee name')" type="text"
                                            v-model="variant.other_info.signup_fee_name"
                                            @input="value => {productEditModel.updatePricingOtherValue('signup_fee_name', value, fieldKey, variant, modeType)}">
                                        </el-input>
                                        <ValidationError :validation-errors="productEditModel.validationErrors"
                                                        :field-key="`${fieldKey}.other_info.signup_fee_name`"/>
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
                                            :class="productEditModel.hasValidationError(`${fieldKey}.other_info.signup_fee`) ? 'is-error' : ''"
                                            :id="`${fieldKey}.other_info.signup_fee`"
                                            :placeholder="translate('Setup fee amount')"
                                            v-model.number="variant.other_info.signup_fee" :min="1"
                                            @input="value => {productEditModel.updatePricingOtherValue('signup_fee', value, fieldKey, variant, modeType)}">
                                            <template #prefix>
                                                <span>{{ appVars.shop.currency_sign }}</span>
                                            </template>
                                        </el-input>

                                        <ValidationError :validation-errors="productEditModel.validationErrors"
                                                        :field-key="`${fieldKey}.other_info.signup_fee`"/>
                                        <ValidationError :validation-errors="productEditModel.validationErrors"
                                                        :field-key="`${fieldKey}.other_info.setup_fee_per_item`"/>
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
                                <el-switch
                                    size="small"
                                    v-model="variant.manage_cost" active-value="true" 
                                    inactive-value="false" 
                                    @change="value => {
                                        if(isUpdatedOnce){
                                            productEditModel.updatePricingOtherValue('manage_cost', value, fieldKey, variant, modeType)
                                        }
                                        isUpdatedOnce = true;
                                    }"
                                    :active-text="translate('Calculate profit/cost')"
                                >
                                </el-switch>
                            </el-form-item>
                        </div>
                    </el-col>

                    <Animation :visible="variant.manage_cost === 'true'" accordion>
                        <el-row :gutter="15" class="fct-cost-profit-wrap">
                            <el-col :lg="product.detail?.variation_type === 'simple_variations' ? 12 : 8">
                                <div class="fct-admin-input-wrapper">
                                    <el-form-item>
                                        <template #label>
                                            <LabelHint :title="translate('Cost per item')" :content="translate('Customers won\'t see this')"/>
                                        </template>
                                        <el-input
                                            :class="productEditModel.hasValidationError(`${fieldKey}.item_cost`) ? 'is-error' : ''"
                                            :id="`${fieldKey}.item_cost`"
                                            :placeholder="translate('Cost per item')" :min="0"
                                            v-model="variant.item_cost"
                                            @blur="() => {
                                                variant.item_cost = String(variant.item_cost).replace(',', '.');
                                            }"
                                            @change="value => {productEditModel.updatePricingValue('item_cost', String(value).replace(',', '.'), fieldKey, variant, modeType)}">
                                            <template #prefix>
                                                <span>{{ appVars.shop.currency_sign }}</span>
                                            </template>
                                        </el-input>
                                        <ValidationError :validation-errors="productEditModel.validationErrors"
                                                        :field-key="`${fieldKey}.item_cost`"/>
                                    </el-form-item>
                                </div><!-- .fct-admin-input-wrapper -->
                            </el-col>

                            <el-col :lg="product.detail?.variation_type === 'simple_variations' ? 6 : 8">
                                <div class="fct-admin-input-wrapper">
                                    <el-form-item :label="translate('Profit')">
                                        <el-input disabled
                                                    :value="getProfit(variant)"/>
                                    </el-form-item>
                                </div><!-- .fct-admin-input-wrapper -->
                            </el-col>

                            <el-col :lg="product.detail?.variation_type === 'simple_variations' ? 6 : 8">
                                <div class="fct-admin-input-wrapper">
                                    <el-form-item :label="translate('Margin')">
                                        <el-input disabled
                                                    :value="getMargin(variant)"/>
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
                                        productEditModel.updatePricingOtherValue('purchasable', value, fieldKey, variant, modeType)
                                    }
                                    isUpdatedOnce = true;
                                }" :active-text="translate('Purchasable')">
                                </el-switch>
                            </el-form-item>
                        </div>
                    </el-col>
                </el-row>
            </div>

        </VariantItemCollapse>
    </SharedVariantItemBox>
</template>
