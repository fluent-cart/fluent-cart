<script setup>
import translate from "@/utils/translator/Translator";

const props = defineProps({
  nextPageCount: {
    type: Number,
    default: 0
  },
  reviewTable: {
    type: Object,
    default: () => ({})
  }
});

const nextPageCount = Array.from({length: props.nextPageCount}, (_, i) => i + 1);
</script>

<template>
  <el-table
      :data="nextPageCount"
      class="w-full compact-table full-compact"
  >
    <el-table-column :width="80" :label="translate('ID')">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p"/>
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="reviewTable.isColumnVisible('product')" :label="translate('Product')" :min-width="180">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p"/>
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column :label="translate('Reviewer')" :min-width="160">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p"/>
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column :label="translate('Rating')" :width="120">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p"/>
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="reviewTable.isColumnVisible('content')" :label="translate('Review')" :min-width="200">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p"/>
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column :label="translate('Status')" :width="110">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p"/>
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="reviewTable.isColumnVisible('date')" :label="translate('Date')" :width="120">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p"/>
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="reviewTable.isColumnVisible('actions')" :label="translate('Actions')" :width="100">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p"/>
          </template>
        </el-skeleton>
      </div>
    </el-table-column>
  </el-table>
</template>
