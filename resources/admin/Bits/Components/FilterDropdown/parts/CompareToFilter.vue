<template>
  <div class="fct-compare-to-filter">
    <div class="fct-applied-filter-label">{{translate('Compare to')}}</div>
    <div class="fct-applied-filter-actions">
      <div class="fct-compare-to-select">
        <el-select
            v-model="compareType"
            popper-class="fct-dropdown-compact fct-compare-to-dropdown"
            :disabled="!hasPro"
        >
          <el-option
              v-for="option in compareOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
          >
            <span class="fct-compare-to-option-label">{{ option.label }}</span>
            <span v-if="optionRangeText[option.value]" class="fct-compare-to-option-range">{{ optionRangeText[option.value] }}</span>
          </el-option>
        </el-select>
      </div>

      <div v-if="compareType === 'custom'" class="fct-compare-to-date">
        <el-date-picker
            v-model="compareDate"
            type="date"
            size="small"
            :placeholder="translate('Select Date')"
        />
      </div>

      <span v-if="compareRangeText" class="fct-compare-to-range">{{ compareRangeText }}</span>
    </div>
  </div>
</template>

<script setup>
import {computed} from "vue";
import dayjs from "dayjs";
import translate, {translateNumber} from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import {fluentDayjsLocale, resolveDateFormat, storeNow} from "@/utils/dateFormats";

const props = defineProps({
  filterState: {
    type: [Object]
  },
  compareRange: {
    type: Object,
    default: null,
  },
  compareRanges: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['selectChange', 'dateChange']);
const filter = props.filterState;

const compareType = computed({
  get: () => filter.data.selectedFilters.compareType?.value ?? filter.data.compareType,
  set: (val) => {
    filter.setCompareTypeChange(val);
    emit('selectChange', val);
  }
});

const compareDate = computed({
  get: () => filter.data.selectedFilters.compareDate?.value ?? filter.data.compareDate,
  set: (val) => {
    filter.setCompareDateChange(val);
    emit('dateChange', val);
  }
});

const compareOptions = computed(() => [
  {value: 'previous_period', label: translate('Previous Period')},
  {value: 'previous_month', label: translate('Previous Month')},
  {value: 'previous_quarter', label: translate('Previous Quarter')},
  {value: 'previous_year', label: translate('Previous Year')},
  {value: 'custom', label: translate('Custom')},
  {value: 'no_comparison', label: translate('No Comparison')},
]);

// compare ranges are wall-clock time in the offset the request was sent with; a timezone shift would move the day
const toRangeDay = (value) => dayjs(String(value).slice(0, 10));

const formatRange = (range) => {
  if (!range?.start || !range?.end) return '';

  const start = toRangeDay(range.start);
  const end = toRangeDay(range.end);
  // abbreviated month keeps prior-year windows as short as their sibling options
  const format = resolveDateFormat(start.year() === end.year() && start.year() === storeNow().year() ? 'date_short' : 'date')
    .replace('MMMM', 'MMM');
  const formatDay = (day) => translateNumber(day.locale(fluentDayjsLocale()).format(format));

  /* translators: %1$s - compare period start date, %2$s - compare period end date */
  return translate('%1$s – %2$s', formatDay(start), formatDay(end));
};

const compareRangeText = computed(() =>
    compareType.value === 'no_comparison' ? '' : formatRange(props.compareRange)
);

const optionRangeText = computed(() => {
  const texts = {};
  Object.entries(props.compareRanges || {}).forEach(([type, range]) => {
    texts[type] = formatRange(range);
  });
  return texts;
});

const hasPro = computed(() =>
    AppConfig.get('app_config.isProActive')
);
</script>
