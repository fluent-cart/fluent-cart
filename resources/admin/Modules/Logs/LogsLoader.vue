<script setup>
import translate from "@/utils/translator/Translator";

const props = defineProps({
  nextPageCount: {
    type: Number,
    default: 0
  },
  logTable: {
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

    <el-table-column :width="100" :label="translate('ID')" >
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="logTable.isColumnVisible('date')" :label="translate('Date')" :width="120">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="logTable.isColumnVisible('title')" :label="translate('Title')" :width="200">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="logTable.isColumnVisible('content')" :label="translate('Content')" :width="200">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column :label="translate('Status')" :width="100">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column :label="translate('Module')" :width="100">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="logTable.isColumnVisible('actions')" :label="translate('Actions')" :width="100">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

  </el-table>
</template>

