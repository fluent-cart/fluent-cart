<script setup>
import { computed } from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import ExportReport from "../ExportReport.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import translate, { translateNumber } from "@/utils/translator/Translator";

const props = defineProps({
  rows: {
    type: Array,
    default: () => [],
  },
  reportFilter: {
    type: Object,
    required: true,
  },
  loading: {
    type: Boolean,
    default: true,
  },
  error: {
    type: String,
    default: "",
  },
});

const emit = defineEmits(["retry"]);

const exportRows = computed(() => props.rows.map((row) => ({
  period: row.group,
  units_sold: Number(row.units_sold) || 0,
  customer_count: Number(row.customer_count) || 0,
  gross_sale: Number(row.gross_sale) || 0,
  total_discounts: Number(row.total_discounts) || 0,
  total_refunds: Number(row.total_refunds) || 0,
  net_sale: Number(row.net_sale) || 0,
})));
</script>

<template>
  <Card.Container class="overflow-hidden">
    <Card.Header :title="translate('Units Sold by Period')" title_size="small">
      <template #action v-if="!loading && !error && exportRows.length">
        <ExportReport :data="exportRows" filename="product_report.csv" />
      </template>
    </Card.Header>
    <Card.Body class="px-0 pb-0">
      <el-skeleton v-if="loading" animated :rows="7" class="p-4" />

      <div v-else-if="error" class="p-4">
        <el-alert type="error" :title="error" :closable="false" show-icon>
          <el-button size="small" class="mt-2" @click="emit('retry')">{{ translate('Retry') }}</el-button>
        </el-alert>
      </div>

      <el-table v-else :data="exportRows" max-height="400">
        <el-table-column prop="period" :label="translate('Period')" min-width="120" />

        <el-table-column prop="units_sold" :label="translate('Units Sold')" min-width="110">
          <template #default="{row}">{{ translateNumber(row.units_sold) }}</template>
        </el-table-column>

        <el-table-column prop="customer_count" :label="translate('Customers')" min-width="110">
          <template #default="{row}">{{ translateNumber(row.customer_count) }}</template>
        </el-table-column>

        <el-table-column prop="gross_sale" :label="translate('Item Sales')" min-width="130">
          <template #default="{row}">{{ reportFilter.formatAmount(row.gross_sale) }}</template>
        </el-table-column>

        <el-table-column prop="total_discounts" :label="translate('Discounts')" min-width="130">
          <template #default="{row}">{{ reportFilter.formatAmount(row.total_discounts) }}</template>
        </el-table-column>

        <el-table-column prop="total_refunds" :label="translate('Refunds')" min-width="130">
          <template #default="{row}">{{ reportFilter.formatAmount(row.total_refunds) }}</template>
        </el-table-column>

        <el-table-column prop="net_sale" :label="translate('Revenue')" min-width="130">
          <template #default="{row}">{{ reportFilter.formatAmount(row.net_sale) }}</template>
        </el-table-column>

        <template #empty>
          <Empty icon="Empty/ListView" :has-dark="true" :text="translate('Currently there is no data!')"/>
        </template>
      </el-table>
    </Card.Body>
  </Card.Container>
</template>
