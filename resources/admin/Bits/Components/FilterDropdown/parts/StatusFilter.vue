<template>
  <el-select
      v-model="filter.data.selectedFilters[filterItemKey].value"
      :placeholder="$t('Select Status')"
      filterable
      @change="filter.applyFilterChange"
      popper-class="fct-dropdown-compact"
      size="small"
  >
    <el-option
        v-for="filterOption in filterItem.options"
        :key="filterOption.value"
        :label="filterOption.label"
        :value="filterOption.value"
    />
  </el-select>
</template>

<script setup>
import { defineProps } from 'vue';

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
</script>

