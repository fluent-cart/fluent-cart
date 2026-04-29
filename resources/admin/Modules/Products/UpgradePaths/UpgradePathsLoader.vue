<script setup>

import translate from "@/utils/translator/Translator";
</script>

<template>
  <el-table :data="[1,2,3,4,5]">
    <el-table-column :label="translate('From Plan')" prop="object_id" :width="300">
      <el-skeleton animated class="flex items-center min-h-[28px]">
        <template #template>
          <el-skeleton-item varient="p" class="w-4/5" />
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :label="translate('To Plan')" :width="200">
      <el-skeleton animated class="flex items-center min-h-[28px]">
        <template #template>
          <el-skeleton-item varient="p" class="w-4/5" />
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :label="translate('Discount Amount')" :width="110">
      <el-skeleton animated class="flex items-center min-h-[28px]">
        <template #template>
          <el-skeleton-item varient="p" class="w-4/5" />
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :label="translate('Is Prorate')" :width="100">
      <el-skeleton animated class="flex items-center min-h-[28px]">
        <template #template>
          <el-skeleton-item varient="p" class="w-4/5" />
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :width="100">
      <el-skeleton animated class="flex items-center min-h-[28px]">
        <template #template>
          <el-skeleton-item varient="p" class="w-3/5" />
        </template>
      </el-skeleton>
    </el-table-column>
  </el-table>
</template>

<style scoped>

</style>
