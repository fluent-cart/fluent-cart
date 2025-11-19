<template>
  <el-select
      v-model="orderTypes"
      :placeholder="$t('Select Order Type')"
      multiple
      filterable
      popper-class="fct-dropdown-compact fct-multi-select-filter"
      size="small"
  >
    <el-option
        v-for="filterOption in filterItem.options"
        :key="filterOption.value"
        :label="filterOption.label"
        :value="filterOption"
    />
  </el-select>
</template>

<script setup>
import {computed, defineProps} from 'vue';

const props = defineProps({
  filterState: {
    type: [Object]
  },
  filterItem: {
    type: Object,
    required: true
  },
  filterItemKey: {
    type: String,
    required: true
  }
});

const filter = props.filterState;

const orderTypes = computed({
  get: () => {
    return filter.data.selectedFilters[props.filterItemKey]?.value ?? [];
  },
  set: (val) => {
    filter.setMultiSelectChange(props.filterItemKey, val);
  }
});
</script>

