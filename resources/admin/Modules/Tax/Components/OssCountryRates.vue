<template>
    <div class="fct-tax-rate-box">
        <div class="fct-tax-rate-box-head">
            <h4>
                {{ $t('Country Rates') }}
            </h4>
        </div>

        <el-collapse
            class="fct-tax-collapse"
            v-model="activeCollapse" 
            @change="handleCollapseChange"
        >
            <el-collapse-item name="1">
                <template #title="{ isActive }">
                    <div class="inline-flex items-center gap-2">
                        <DynamicIcon name="CaretRight" class="w-3" :class="[{ 'rotate-90': isActive }]"/>
                        {{ $t('Collecting in 27 regions') }}
                    </div>
                </template>

                <div class="fct-tax-collapse-inner">
                    <div v-if="ossCountryRatesLoading" class="text-center py-4">
                        <el-icon class="is-loading"><Loading /></el-icon>
                        <span class="ml-2">Loading EU tax rates...</span>
                    </div>

                    <template v-else-if="ossCountryRates.length > 0">
                        <el-collapse 
                            class="fct-tax-active-country-collapse"
                            v-model="activeCountryCollapse"
                            accordion
                        >
                            <el-collapse-item v-for="(rate, index) in ossCountryRates" :key="index" :name="`country-${index}`">
                                <template #title="{ isActive }">
                                    <div class="collapse-header">
                                        <div class="country-info">
                                            <DynamicIcon name="CaretRight" class="w-3" :class="[{ 'rotate-90': isActive }]"/>

                                            <img class="w-5" 
                                                :src="`https://flagcdn.com/w40/${rate.country_code.toLowerCase()}.png`" 
                                                :alt="rate.country_code">

                                            <span class="font-medium">
                                                {{ rate.country_name }}
                                            </span>
                                        </div>

                                        <div class="total-rates">
                                            {{ $t('Total Rates') }}: {{ rate.total_rates }}
                                        </div>
                                    </div>
                                </template>

                                <div class="fct-country-rates">
                                    <template v-if="rate.rates && rate.rates.length > 0">
                                        <div
                                            v-for="(taxRate, rateIndex) in rate.rates" :key="rateIndex"
                                            class="fct-country-rate-item"
                                        >
                                            <div class="flex items-center gap-2">
                                                <span class="rate-type">
                                                    {{ taxRate.type}}
                                                </span>

                                                <span v-if="taxRate.tax_class" class="tax-class">
                                                    ({{ taxRate.tax_class }})
                                                </span>
                                            </div>

                                            <div class="tax-rate">
                                                {{ taxRate.rate }}%
                                            </div>
                                        </div>
                                    </template>

                                    <div v-else class="text-sm text-gray-500 text-center py-2">
                                        No individual rates available
                                    </div>
                                </div>
                            </el-collapse-item>
                        </el-collapse>
                    </template>

                    <div v-else class="text-gray-500 text-center py-4">
                        No EU tax rates found
                    </div>
                </div>
            </el-collapse-item>
        </el-collapse>
    </div>
</template>

<script>
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import { Loading } from '@element-plus/icons-vue';

export default {
    name: 'OssCountryRates',
    components: { DynamicIcon, Loading },
    props: {
        ossCountryRates: {
            type: Array,
            default: () => [],
        },
        ossCountryRatesLoading: {
            type: Boolean,
            default: false,
        },
    },
    data() {
        return {
            activeCollapse: ['1'],
            activeCountryCollapse: [],
        };
    },
    methods: {
        handleCollapseChange(activeNames) {
            // Check if collapse item "1" is being opened
            if (activeNames.includes('1') && this.ossCountryRates.length === 0) {
                this.$emit('fetch');
            }
        },
    },
    
    
}
</script>
