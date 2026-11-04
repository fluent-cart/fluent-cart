<script setup>
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import SourceChannels from "./SourceChannels.vue";
import { ref, computed, watch, onMounted, onUnmounted, nextTick, getCurrentInstance } from "vue";
import * as echarts from "echarts";
import AdvancedFilter from "@/Bits/Components/TableNew/Components/AdvancedFilter/AdvancedFilter.vue";
import SourceReportFilterModel from "@/Models/Reports/SourceReportFilterModel";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import { formatNumber } from "../Utils/formatNumber";
import Empty from "@/Bits/Components/Table/Empty.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import Theme from "@/utils/Theme";
import { exportCSVWithColumns, csvSafe } from "@/utils/Export";
import Pagination from "@/Bits/Components/Pagination.vue";
import AppConfig from "@/utils/Config/AppConfig";

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
const totals = ref({});
const fluctuations = ref({});
const isNet = ref(false);
const groupBy = ref('campaign');
const includeNone = ref(false);
const loading = ref(true);
const search = ref('');
// The server pages, sorts and searches the rows; a period grouping also sends every period for the chart
const pagination = ref({current_page: 1, per_page: 50, total: 0, last_page: 1});
const sort = ref({prop: null, order: null});
const chartData = ref([]);
const chartRef = ref(null);
let chartInstance = null;

const utmLabels = {
    utm_campaign: translate('UTM Campaign'),
    utm_source: translate('UTM Source'),
    utm_medium: translate('UTM Medium'),
    utm_term: translate('UTM Term'),
    utm_content: translate('UTM Content'),
};

// UTM groupings list their fields; the rest show one label column
const groupOptions = [
    {value: 'campaign', label: translate('Campaign'), fields: ['utm_campaign']},
    {value: 'source', label: translate('Source'), fields: ['utm_source']},
    {value: 'medium', label: translate('Medium'), fields: ['utm_medium']},
    {value: 'source_medium', label: translate('Source / Medium'), fields: ['utm_source', 'utm_medium']},
    {value: 'term', label: translate('Term'), fields: ['utm_term']},
    {value: 'content', label: translate('Content'), fields: ['utm_content']},
    {value: 'campaign_source_medium', label: translate('Campaign / Source / Medium'), fields: ['utm_campaign', 'utm_source', 'utm_medium']},
    // FluentCart Pro groupings: the server serves them only with Pro
    {value: 'product', label: translate('Product'), column: translate('Product'), pro: true},
    {value: 'variation', label: translate('Variation'), column: translate('Variation'), pro: true},
    {value: 'day', label: translate('Day'), column: translate('Day'), time: true, pro: true},
    {value: 'week', label: translate('Week'), column: translate('Week'), time: true, pro: true},
    {value: 'month', label: translate('Month'), column: translate('Month'), time: true, pro: true},
    {value: 'referrer', label: translate('Referrer domain'), column: translate('Referrer domain'), pro: true},
];
const hasPro = !!AppConfig.get('app_config.isProActive');
/* translators: %1$s - a grouping, e.g. Product */
const optionLabel = (option) => (option.pro && !hasPro ? translate('%1$s (Pro)', option.label) : option.label);

/**
 * ----------------------------------------------------------------------------
 * Computed Properties
 * ----------------------------------------------------------------------------
 */
const activeGroup = computed(() => groupOptions.find(option => option.value === groupBy.value) || groupOptions[0]);
const groupFields = computed(() => activeGroup.value.fields || []);
const isTimeGroup = computed(() => !!activeGroup.value.time);

const chartRows = computed(() => chartData.value.filter(row => !row.is_none));

const onSort = ({prop, order}) => {
    sort.value = {prop, order};
    reload();
};
// A new search waits for typing to pause. The advanced filter clears the search when it opens, and
// asks for rows only on Apply
let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    if (!sourceFilterModel.isUsingAdvanceFilter()) {
        searchTimer = setTimeout(reload, 300);
    }
});

