<script setup>
import {ref, computed, nextTick, watch} from 'vue';
import {formatNumber} from "@/Bits/productService";
import {getMargin, getProfit} from "@/Bits//productService";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Animation from "@/Bits/Components/Animation.vue";
import VariantItemCollapse from "@/Modules/Products/parts/VariantItemCollapse.vue";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import SharedVariantItemBox from "@/Modules/Products/parts/SharedVariantItemBox.vue";
import PriceInput from "@/Bits/Components/Inputs/PriceInput.vue";
import Field from "@/Bits/Components/Fct/Field.vue";

const props = defineProps({
    variant: Object,
    fieldKey: String,
    modeType: String,
    product: Object,
    productEditModel: Object,
    isGroupMode: { type: Boolean, default: false },
});

const hasPro = AppConfig.get('app_config.isProActive');
const inputSubscriptionTimesRef = ref();
const activeCollapse = ref(['']);
const tempSignupValue = ref(0);

// The normal editor deliberately does not expose other_info.setup_fee_per_item:
// the setup fee is charged per order here, which is what the amount field's own
// hint tells the merchant. The control exists only in the bulk importer
// (BulkInsert/SingleVariation.vue). This set therefore lists no entry for it —
// nothing in this component can raise that error.
const COLLAPSE_ERROR_FIELDS = new Set([
    'compare_price',
    'repeat_interval',
    'trial_days',
    'times',
    'signup_fee_name',
    'signup_fee',
    'item_cost',
    'manage_cost'
]);

watch(() => props.variant?.id, () => {
    tempSignupValue.value = Number(props.variant?.other_info?.signup_fee) || 0;
});

watch(() => props.variant?.other_info?.payment_type, (newVal) => {
    if (newVal === 'subscription') {
        activeCollapse.value = ['1'];
    }
});

watch(() => props.productEditModel?.validationErrors, (errors) => {
    if (!errors || Object.keys(errors).length === 0) return;

    const hasCollapseError = Object.keys(errors).some(key => {
        for (const field of COLLAPSE_ERROR_FIELDS) {
            if (key.includes(field)) return true;
        }
        return false;
    });

    if (hasCollapseError) {
        activeCollapse.value = ['1'];
    }
}, { deep: true });

//This is a fix for ui related issue, when a product is newly created, and came to product edit page,
//its trigger change event from a specific input; this variable used to fix this.
let isUpdatedOnce = props.product.variants.length > 0;

const subscriptionIntervals = computed(() => {
    return window.fluentCartAdminApp?.subscription_intervals ?? [];
});

const hasSubscriptionPayment = () => {
    return props.variant?.other_info && ['subscription'].includes(props.variant?.other_info?.payment_type);
};

const MIN_INSTALLMENT_TIMES = 2;

const updatePrice = (property, value, fieldKey, variant) => {
    props.productEditModel.updatePricingOtherValue(property, value, fieldKey, variant, props.modeType);
};

