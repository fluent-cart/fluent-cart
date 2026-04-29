<script setup>

import translate from "@/utils/translator/Translator";
import Empty from "@/Bits/Components/Table/Empty.vue";
import Badge from "@/Bits/Components/Badge.vue";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
</script>

<template>
  <el-table :data="[1,2,3,4,5]" class="w-full compact-table">
    <el-table-column :width="100" :label="translate('ID')">
      <template #default="scope">
        <div class="table-cell">
          <el-skeleton animated>
            <template #template>
              <el-skeleton-item varient="p" class="w-4/5"/>
            </template>
          </el-skeleton>
        </div>
      </template>
    </el-table-column>
    <el-table-column :label="translate('Title')">
      <template #default="scope">
        <div class="table-cell">
          <el-skeleton animated>
            <template #template>
              <el-skeleton-item varient="p" class="w-4/5"/>
            </template>
          </el-skeleton>
        </div>
      </template>
    </el-table-column>

    <el-table-column :label="translate('Price')">
      <template #default="scope">
        <div class="table-cell">
          <el-skeleton animated>
            <template #template>
              <el-skeleton-item varient="p" class="w-4/5"/>
            </template>
          </el-skeleton>
        </div>
      </template>
    </el-table-column>

    <el-table-column :width="130" :label="translate('Status')">
      <template #default="scope">
        <div class="table-cell">
          <el-skeleton animated>
            <template #template>
              <el-skeleton-item varient="p" class="w-4/5"/>
            </template>
          </el-skeleton>
        </div>
      </template>
    </el-table-column>

    <template #empty>
      <Empty icon="Empty/ListView" :has-dark="true"
             :text="translate('No order bumps found!')"/>
    </template>
  </el-table>
</template>

<style scoped>

</style>
