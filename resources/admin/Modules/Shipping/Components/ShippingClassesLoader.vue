<template>
  <div class="shipping-classes-loader">

    <div class="shipping-classes-table">
      <el-table
          :data="[]"
          class="w-full compact-table responsive"
      >
        <el-table-column prop="name" :label="translate('Class Name')">
          <div class="table-cell">
            <el-skeleton animated>
              <template #template>
                <el-skeleton-item varient="p" class="w-[120px]" />
              </template>
            </el-skeleton>
          </div>
        </el-table-column>

        <el-table-column :label="translate('Cost')">
          <div class="table-cell">
            <el-skeleton animated>
              <template #template>
                <el-skeleton-item varient="p" class="w-[80px]" />
              </template>
            </el-skeleton>
          </div>
        </el-table-column>

        <el-table-column :label="translate('Type')">
          <div class="table-cell">
            <el-skeleton animated>
              <template #template>
                <el-skeleton-item varient="p" class="w-[100px]" />
              </template>
            </el-skeleton>
          </div>
        </el-table-column>

        <el-table-column :label="translate('Actions')" width="200" align="right">
          <div class="table-cell">
            <el-skeleton animated>
              <template #template>
                <el-skeleton-item varient="p" class="w-[80px]" />
              </template>
            </el-skeleton>
          </div>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>

<script setup>
import translate from "@/utils/translator/Translator";

defineProps({
  shippingClassTable: {
    type: Object,
    required: true
  },
  nextPageCount: {
    type: Array,
    default: () => [1, 2, 3, 4, 5]
  }
});
</script>