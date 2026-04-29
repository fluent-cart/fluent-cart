<script setup>
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import { ref, computed, watch, onMounted, nextTick } from "vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import CurrencyFormatter from '@/utils/support/CurrencyFormatter';
import { formatNumber } from "../Utils/formatNumber";
import Empty from "@/Bits/Components/Table/Empty.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import { ElLink } from "element-plus";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";

/**
 * ----------------------------------------------------------------------------
 * Props & Data
 * ----------------------------------------------------------------------------
 */
const props = defineProps({
    reportFilter: {
        type: Object,
        required: true,
    },
});

const sourceReportData = ref([]);
const fluctuations = ref({});
const isNet = ref(false);
const tableColumns = ref({
    utm_campaign: translate('UTM Campaign'),
    utm_source: translate('UTM Source'),
    utm_medium: translate('UTM Medium'),
    utm_term: translate('UTM Term'),
    utm_content: translate('UTM Content'),
    utm_id: translate('UTM ID'),
});
const selectedColumns = ref([
    'utm_campaign',
    'utm_source',
    'utm_medium',
]);
const maxSelection = 4;
const loading = ref(true);
const search = ref('');

/**
 * ----------------------------------------------------------------------------
 * Computed Properties
 * ----------------------------------------------------------------------------
 */
const filteredData = computed(() => {
    return sourceReportData.value.filter((item) => {
        if (!search.value) return true;

        const searchTerm = search.value.toLowerCase();

        return (
            (item.utm_campaign && item.utm_campaign.toLowerCase().includes(searchTerm)) ||
            (item.utm_source && item.utm_source.toLowerCase().includes(searchTerm)) ||
            (item.utm_medium && item.utm_medium.toLowerCase().includes(searchTerm)) ||
            (item.utm_term && item.utm_term.toLowerCase().includes(searchTerm)) ||
            (item.utm_content && item.utm_content.toLowerCase().includes(searchTerm)) ||
            (item.utm_id && item.utm_id.toLowerCase().includes(searchTerm))
        );
    });
});

/**
 * ----------------------------------------------------------------------------
 * Methods
 * ----------------------------------------------------------------------------
 */
const getSourceReport = () => {
    loading.value = true;
    Rest.get('/reports/sources', props.reportFilter.applicableFilters)
        .then((response) => {
            sourceReportData.value = response.sourceReportData;
            fluctuations.value = response.fluctuations || {};
        })
        .finally(() => {
            loading.value = false;
        });
};

const isCheckboxDisabled = (key) => {
    if (selectedColumns.value.includes(key)) return false;

    return selectedColumns.value.length >= maxSelection;
};

onMounted(() => {
    getSourceReport();

    props.reportFilter.addListener("product-top-chart", () => {
        getSourceReport()
    });
});
</script>

