<script setup>
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import { ref, computed, onMounted, onUnmounted } from "vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import { formatNumber } from "../Utils/formatNumber";
import Empty from "@/Bits/Components/Table/Empty.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import CouponOverview from "./CouponOverview.vue";
import { exportCSVWithColumns, csvSafe } from "@/utils/Export";

const props = defineProps({
    reportFilter: {
        type: Object,
        required: true,
    },
});

const rows = ref([]);
const totals = ref({});
const noCode = ref({orders: 0, discount: 0});
const loading = ref(true);
const search = ref('');
let latestRequestId = 0;

const getReport = () => {
    const requestId = ++latestRequestId;
    loading.value = true;

    Rest.get('reports/coupons', {params: props.reportFilter.applicableFilters.params})
        .then((response) => {
            if (requestId !== latestRequestId) return;
            rows.value = response.rows || [];
            totals.value = response.totals || {};
            noCode.value = response.no_code || {orders: 0, discount: 0};
        })
        .catch((errors) => {
            if (requestId !== latestRequestId) return;
            Notify.error(errors?.data?.message || translate('Failed to load the coupons report'));
        })
        .finally(() => {
            if (requestId !== latestRequestId) return;
            loading.value = false;
        });
};

const filteredRows = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) {
        return rows.value;
    }

    return rows.value.filter(row => [row.code, row.title || '', ...(row.used_as || [])].some(text => text.toLowerCase().includes(term)));
});

const byNumber = (key) => (a, b) => Number(a[key]) - Number(b[key]);
const money = (value) => props.reportFilter.formatAmount(value || 0);

// What a coupon cost, what it brought in, and what the customers it won are worth
const allMetricColumns = [
    {prop: 'discount', label: translate('Discount Given'), money: true},
    {prop: 'net_sales', label: translate('Net'), money: true},
    {prop: 'discount_rate', label: translate('Discount Rate'), percent: true},
    {prop: 'won_ltv', label: translate('Value of Customers Won'), money: true},
    {prop: 'refund_rate', label: translate('Refund Rate'), percent: true},
    {prop: 'refunds', label: translate('Refunds'), money: true},
];
// Value of customers won and refund rate come with FluentCart Pro only
const metricColumns = computed(() => allMetricColumns.filter(column => column.prop in totals.value));

// The totals row comes from the server: an order with two coupons counts once there
const summaryRow = ({columns}) => columns.map((column, index) => {
    if (index === 0) {
        return translate('Total');
    }
    const key = column.property;
    if (key === 'orders' || key === 'new_orders' || key === 'returning_orders') {
        return formatNumber(totals.value[key] || 0);
    }
    if (key === 'discount_rate') {
        return (totals.value.discount_rate || 0) + '%';
    }
    const metric = metricColumns.value.find(item => item.prop === key);

    return metric && metric.money ? money(totals.value[key]) : '';
});

const exportCsv = () => {
    const columns = [{header: 'code', accessor: 'code', formatter: csvSafe}];
    ['orders', 'new_orders', 'returning_orders', 'guest_orders', 'customers', 'gross_sales', 'discount', 'discount_rate',
        'refunds', 'refund_rate', 'net_sales', 'first_order_net', 'later_net', 'lifetime_net', 'won_ltv']
        .filter(key => key in totals.value)
        .forEach(key => columns.push({header: key, accessor: key}));

    exportCSVWithColumns([...rows.value, {...totals.value, code: translate('Total')}], columns, 'coupons.csv')
        .catch(error => Notify.error(error.message));
};

onMounted(() => {
    getReport();
    props.reportFilter.addListener("coupons-report", getReport);
});

onUnmounted(() => {
    props.reportFilter.removeListener("coupons-report");
    latestRequestId++;
});
</script>

