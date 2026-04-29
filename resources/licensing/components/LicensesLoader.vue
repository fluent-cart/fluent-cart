<template>
    <el-skeleton animated>
      <template #template>
        <el-table :data="nextPageCount" class="w-full compact-table responsive">
          <el-table-column v-if="false" type="selection" width="45"/>
  
          <el-table-column :label="$t('License Key')" :width="220">
            <div class="table-cell">
                <div class="w-full flex flex-col items-start justify-start gap-1">
                    <el-skeleton-item varient="p" class="w-1/5" />
                    <el-skeleton-item varient="p" class="w-3/5" />
                </div>
            </div>
          </el-table-column>
  
          <el-table-column v-if="isColumnVisible('product') !== -1" :width="200" :label="$t('Product')">
            <div class="table-cell">
                <div class="w-full flex flex-col items-start justify-start gap-1">
                    <el-skeleton-item varient="p" class="w-1/5" />
                    <el-skeleton-item varient="p" class="w-2/5" />
                </div>
            </div>
          </el-table-column>
  
          <el-table-column v-if="isColumnVisible('order_id') !== -1" :width="80" :label="$t('Order ID')">
            <div class="table-cell">
              <el-skeleton-item varient="p" class="w-3/5" />
            </div>
          </el-table-column>
  
          <el-table-column v-if="isColumnVisible('customer') !== -1" :width="100" :label="$t('Customer')">
            <div class="table-cell">
              <el-skeleton-item varient="p" class="w-4/5" />
            </div>
          </el-table-column>
  
          <el-table-column width="100" :label="$t('Activations')">
            <div class="table-cell">
              <el-skeleton-item varient="p" class="w-4/5" />
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
    licenseTable: {
      type: Object,
      default: () => ({})
    }
  });
  const isColumnVisible = (column) => {
    return props.licenseTable.columns.includes(column)
  }
  
  const nextPageCount = Array.from({length: props.nextPageCount}, (_, i) => i + 1);
  
  </script>