// An installment plan must bill at least twice: 0 means unlimited and 1 is a
// one-time payment. Snap back on commit so the invalid value never reaches save.
const clampInstallmentTimes = (value, fieldKey, variant) => {
    const times = Number(value);
    if (!Number.isFinite(times) || times < MIN_INSTALLMENT_TIMES) {
        updatePrice('times', MIN_INSTALLMENT_TIMES, fieldKey, variant);
    }
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

// CENTS x a plain count, so the product is still cents. Rendered through a
// disabled PriceInput below rather than interpolated — a bare el-input showed
// "3000" for three $10.00 installments, contradicting the PriceInput directly
// above it.
const totalPrice = computed(() => {
    const price = Number(props.variant.item_price) || 0;
    const times = Number(props.variant?.other_info?.times) || 1;
    return price * times;
});

// el-row / el-col stand-ins, styled in shared-variant-modal.scss (the drawer renders outside the Tailwind wrapper).
const ROW = 'fct-variant-row';
const GUTTER_ROW = 'fct-variant-row is-guttered';
const col = (lg) => ['fct-variant-col', lg && lg !== 24 ? 'is-lg-' + lg : ''];
// Stands in for an unlabelled el-form-item: same flex content row and 18px gap.
const PLAIN_ITEM = 'fct-variant-plain-item';

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
                <template v-if="!isGroupMode">
                    <span class="fct-shared-variant-item-box__hint">
                        {{ translate('Subscription') }}
                    </span>
                    <fct-switch
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
                                        inputSubscriptionTimesRef?.focus();
                                    }
                                });
                            }
                        }"
                    />
                </template>

                <div v-else class="fct-inline-select-wrap">
                    <label>{{ translate('Payment Type') }}:</label>

                    <fct-select
                        size="small"
                        v-model="variant.other_info.payment_type"
                        popper-class="fct-group-select-popper"
                        @change="(val) => {
                            if (val === 'subscription' && !variant.other_info.repeat_interval) {
                                nextTick(() => { variant.other_info.repeat_interval = 'yearly'; });
                            }
                        }"
                        class="fct-inline-select"
                        placement="bottom"
                        :options="[
                            {value: '__unchanged__', label: translate('Unchanged')},
                            {value: 'subscription', label: translate('Subscription')},
                            {value: 'onetime', label: translate('One-time')},
                        ]"
                    />
                </div>
            </div>
         </template>

         <div class="fct-price-with-inclusion">
            <div class="fct-price-with-inclusion__price">
                <Field
                    :id="`${fieldKey}.item_price`"
                    :label="variant?.other_info?.installment === 'yes' ? translate('Installment Price') : translate('Price')"
                    :required="!isGroupMode"
                >
                    <div class="fct-admin-input-wrapper">
                        <PriceInput
                            :error-class="productEditModel.hasValidationError(`${fieldKey}.item_price`) ? 'is-error' : ''"
                            :id="`${fieldKey}.item_price`"
                            :placeholder="isGroupMode ? translate('Unchanged') : (variant?.other_info?.installment === 'yes' ? translate('Installment Price') : translate('Price'))"
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
                </Field>
            </div>

            <div
                v-if="productEditModel.isTaxEnabled()"
                class="fct-price-with-inclusion__tax"
            >
                <!-- Group mode: null = Unchanged, no Store-default option -->
                <fct-select
                    v-if="isGroupMode"
                    :label="translate('Tax')"
                    v-model="variant.other_info.tax_inclusion"
                    popper-class="fct-group-select-popper"
                    :options="[
                        {value: '__unchanged__', label: translate('Unchanged')},
                        {value: 'included', label: translate('Tax included')},
                        {value: 'excluded', label: translate('Tax excluded')},
                    ]"
                />
                <!-- Normal mode -->
                <fct-select
                    v-else
                    :label="translate('Tax')"
                    :model-value="variant?.other_info?.tax_inclusion || '_default_'"
                    @change="(value) => {
                        const persistValue = value === '_default_' ? '' : value;
                        if (variant.other_info) {
                            variant.other_info.tax_inclusion = persistValue;
                        }
                        productEditModel.updatePricingOtherValue('tax_inclusion', persistValue, fieldKey, variant, modeType);
                    }"
                    :options="[
                        {value: '_default_', label: translate('Store default')},
                        {value: 'included', label: translate('Tax included')},
                        {value: 'excluded', label: translate('Tax excluded')},
                    ]"
                />
            </div>
        </div><!-- .fct-price-with-inclusion -->

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
                            {{ variant?.compare_price ? formatNumber(variant.compare_price, true) : 0 }}
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
                            {{ variant?.item_cost ? formatNumber(variant.item_cost, true) : translate('N/A') }}
                        </span>
                    </div>
                </div>
            </template>

            <div class="fct-shared-variant-pricing-additional">
                <div :class="GUTTER_ROW">
                    <div :class="col(12)">
                        <div class="fct-admin-input-wrapper">
                            <Field
                                :id="`${fieldKey}.compare_price`"
                                :label="translate('Compare at price')"
                                :tooltip="translate('Product_Set_Higher')"
                            >
                                <PriceInput
                                    :error-class="productEditModel.hasValidationError(`${fieldKey}.compare_price`) ? 'is-error' : ''"
                                    :id="`${fieldKey}.compare_price`"
                                    :placeholder="isGroupMode ? translate('Unchanged') : translate('Compare at price')"
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
                            </Field>
                        </div><!-- .fct-admin-input-wrapper -->
                    </div>

                    <template v-if="hasSubscriptionPayment()">
                        <div :class="col(12)">
                            <div class="fct-admin-input-wrapper">
                                <fct-select
                                    :label="translate('Interval')"
                                    required
                                    class="fct-repeat-payment-every-select"
                                    :class="productEditModel.hasValidationError(`${fieldKey}.other_info.repeat_interval`) ? 'is-error' : ''"
                                    :id="`${fieldKey}.other_info.repeat_interval`"
                                    v-model="variant.other_info.repeat_interval" :placeholder="translate('Interval')"
                                    :options="subscriptionIntervals"
                                    :validation-errors="productEditModel.validationErrors"
                                    :field-key="`${fieldKey}.other_info.repeat_interval`"
                                    @change="value => {productEditModel.updatePricingOtherValue('repeat_interval', value, fieldKey, variant, modeType);
                                        }"
                                />
                            </div><!-- .fct-admin-input-wrapper -->
                        </div>

                        <div :class="col(12)">
                            <fct-input
                                :label="translate('Trial Days')"
                                :tooltip="translate('Product_Enter_Number')"
                                :class="productEditModel.hasValidationError(`${fieldKey}.other_info.trial_days`) ? 'is-error' : ''"
                                :id="`${fieldKey}.other_info.trial_days`"
                                :placeholder="translate('Trial Days')"
                                type="number"
                                :controls="false"
                                :min="1"
                                :max="365"
                                v-model.number="variant.other_info.trial_days"
                                autofocus
                                :validation-errors="productEditModel.validationErrors"
                                :field-key="`${fieldKey}.other_info.trial_days`"
                                @input="value => {productEditModel.updatePricingOtherValue('trial_days', value, fieldKey, variant, modeType);}"
                            />
                        </div>
                    </template>
                </div>


                <div v-if="hasSubscriptionPayment()" :class="ROW">
                    <div :class="col(24)">
                        <div :class="PLAIN_ITEM">
                            <fct-choice
                                true-value="yes"
                                false-value="no"
                                :class="productEditModel.hasValidationError(`${fieldKey}.other_info.installment`) ? 'is-error' : ''"
                                :id="`${fieldKey}.other_info.installment`"
                                v-model.number="variant.other_info.installment"
                                :disabled="!hasPro"
                                @change="value => {
                                    updatePrice('installment', value, fieldKey, variant)

                                    if(value === 'no'){
                                        updatePrice('times', 0, fieldKey, variant)
                                        return;
                                    }
                                    if (!variant.other_info.times || variant.other_info.times < 2){
                                        updatePrice('times', 2, fieldKey, variant)
                                    }

                                }"
                            >
                                {{ translate('Enable installment payment') }}
                            </fct-choice>
                            <template v-if="!hasPro">
                                <fct-tooltip>
                                    <template #content>
                                        {{ translate('This feature is available in pro version only.') }}
                                    </template>

                                    <DynamicIcon name="Crown" class="fct-pro-icon" style="margin-inline-start: 5px;"/>
                                </fct-tooltip>
                            </template>
                            <ValidationError :validation-errors="productEditModel.validationErrors"
                                            :field-key="`${fieldKey}.other_info.times`"/>
                        </div>
                    </div>

                    <Animation :visible="variant.other_info?.installment === 'yes'" accordion>
                        <div :class="GUTTER_ROW">
                            <div :class="col(12)">
                                <fct-input
                                    :label="translate('Installment Count')"
                                    :tooltip="translate('Number of payments to split the price over the installment period.')"
                                    required
                                    :class="productEditModel.hasValidationError(`${fieldKey}.other_info.times`) ? 'is-error' : ''"
                                    :id="`${fieldKey}.other_info.times`"
                                    :placeholder="translate('Installment Count')"
                                    type="number"
                                    :controls="false"
                                    :min="MIN_INSTALLMENT_TIMES"
                                    v-model.number="variant.other_info.times"
                                    :validation-errors="productEditModel.validationErrors"
                                    :field-key="`${fieldKey}.other_info.times`"
                                    @input="value => {
                                        updatePrice('times', value, fieldKey, variant);
                                    }"
                                    @change="value => {
                                        clampInstallmentTimes(value, fieldKey, variant);
                                    }"
                                    autofocus ref="inputSubscriptionTimesRef"
                                />
                            </div>
                            <div :class="col(12)" v-if="variant.other_info?.installment === 'yes'">
                                <Field
                                    :id="`${fieldKey}.total_price`"
                                    :label="translate('Total Price')"
                                    :tooltip="translate('Final price after all installments, excluding any fees.')"
                                    :validation-errors="productEditModel.validationErrors"
                                    :field-key="`${fieldKey}.other_info.times`"
                                >
                                    <PriceInput
                                        :id="`${fieldKey}.total_price`"
                                        disabled
                                        :model-value="totalPrice"
                                    />
                                </Field>
                            </div>
                        </div>
                    </Animation>
                </div>

                <div v-if="hasSubscriptionPayment()" :class="GUTTER_ROW">
                    <div :class="col(24)">
                        <div class="fct-admin-input-wrapper" :class="isGroupMode ? 'fct-group-select-label' : ''">
                            <!-- Group mode: select so "Unchanged" is available -->
                            <fct-select
                                v-if="isGroupMode"
                                :label="translate('Setup fee')"
                                size="small"
                                v-model="variant.other_info.manage_setup_fee"
                                style="width: 160px"
                                popper-class="fct-group-select-popper"
                                :options="[
                                    {value: '__unchanged__', label: translate('Unchanged')},
                                    {value: 'yes', label: translate('Setup fee: Yes')},
                                    {value: 'no', label: translate('Setup fee: No')},
                                ]"
                            />
                            <!-- Normal mode: switch -->
                            <div v-else :class="PLAIN_ITEM">
                                <fct-switch
                                    size="small"
                                    :disabled="!hasPro"
                                    v-model="variant.other_info.manage_setup_fee" active-value="yes"
                                    inactive-value="no"
                                    @change="changeSetupFee"
                                    :active-text="translate('Setup fee')"
                                />

                                <span v-if="!hasPro">
                                    <fct-tooltip>
                                        <template #content>
                                            {{ translate('This feature is available in pro version only.') }}
                                        </template>

                                        <DynamicIcon
                                            name="Crown"
                                            class="fct-pro-icon"
                                            style="margin-inline-start: 5px;"
                                        />
                                    </fct-tooltip>
                                </span>
                            </div>
                        </div>
                    </div>

                    <Animation :visible="variant?.other_info?.manage_setup_fee == 'yes'" accordion>
                        <div :class="GUTTER_ROW" class="fct-setup-fee-wrap px-[7.5px]">
                            <div :class="col(12)">
                                <div class="fct-admin-input-wrapper">
                                    <fct-input
                                        :label="translate('Setup fee label')"
                                        :tooltip="translate('Name the one-time setup fee (e.g., Initial Setup)')"
                                        required
                                        :class="productEditModel.hasValidationError(`${fieldKey}.other_info.signup_fee_name`) ? 'is-error' : ''"
                                        :id="`${fieldKey}.other_info.signup_fee_name`"
                                        :placeholder="translate('Setup fee name')" type="text"
                                        v-model="variant.other_info.signup_fee_name"
                                        :validation-errors="productEditModel.validationErrors"
                                        :field-key="`${fieldKey}.other_info.signup_fee_name`"
                                        @input="value => {productEditModel.updatePricingOtherValue('signup_fee_name', value, fieldKey, variant, modeType)}"
                                    />
                                </div><!-- .fct-admin-input-wrapper -->
                            </div>
                            <div :class="col(12)">
                                <div class="fct-admin-input-wrapper">
                                    <Field
                                        :id="`${fieldKey}.other_info.signup_fee`"
                                        :label="translate('Setup fee amount')"
                                        :tooltip="translate('Product_Set_Time')"
                                        required
                                        :validation-errors="productEditModel.validationErrors"
                                        :field-key="`${fieldKey}.other_info.signup_fee`"
                                    >
                                        <PriceInput
                                            :error-class="productEditModel.hasValidationError(`${fieldKey}.other_info.signup_fee`) ? 'is-error' : ''"
                                            :id="`${fieldKey}.other_info.signup_fee`"
                                            :placeholder="translate('Setup fee amount')"
                                            :model-value="variant.other_info.signup_fee"
                                            @update:model-value="value => {
                                                variant.other_info.signup_fee = value;
                                                productEditModel.updatePricingOtherValue('signup_fee', value, fieldKey, variant, modeType);
                                            }"
                                        />
                                    </Field>
                                </div><!-- .fct-admin-input-wrapper -->
                            </div>
                        </div>
                    </Animation>

                </div>

                <div :class="GUTTER_ROW">
                    <div :class="col(24)" v-if="variant.manage_cost || isGroupMode" class="fct-manage-cost-col">
                        <div class="fct-admin-input-wrapper" :class="isGroupMode ? 'fct-group-select-label' : ''">
                            <!-- Group mode: select so "Unchanged" is available -->
                            <fct-select
                                v-if="isGroupMode"
                                :label="translate('Calculate profit/cost')"
                                size="small"
                                v-model="variant.manage_cost"
                                style="width: 200px"
                                popper-class="fct-group-select-popper"
                                :options="[
                                    {value: '__unchanged__', label: translate('Unchanged')},
                                    {value: 'true', label: translate('Enable')},
                                    {value: 'false', label: translate('Disable')},
                                ]"
                            />
                            <!-- Normal mode -->
                            <div v-else :class="PLAIN_ITEM">
                                <fct-switch
                                    size="small"
                                    v-model="variant.manage_cost" active-value="true"
                                    inactive-value="false"
                                    @change="value => {
                                        if(isUpdatedOnce){
                                            // manage_cost is a column on the variation, not an
                                            // other_info key — updatePricingOtherValue would have
                                            // written it to the wrong place, and does not list it,
                                            // so the toggle staged nothing at all.
                                            productEditModel.updatePricingValue('manage_cost', value, fieldKey, variant, modeType)
                                        }
                                        isUpdatedOnce = true;
                                    }"
                                    :active-text="translate('Calculate profit/cost')"
                                />
                            </div>
                        </div>
                    </div>

                    <Animation :visible="variant.manage_cost === 'true'" accordion>
                        <div :class="GUTTER_ROW" class="fct-cost-profit-wrap">
                            <div :class="col(isGroupMode ? 24 : (product.detail?.variation_type === 'simple_variations' ? 12 : 8))">
                                <div class="fct-admin-input-wrapper">
                                    <Field
                                        :id="`${fieldKey}.item_cost`"
                                        :label="translate('Cost per item')"
                                        :tooltip="translate('Customers won\'t see this')"
                                        :validation-errors="productEditModel.validationErrors"
                                        :field-key="`${fieldKey}.item_cost`"
                                    >
                                        <PriceInput
                                            :error-class="productEditModel.hasValidationError(`${fieldKey}.item_cost`) ? 'is-error' : ''"
                                            :id="`${fieldKey}.item_cost`"
                                            :placeholder="isGroupMode ? translate('Unchanged') : translate('Cost per item')"
                                            :model-value="variant.item_cost"
                                            @update:model-value="value => {
                                                variant.item_cost = value;
                                                productEditModel.updatePricingValue('item_cost', value, fieldKey, variant, modeType);
                                            }"
                                        />
                                    </Field>
                                </div><!-- .fct-admin-input-wrapper -->
                            </div>

                            <div v-if="!isGroupMode" :class="col(product.detail?.variation_type === 'simple_variations' ? 6 : 8)">
                                <div class="fct-admin-input-wrapper">
                                    <fct-input :label="translate('Profit')" disabled class="fct-profit-display"
                                                :model-value="getProfit(variant)"/>
                                </div><!-- .fct-admin-input-wrapper -->
                            </div>

                            <div v-if="!isGroupMode" :class="col(product.detail?.variation_type === 'simple_variations' ? 6 : 8)">
                                <div class="fct-admin-input-wrapper">
                                    <fct-input :label="translate('Margin')" disabled class="fct-margin-display"
                                                :model-value="getMargin(variant)"/>
                                </div><!-- .fct-admin-input-wrapper -->
                            </div>
                        </div>
                    </Animation>

                    <div :class="col(24)">
                        <div class="fct-admin-input-wrapper">
                            <div v-if="variant.other_info && false" :class="PLAIN_ITEM">
                                <fct-switch v-model="variant.other_info.purchasable" active-value="yes" inactive-value="no" @change="value => {
                                    if(isUpdatedOnce){
                                        productEditModel.updatePricingOtherValue('purchasable', value, fieldKey, variant, modeType)
                                    }
                                    isUpdatedOnce = true;
                                }" :active-text="translate('Purchasable')"/>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </VariantItemCollapse>
    </SharedVariantItemBox>
</template>