<template>
    <UserCan :permission="'reports/view'">
        <div class="fct-revenue-report-page">
            <PageHeading :title="translate('Order Sources')"></PageHeading>

            <div class="fct-revenue-comparison fct-table-wrapper">
                <div class="fct-card">
                    <div class="fct-card-header">
                        <div class="fct-card-header-top">
                            <div class="fct-card-header-left flex-1">
                                <div class="search-bar is-transparent">
                                    <el-input :placeholder="translate('Search')" ref="search-input" type="text"
                                        clearable v-model="search">
                                        <template #prefix>
                                            <DynamicIcon name="Search" />
                                        </template>
                                    </el-input>
                                </div>
                                <div class="text-xs text-system-light pt-1 dark:text-gray-300">
                                    {{ translate('Filter results by UTM values') }}
                                </div>
                            </div>

                            <div class="fct-card-header-actions">
                                <div class="w-[130px]">
                                    <el-switch v-model="isNet" :active-text="translate('Net')"
                                        :inactive-text="translate('Gross')" />
                                </div>

                                <div class="fct-btn-group sm">
                                    <el-popover trigger="click" placement="bottom-start" width="240"
                                        popper-class="filter-popover">
                                        <div class="filter-popover-item">
                                            <h3 class="filter-popover-title">{{ $t('Columns') }}</h3>
                                            <el-checkbox-group class="fct-checkbox-blocks" v-model="selectedColumns">
                                                <el-checkbox v-for="(value, key) in tableColumns" :label="value"
                                                    :value="key" :disabled="isCheckboxDisabled(key)" />
                                            </el-checkbox-group>
                                        </div>
                                        <template #reference>
                                            <span>
                                                <el-tooltip effect="light" :content="translate('Toggle columns')"
                                                    placement="top" popper-class="fct-tooltip">
                                                    <IconButton tag="button">
                                                        <DynamicIcon name="ColumnIcon" />
                                                    </IconButton>
                                                </el-tooltip>
                                            </span>
                                        </template>
                                    </el-popover>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="fct-card-body">
                        <el-skeleton class="px-5 pb-5" v-if="loading" :rows="5" animated></el-skeleton>

                        <el-table v-else :data="filteredData" class="w-full fct-source-report">
                            <el-table-column v-if="selectedColumns.includes('utm_campaign')"
                                :label="translate('UTM Campaign')" width="180">
                                <template #default="scope">
                                    <el-link class="font-normal" underline="always"
                                        v-on:click="search = scope.row.utm_campaign" v-if="scope.row.utm_campaign">
                                        {{ scope.row.utm_campaign }}
                                    </el-link>

                                    <span v-else class="unknown"> {{ translate('Unknown') }}</span>
                                </template>
                            </el-table-column>

                            <el-table-column v-if="selectedColumns.includes('utm_source')"
                                :label="translate('UTM Source')" width="150">
                                <template #default="scope">
                                    <el-link class="font-normal" underline="always"
                                        v-on:click="search = scope.row.utm_source" v-if="scope.row.utm_source">
                                        {{ scope.row.utm_source }}
                                    </el-link>

                                    <span v-else class="unknown"> {{ translate('Unknown') }}</span>
                                </template>
                            </el-table-column>

                            <el-table-column v-if="selectedColumns.includes('utm_medium')"
                                :label="translate('UTM Medium')" width="150">
                                <template #default="scope">
                                    <el-link class="font-normal" underline="always"
                                        v-on:click="search = scope.row.utm_medium" v-if="scope.row.utm_medium">
                                        {{ scope.row.utm_medium }}
                                    </el-link>

                                    <span v-else class="unknown"> {{ translate('Unknown') }}</span>
                                </template>
                            </el-table-column>

                            <el-table-column v-if="selectedColumns.includes('utm_term')" :label="translate('UTM Term')"
                                width="150">
                                <template #default="scope">
                                    <el-link class="font-normal" underline="always"
                                        v-on:click="search = scope.row.utm_term" v-if="scope.row.utm_term">
                                        {{ scope.row.utm_term }}
                                    </el-link>

                                    <span v-else class="unknown"> {{ translate('Unknown') }}</span>
                                </template>
                            </el-table-column>

                            <el-table-column v-if="selectedColumns.includes('utm_content')"
                                :label="translate('UTM Content')" width="150">
                                <template #default="scope">
                                    <el-link class="font-normal" underline="always"
                                        v-on:click="search = scope.row.utm_content" v-if="scope.row.utm_content">
                                        {{ scope.row.utm_content }}
                                    </el-link>

                                    <span v-else class="unknown"> {{ translate('Unknown') }}</span>
                                </template>
                            </el-table-column>

                            <el-table-column v-if="selectedColumns.includes('utm_id')" :label="translate('UTM ID')"
                                width="100">
                                <template #default="scope">
                                    <el-link class="font-normal" underline="always"
                                        v-on:click="search = scope.row.utm_id" v-if="scope.row.utm_id">
                                        {{ scope.row.utm_id }}
                                    </el-link>

                                    <span v-else class="unknown"> {{ translate('Unknown') }}</span>
                                </template>
                            </el-table-column>

                            <el-table-column :label="translate('Orders')" width="200">
                                <template #default="scope">
                                    <div class="inline-flex items-center gap-2">
                                        {{ formatNumber(scope.row.orders) }}

                                        <Fluctuation class="flex"
                                            :fluctuation="fluctuations[scope.row.utm_key]?.orders_fluctuation || 0"
                                            :amount="fluctuations[scope.row.utm_key]?.previous_orders || 0"
                                            :has-currency="false" />
                                    </div>
                                </template>
                            </el-table-column>

                            <template v-if="isNet">
                                <el-table-column :label="translate('Net Sales')" width="200">
                                    <template #default="scope">
                                        <div class="inline-flex items-center gap-2">
                                            {{ CurrencyFormatter.scaled(scope.row.net_sales) }}

                                            <Fluctuation class="flex"
                                                :fluctuation="fluctuations[scope.row.utm_key]?.net_sales_fluctuation || 0"
                                                :amount="fluctuations[scope.row.utm_key]?.previous_net_sales || 0" />
                                        </div>
                                    </template>
                                </el-table-column>

                                <el-table-column :label="translate('Average Order Net')" width="200">
                                    <template #default="scope">
                                        <div class="inline-flex items-center gap-2">
                                            {{ CurrencyFormatter.scaled(scope.row.average_net_order) }}

                                            <Fluctuation class="flex"
                                                :fluctuation="fluctuations[scope.row.utm_key]?.average_net_order_fluctuation || 0"
                                                :amount="fluctuations[scope.row.utm_key]?.previous_average_net_order || 0" />
                                        </div>
                                    </template>
                                </el-table-column>
                            </template>

                            <template v-else>
                                <el-table-column :label="translate('Gross Sales')" width="200">
                                    <template #default="scope">
                                        <div class="inline-flex items-center gap-2">
                                            {{ CurrencyFormatter.scaled(scope.row.gross_sales) }}

                                            <Fluctuation class="flex"
                                                :fluctuation="fluctuations[scope.row.utm_key]?.gross_sales_fluctuation || 0"
                                                :amount="fluctuations[scope.row.utm_key]?.previous_gross_sales || 0" />
                                        </div>
                                    </template>
                                </el-table-column>

                                <el-table-column :label="translate('Average Order')" width="200">
                                    <template #default="scope">
                                        <div class="inline-flex items-center gap-2">
                                            {{ CurrencyFormatter.scaled(scope.row.average_order) }}

                                            <Fluctuation class="flex"
                                                :fluctuation="fluctuations[scope.row.utm_key]?.average_order_fluctuation || 0"
                                                :amount="fluctuations[scope.row.utm_key]?.previous_average_order || 0" />
                                        </div>
                                    </template>
                                </el-table-column>
                            </template>

                            <template #empty>
                                <Empty icon="Empty/Order" has-dark :text="translate('No data available')" />
                            </template>
                        </el-table>
                    </div>
                </div>
            </div>
        </div>
    </UserCan>
</template>

<style lang="scss">
.fct-source-report {
    .percentage {
        gap: 2px;
        font-size: 11px;
    }

    .el-link {
        &.is-underline::after {
            border-bottom: 1px dotted #c2d4e0;
        }

        &:hover {
            color: #3498F5;
        }
    }

    .unknown {
        color: rgb(157 159 172 / var(--tw-text-opacity, 1));
        font-style: italic;
    }
}
</style>
