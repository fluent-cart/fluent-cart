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
      class="w-full compact-table"
  >
    <el-table-column :label="translate('ID')" width="300">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p"/>
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="reviewTable.isColumnVisible('reviewer')" :label="translate('Reviewer')" :width="220">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p"/>
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="reviewTable.isColumnVisible('rating')" :label="translate('Rating')" :width="120">
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

    <el-table-column v-if="reviewTable.isColumnVisible('status')" :label="translate('Status')" :width="120">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p"/>
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="reviewTable.isColumnVisible('date')" :label="translate('Date')" :width="140">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p"/>
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="reviewTable.isColumnVisible('actions')" :label="translate('Actions')" :width="90">
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
