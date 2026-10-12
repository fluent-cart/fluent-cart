<script setup>
import {computed, ref, watch} from "vue";
import {useRouter} from "vue-router";
import translate from "@/utils/translator/Translator";
import {ArrowRight} from "@element-plus/icons-vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import AppliesToOptions from "@/Modules/DynamicPricing/parts/AppliesToOptions.vue";
import WhenToApplyOptions from "@/Modules/DynamicPricing/parts/WhenToApplyOptions.vue";
import FeeTaxOption from "@/Modules/DynamicPricing/parts/FeeTaxOption.vue";
import Notify from "@/utils/Notify";
import Rest from "@/utils/http/Rest";
import ModuleDisabledNotice from "@/Modules/DynamicPricing/ModuleDisabledNotice.vue";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";
import AppConfig from "@/utils/Config/AppConfig";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";

const router = useRouter();

const isProActive = computed(() => AppConfig.get('app_config.isProActive'));
const isDynamicPricingEnabled = computed(() => AppConfig.get('modules_settings.dynamic_pricing.active') === 'yes');
const shouldShowDynamicPricing = computed(() => isProActive.value && isDynamicPricingEnabled.value);

const ruleTypes = [
    {
        value: 'scratch', 
        icon: 'Document', 
        title: translate('Start from scratch'), 
        description: translate('Build your own rule from an empty canvas.')
    },
    {
        value: 'volume_discount',
        icon: 'Discount',
        title: translate('Bulk Purchase Discount'),
        description: translate('10% discount for bulk purchases.'),
        // Filled in when the template is picked; every field stays editable.
        preset: {
            display_name: translate('Bulk Purchase Discount'),
            price_type: 'discount',
            applies_to: 'line_item',
            when_to_apply: 'every',
            apply_as: 'percentage',
            amount: '10',
            // Cart / Quantity is at least 5 — one group, one row.
            conditions: [[{key: 'quantity', operator: 'is_at_least', value: '5'}]],
        },
    },
    {
        value: 'free_shipping_minimum',
        icon: 'Truck',
        title: translate('Free‑shipping minimum'),
        /* translators: %1$s: minimum subtotal, e.g. $75 */
        description: translate('Free shipping when the subtotal is at least %1$s.', CurrencyFormatter.formatNumber(7500)),
        preset: {
            display_name: translate('Free‑shipping minimum'),
            // 100% off the shipping cost is free shipping.
            price_type: 'discount',
            applies_to: 'shipping',
            when_to_apply: 'every',
            apply_as: 'percentage',
            amount: '100',
            // Cart / Subtotal is at least 75, stored in cents like the editor's currency input.
            conditions: [[{key: 'cart_subtotal', operator: 'is_at_least', value: 7500}]],
        },
    },
];

const priceTypeOptions = [
    {value: 'discount', label: translate('Discount')},
    {value: 'fee', label: translate('Fee')},
];

const applyAsOptions = [
    {value: 'percentage', label: translate('Percentage')},
    {value: 'fixed', label: translate('Fixed')},
];

const currencySign = computed(() => AppConfig.get('shop.currency_sign', '$'));

// What a template fills in, as a rule starts out before any template is picked.
const presetDefaults = {
    price_type: 'discount',
    applies_to: '',
    when_to_apply: '',
    apply_as: 'percentage',
    amount: '',
    conditions: [],
};

const form = ref({
    name: '',
    display_name: '',
    rule_type: 'scratch',
    price_type: presetDefaults.price_type,
    applies_to: presetDefaults.applies_to,
    config: {
        when_to_apply: presetDefaults.when_to_apply,
        apply_as: presetDefaults.apply_as,
        amount: presetDefaults.amount,
        fee_taxable: 'yes',
        conditions: [],
    },
});

const isSaving = ref(false);

/*
 * A renewal invoice carries its shipping inside the recurring amount, so a
 * shipping rule has nothing to discount there. Shipping is not offered with
 * renewals, and a shipping choice made first is cleared when renewals is picked.
 */
const isAppliesToDisabled = (value) => {
    return value === 'shipping' && form.value.config.when_to_apply === 'renewals';
};

// A renewal invoice is a bill the customer already agreed to, so a rule may
// take money off it but never add a fee to it.
const isPriceTypeDisabled = (value) => {
    return value === 'fee' && form.value.config.when_to_apply === 'renewals';
};

