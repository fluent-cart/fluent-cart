<template>
  <fct-select
      v-model="filter.data.selectedFilters[filterItemKey].value"
      :options="filterItem.options"
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
  </fct-select>
</template>

<script setup>

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

