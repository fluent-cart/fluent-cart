<template>
  <div class="fct-compare-to-filter">
    <div class="fct-applied-filter-label">{{translate('Compare to')}}</div>
    <div class="fct-applied-filter-actions">
      <div class="fct-compare-to-select">
        <el-select
            v-model="compareType"
            popper-class="fct-dropdown-compact"
            :disabled="!hasPro"
        >
          <el-option :label="translate('Previous Period')" value="previous_period"/>
          <el-option :label="translate('Previous Month')" value="previous_month"/>
          <el-option :label="translate('Previous Quarter')" value="previous_quarter"/>
          <el-option :label="translate('Previous Year')" value="previous_year"/>
          <el-option :label="translate('Custom')" value="custom"/>
          <el-option :label="translate('No Comparison')" value="no_comparison"/>
        </el-select>
      </div>

      <div class="fct-compare-to-date">
        <el-date-picker
            v-if="compareType === 'custom'"
            v-model="compareDate"
            type="date"
            size="small"
            :placeholder="translate('Select Date')"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import {computed, onMounted} from "vue";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";

const props = defineProps({
  filterState: {
    type: [Object]
  }
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

const hasPro = computed(() =>
    AppConfig.get('app_config.isProActive')
);
</script>