<template>
    <UserCan :permission="'reports/view'">
        <div class="fct-revenue-report-page">
            <PageHeading :title="translate('Coupons')"></PageHeading>

            <CouponOverview :report-filter="reportFilter"/>

            <div class="fct-revenue-comparison fct-table-wrapper">
                <div class="fct-card">
                    <div class="fct-card-header">
                        <div class="fct-card-header-top">
                            <div class="fct-card-header-left flex-1">
                                <div class="search-bar is-transparent">
                                    <el-input :placeholder="translate('Search')" type="text" clearable v-model="search">
                                        <template #prefix>
                                            <DynamicIcon name="Search" />
                                        </template>
                                    </el-input>
                                </div>
                                <div class="text-xs text-system-light pt-1 dark:text-gray-300">
                                    {{ translate('Filter the rows below by coupon code or title') }}
                                </div>
                            </div>

                            <div class="fct-card-header-actions">
                                <div class="fct-btn-group sm">
                                    <el-tooltip effect="dark" :content="translate('Export CSV')" placement="top" popper-class="fct-tooltip">
                                        <IconButton tag="button" :disabled="loading || !rows.length"
                                            :aria-label="translate('Export CSV')" @click="exportCsv">
                                            <DynamicIcon name="Download" />
                                        </IconButton>
                                    </el-tooltip>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="fct-card-body">
                        <el-skeleton class="px-5 pb-5" v-if="loading" :rows="5" animated></el-skeleton>

                        <template v-else>
                            <el-table :data="filteredRows" class="w-full fct-coupon-report" show-summary :summary-method="summaryRow"
                                :default-sort="{prop: 'orders', order: 'descending'}">
                                <el-table-column :label="translate('Coupon')" min-width="180" fixed>
                                    <template #default="scope">
                                        <div class="font-medium">{{ scope.row.code }}</div>
                                        <div v-if="scope.row.used_as && scope.row.used_as.length" class="text-xs text-system-light dark:text-gray-300">
                                            <!-- translators: %1$s: earlier coupon codes -->
                                            {{ translate('Used as %1$s', scope.row.used_as.join(', ')) }}
                                        </div>
                                        <div v-if="!scope.row.coupon_id" class="text-xs text-system-light dark:text-gray-300">
                                            {{ translate('Deleted coupon') }}
                                        </div>
                                    </template>
                                </el-table-column>

                                <el-table-column prop="orders" :label="translate('Orders')" min-width="110" sortable :sort-method="byNumber('orders')">
                                    <template #default="scope">{{ formatNumber(scope.row.orders) }}</template>
                                </el-table-column>

                                <el-table-column prop="new_orders" :label="translate('New Customers')" min-width="140" sortable :sort-method="byNumber('new_orders')">
                                    <template #default="scope">{{ formatNumber(scope.row.new_orders) }}</template>
                                </el-table-column>

                                <el-table-column prop="returning_orders" :label="translate('Returning')" min-width="120" sortable :sort-method="byNumber('returning_orders')">
                                    <template #default="scope">{{ formatNumber(scope.row.returning_orders) }}</template>
                                </el-table-column>

                                <el-table-column v-for="column in metricColumns" :key="column.prop" :prop="column.prop"
                                    :label="column.label" min-width="140" sortable :sort-method="byNumber(column.prop)">
                                    <template #default="scope">
                                        <template v-if="column.percent">{{ scope.row[column.prop] }}%</template>
                                        <template v-else>{{ money(scope.row[column.prop]) }}</template>
                                    </template>
                                </el-table-column>

                                <template #empty>
                                    <Empty icon="Empty/Order" has-dark :text="translate('No coupon orders in this period')" />
                                </template>
                            </el-table>

                            <p v-if="noCode.orders" class="px-4 pt-3 text-sm m-0">
                                {{ translate('Discount with no code') }}:
                                <strong>{{ money(noCode.discount) }}</strong>
                                <!-- translators: %1$s: number of orders -->
                                ({{ translate('%1$s orders', formatNumber(noCode.orders)) }})
                                <el-tooltip effect="dark" placement="top" popper-class="fct-tooltip"
                                    :content="translate('CouponRpt_Orders_Coupon')">
                                    <DynamicIcon class="text-gray-500 w-4 h-4 cursor-help inline-block align-middle" name="InformationFill" />
                                </el-tooltip>
                            </p>

                            <p class="px-4 py-3 text-xs text-gray-500 m-0">
                                {{ translate('CouponRpt_New_Customers_Counts') }}
                            </p>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </UserCan>
</template>
