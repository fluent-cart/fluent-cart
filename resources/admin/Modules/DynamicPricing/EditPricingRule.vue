<script setup>
import {computed, onMounted, ref, watch} from "vue";
import {useRoute} from "vue-router";
import translate from "@/utils/translator/Translator";
import {ArrowRight} from "@element-plus/icons-vue";
import Badge from "@/Bits/Components/Badge.vue";
import AppliesToOptions, {appliesToOptions} from "@/Modules/DynamicPricing/parts/AppliesToOptions.vue";
import WhenToApplyOptions from "@/Modules/DynamicPricing/parts/WhenToApplyOptions.vue";
import FeeTaxOption from "@/Modules/DynamicPricing/parts/FeeTaxOption.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import * as Card from "@/Bits/Components/Card/Card.js";
import AppConfig from "@/utils/Config/AppConfig";
import SaveBar from "@/Bits/Components/SaveBar.vue";
import ModuleDisabledNotice from "@/Modules/DynamicPricing/ModuleDisabledNotice.vue";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";
import PricingRuleConditions from "@/Modules/DynamicPricing/parts/PricingRuleConditions.vue";
import PricingRuleSchedule from "@/Modules/DynamicPricing/parts/PricingRuleSchedule.vue";
import PricingRuleEditLoader from "@/Modules/DynamicPricing/parts/PricingRuleEditLoader.vue";
import Permission from "@/utils/permission/Permission";

const route = useRoute();

const rule = ref(null);
const isLoading = ref(false);
const isSaving = ref(false);
const isTogglingStatus = ref(false);
const changesMade = ref(0);

const canManage = Permission.has('dynamic_pricing/manage');

/*
 * Status writes through its own endpoint while the rest of the rule goes
 * through Save, so the two can be in flight at once and answer out of order:
 * a save started mid-toggle sends the optimistic status in its payload, and a
 * toggle that then fails rolls the screen back to a value the server no longer
 * holds. One at a time is enough to stop it.
 */
const isBusy = computed(() => isLoading.value || isSaving.value || isTogglingStatus.value);

const currencySign = computed(() => AppConfig.get('shop.currency_sign', '$'));

const isProActive = computed(() => AppConfig.get('app_config.isProActive'));
const isDynamicPricingEnabled = computed(() => AppConfig.get('modules_settings.dynamic_pricing.active') === 'yes');
const shouldShowDynamicPricing = computed(() => isProActive.value && isDynamicPricingEnabled.value);

const priceTypeOptions = [
    {value: 'discount', label: translate('Discount')},
    {value: 'fee', label: translate('Fee')},
];

const applyAsOptions = [
    {value: 'percentage', label: translate('Percentage')},
    {value: 'fixed', label: translate('Fixed')},
];

// A renewal invoice carries its shipping inside the recurring amount, so a
// shipping rule has nothing to discount there.
const isAppliesToDisabled = (value) => {
    return value === 'shipping' && rule.value?.config?.when_to_apply === 'renewals';
};

// A renewal invoice is a bill the customer already agreed to, so a rule may
// take money off it but never add a fee to it.
const isPriceTypeDisabled = (value) => {
    return value === 'fee' && rule.value?.config?.when_to_apply === 'renewals';
};

/*
 * The one-line summary under the breadcrumb, read from the form as it is
 * edited: "Apply [10% Discount] to [Shipping] on every purchase · 1 condition".
 */
const summary = computed(() => {
    const config = rule.value?.config || {};
    const amount = config.amount === '' || config.amount === null || config.amount === undefined
        ? ''
        : (config.apply_as === 'fixed' ? `${currencySign.value}${config.amount}` : `${config.amount}%`);

    const priceTypes = {
        /* translators: %1$s: amount, e.g. 10% or $5 */
        discount: {text: translate('%1$s Discount', amount).trim(), type: 'success'},
        /* translators: %1$s: amount, e.g. 10% or $5 */
        fee: {text: translate('%1$s Fee', amount).trim(), type: 'info'},
    };

    const whenPhrases = {
        every: translate('every purchase'),
        first: translate('first purchase only'),
        renewals: translate('store managed renewals'),
        upgrades: translate('upgrades & downgrades'),
    };

    // Conditions are stored as groups of rows; every row is one condition.
    const groups = Array.isArray(config.conditions) ? config.conditions : [];
    const count = groups.reduce((total, group) => total + (Array.isArray(group) ? group.length : 0), 0);

    let conditions = '';
    if (count === 1) {
        conditions = translate('1 condition');
    } else if (count > 1) {
        /* translators: %1$s: number of conditions */
        conditions = translate('%1$s conditions', count);
    }

    const appliesTo = appliesToOptions.find((option) => option.value === rule.value?.applies_to);

    return {
        priceType: priceTypes[rule.value?.price_type] || null,
        appliesTo: appliesTo || null,
        when: whenPhrases[config.when_to_apply] || whenPhrases.every,
        conditions,
    };
});

