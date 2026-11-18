<template>
  <fct-select
      v-model="orderTypes"
      :placeholder="$t('Select Order Type')"
      multiple
      filterable
      clearable
      popper-class="fct-dropdown-compact fct-multi-select-filter"
      size="small"
      :options="filterItem.options.map((filterOption) => ({label: filterOption.label, value: filterOption}))"
  >
    <template #tag="{ data }">
      <el-tag v-for="item in data" :key="item.value" disable-transitions>{{ item.currentLabel }}</el-tag>
    </template>
  </fct-select>
</template>

<script setup>
import {computed} from 'vue';

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

const emit = defineEmits(['clear']);

const filter = props.filterState;

const orderTypes = computed({
  get: () => {
    return filter.data.selectedFilters[props.filterItemKey]?.value ?? [];
  },
  set: (val) => {
    // Element Plus empties the model and then fires clear for one click;
    // clearing from the setter keeps the report to a single refresh.
    if (val.length) {
      filter.setMultiSelectChange(props.filterItemKey, val);
    } else {
      emit('clear');
    }
  }
});
</script>

