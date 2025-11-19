<script setup>
import translate from "@/utils/translator/Translator";

const props = defineProps({
  nextPageCount: {
    type: Number,
    default: 0
  },
  taxTable: {
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
    <el-table-column type="selection" :width="45" />

    <el-table-column :width="100" :label="translate('ID')" v-if="taxTable.isColumnVisible('id')" >
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column
        v-if="taxTable.isColumnVisible('order_id')"
        :label="translate('Order ID')"
        :width="100"
    >
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column
        :label="translate('Tax Country')"
        :width="120"
    >
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column :label="translate('Tax Region')" :width="120">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="taxTable.isColumnVisible('postcode')" :label="translate('Zip Code')" :width="120">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column :label="translate('Tax Name')" :width="120">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column v-if="taxTable.isColumnVisible('rate')" :label="translate('Tax Rate')" :width="120">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>

    <el-table-column :label="translate('Sale Taxes')" :width="120">
      <div class="table-cell">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item varient="p" />
          </template>
        </el-skeleton>
      </div>
    </el-table-column>


    <el-table-column v-if="taxTable.isColumnVisible('filed_at')" :label="translate('Filed')" :width="120">
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

