<template>
  <ElTable
      ref="control"
      v-loading="loading"
      :element-loading-text="loadingText"
      :class="$attrs.class"
      :style="$attrs.style"
      :data="data"
      :default-sort="defaultSort"
      :row-class-name="rowClassName"
      :row-key="rowKey"
      :size="size"
      :stripe="stripe"
      :border="border"
      :show-header="showHeader"
      :max-height="maxHeight"
      :empty-text="emptyText"
      :show-summary="showSummary"
      :summary-method="summaryMethod"
      :span-method="spanMethod"
      :cell-style="cellStyle"
      :default-expand-all="defaultExpandAll"
      :tooltip-options="tooltipOptions"
      :aria-label="ariaLabel"
      @selection-change="emit('selection-change', $event)"
      @expand-change="(row, expanded) => emit('expand-change', row, expanded)"
      @row-click="(row, column, event) => emit('row-click', row, event)"
      @sort-change="emit('sort-change', $event)"
  >
    <slot/>
    <template v-if="$slots.empty" #empty>
      <slot name="empty"/>
    </template>
  </ElTable>
</template>

<script setup>
import {ref} from 'vue';
import {ElTable, vLoading} from 'element-plus';

defineOptions({name: 'FctTable', inheritAttrs: false});

defineProps({
  data: {type: Array, default: () => []},
  loading: {type: Boolean, default: false},
  loadingText: {type: String, default: undefined},
  defaultSort: {type: Object, default: undefined},
  rowClassName: {type: [String, Function], default: ''},
  rowKey: {type: [String, Function], default: undefined},
  size: {type: String, default: '', validator: (value) => ['', 'small', 'default', 'large'].includes(value)},
  stripe: {type: Boolean, default: false},
  border: {type: Boolean, default: false},
  showHeader: {type: Boolean, default: true},
  maxHeight: {type: [String, Number], default: undefined},
  emptyText: {type: String, default: undefined},
  // A totals row: summaryMethod({columns, data}) returns one cell per column.
  showSummary: {type: Boolean, default: false},
  summaryMethod: {type: Function, default: undefined},
  spanMethod: {type: Function, default: undefined},
  cellStyle: {type: [Object, Function], default: undefined},
  defaultExpandAll: {type: Boolean, default: false},
  tooltipOptions: {type: Object, default: undefined},
  ariaLabel: {type: String, default: undefined},
});

const emit = defineEmits(['selection-change', 'expand-change', 'row-click', 'sort-change']);

const control = ref(null);
defineExpose({
  toggleRowSelection: (row, selected) => control.value && control.value.toggleRowSelection(row, selected),
  toggleRowExpansion: (row, expanded) => control.value && control.value.toggleRowExpansion(row, expanded),
  clearSelection: () => control.value && control.value.clearSelection(),
});
</script>
