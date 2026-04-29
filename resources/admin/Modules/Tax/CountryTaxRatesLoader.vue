<script setup>
import translate from "@/utils/translator/Translator";
</script>

<template>
  <el-table :data="[1,2,3,4,5]">
    <el-table-column :label="translate('Tax Label')" width="150">
      <el-skeleton animated>
        <template #template>
          <el-skeleton-item variant="p" class="w-full h-4"/>
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :label="translate('City')" width="100">
      <el-skeleton animated>
        <template #template>
          <el-skeleton-item variant="p" class="w-full h-4"/>
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :label="translate('State')">
      <el-skeleton animated>
        <template #template>
          <el-skeleton-item variant="p" class="w-full h-4"/>
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :label="translate('Postcode')">
      <el-skeleton animated>
        <template #template>
          <el-skeleton-item variant="p" class="w-full h-4"/>
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :label="translate('Rate (%)')">
      <el-skeleton animated>
        <template #template>
          <el-skeleton-item variant="p" class="w-full h-4"/>
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :label="translate('Tax Class')" width="130">
      <el-skeleton animated>
        <template #template>
          <el-skeleton-item variant="p" class="w-full h-4"/>
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :label="translate('Compound')" width="100">
      <el-skeleton animated class="flex items-center justify-center">
        <template #template>
          <el-skeleton-item variant="p" class="w-1/2 h-4"/>
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :label="translate('Priority')" width="100">
      <el-skeleton animated>
        <template #template>
          <el-skeleton-item variant="p" class="w-full h-4"/>
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column :label="translate('Shipping')">
      <el-skeleton animated class="flex items-center justify-center">
        <template #template>
          <el-skeleton-item variant="p" class="w-1/2 h-4"/>
        </template>
      </el-skeleton>
    </el-table-column>
    <el-table-column>
      <div class="fct-tax-rates-country-modal-actions">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item variant="p" class="w-full h-4"/>
          </template>
        </el-skeleton>
      </div>
    </el-table-column>
  </el-table>
</template>
