<template>
  <ElTableColumn
      :class-name="className || $attrs.class"
      :type="type || undefined"
      :label="label"
      :prop="prop"
      :width="width"
      :min-width="minWidth"
      :sortable="sortable"
      :sort-method="sortMethod"
      :selectable="selectable"
      :align="align"
      :header-align="headerAlign"
      :fixed="fixed"
      :show-overflow-tooltip="showOverflowTooltip"
  >
    <!-- Cells get the row and its index. Element Plus also probes this slot with $index -1; skip that call
         (v-for, not v-if: a v-if would add a `key` to the slot props). -->
    <template v-if="$slots.default" #default="scope">
      <slot v-for="cell in (scope.$index === -1 ? [] : [scope])" :row="cell.row" :index="cell.$index"/>
    </template>
    <template v-if="$slots.header" #header>
      <slot name="header"/>
    </template>
  </ElTableColumn>
</template>

<script setup>
import {ElTableColumn} from 'element-plus';

defineOptions({inheritAttrs: false});

defineProps({
  type: {type: String, default: '', validator: (value) => ['', 'expand', 'selection'].includes(value)},
  label: {type: String, default: ''},
  prop: {type: String, default: undefined},
  width: {type: [String, Number], default: ''},
  minWidth: {type: [String, Number], default: ''},
  sortable: {type: [Boolean, String], default: false},
  sortMethod: {type: Function, default: undefined},
  // type="selection": whether a row can be selected.
  selectable: {type: Function, default: undefined},
  className: {type: String, default: undefined},
  align: {type: String, default: 'left', validator: (value) => ['left', 'center', 'right'].includes(value)},
  headerAlign: {type: String, default: undefined, validator: (value) => ['left', 'center', 'right'].includes(value)},
  fixed: {type: [Boolean, String], default: false, validator: (value) => [true, false, 'left', 'right'].includes(value)},
  showOverflowTooltip: {type: Boolean, default: false},
});
</script>
