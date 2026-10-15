<template>
  <el-select
      v-model="filter.data.selectedFilters[filterItemKey].value"
      :placeholder="$t('Select Status')"
      filterable
      clearable
      @change="onChange"
      popper-class="fct-dropdown-compact"
      size="small"
  >
    <template #label="{ label }">
      <el-tag disable-transitions>{{ label }}</el-tag>
    </template>
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

const emit = defineEmits(['clear']);

const filter = props.filterState;

// Element Plus fires change (with the empty value) and then clear for one
// click; routing clear off change keeps the report to a single refresh.
const onChange = (value) => {
  if (value) {
    filter.applyFilterChange();
  } else {
    emit('clear');
  }
};
</script>

