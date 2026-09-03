<script setup>
import translate from "@/utils/translator/Translator";
</script>

<template>
  <el-skeleton animated>
    <template #template>
      <el-table :data="[1,2,3,4,5]" class="w-full compact-table">

        <el-table-column :width="40"/>

        <el-table-column :min-width="150" :label="translate('Title')">
          <div class="table-cell pl-5">
            <el-skeleton-item varient="p" class="w-4/5"/>
          </div>
        </el-table-column>

        <el-table-column :min-width="100" :label="translate('Type')">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-3/5"/>
          </div>
        </el-table-column>

        <el-table-column :min-width="100" :label="translate('Styling')">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-3/5"/>
          </div>
        </el-table-column>

        <el-table-column :min-width="80" :label="translate('Terms')">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-1/2"/>
          </div>
        </el-table-column>

        <el-table-column :min-width="120" :label="translate('Date')">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5"/>
          </div>
        </el-table-column>

        <el-table-column :width="80" align="right" :label="translate('Actions')">
          <div class="table-cell justify-end">
            <el-skeleton-item varient="p" class="w-6"/>
          </div>
        </el-table-column>

        <template #empty/>
      </el-table>
    </template>
  </el-skeleton>
</template>