const statusToggleLabel = computed(() => {
    /* translators: %1$s: rule display name */
    return translate('Toggle status for %1$s', rule.value?.display_name || '');
});

let skipNextChange = false;

/*
 * Rules saved before "when to apply" existed have no value for it, yet they
 * behave as "every purchase". Fill that in before the form sees the rule, so
 * the selector shows what the rule actually does — and, because it happens
 * before assignment, without marking the form as changed.
 */
const withDefaults = (pricingRule) => {
    if (pricingRule?.config && !pricingRule.config.when_to_apply) {
        pricingRule.config.when_to_apply = 'every';
    }

    return pricingRule;
};

const fetchRule = () => {
    isLoading.value = true;

    Rest.get(`pricing_rules/${route.params.id}`)
        .then((response) => {
            skipNextChange = true;
            rule.value = withDefaults(response.pricing_rule);
            changesMade.value = 0;
        })
        .catch((errors) => {
            Notify.error(errors?.data?.message || translate('Failed to load pricing rule'));
        })
        .finally(() => {
            isLoading.value = false;
        });
};

/*
 * Catches an incomplete row before saving. The server drops rows it cannot
 * validate, so without this the admin would watch a half-filled condition
 * silently vanish under a success message.
 */
const hasIncompleteCondition = () => {
    const groups = rule.value?.config?.conditions || [];

    return groups.some((group) => group.some((condition) => {
        // A multi-value picker left empty is an array, which the checks below
        // would let through — the server then drops the row under a success
        // message.
        if (Array.isArray(condition.value)) {
            return !condition.key || !condition.operator || !condition.value.length;
        }

        return !condition.key || !condition.operator || condition.value === '' || condition.value === null;
    }));
};

const updateRule = () => {
    if (isBusy.value) {
        return;
    }

    if (hasIncompleteCondition()) {
        Notify.error(translate('Please complete or remove the unfinished conditions'));
        return;
    }

    if (isAppliesToDisabled(rule.value.applies_to)) {
        Notify.error(translate('A store managed renewals rule cannot apply to shipping'));
        return;
    }

    if (isPriceTypeDisabled(rule.value.price_type)) {
        Notify.error(translate('A store managed renewals rule can only be a discount'));
        return;
    }

    isSaving.value = true;

    Rest.put(`pricing_rules/${route.params.id}`, rule.value)
        .then((response) => {
            Notify.success(response?.message || translate('Pricing rule updated successfully'));

            /*
             * Re-seed from the saved rule. The server drops conditions it cannot
             * validate, so without this the editor keeps showing rows that were
             * never stored — under a success message, with no unsaved indicator.
             */
            if (response?.pricing_rule) {
                skipNextChange = true;
                rule.value = withDefaults(response.pricing_rule);
            }

            changesMade.value = 0;
        })
        .catch((errors) => {
            if (errors.status_code === 422) {
                Notify.validationErrors(errors);
            } else {
                Notify.error(errors?.data?.message || translate('Failed to update pricing rule'));
            }
        })
        .finally(() => {
            isSaving.value = false;
        });
};

const discardChanges = () => {
    if (isBusy.value) {
        return;
    }

    fetchRule();
};


const toggleStatus = () => {
    if (isBusy.value) {
        return;
    }

    const previousStatus = rule.value.status;
    const newStatus = previousStatus === 'active' ? 'inactive' : 'active';

    isTogglingStatus.value = true;
    skipNextChange = true;
    rule.value.status = newStatus;

    Rest.put(`pricing_rules/${route.params.id}/status`, {status: newStatus})
        .then(() => {
            Notify.success(translate('Status updated successfully'));
        })
        .catch((errors) => {
            skipNextChange = true;
            rule.value.status = previousStatus;
            Notify.error(errors?.data?.message || translate('Failed to update status'));
        })
        .finally(() => {
            isTogglingStatus.value = false;
        });
};

watch(rule, () => {
    if (skipNextChange) {
        skipNextChange = false;
        return;
    }

    changesMade.value++;
}, {deep: true});

onMounted(() => {
    if (shouldShowDynamicPricing.value) {
        fetchRule();
    }
});
</script>

