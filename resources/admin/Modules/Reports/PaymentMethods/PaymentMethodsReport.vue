<script setup>
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import { computed, ref, onMounted, onUnmounted } from "vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import { formatNumber } from "../Utils/formatNumber";
import Empty from "@/Bits/Components/Table/Empty.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import ReportSummary from "@/Bits/Components/ReportSummary.vue";
import Fluctuation from "@/Bits/Components/Fluctuation.vue";
import SummaryLoader from "../Components/SummaryLoader.vue";
import PaymentMethodsChart from "./PaymentMethodsChart.vue";
import ExtensionComponent from "@/Bits/Components/Extension/ExtensionComponent.vue";
import ProReportPreview from "../Components/ProReportPreview.vue";
import ProReportSample from "../Components/ProReportSample.vue";
import { exportCSVWithColumns } from "@/utils/Export";

const props = defineProps({
    reportFilter: {
        type: Object,
        required: true,
    },
});

const data = ref({rows: [], totals: {}, previous: null, chart: [], methods: {}});
const loading = ref(true);
let latestRequestId = 0;

const getReport = (filters) => {
    const requestId = ++latestRequestId;
    loading.value = true;

    const call = Rest.get('reports/payment-methods', {params: (filters || props.reportFilter.applicableFilters).params});
    props.reportFilter.trackCompareRange(call);
    call.then((response) => {
            if (requestId !== latestRequestId) return;
            data.value = {rows: [], totals: {}, previous: null, chart: [], methods: {}, ...response};
        })
        .catch((errors) => {
            if (requestId !== latestRequestId) return;
            Notify.error(errors?.data?.message || translate('Failed to load the payment methods report'));
        })
        .finally(() => {
            if (requestId !== latestRequestId) return;
            loading.value = false;
        });
};

const rows = computed(() => data.value.rows || []);
const totals = computed(() => data.value.totals || {});
const byNumber = (key) => (a, b) => Number(a[key]) - Number(b[key]);
const money = (value) => props.reportFilter.formatAmount(value || 0);
/* translators: %1$s - left empty; the text follows a change in percentage points, e.g. "+1.2%" then " pts" */
const pointsSuffix = translate('%1$s pts', '');

const cards = computed(() => {
    const summary = totals.value;
    const previous = data.value.previous;
    // A rate changes in points, an amount as a share of itself
    const change = (key, points) => {
        if (!previous || (!points && !previous[key])) {
            return null;
        }
        return points
            ? Math.round(((summary[key] || 0) - (previous[key] || 0)) * 10) / 10
            : Math.round((summary[key] - previous[key]) / Math.abs(previous[key]) * 1000) / 10;
    };
    const card = (key, title, value, info, extra = {}) => ({
        key, title, value, info, change: extra.now ? null : change(key, !!extra.points), previous: previous ? previous[key] : null, ...extra,
    });

    return [
        card('net_sales', translate('Net sales'), money(summary.net_sales),
            translate('What customers paid in this period, less refunds and tax.'), {money: true}),
        card('success_rate', translate('Payment success rate'), (summary.success_rate || 0) + '%',
            translate('GatewayRpt_Paid_Orders'),
            {points: true}),
        card('refund_rate', translate('Refund rate'), (summary.refund_rate || 0) + '%',
            translate('Orders in this period refunded in part or full.'), {points: true, liability: true}),
        card('mrr_at_risk', translate('MRR at risk (now)'), money(summary.mrr_at_risk),
            translate('GatewayRpt_Monthly_Recurring_Revenue'),
            {now: true}),
    ];
});

const groups = [
    {label: translate('Sales'), columns: [
        {prop: 'orders', label: translate('Orders'), count: true},
        {prop: 'net_sales', label: translate('Net'), money: true},
        {prop: 'net_share', label: translate('Share of Net'), percent: true},
        {prop: 'renewal_net', label: translate('From Renewals'), money: true,
            info: translate('Net sales from subscription renewals and installment payments.')},
        {prop: 'refunds', label: translate('Refunds'), money: true},
        {prop: 'refund_rate', label: translate('Refund Rate'), percent: true},
        {prop: 'average_order', label: translate('Average Order'), money: true},
    ]},
    {label: translate('Risk'), columns: [
        {prop: 'success_rate', label: translate('Payment Success'), percent: true,
            info: translate('Paid orders out of paid orders and recorded declined charges.')},
        {prop: 'failed_charges', label: translate('Declined Charges'), count: true,
            info: translate('GatewayRpt_Declined_Charges')},
        {prop: 'failed_orders', label: translate('Failed Orders'), count: true,
            info: translate('Orders created in this period whose payment failed.')},
        {prop: 'failing_subscriptions', label: translate('Failing Subscriptions (Now)'), count: true,
            info: translate('GatewayRpt_Subscriptions_Whose')},
        {prop: 'mrr_at_risk', label: translate('MRR at Risk (Now)'), money: true,
            info: translate('GatewayRpt_Monthly_Recurring')},
    ]},
];
const columns = groups.flatMap(group => group.columns);

const cell = (column, row) => {
    if (column.money) {
        return money(row[column.prop]);
    }
    if (column.percent) {
        return (row[column.prop] || 0) + '%';
    }
    const text = formatNumber(row[column.prop] || 0);
    if (column.rate && row[column.prop]) {
        return text + ' · ' + (row[column.rate] || 0) + '%';
    }

    return column.amount && row[column.prop] ? text + ' · ' + money(row[column.amount]) : text;
};

