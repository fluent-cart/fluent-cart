<script setup>

import translate from "@/utils/translator/Translator";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";

const props = defineProps({
  columns: {
    type: Array,
    default: () => []
  }
});

const isColumnVisible = (column) => {
  return props.columns.includes(column)
}
</script>

<template>
  <el-table class="w-full compact-table responsive" :data="[1,2,3,4,5,6,7,8,9,10]">
    <el-table-column v-if="isColumnVisible('selection')" type="selection" width="45"/>
    <el-table-column :width="140" :label="translate('# Date')">
      <div class="min-h-[72px] px-2 pl-6 flex flex-col justify-center">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" class="w-3/5" />
          </template>
        </el-skeleton>
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" class="w-4/5" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>
    <el-table-column v-if="isColumnVisible('order_items')" :min-width="200" :label="translate('Items')">
      <el-skeleton animated>
        <template #template>
          <el-skeleton-item varient="p" class="w-4/5" />
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :width="150" :label="translate('Total')">
      <el-skeleton animated>
        <template #template>
          <el-skeleton-item varient="p" class="w-3/5" />
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :width="150" :label="translate('Payment Status')">
      <el-skeleton animated>
        <template #template>
          <el-skeleton-item varient="p" class="w-4/5" />
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column v-if="isColumnVisible('status')" :width="120" :label="translate('Status')">
      <el-skeleton animated>
        <template #template>
          <el-skeleton-item varient="p" class="w-3/5" />
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column v-if="isColumnVisible('type')" :width="140" :label="translate('Order Type')">
      <el-skeleton animated>
        <template #template>
          <el-skeleton-item varient="p" class="w-3/5" />
        </template>
      </el-skeleton>
    </el-table-column>

  </el-table>
</template>

<style scoped>

</style>
