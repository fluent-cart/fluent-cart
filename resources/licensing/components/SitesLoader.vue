<template>
  <el-skeleton animated>
    <template #template>
      <el-table :data="rows" class="w-full compact-table responsive">
        <el-table-column :label="$t('Site URL')" :min-width="200">
          <div class="table-cell">
            <div class="w-full flex flex-col items-start justify-start gap-1">
              <el-skeleton-item varient="p" class="w-2/5"/>
              <el-skeleton-item varient="p" class="w-3/5"/>
            </div>
          </div>
        </el-table-column>

        <el-table-column v-if="isColumnVisible('products')" :min-width="180" :label="$t('Products')">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-3/5"/>
          </div>
        </el-table-column>

        <el-table-column v-if="isColumnVisible('active_licenses')" :width="130" :label="$t('Active Licenses')">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-2/5"/>
          </div>
        </el-table-column>

        <el-table-column v-if="isColumnVisible('customer')" :width="150" :label="$t('Customer')">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5"/>
          </div>
        </el-table-column>

        <el-table-column v-if="isColumnVisible('last_activity')" :width="130" :label="$t('Last Activity')">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-3/5"/>
          </div>
        </el-table-column>

        <el-table-column v-if="isColumnVisible('created_at')" :width="130" :label="$t('Created At')">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-3/5"/>
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
  siteTable: {
    type: Object,
    default: () => ({})
  }
});

const isColumnVisible = (column) => {
  const columns = props.siteTable?.data?.columns || [];
  return columns.indexOf(column) !== -1;
};

const rows = Array.from({length: Math.max(props.nextPageCount, 5)}, (_, i) => i + 1);
</script>
