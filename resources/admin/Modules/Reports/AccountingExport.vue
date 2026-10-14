<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import translate from "@/utils/translator/Translator";
import {computed, onMounted, ref} from "vue";
import {useExport} from "@/utils/export/useExport";

// Reports → Export (FluentCart Pro): OSS / accounting-ready rows for this
// store, dev-docs/multi-currency/plan.md §15.3. The rows come from
// data-export/accounting/batch; useExport writes the file in the browser.

const pad = (value) => String(value).padStart(2, '0');
const isoDate = (date) => `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;

// Calendar quarters in UTC, `back` quarters before the current one
const quarterRange = (back) => {
  const now = new Date();
  const quarter = Math.floor(now.getUTCMonth() / 3) - back;
  const year = now.getUTCFullYear() + Math.floor(quarter / 4);
  const startMonth = ((quarter % 4) + 4) % 4 * 3;
  const start = new Date(Date.UTC(year, startMonth, 1));
  const end = new Date(Date.UTC(year, startMonth + 3, 0));
  return [isoDate(start), isoDate(end)];
};

const quarterLabel = (back) => {
  const [start] = quarterRange(back);
  return `${start.slice(0, 4)}-Q${Math.floor(Number(start.slice(5, 7)) / 3) + 1}`;
};

const period = ref(quarterRange(1));
const scheme = ref('all');
const includeTest = ref(false);
const format = ref('csv');

const filters = () => ({
  date_from: period.value?.[0] || '',
  date_to: period.value?.[1] || '',
  scheme: scheme.value,
  include_test: includeTest.value ? 'yes' : 'no',
});

const exportState = useExport({
  entity: 'accounting',
  filenamePrefix: 'accounting-export',
  buildAllParams: filters,
  buildParams: filters,
});

const schema = computed(() => exportState.schema.value);
const schemes = computed(() => schema.value?.schemes || {});
const canExport = computed(() => !!schema.value && !!period.value?.[0] && !exportState.isExporting.value);

const startExport = () => {
  exportState.startExport({
    format: format.value,
    scope: 'all',
    columns: (schema.value?.csv_columns || []).map(column => column.key),
    modules: [],
  });
};

onMounted(() => {
  exportState.loadSchema().catch(() => {});
});
</script>

<template>
  <div class="fct-accounting-export">
    <Card.Container>
      <Card.Header
          :title="translate('Accounting export')"
          :text="translate('Every sale and refund with its tax line, currency and exchange rates, for your accounting or tax software.')"
          border_bottom
      />
      <Card.Body>
        <el-alert v-if="!exportState.isProActive" type="warning" :closable="false"
                  :title="translate('The accounting export is part of FluentCart Pro.')"/>

        <template v-else>
          <el-alert v-if="exportState.schemaError.value" type="error" :closable="false" :title="exportState.schemaError.value"/>

          <el-alert v-if="schema?.notice" class="mb-4" type="info" :closable="false" :title="schema.notice"/>

          <el-form label-position="top" class="max-w-[640px]">
            <el-form-item :label="translate('Period (UTC)')">
              <div class="flex flex-wrap items-center gap-2 w-full">
                <el-date-picker
                    v-model="period"
                    type="daterange"
                    value-format="YYYY-MM-DD"
                    :start-placeholder="translate('From')"
                    :end-placeholder="translate('To')"
                    :clearable="false"
                    :disabled="exportState.isExporting.value"
                />
                <el-button size="small" :disabled="exportState.isExporting.value" @click="period = quarterRange(1)">{{ quarterLabel(1) }}</el-button>
                <el-button size="small" :disabled="exportState.isExporting.value" @click="period = quarterRange(0)">{{ quarterLabel(0) }}</el-button>
              </div>
            </el-form-item>

            <el-form-item :label="translate('Tax scheme')">
              <el-select v-model="scheme" class="w-full" :disabled="exportState.isExporting.value">
                <el-option :label="translate('All')" value="all"/>
                <el-option v-for="(label, key) in schemes" :key="key" :label="label" :value="key"/>
              </el-select>
            </el-form-item>

            <el-form-item :label="translate('Format')">
              <el-radio-group v-model="format" :disabled="exportState.isExporting.value">
                <el-radio value="csv">CSV</el-radio>
                <el-radio value="json">JSON</el-radio>
              </el-radio-group>
            </el-form-item>

            <el-form-item>
              <el-checkbox v-model="includeTest" :disabled="exportState.isExporting.value">{{ translate('Include test-mode orders') }}</el-checkbox>
            </el-form-item>

            <div class="flex items-center gap-3">
              <el-button type="primary" :disabled="!canExport" :loading="exportState.isExporting.value" @click="startExport">
                {{ translate('Export') }}
              </el-button>
              <el-button v-if="exportState.isExporting.value" @click="exportState.cancelExport()">
                {{ translate('Cancel') }}
              </el-button>
              <span v-if="exportState.isExporting.value || exportState.isComplete.value" class="text-sm">
                {{ translate('%1$s rows written', exportState.progress.value.fetched) }}
              </span>
            </div>

            <el-alert v-if="exportState.hasError.value" class="mt-4" type="error" :closable="false" :title="exportState.errorMessage.value"/>
          </el-form>

          <p class="mt-4 mb-0 text-xs">
            {{ translate('Refunds are negative rows; their tax is estimated in proportion to the order and marked in the Tax estimated column. ECB rates are units of the order currency per 1 EUR, for the transaction date and the last day of its quarter.') }}
          </p>
        </template>
      </Card.Body>
    </Card.Container>
  </div>
</template>
