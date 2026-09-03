<script setup>
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import { ref, computed, watch, onMounted, nextTick, getCurrentInstance } from "vue";
import AdvancedFilter from "@/Bits/Components/TableNew/Components/AdvancedFilter/AdvancedFilter.vue";
import SourceReportFilterModel from "@/Models/Reports/SourceReportFilterModel";
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
/**
 * Sequence number of the most recently started report request.
 *
 * Applying a filter, resetting it and changing the shared date range each start
 * their own request, so two can easily be in flight at once. Responses are not
 * guaranteed to arrive in the order they were sent: without this guard a slower
 * earlier response can land last and overwrite the newer one, leaving the filter
 * controls describing criteria the table no longer reflects. An earlier response
 * would also clear the loading state while the latest request is still pending.
 *
 * Only the newest request is allowed to write data, errors or loading.
 */
let latestRequestId = 0;

const getSourceReport = () => {
    const requestId = ++latestRequestId;

    loading.value = true;

    // The controller reads both advanced-filter keys out of `params`, so they
    // are merged into the shared report filter payload rather than sent
    // alongside it.
    const requestParams = {
        params: {
            ...props.reportFilter.applicableFilters.params,
            ...sourceFilterModel.getQueryParams(),
        },
    };

    Rest.get('/reports/sources', requestParams)
        .then((response) => {
            if (requestId !== latestRequestId) return;

            sourceReportData.value = response.sourceReportData;
            fluctuations.value = response.fluctuations || {};
        })
        .catch((errors) => {
            if (requestId !== latestRequestId) return;

            Notify.error(errors?.data?.message || translate('Failed to load the order sources report'));
        })
        .finally(() => {
            if (requestId !== latestRequestId) return;

            loading.value = false;
        });
};

const sourceFilterModel = SourceReportFilterModel.init({
    instance: getCurrentInstance(),
    onApply: () => getSourceReport(),
});

/**
 * The UTM cells double as a shortcut into the simple search. In advanced mode
 * that box is hidden, so they render as plain text — a link that silently
 * populates a control the user cannot see is worse than no link.
 */
const quickSearchEnabled = computed(() => !sourceFilterModel.isUsingAdvanceFilter());

/**
 * Switching to the advanced builder drops the simple search term along with the
 * input. Keeping it would leave the table filtered by a hidden substring match
 * on top of the conditions, and the row count would not match either filter.
 */
const onFilterTypeChanged = (filterType) => {
    if (filterType === 'advanced') {
        search.value = '';
    }

    sourceFilterModel.onFilterTypeChanged(filterType);
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
                            <!--
                                The simple search filters the loaded rows client-side, so it is
                                hidden in advanced mode rather than left alongside the builder:
                                two filters narrowing the same table, one of them a plain
                                substring match, reads as one result set produced by neither.
                                onFilterTypeChanged() clears the term as it hides, so nothing
                                keeps filtering from behind a control the user cannot see.
                            -->
                            <div v-if="!sourceFilterModel.isUsingAdvanceFilter()" class="fct-card-header-left flex-1">
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
                            <div v-else class="flex-1"></div>

                            <div class="fct-card-header-actions">
                                <div v-if="sourceFilterModel.isAdvanceFilterEnabled()"
                                    class="fct-advanced-filter-toggle-wrapper">
                                    <el-switch class="fct-advanced-filter-toggle"
                                        @change="(filterType) => { onFilterTypeChanged(filterType) }"
                                        active-value="advanced" inactive-value="simple"
                                        v-model="sourceFilterModel.data.filterType"
                                        :active-text="translate('Advanced Filter')" size="small" />
                                </div>

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

                        <AdvancedFilter :table="sourceFilterModel" :show-save-view="false" />
                    </div>
                    <div class="fct-card-body">
                        <el-skeleton class="px-5 pb-5" v-if="loading" :rows="5" animated></el-skeleton>

                        <el-table v-else :data="filteredData" class="w-full fct-source-report">
                            <el-table-column v-if="selectedColumns.includes('utm_campaign')"
                                :label="translate('UTM Campaign')" width="180">
                                <template #default="scope">
                                    <ElLink v-if="scope.row.utm_campaign && quickSearchEnabled" class="font-normal"
                                        underline="always" @click="search = scope.row.utm_campaign">
                                        {{ scope.row.utm_campaign }}
                                    </ElLink>

                                    <span v-else-if="scope.row.utm_campaign">{{ scope.row.utm_campaign }}</span>

                                    <span v-else class="unknown"> {{ translate('Unknown') }}</span>
                                </template>
                            </el-table-column>

                            <el-table-column v-if="selectedColumns.includes('utm_source')"
                                :label="translate('UTM Source')" width="150">
                                <template #default="scope">
                                    <ElLink v-if="scope.row.utm_source && quickSearchEnabled" class="font-normal"
                                        underline="always" @click="search = scope.row.utm_source">
                                        {{ scope.row.utm_source }}
                                    </ElLink>

                                    <span v-else-if="scope.row.utm_source">{{ scope.row.utm_source }}</span>

                                    <span v-else class="unknown"> {{ translate('Unknown') }}</span>
                                </template>
                            </el-table-column>

                            <el-table-column v-if="selectedColumns.includes('utm_medium')"
                                :label="translate('UTM Medium')" width="150">
                                <template #default="scope">
                                    <ElLink v-if="scope.row.utm_medium && quickSearchEnabled" class="font-normal"
                                        underline="always" @click="search = scope.row.utm_medium">
                                        {{ scope.row.utm_medium }}
                                    </ElLink>

                                    <span v-else-if="scope.row.utm_medium">{{ scope.row.utm_medium }}</span>

                                    <span v-else class="unknown"> {{ translate('Unknown') }}</span>
                                </template>
                            </el-table-column>

                            <el-table-column v-if="selectedColumns.includes('utm_term')" :label="translate('UTM Term')"
                                width="150">
                                <template #default="scope">
                                    <ElLink v-if="scope.row.utm_term && quickSearchEnabled" class="font-normal"
                                        underline="always" @click="search = scope.row.utm_term">
                                        {{ scope.row.utm_term }}
                                    </ElLink>

                                    <span v-else-if="scope.row.utm_term">{{ scope.row.utm_term }}</span>

                                    <span v-else class="unknown"> {{ translate('Unknown') }}</span>
                                </template>
                            </el-table-column>

                            <el-table-column v-if="selectedColumns.includes('utm_content')"
                                :label="translate('UTM Content')" width="150">
                                <template #default="scope">
                                    <ElLink v-if="scope.row.utm_content && quickSearchEnabled" class="font-normal"
                                        underline="always" @click="search = scope.row.utm_content">
                                        {{ scope.row.utm_content }}
                                    </ElLink>

                                    <span v-else-if="scope.row.utm_content">{{ scope.row.utm_content }}</span>

                                    <span v-else class="unknown"> {{ translate('Unknown') }}</span>
                                </template>
                            </el-table-column>

                            <el-table-column v-if="selectedColumns.includes('utm_id')" :label="translate('UTM ID')"
                                width="100">
                                <template #default="scope">
                                    <ElLink v-if="scope.row.utm_id && quickSearchEnabled" class="font-normal"
                                        underline="always" @click="search = scope.row.utm_id">
                                        {{ scope.row.utm_id }}
                                    </ElLink>

                                    <span v-else-if="scope.row.utm_id">{{ scope.row.utm_id }}</span>

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
