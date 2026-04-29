
<script setup>
import translate from "@/utils/translator/Translator";

const props = defineProps({
  nextPageCount: {
    type: Number,
    default: 0
  },
  couponTable: {
    type: Object,
    default: () => ({})
  }
});

const nextPageCount = Array.from({length: props.nextPageCount}, (_, i) => i + 1);

</script>

<template>
  <el-table
      :data="nextPageCount"
      class="w-full compact-table responsive full-compact"
  >
    <el-table-column :width="100" :label="translate('ID')">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" class="w-[70px]" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column
        v-if="couponTable.isColumnVisible('title')"
        :label="translate('Title')"
        :width="250"
    >
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" class="w-[180px]" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column :label="translate('Code')" :width="200">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" class="w-[130px]" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>
    <el-table-column :label="translate('Amount')" :width="120">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" class="w-[100px]" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>
    <el-table-column :label="translate('Usage')" :width="150">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" class="w-[100px]" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>
    <el-table-column
        v-if="couponTable.isColumnVisible('stackable')"
        :label="translate('Stackable')"
        :width="120"
    >
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" class="w-[80px]" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>
    <el-table-column :label="translate('Status')" :width="120">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" class="w-[90px]" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>
    <el-table-column :label="translate('Expiry Date')" :width="120">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" class="w-[90px]" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>
    <el-table-column
        v-if="couponTable.isColumnVisible('actions')"
        :label="translate('Actions')"
        :width="100"
        align="right"
    >
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" class="w-[70px]" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

  </el-table>
</template>

<style scoped>

</style>
