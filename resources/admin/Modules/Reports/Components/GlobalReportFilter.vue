<template>
  <div class="fct-report-global-filter-wrap flex justify-between items-center">
    <div class="fct-form-row col-5">
      <div
        v-if="reportFilter.data.active_tab === 'product-report'"
        class="fct-form-group"
      >
        <label>{{ $t("Product") }}</label>
        <el-tree-select
          v-model="filters.variation_ids"
          :data="products"
          :filter-method="Utils.debounce(searchVariantByName)"
          filterable
          show-checkbox
          multiple
          clearable
          :render-after-expand="true"
          :loading="loading"
          collapse-tags
          @check="reportFilter.onFilterChange"
          popper-class="fct-tooltip-long"
        />
      </div>

      <div class="fct-form-group">
        <label>{{ $t("Order Status") }}</label>
        <el-select
          size="small"
          v-model="filters.orderStatus"
          :placeholder="$t('Select')"
          filterable
          @change="reportFilter.onFilterChange"
          class="custom-select-with-label"
        >
          <el-option
            v-for="status in filters.orderStatuses"
            :key="status.value"
            :label="status.label"
            :value="status.value"
          />
        </el-select>
      </div>

      <div class="fct-form-group">
        <label>{{ $t("Payment Status") }}</label>
        <el-select
          size="small"
          v-model="filters.paymentStatus"
          :placeholder="$t('Payment Status')"
          filterable
          @change="reportFilter.onFilterChange"
        >
          <el-option
            v-for="status in filters.paymentStatuses"
            :key="status.value"
            :label="status.label"
            :value="status.value"
          />
        </el-select>
      </div>

      <div
        v-if="filters.currencies && Object.keys(filters.currencies).length > 1"
        class="fct-form-group"
      >
        <label>{{ $t("Currency") }}</label>
        <el-select
          v-model="filters.currency"
          :placeholder="$t('Select Currency')"
          filterable
          @change="reportFilter.onFilterChange"
        >
          <el-option
            v-for="(sign, currency) in filters.currencies"
            :key="currency"
            :label="currency"
            :value="currency"
          />
        </el-select>
      </div>

      <div class="fct-form-group">
        <label>{{ $t("Date") }}</label>
        <el-date-picker
          size="small"
          v-model="filters.dateRange"
          type="daterange"
          range-separator="-"
          :start-placeholder="$t('Start date')"
          :end-placeholder="$t('End date')"
          unlink-panels
          :clearable="false"
          :shortcuts="dateShortcuts"
          @change="handleDateChange"
        />
      </div>
    </div>
    <div class="fct-filter-mode w-[220px] flex gap-3">
      <div
        class="fct-form-group fct-mode-switch"
        :class="{ 'fct-disabled': !isEditable }"
      >
        <el-dropdown trigger="click" @command="changeMode">
          <template #default>
            <span class="el-dropdown-link fct-mode-toggle">
              <LiveMode v-if="mode === 'Live Mode'" />
              <TestMode v-else />
              {{ mode }}
            </span>
          </template>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="live">
                <LiveMode />
                {{ $t("Live Mode") }}
              </el-dropdown-item>
              <el-dropdown-item command="test">
                <TestMode />
                {{ $t("Test Mode") }}
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>

      <div class="fct-form-group">
        <DynamicIcon
          class="w-[15px]"
          name="Edit"
          @click="isEditable = !isEditable"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import dateShortcuts from "@/Modules/Reports/Utils/dateShortCuts";
import { getCurrentInstance, ref, computed } from "vue";
import Utils from "@/utils/Utils";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import LiveMode from "@/Bits/Components/Icons/LiveMode.vue";
import TestMode from "@/Bits/Components/Icons/TestMode.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  reportFilter: {
    required: true,
  },
});
const filters = props.reportFilter.data;
const isEditable = ref(false);
let dateRange = filters.dateRange;

const selfRef = getCurrentInstance().ctx;

const loading = ref(false);

const products = ref([]);

const changeMode = (mode) => {
  filters.filterMode = mode;
  props.reportFilter.onFilterChange();
};

const mode = computed(() => {
  isEditable.value = false;
  const currentMode = filters.filterMode || filters.storeMode;
  return currentMode === "test" ? "Test Mode" : "Live Mode";
});

const searchVariantByName = (name) => {
  loading.value = true;
  selfRef
    .$get("products/searchVariantByName", {
      name: name,
    })
    .then((response) => {
      products.value = response;
    })
    .catch((errors) => {})
    .finally(() => {
      loading.value = false;
    });
};

const handleDateChange = (dateRange) => {
  const selectedText = dateShortcuts.find((shortcut) => {
    const [shortcutStart, shortcutEnd] = shortcut.value();
    return (
      dateRange[0].toISOString() === shortcutStart.toISOString() &&
      dateRange[1].toISOString() === shortcutEnd.toISOString()
    );
  })?.text;
  filters.rangeKey = selectedText;
  props.reportFilter.onFilterChange();
};
</script>

<style scoped>
.fct-mode-toggle {
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.fct-disabled {
  pointer-events: none; /* Disables clicking */
  opacity: 0.5; /* Optionally, add opacity to indicate it's disabled */
}
</style>
