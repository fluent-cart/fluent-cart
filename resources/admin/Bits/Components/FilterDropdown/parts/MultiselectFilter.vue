<template>
  <el-select
      v-model="orderTypes"
      :placeholder="$t('Select Order Type')"
      multiple
      filterable
      clearable
      popper-class="fct-dropdown-compact fct-multi-select-filter"
      size="small"
  >
    <template #tag="{ data }">
      <el-tag v-for="item in data" :key="item.value" disable-transitions>{{ item.currentLabel }}</el-tag>
    </template>
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

