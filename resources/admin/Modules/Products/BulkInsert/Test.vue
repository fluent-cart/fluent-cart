<template>
  <el-table-v2
      :columns="columns"
      :data="data"
      :estimated-row-height="50"
      :expand-column-key="columns[0].key"
      :width="700"
      :height="400"
  >
    <template #row="props">
      <div>
        <TestRow v-bind="props"/>
      </div>
<!--      <div>-->
<!--        <component :is="props.cells"/>-->
<!--      </div>-->

    </template>
  </el-table-v2>
</template>

<script lang="tsx" setup>
import {defineComponent, h, ref} from 'vue';
import {ElTableV2} from "element-plus";
import TestRow from "@/Modules/Products/BulkInsert/TestRow.vue";

const detailedText = `Velit sed aspernatur tempora. Natus consequatur officiis dicta vel assumenda.
Itaque est temporibus minus quis. Ipsum commodiab porro vel voluptas illum.`;

const columns = [
  {key: 'column-0', dataKey: 'column-0', title: 'Column 0', width: 150},
  {key: 'column-1', dataKey: 'column-1', title: 'Column 1', width: 150},
  {key: 'column-2', dataKey: 'column-2', title: 'Column 2', width: 150},
  {key: 'column-3', dataKey: 'column-3', title: 'Column 3', width: 150},
  {key: 'column-4', dataKey: 'column-4', title: 'Column 4', width: 150},
  {key: 'column-5', dataKey: 'column-5', title: 'Column 5', width: 150},
  {key: 'column-6', dataKey: 'column-6', title: 'Column 6', width: 150},
  {key: 'column-7', dataKey: 'column-7', title: 'Column 7', width: 150},
  {key: 'column-8', dataKey: 'column-8', title: 'Column 8', width: 150},
  {key: 'column-9', dataKey: 'column-9', title: 'Column 9', width: 150},
];

const data = ref([
  {
    id: 'row-0',
    parentId: null,
    'column-0': 'Row 0 - Col 0',
    'column-1': 'Row 0 - Col 1',
    'column-2': 'Row 0 - Col 2',
    'column-3': 'Row 0 - Col 3',
    'column-4': 'Row 0 - Col 4',
    'column-5': 'Row 0 - Col 5',
    'column-6': 'Row 0 - Col 6',
    'column-7': 'Row 0 - Col 7',
    'column-8': 'Row 0 - Col 8',
    'column-9': 'Row 0 - Col 9',
    children: [{id: 'row-0-detail-content', detail: detailedText}],
  },
  {
    id: 'row-1',
    parentId: null,
    'column-0': 'Row 1 - Col 0',
    'column-1': 'Row 1 - Col 1',
    'column-2': 'Row 1 - Col 2',
    'column-3': 'Row 1 - Col 3',
    'column-4': 'Row 1 - Col 4',
    'column-5': 'Row 1 - Col 5',
    'column-6': 'Row 1 - Col 6',
    'column-7': 'Row 1 - Col 7',
    'column-8': 'Row 1 - Col 8',
    'column-9': 'Row 1 - Col 9',
    children: [{id: 'row-1-detail-content', detail: detailedText}],
  },
  {
    id: 'row-2',
    parentId: null,
    'column-0': 'Row 2 - Col 0',
    'column-1': 'Row 2 - Col 1',
    'column-2': 'Row 2 - Col 2',
    'column-3': 'Row 2 - Col 3',
    'column-4': 'Row 2 - Col 4',
    'column-5': 'Row 2 - Col 5',
    'column-6': 'Row 2 - Col 6',
    'column-7': 'Row 2 - Col 7',
    'column-8': 'Row 2 - Col 8',
    'column-9': 'Row 2 - Col 9',
    children: [{id: 'row-2-detail-content'}],
  },
]);
</script>

<style>
.el-table-v2__row-depth-0 {
  height: 50px;
}

.el-table-v2__cell-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
