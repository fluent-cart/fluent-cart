<template>
  <el-skeleton animated>
    <template #template>
      <el-table :data="nextPageCount" class="w-full compact-table responsive">
        <el-table-column v-if="false" type="selection" width="45"/>

        <el-table-column :label="$t('# Date')" :width="140">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>


        <el-table-column v-if="isColumnVisible('customer')" width="100" :label="$t('Customer')">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column v-if="isColumnVisible('order_items')" :label="$t('Items')" width="250">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column width="100" :label="$t('Total')">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column width="150" :label="$t('Payment Status')">
          <div class="table-cell items-center gap-1">
            <el-skeleton-item varient="p" class="w-4/5" />
            <el-skeleton-item varient="p" class="w-3/5" />
          </div>
        </el-table-column>

        <el-table-column v-if="isColumnVisible('status')" width="150" :label="$t('Status')">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column v-if="isColumnVisible('type')" width="110" :label="$t('Order Type')">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column v-if="isColumnVisible('actions')" width="60" label="">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-3/5" />
          </div>
        </el-table-column>

        <template #empty>
        </template>
      </el-table>
    </template>
  </el-skeleton>
</template>

<script setup>

const props = defineProps({
  nextPageCount: {
    type: Number,
    default: 0
  },
  orderTable: {
    type: Object,
    default: () => ({})
  }
});
const isColumnVisible = (column) => {
  return props.orderTable.columns.includes(column)
}

const nextPageCount = Array.from({length: props.nextPageCount}, (_, i) => i + 1);

</script>