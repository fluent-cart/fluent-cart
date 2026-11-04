<script setup>
import { computed, ref } from "vue";
import dayjs from "dayjs";
import translate, { translateNumber } from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import FilterDropdown from "@/Bits/Components/FilterDropdown/FilterDropdown.vue";
import CurrencyFilter from "@/Bits/Components/FilterDropdown/parts/CurrencyFilter.vue";
import AppConfig from "@/utils/Config/AppConfig";
import reportFilter from "@/Models/Reports/ReportFilterModel";
import { fluentDayjsLocale, resolveDateFormat, storeNow } from "@/utils/dateFormats";
import { storeTimeNote } from "../Utils/decorator";
import { reportPresets, findPreset, presetRange } from "../Utils/datePresets";

// The title-bar controls of every report page: date, compare, currency (only with 2+), Filters.
// They write through the same FilterState methods as before, so the API parameters do not change.
const props = defineProps({
  filterState: {
    type: Object,
    required: true,
  },
  compareRanges: {
    type: Object,
    default: null,
  },
  showCompare: {
    type: Boolean,
    default: true,
  },
  showFilters: {
    type: Boolean,
    default: true,
  },
  showDate: {
    type: Boolean,
    default: true,
  },
  activeFilterCount: {
    type: Number,
    default: 0,
  },
});

const filter = props.filterState;
const hasPro = computed(() => AppConfig.get("app_config.isProActive"));
const dateOpen = ref(false);
const customOpen = ref(false);
const customRange = ref(null);

const formatDay = (day, withYear) => {
  const format = resolveDateFormat(withYear ? "date" : "date_short").replace("MMMM", "MMM");

  return translateNumber(dayjs(day).locale(fluentDayjsLocale()).format(format));
};

const formatRange = (start, end) => {
  if (!start || !end) {
    return "";
  }
  const sameYear = dayjs(start).year() === dayjs(end).year();

  /* translators: %1$s - the first item, %2$s - the second item (for example a start and an end date) */
  return translate("%1$s – %2$s", formatDay(start, !sameYear || dayjs(start).year() !== storeNow().year()), formatDay(end, true));
};

const presets = computed(() => reportPresets());

// The saved preset key, not its dates: "Last 7 days" opened tomorrow still means the last 7 days
const activePreset = computed(() => findPreset(reportFilter.data.rangeKey));

const currentRange = computed(() => (activePreset.value ? presetRange(activePreset.value.key) : (filter.data.dateRange || [])));

const dateLabel = computed(() => formatRange(currentRange.value[0], currentRange.value[1]));

const dateText = computed(() => (activePreset.value ? activePreset.value.label : (dateLabel.value || translate("Select dates"))));

/* translators: %1$s - the selected date range, e.g. Last 7 days (Oct 26 – Nov 1, 2026) */
const dateAriaLabel = computed(() => translate("Date range: %1$s", activePreset.value && dateLabel.value ? activePreset.value.label + " (" + dateLabel.value + ")" : dateText.value));

const applyRange = (range, key) => {
  const value = [range[0].toDate ? range[0].toDate() : range[0], range[1].toDate ? range[1].toDate() : range[1]];
  // Same path as the date picker: v-model on dateRange, then setDateRange
  filter.data.dateRange = value;
  filter.setDateRange(value, key);
  dateOpen.value = false;
  customOpen.value = false;
};

const openCustom = () => {
  customRange.value = currentRange.value.length === 2 ? [...currentRange.value] : null;
  customOpen.value = true;
};

const compareType = computed({
  get: () => filter.data.selectedFilters.compareType?.value ?? filter.data.compareType,
  set: (value) => filter.setCompareTypeChange(value),
});

const compareDate = computed({
  get: () => filter.data.selectedFilters.compareDate?.value ?? filter.data.compareDate,
  set: (value) => filter.setCompareDateChange(value),
});

const compareOptions = computed(() => [
  {value: "previous_period", label: translate("Previous period")},
  {value: "previous_month", label: translate("Previous month")},
  {value: "previous_quarter", label: translate("Previous quarter")},
  {value: "previous_year", label: translate("Previous year")},
  {value: "custom", label: translate("Custom")},
  {value: "no_comparison", label: translate("No comparison")},
]);