const presetOf = (ruleType) => ruleTypes.find((type) => type.value === ruleType)?.preset;

const applyPreset = (preset) => {
    form.value.price_type = preset.price_type;
    form.value.applies_to = preset.applies_to;
    form.value.config.when_to_apply = preset.when_to_apply;
    form.value.config.apply_as = preset.apply_as;
    form.value.config.amount = preset.amount;
    form.value.config.conditions = JSON.parse(JSON.stringify(preset.conditions));
};

/*
 * Picking a template fills the form from its preset. Leaving a template for
 * one without a preset (scratch) puts back what the template filled in, so
 * nothing from it, the hidden conditions included, reaches the new rule.
 *
 * The display name is the admin's own text as much as the template's: it is
 * filled only when empty or still another template's name, and cleared only
 * while it is still the template's name.
 */
watch(() => form.value.rule_type, (ruleType, previousType) => {
    const preset = presetOf(ruleType);
    const previousPreset = presetOf(previousType);
    const displayName = form.value.display_name;

    if (preset) {
        applyPreset(preset);

        if (!displayName || displayName === previousPreset?.display_name) {
            form.value.display_name = preset.display_name;
        }
    } else if (previousPreset) {
        applyPreset(presetDefaults);

        if (displayName === previousPreset.display_name) {
            form.value.display_name = '';
        }
    }
});

watch(() => form.value.config.when_to_apply, (whenToApply) => {
    if (whenToApply === 'renewals' && form.value.applies_to === 'shipping') {
        form.value.applies_to = '';
    }

    if (whenToApply === 'renewals' && form.value.price_type === 'fee') {
        form.value.price_type = 'discount';
    }
});

const createRule = () => {
    if (!form.value.name) {
        Notify.error(translate('Name is required'));
        return;
    }

    if (!form.value.display_name) {
        Notify.error(translate('Display Name is required'));
        return;
    }

    if (!form.value.rule_type) {
        Notify.error(translate('Please select a template to start from'));
        return;
    }

    if (!form.value.applies_to) {
        Notify.error(translate('Please select where this rule applies to'));
        return;
    }

    if (!form.value.config.when_to_apply) {
        Notify.error(translate('Please select when this rule should apply'));
        return;
    }

    isSaving.value = true;

    Rest.post('pricing_rules', form.value)
        .then((response) => {
            Notify.success(response?.message || translate('Pricing rule created successfully'));
            router.push({name: 'dynamic_pricing_edit', params: {id: response.pricing_rule.id}});
        })
        .catch((errors) => {
            if (errors.status_code === 422) {
                Notify.validationErrors(errors);
            } else {
                Notify.error(errors?.data?.message || translate('Failed to create pricing rule'));
            }
        })
        .finally(() => {
            isSaving.value = false;
        });
};
</script>