// The totals row comes from the server; MRR at risk is rounded once there, as on the Subscriptions report
const summaryRow = ({columns: tableColumns}) => tableColumns.map((column, index) => {
    if (index === 0) {
        return translate('Total');
    }
    const definition = columns.find(item => item.prop === column.property);

    return definition ? cell(definition, totals.value) : '';
});

const exportCsv = () => {
    const csvColumns = [{header: 'gateway', accessor: 'label'}];
    ['orders', 'gross_sales', 'refunds', 'net_sales', 'net_share', 'renewal_net', 'refund_rate', 'average_order', 'success_rate',
        'failed_charges', 'failed_charge_amount', 'failed_orders',
        'failing_subscriptions', 'mrr_at_risk']
        .forEach(key => csvColumns.push({header: key, accessor: key}));

    exportCSVWithColumns([...rows.value, {...totals.value, label: translate('Total')}], csvColumns, 'payment-methods.csv')
        .catch(error => Notify.error(error.message));
};

onMounted(() => {
    getReport();
    props.reportFilter.addListener("payment-methods-report", getReport);
});

onUnmounted(() => {
    props.reportFilter.removeListener("payment-methods-report");
    latestRequestId++;
});
</script>

<template>
    <UserCan :permission="'reports/view'">
        <div class="fct-revenue-report-page">
            <PageHeading :title="translate('Payment Methods')"></PageHeading>

            <SummaryLoader v-if="loading" :loading="loading" :count="4"/>
            <div v-else class="fct-report-order-summary summary-col-4">
                <div class="summary-item" v-for="item in cards" :key="item.key">
                    <div class="summary-item-inner">
                        <div class="value">
                            <div class="inner-value">
                                <ReportSummary :amount="item.value" :title="item.title"/>
                            </div>
                            <Fluctuation
                                v-if="item.change !== null"
                                :fluctuation="item.change"
                                :amount="item.previous"
                                :has-currency="!!item.money"
                                :is-percentage="!!item.points"
                                :is-liability="!!item.liability"
                                :fluctuation-suffix="item.points ? pointsSuffix : ''"
                            />
                        </div>
                        <div class="title">
                            {{ item.title }}
                            <el-tooltip :content="item.info" placement="top" popper-class="fct-tooltip" effect="dark" :trigger="['hover', 'focus']">
                                <button type="button" class="inline-flex p-0 bg-transparent border-0 cursor-help" :aria-label="translate('About this metric')">
                                    <DynamicIcon class="text-gray-500 w-4 h-4" name="InformationFill" aria-hidden="true"/>
                                </button>
                            </el-tooltip>
                        </div>
                    </div>
                </div>
            </div>

            <PaymentMethodsChart :points="data.chart || []" :gateways="rows.filter(row => row.orders > 0)" :loading="loading" :report-filter="reportFilter"/>

            <div class="fct-revenue-comparison fct-table-wrapper">
                <div class="fct-card">
                    <div class="fct-card-header">
                        <div class="fct-card-header-top">
                            <div class="flex-1"></div>
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
                        <el-skeleton class="px-5 pb-5" v-if="loading" :rows="4" animated></el-skeleton>

                        <el-table v-else :data="rows" class="w-full" show-summary :summary-method="summaryRow">
                            <el-table-column :label="translate('Payment Method')" min-width="160" fixed>
                                <template #default="scope">
                                    <span class="font-medium">{{ scope.row.label }}</span>
                                </template>
                            </el-table-column>

                            <el-table-column v-for="group in groups" :key="group.label" :label="group.label" align="center">
                                <el-table-column v-for="column in group.columns" :key="column.prop" :prop="column.prop"
                                    :min-width="column.info ? 170 : 130" sortable :sort-method="byNumber(column.prop)">
                                    <template #header>
                                        {{ column.label }}
                                        <el-tooltip v-if="column.info" :content="column.info" placement="top" popper-class="fct-tooltip" effect="dark">
                                            <DynamicIcon class="text-gray-500 w-4 h-4 cursor-help inline-block align-middle" name="InformationFill" />
                                        </el-tooltip>
                                    </template>
                                    <template #default="scope">{{ cell(column, scope.row) }}</template>
                                </el-table-column>
                            </el-table-column>

                            <template #empty>
                                <Empty icon="Empty/Order" has-dark :text="translate('No orders in this period')" />
                            </template>
                        </el-table>
                    </div>
                </div>
            </div>

            <ExtensionComponent name="reports.payment_methods.insights" :report-filter="reportFilter">
                <ProReportPreview
                    :title="translate('Watch disputes and how customers pay')"
                    :text="translate('GatewayRpt_See_Disputed')"
                    :bullets="[translate('Dispute rate by payment method'), translate('Disputes lost and their amount'), translate('Card brands and wallets')]"
                    placement="feature_lock_report_payment_insights"
                >
                    <ProReportSample
                        :title="translate('Disputes')"
                        :columns="[translate('Payment Method'), translate('Disputed Orders'), translate('Disputes Lost')]"
                        :rows="[[translate('Gateway A'), '2 · 0.4%', '1 · 50.00'], [translate('Gateway B'), '0', '0']]"
                    />
                </ProReportPreview>
            </ExtensionComponent>
        </div>
    </UserCan>
</template>
