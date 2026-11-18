<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import translate from "@/utils/translator/Translator";
import Field from '@/Bits/Components/Fct/Field.vue';
import {computed, onMounted, ref} from "vue";
import {useExport} from "@/utils/export/useExport";

// Reports → Export (FluentCart Pro): OSS / accounting-ready rows for this
// store, dev-docs/multi-currency/11-accounting-export.md. The rows come from
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
          :text="translate('Report_Every_Sale')"
          border_bottom
      />
      <Card.Body>
        <el-alert v-if="!exportState.isProActive" type="warning" :closable="false"
                  :title="translate('The accounting export is part of FluentCart Pro.')"/>

        <template v-else>
          <el-alert v-if="exportState.schemaError.value" type="error" :closable="false" :title="exportState.schemaError.value"/>

          <el-alert v-if="schema?.notice" class="mb-4" type="info" :closable="false" :title="schema.notice"/>

          <div class="max-w-[640px]">
            <Field :label="translate('Period (UTC)')">
              <div class="flex flex-wrap items-center gap-2 w-full">
                <fct-date-picker
                    v-model="period"
                    type="daterange"
                    value-format="YYYY-MM-DD"
                    :start-placeholder="translate('From')"
                    :end-placeholder="translate('To')"
                    :clearable="false"
                    :disabled="exportState.isExporting.value"
                />
                <fct-button size="small" :disabled="exportState.isExporting.value" @click="period = quarterRange(1)">{{ quarterLabel(1) }}</fct-button>
                <fct-button size="small" :disabled="exportState.isExporting.value" @click="period = quarterRange(0)">{{ quarterLabel(0) }}</fct-button>
              </div>
            </Field>

            <fct-select v-model="scheme" class="w-full" :label="translate('Tax scheme')" :disabled="exportState.isExporting.value"
                        :options="[{label: translate('All'), value: 'all'}, ...Object.entries(schemes).map(([key, label]) => ({label, value: key}))]"/>

            <fct-choice v-model="format" :label="translate('Format')" :disabled="exportState.isExporting.value" type="radio" :options="[{label: 'CSV', value: 'csv'}, {label: 'JSON', value: 'json'}]"/>

            <fct-choice v-model="includeTest" class="mb-[18px]" :disabled="exportState.isExporting.value">{{ translate('Include test-mode orders') }}</fct-choice>

            <div class="flex items-center gap-3">
              <fct-button type="primary" :disabled="!canExport" :loading="exportState.isExporting.value" @click="startExport">
                {{ translate('Export') }}
              </fct-button>
              <fct-button v-if="exportState.isExporting.value" @click="exportState.cancelExport()">
                {{ translate('Cancel') }}
              </fct-button>
              <span v-if="exportState.isExporting.value || exportState.isComplete.value" class="text-sm">
                {{ translate('%1$s rows written', exportState.progress.value.fetched) }}
              </span>
            </div>

            <el-alert v-if="exportState.hasError.value" class="mt-4" type="error" :closable="false" :title="exportState.errorMessage.value"/>
          </div>

          <p class="mt-4 mb-0 text-xs">
            {{ translate('Report_Refunds_Negative') }}
          </p>
        </template>
      </Card.Body>
    </Card.Container>
  </div>
</template>