<template>
    <div class="fct-create-pricing-rule-page fct-layout-width">
        <template v-if="shouldShowDynamicPricing">
        <div class="single-page-header flex items-center justify-between gap-4">
            <div class="single-page-header-title-wrap">
                <el-breadcrumb :separator-icon="ArrowRight">
                    <el-breadcrumb-item :to="{ name: 'dynamic_pricing' }">
                        {{ translate("Dynamic Pricing") }}
                    </el-breadcrumb-item>
                    <el-breadcrumb-item>
                        {{ translate("Add New Rule") }}
                    </el-breadcrumb-item>
                </el-breadcrumb>
            </div>

            <el-button type="primary" :loading="isSaving" @click="createRule">
                {{ translate("Create Rule") }}
            </el-button>
        </div>

        <div class="single-page-body">
            <el-row :gutter="30">
                <el-col :lg="16">
                    <Card.Container>
                        <Card.Header :title="translate('Basic Information')" border_bottom/>
                        <Card.Body>
                            <div class="fct-pricing-rule-form">
                                <div class="fct-form-group">
                                    <label class="required">
                                        {{ translate('Name') }}
                                    </label>

                                    <el-input
                                        v-model="form.name"
                                        :placeholder="translate('e.g. summer-sale-10')"
                                    />
                                </div><!-- form-group -->

                                <div class="fct-form-group">
                                    <label class="required">
                                        {{ translate('Display Name') }}
                                    </label>

                                    <el-input
                                        v-model="form.display_name"
                                        :placeholder="translate('e.g. Summer Sale')"
                                    />

                                    <div class="form-note">
                                        <p>{{ translate('The friendly name shown to customers at checkout.') }}</p>
                                    </div>
                                </div><!-- form-group -->

                                <div class="fct-form-group fct-pricing-rule-form-section">
                                    <label class="required">
                                        {{ translate('Start from a template') }}
                                    </label>

                                    <el-radio-group v-model="form.rule_type" class="fct-pricing-rule-template">
                                        <el-radio
                                            v-for="ruleType in ruleTypes"
                                            :key="ruleType.value"
                                            :label="ruleType.value"
                                        >
                                            <DynamicIcon
                                                class="fct-rule-template-icon"
                                                :name="ruleType.icon"
                                            />

                                            <span class="fct-rule-template-content">
                                                <span class="fct-rule-template-title">
                                                    {{ ruleType.title }}
                                                </span>
                                                <span class="fct-rule-template-text">
                                                    {{ ruleType.description }}
                                                </span>
                                            </span>

                                            <DynamicIcon
                                                class="fct-rule-template-recipe-check"
                                                name="Check"
                                            />
                                        </el-radio>
                                    </el-radio-group>
                                </div><!-- form-group -->

                                <div class="fct-form-group fct-pricing-rule-form-section">
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                        <div class="fct-pricing-rule-price-type">
                                            <label>
                                                {{ translate('Price Type') }}
                                            </label>
                                            <el-select v-model="form.price_type" class="w-full">
                                                <el-option
                                                    v-for="option in priceTypeOptions"
                                                    :key="option.value"
                                                    :value="option.value"
                                                    :label="option.label"
                                                    :disabled="isPriceTypeDisabled(option.value)"
                                                />
                                            </el-select>
                                        </div>

                                        <div class="fct-pricing-rule-apply-as">
                                            <label>
                                                {{ translate('Apply as') }}
                                            </label>
                                            <el-select v-model="form.config.apply_as" class="w-full">
                                                <el-option
                                                    v-for="option in applyAsOptions"
                                                    :key="option.value"
                                                    :value="option.value"
                                                    :label="option.label"
                                                />
                                            </el-select>
                                        </div>

                                        <div class="fct-pricing-rule-amount">
                                            <label>
                                                {{ form.config.apply_as === 'fixed' ? translate('Amount') : translate('Percent') }}
                                            </label>
                                            <el-input
                                                v-model="form.config.amount"
                                                type="number"
                                            >
                                                <template v-if="form.config.apply_as === 'fixed'" #prepend>
                                                    {{ currencySign }}
                                                </template>

                                                <template v-else #append>
                                                    %
                                                </template>
                                            </el-input>
                                        </div>
                                    </div>

                                    <FeeTaxOption
                                        v-if="form.price_type === 'fee'"
                                        v-model="form.config.fee_taxable"
                                        class="mt-4"
                                    />
                                </div><!-- form-group -->
                            </div>
                        </Card.Body>
                    </Card.Container>
                </el-col>

                <el-col :lg="8">
                    <Card.Container>
                        <Card.Header :title="translate('Applies To')" border_bottom/>
                        <Card.Body>
                            <AppliesToOptions
                                v-model="form.applies_to"
                                :is-disabled="isAppliesToDisabled"
                            />
                        </Card.Body>
                    </Card.Container>

                    <Card.Container>
                        <Card.Body>
                            <div class="fct-form-group">
                                <label class="required">
                                    {{ translate('Apply discount to') }}
                                </label>
                                <div class="form-note">
                                    <p>{{ translate('Choose which transactions this rule should run on.') }}</p>
                                </div>

                                <WhenToApplyOptions
                                    v-model="form.config.when_to_apply"
                                    class="mt-4"
                                />
                            </div><!-- form-group -->
                        </Card.Body>
                    </Card.Container>
                </el-col>
            </el-row>
        </div>
        </template>

        <ModuleDisabledNotice v-else-if="isProActive && !isDynamicPricingEnabled" />

        <div v-else class="py-10">
            <ProFeatureNotice
                placement="feature_lock_dynamic_pricing"
                :title="translate('Dynamic Pricing')"
                :text="translate('This feature is only available in FluentCart Pro')"
            >
            </ProFeatureNotice>
        </div>
    </div>
</template>