<template>
    <div class="fct-create-pricing-rule-page fct-layout-width">
        <template v-if="!shouldShowDynamicPricing">
            <ModuleDisabledNotice v-if="isProActive && !isDynamicPricingEnabled" />

            <div v-else class="py-10">
                <ProFeatureNotice
                    placement="feature_lock_dynamic_pricing"
                    :title="translate('Dynamic Pricing')"
                    :text="translate('This feature is only available in FluentCart Pro')"
                >
                </ProFeatureNotice>
            </div>
        </template>

        <PricingRuleEditLoader v-else-if="isLoading"/>

        <template v-else-if="rule">
            <SaveBar
                :isActive="changesMade > 0 ? 'is-active' : ''"
                :loading="isSaving || isTogglingStatus"
                @discard="discardChanges"
                @save="updateRule"
            />

            <div class="single-page-header">
                <div class="single-page-header-title-wrap">
                    <el-breadcrumb :separator-icon="ArrowRight">
                        <el-breadcrumb-item :to="{ name: 'dynamic_pricing' }">
                            {{ translate("Dynamic Pricing") }}
                        </el-breadcrumb-item>

                        <el-breadcrumb-item>
                            {{ rule.display_name }}
                        </el-breadcrumb-item>
                    </el-breadcrumb>
                </div>
            </div>

            <div class="fct-pricing-rule-summary">
                <p class="fct-pricing-rule-summary-text">
                    {{ translate('Apply') }}
                    <Badge
                        v-if="summary.priceType"
                        :type="summary.priceType.type"
                        :text="summary.priceType.text"
                        icon="Tag"
                    />
                    {{ translate('to') }}
                    <Badge
                        v-if="summary.appliesTo"
                        type="blue"
                        :text="summary.appliesTo.label"
                        :icon="summary.appliesTo.icon"
                    />
                    {{ translate('on') }}
                    <span class="text-system-dark font-medium dark:text-gray-400">
                        {{ summary.when }}
                    </span>
                    <span v-if="summary.conditions" class="text-system-mid dark:text-gray-400">
                        · {{ summary.conditions }}
                    </span>
                </p>

                <div v-if="canManage" class="fct-pricing-rule-summary-status">
                    <el-switch
                        :model-value="rule.status === 'active'"
                        :disabled="isBusy"
                        :loading="isTogglingStatus"
                        :aria-label="statusToggleLabel"
                        @change="toggleStatus"
                    />
                    <span>{{ rule.status === 'active' ? translate('Active') : translate('Inactive') }}</span>
                </div>
            </div>

            <div class="single-page-body">
                <el-row :gutter="30">
                    <el-col :lg="16">
                        <Card.Container>
                            <Card.Header :title="translate('Details')"/>
                            <Card.Body>
                                <div class="fct-pricing-rule-form">
                                    <div class="fct-form-group">
                                        <label class="required">
                                            {{ translate('Name') }}
                                        </label>

                                        <el-input
                                            v-model="rule.name"
                                        />
                                    </div><!-- fct-form-group -->

                                    <div class="fct-form-group">
                                        <label class="required">
                                            {{ translate('Display Name') }}
                                        </label>

                                        <el-input
                                            v-model="rule.display_name"
                                        />
                                    </div><!-- fct-form-group -->

                                    <div class="fct-form-group">
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                            <div class="fct-pricing-rule-price-type">
                                                <label>
                                                    {{ translate('Price Type') }}
                                                </label>
                                                <el-select v-model="rule.price_type" class="w-full">
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
                                                <el-select v-model="rule.config.apply_as" class="w-full">
                                                    <el-option
                                                        v-for="option in applyAsOptions"
                                                        :key="option.value"
                                                        :value="option.value"
                                                        :label="option.label"
                                                    />
                                                </el-select>
                                            </div>

                                            <div class="fct-pricing-rule-amount">
                                                <label class="required">
                                                    {{ rule.config.apply_as === 'fixed' ? translate('Amount') : translate('Percent') }}
                                                </label>
                                                <el-input
                                                    v-model="rule.config.amount"
                                                    type="number"
                                                >
                                                    <template v-if="rule.config.apply_as === 'fixed'" #prepend>
                                                        {{ currencySign }}
                                                    </template>

                                                    <template v-else #append>
                                                        %
                                                    </template>
                                                </el-input>
                                            </div>
                                        </div>

                                        <FeeTaxOption
                                            v-if="rule.price_type === 'fee'"
                                            v-model="rule.config.fee_taxable"
                                            class="mt-4"
                                        />
                                    </div><!-- fct-form-group -->

                                </div>
                            </Card.Body>
                        </Card.Container>

                        <Card.Container>
                            <Card.Header :title="translate('Display Conditions')" border_bottom/>
                            <Card.Body>
                                <PricingRuleConditions
                                    v-model="rule.config.conditions"
                                />
                            </Card.Body>
                        </Card.Container>
                    </el-col>
                    <el-col :lg="8">
                        <Card.Container>
                            <Card.Header :title="translate('Applies To')" border_bottom/>
                            <Card.Body>
                                <AppliesToOptions
                                    v-model="rule.applies_to"
                                    :is-disabled="isAppliesToDisabled"
                                />
                            </Card.Body>
                        </Card.Container>

                        <Card.Container>
                            <Card.Header :title="translate('When to Apply')" border_bottom/>
                            <Card.Body>
                                <WhenToApplyOptions
                                    v-model="rule.config.when_to_apply"
                                    :disabled="isBusy"
                                />
                            </Card.Body>
                        </Card.Container>

                        <PricingRuleSchedule
                            v-model:start-date="rule.start_date"
                            v-model:end-date="rule.end_date"
                            :disabled="isBusy"
                        />
                    </el-col>
                </el-row>
            </div>
        </template>
    </div>
</template>