// Compare ranges are wall-clock days in the offset the request used; reading the date part keeps the day
const compareRangeText = (type) => {
  const range = (props.compareRanges || {})[type];

  return range?.start && range?.end ? formatRange(String(range.start).slice(0, 10), String(range.end).slice(0, 10)) : "";
};

const compareLabel = computed(() => {
  const option = compareOptions.value.find(item => item.value === compareType.value);
  if (!option || compareType.value === "no_comparison") {
    return translate("No comparison");
  }

  /* translators: %1$s - the compare period, e.g. Previous period */
  return translate("vs %1$s", option.label);
});

const realCurrencies = computed(() => filter.currencies.filter(currency => currency.value !== "__all__"));

const currencyItem = computed(() => filter.data.selectedFilters.currency);

const onCurrencyChange = (value) => {
  filter.setSelectChange(value, {...currencyItem.value, value: "currency", label: translate("Currency"), type: "currency"});
};
</script>

<template>
  <div class="fct-report-filter-bar">
    <el-popover v-if="showDate" v-model:visible="dateOpen" placement="bottom-end" :width="customOpen ? 'auto' : 220" trigger="click" popper-class="fct-report-filter-popover"
        @after-leave="customOpen = false">
      <template #reference>
        <el-button size="small" class="fct-report-filter-bar-button" :aria-label="dateAriaLabel" :title="activePreset ? dateLabel : ''">
          <DynamicIcon name="Calendar" class="w-4 h-4" />
          <span>{{ dateText }}</span>
          <DynamicIcon name="ChevronDown" class="w-3.5 h-3.5" />
        </el-button>
      </template>

      <ul v-if="!customOpen" class="fct-report-filter-menu">
        <li v-for="preset in presets" :key="preset.key">
          <button type="button" :class="{'is-active': activePreset && activePreset.key === preset.key}" @click="applyRange(presetRange(preset.key), preset.key)">
            {{ preset.label }}
          </button>
        </li>
        <li>
          <button type="button" :class="{'is-active': !activePreset}" @click="openCustom">{{ translate('Custom…') }}</button>
        </li>
      </ul>

      <div v-else class="fct-report-filter-custom">
        <el-date-picker
            v-model="customRange"
            type="daterange"
            size="small"
            unlink-panels
            :start-placeholder="translate('Start date')"
            :end-placeholder="translate('End date')"
            :teleported="false"
        />
        <div class="fct-report-filter-custom-actions">
          <el-button size="small" text @click="customOpen = false">{{ translate('Back') }}</el-button>
          <el-button size="small" type="primary" :disabled="!customRange || customRange.length !== 2" @click="applyRange(customRange, 'custom')">
            {{ translate('Apply') }}
          </el-button>
        </div>
      </div>
      <p class="fct-report-filter-note">{{ storeTimeNote() }}</p>
    </el-popover>

    <el-dropdown v-if="showCompare" trigger="click" placement="bottom-end" :disabled="!hasPro" @command="(value) => (compareType = value)">
      <el-button size="small" class="fct-report-filter-bar-button">
        <span>{{ compareLabel }}</span>
        <DynamicIcon name="ChevronDown" class="w-3.5 h-3.5" />
      </el-button>
      <template #dropdown>
        <el-dropdown-menu class="fct-report-filter-compare-menu">
          <el-dropdown-item v-for="option in compareOptions" :key="option.value" :command="option.value" :class="{'is-active': compareType === option.value}">
            <span class="fct-report-filter-compare-label">{{ option.label }}</span>
            <span v-if="compareRangeText(option.value)" class="fct-report-filter-compare-range">{{ compareRangeText(option.value) }}</span>
          </el-dropdown-item>
        </el-dropdown-menu>
      </template>
    </el-dropdown>

    <el-date-picker
        v-if="showCompare && compareType === 'custom'"
        v-model="compareDate"
        type="date"
        size="small"
        class="fct-report-filter-compare-date"
        :placeholder="translate('Compare from')"
    />

    <CurrencyFilter
        v-if="realCurrencies.length > 1"
        v-model="filter.data.currency"
        :aria-label="translate('Currency')"
        :currencies="filter.currencies"
        @change="onCurrencyChange"
        @clear="filter.removeFilter('currency')"
    />

    <slot/>

    <FilterDropdown v-if="showFilters" :filter-state="filter" :label="translate('Filters')" :count="activeFilterCount" />
  </div>
</template>