// Gross and Net show different columns: a sort follows its column to the other side
const SORT_PAIRS = {gross_sales: 'net_sales', net_sales: 'gross_sales', average_order: 'average_net_order', average_net_order: 'average_order'};
watch(isNet, () => {
    const pair = SORT_PAIRS[sort.value.prop];
    if (pair && sort.value.order) {
        sort.value = {...sort.value, prop: pair};
        reload();
    }
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

// A new filter, grouping or sort starts again at page 1
const reload = () => {
    pagination.value.current_page = 1;
    getSourceReport();
};

const reportParams = () => ({
    ...props.reportFilter.applicableFilters.params,
    ...sourceFilterModel.getQueryParams(),
    group_by: groupBy.value,
    include_none: includeNone.value ? 1 : 0,
});

const getSourceReport = () => {
    const requestId = ++latestRequestId;
    const requestedGroup = groupBy.value;

    loading.value = true;

    // The controller reads both advanced-filter keys out of `params`, so they
    // are merged into the shared report filter payload rather than sent
    // alongside it.
    const requestParams = {
        params: {
            ...reportParams(),
            page: pagination.value.current_page,
            per_page: pagination.value.per_page,
            search: search.value,
            sort_by: sort.value.order ? sort.value.prop : '',
            sort_order: sort.value.order === 'ascending' ? 'asc' : 'desc',
        },
    };

    props.reportFilter.trackCompareRange(Rest.get('/reports/sources', requestParams))
        .then((response) => {
            if (requestId !== latestRequestId) return;

            // The server serves a Pro grouping only with an up-to-date Pro; follow the grouping it served, unless the
            // user picked another one while this request was running
            if (response.group_by && response.group_by !== requestedGroup && groupBy.value === requestedGroup) {
                groupBy.value = response.group_by;
                Notify.info(translate('This grouping needs a newer FluentCart Pro. Showing the default grouping.'));
            }
            sourceReportData.value = response.sourceReportData || [];
            pagination.value = {...pagination.value, ...(response.pagination || {})};
            chartData.value = response.chart || [];
            totals.value = response.totals || {};
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
    onApply: () => reload(),
});

/**
 * The cells double as a shortcut into the simple search. In advanced mode
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

const onGroupChange = () => {
    // Clearing the search reloads through its watcher; without one, reload now
    if (search.value) {
        search.value = '';
    } else {
        reload();
    }
};


const money = (value) => props.reportFilter.formatAmount(value || 0);

// One change badge (on sales); lifetime value next to it, as it says what a source's customers are worth
const metricColumns = computed(() => [
    isNet.value
        ? {prop: 'net_sales', label: translate('Net'), money: true, fluctuation: 'net_sales'}
        : {prop: 'gross_sales', label: translate('Gross'), money: true, fluctuation: 'gross_sales'},
    {prop: 'lifetime_net', label: translate('Lifetime Value'), money: true},
    isNet.value
        ? {prop: 'average_net_order', label: translate('Average Order Net'), money: true}
        : {prop: 'average_order', label: translate('Average Order'), money: true},
    {prop: 'refund_rate', label: translate('Refund Rate'), percent: true},
    {prop: 'refunds', label: translate('Refunds'), money: true},
]);

// A search narrows the rows, not the totals
const totalLabel = () => (search.value ? translate('Total (all sources)') : translate('Total'));

// The totals row covers the filtered set; it is computed on the server, not by adding rows
const summaryRow = ({columns}) => columns.map((column, index) => {
    if (index === 0) {
        return totalLabel();
    }
    const key = column.property;
    if (key === 'orders') {
        return formatNumber(totals.value.orders || 0);
    }
    if (key === 'refund_rate') {
        return (totals.value.refund_rate || 0) + '%';
    }
    const metric = metricColumns.value.find(item => item.prop === key);

    return metric && metric.money ? money(totals.value[key]) : '';
});

// The export holds every row: it asks the server without a page
const exportCsv = () => {
    // Columns and file name are fixed now: the grouping may change while the rows load
    const fields = groupFields.value;
    const group = groupBy.value;
    const columns = fields.length
        ? fields.map(field => ({header: utmLabels[field], accessor: field, formatter: csvSafe}))
        : [{header: activeGroup.value.column, accessor: 'label', formatter: csvSafe}];
    ['orders', 'gross_sales', 'refunds', 'net_sales', 'average_order', 'refund_rate', 'first_order_net', 'later_net', 'lifetime_net']
        .forEach(key => columns.push({header: key, accessor: key}));

    // The file holds the rows the table shows; the totals still cover every source, and say so
    return Rest.get('/reports/sources', {params: {...reportParams(), search: search.value}}).then((response) => {
        const rows = [...(response.sourceReportData || []), {...(response.totals || {}), label: totalLabel(), [fields[0] || 'label']: totalLabel()}];
        return exportCSVWithColumns(rows, columns, 'order-sources-' + group + '.csv');
    }).catch(error => Notify.error(error?.message || error?.data?.message || translate('Failed to export the order sources report')));
};

const renderChart = () => {
    if (!isTimeGroup.value || !chartRef.value) {
        return;
    }
    if (!chartInstance) {
        chartInstance = echarts.init(chartRef.value);
    }
    const isDark = Theme.isDark();
    const textColor = isDark ? '#ffffff' : '#000000';
    const lineColor = isDark ? '#253241' : '#D6DAE1';
    const key = isNet.value ? 'net_sales' : 'gross_sales';

    chartInstance.setOption({
        tooltip: {trigger: 'axis', valueFormatter: (value) => money(value)},
        xAxis: {type: 'category', data: chartRows.value.map(row => row.label), axisLabel: {color: textColor}, axisLine: {lineStyle: {type: 'dashed', color: lineColor}}},
        yAxis: {type: 'value', axisLabel: {color: textColor}, splitLine: {lineStyle: {type: 'dashed', color: lineColor}}},
        series: [{
            name: isNet.value ? translate('Net') : translate('Gross'),
            type: 'bar',
            barMaxWidth: 30,
            color: isDark ? Theme.colors.report.blue_dark_mode : Theme.colors.report.royal_blue,
            data: chartRows.value.map(row => row[key]),
        }],
    }, {notMerge: true});
};

const disposeChart = () => {
    if (chartInstance) {
        chartInstance.dispose();
        chartInstance = null;
    }
};

const resizeChart = () => chartInstance && chartInstance.resize();

watch([loading, isNet], () => {
    disposeChart();
    if (!loading.value) {
        nextTick(renderChart);
    }
});

onMounted(() => {
    getSourceReport();

    props.reportFilter.addListener("sources-report", () => {
        reload();
    });
    window.addEventListener('onFluentCartThemeChange', renderChart);
    window.addEventListener('resize', resizeChart);
});

onUnmounted(() => {
    props.reportFilter.removeListener("sources-report");
    clearTimeout(searchTimer);
    latestRequestId++;
    disposeChart();
    window.removeEventListener('onFluentCartThemeChange', renderChart);
    window.removeEventListener('resize', resizeChart);
});
</script>

<template>
    <UserCan :permission="'reports/view'">
        <div class="fct-revenue-report-page">
            <PageHeading :title="translate('Order Sources')"></PageHeading>

            <SourceChannels :report-filter="reportFilter"/>

            <div class="fct-revenue-comparison fct-table-wrapper">
                <div class="fct-card">
                    <div class="fct-card-header">
                        <div class="fct-card-header-top">
                            <!--
                                The simple search narrows the rows by name, so it is
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
                                    {{ translate('Filter the rows below by name') }}
                                </div>
                            </div>
                            <div v-else class="flex-1"></div>

                            <div class="fct-card-header-actions">
                                <el-select v-model="groupBy" size="small" style="width: 230px"
                                    :aria-label="translate('Group by')" @change="onGroupChange">
                                    <template #prefix>{{ translate('Group by') }}</template>
                                    <el-option v-for="option in groupOptions" :key="option.value"
                                        :label="optionLabel(option)" :value="option.value" :disabled="option.pro && !hasPro" />
                                </el-select>

                                <div class="px-2">
                                    <el-tooltip effect="dark" placement="top" popper-class="fct-tooltip"
                                        :content="translate('SourceRpt_Add_None')">
                                        <el-switch v-model="includeNone" size="small" :active-text="translate('Include (none)')"
                                            @change="reload" />
                                    </el-tooltip>
                                </div>

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
                                    <el-tooltip effect="dark" :content="translate('Export CSV')" placement="top" popper-class="fct-tooltip">
                                        <IconButton tag="button" :disabled="loading || !sourceReportData.length"
                                            :aria-label="translate('Export CSV')" @click="exportCsv">
                                            <DynamicIcon name="Download" />
                                        </IconButton>
                                    </el-tooltip>
                                </div>
                            </div>
                        </div>

                        <AdvancedFilter :table="sourceFilterModel" :show-save-view="false" />
                    </div>
                    <div class="fct-card-body">
                        <el-skeleton class="px-5 pb-5" v-if="loading" :rows="5" animated></el-skeleton>

                        <template v-else>
                            <div v-if="isTimeGroup && chartRows.length" ref="chartRef" class="fct-chart-wrap px-4 pt-4" style="height: 300px"></div>

                            <!-- A reload remounts the table: the current sort comes back as its default, so the arrow matches the order -->
                            <el-table :data="sourceReportData" class="w-full fct-source-report" show-summary :summary-method="summaryRow"
                                :default-sort="sort.prop ? sort : undefined" @sort-change="onSort">
                                <el-table-column v-for="field in groupFields" :key="field" :label="utmLabels[field]" min-width="170">
                                    <template #default="scope">
                                        <span v-if="scope.row.is_none" class="unknown">{{ translate('(none)') }}</span>
                                        <!-- Plain text that underlines on hover or focus: a click filters the table by this name -->
                                        <button v-else-if="scope.row[field] && quickSearchEnabled" type="button"
                                            class="p-0 bg-transparent border-0 cursor-pointer text-left text-inherit hover:underline focus-visible:underline"
                                            :title="translate('Filter by this name')" @click="search = scope.row[field]">
                                            {{ scope.row[field] }}
                                        </button>
                                        <span v-else-if="scope.row[field]">{{ scope.row[field] }}</span>
                                        <span v-else class="unknown">{{ translate('(not set)') }}</span>
                                    </template>
                                </el-table-column>

                                <el-table-column v-if="!groupFields.length" :label="activeGroup.column" min-width="200">
                                    <template #default="scope">
                                        <span :class="{unknown: scope.row.is_none || scope.row.is_unset}">{{ scope.row.label }}</span>
                                    </template>
                                </el-table-column>

                                <el-table-column prop="orders" :label="translate('Orders')" min-width="130" sortable="custom">
                                    <template #default="scope">
                                        <div class="inline-flex items-center gap-2">
                                            {{ formatNumber(scope.row.orders) }}
                                            <Fluctuation v-if="fluctuations[scope.row.utm_key]" class="flex"
                                                :fluctuation="fluctuations[scope.row.utm_key].orders_fluctuation || 0"
                                                :amount="fluctuations[scope.row.utm_key].previous_orders || 0"
                                                :has-currency="false" />
                                        </div>
                                    </template>
                                </el-table-column>

                                <el-table-column v-for="column in metricColumns" :key="column.prop" :prop="column.prop"
                                    :label="column.label" min-width="140" sortable="custom">
                                    <template #default="scope">
                                        <div class="inline-flex items-center gap-2">
                                            <template v-if="column.percent">{{ scope.row[column.prop] }}%</template>
                                            <template v-else>{{ money(scope.row[column.prop]) }}</template>
                                            <Fluctuation v-if="column.fluctuation && fluctuations[scope.row.utm_key]" class="flex"
                                                :fluctuation="fluctuations[scope.row.utm_key][column.fluctuation + '_fluctuation'] || 0"
                                                :amount="fluctuations[scope.row.utm_key]['previous_' + column.fluctuation] || 0" />
                                        </div>
                                    </template>
                                </el-table-column>

                                <template #empty>
                                    <Empty icon="Empty/Order" has-dark :text="translate('No data available')" />
                                </template>
                            </el-table>

                            <Pagination v-if="pagination.total > 10" :pagination="pagination" :hide_on_single="false"
                                @fetch="getSourceReport"/>

                            <p class="px-4 py-3 text-xs text-gray-500 m-0">
                                {{ translate('SourceRpt_Spellings_Differ') }}
                            </p>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </UserCan>
</template>

<style lang="scss">
.fct-source-report {
    .unknown {
        @apply text-neutral-900 italic;
    }
}
</style>
