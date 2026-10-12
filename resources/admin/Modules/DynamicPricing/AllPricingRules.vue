<script setup>
import {computed, onMounted} from "vue";
import translate from "@/utils/translator/Translator";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PricingRulesTable from "@/Modules/DynamicPricing/parts/PricingRulesTable.vue";
import PricingRulesLoader from "@/Modules/DynamicPricing/parts/PricingRulesLoader.vue";
import ModuleDisabledNotice from "@/Modules/DynamicPricing/ModuleDisabledNotice.vue";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";
import usePricingRuleTable from "@/utils/table-new/PricingRuleTable";
import AppConfig from "@/utils/Config/AppConfig";

const isProActive = computed(() => AppConfig.get('app_config.isProActive'));
const isDynamicPricingEnabled = computed(() => AppConfig.get('modules_settings.dynamic_pricing.active') === 'yes');
const shouldShowDynamicPricing = computed(() => isProActive.value && isDynamicPricingEnabled.value);

// Initialize table without fetching initially
const pricingRuleTable = usePricingRuleTable({fetch: false});

// Fetch pricing rules only if Dynamic Pricing is active
onMounted(() => {
    if (shouldShowDynamicPricing.value) {
        pricingRuleTable.fetch();
    }
});
</script>

<template>
    <div class="fct-pricing-rules-page fct-layout-width">
        <template v-if="shouldShowDynamicPricing">
            <PageHeading :title="translate('Dynamic Pricing')">
                <template #action>
                    <UserCan permission="dynamic_pricing/manage">
                        <el-button
                            type="primary"
                            tag="router-link"
                            :to="{ name: 'dynamic_pricing_create' }"
                        >
                            {{ translate("Add New Rule") }}
                        </el-button>
                    </UserCan>
                </template>
            </PageHeading>

            <div class="fct-pricing-rules-wrap">
                <TableWrapper :table="pricingRuleTable">
                    <PricingRulesLoader
                        v-if="pricingRuleTable.isLoading()"
                        :pricing-rule-table="pricingRuleTable"
                        :next-page-count="pricingRuleTable.nextPageCount"
                    />
                    <PricingRulesTable
                        v-else
                        :pricing-rule-table="pricingRuleTable"
                    />
                </TableWrapper>
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
