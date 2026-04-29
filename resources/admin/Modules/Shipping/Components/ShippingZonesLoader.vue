<template>
  <div class="shipping-zones-loader">
    <div class="shipping-zones-table">
      <el-table
          :data="nextPageCount"
          class="w-full compact-table responsive"
      >
        <el-table-column prop="name" :label="translate('Zone Name')">
          <div class="table-cell">
            <el-skeleton animated>
              <template #template>
                <el-skeleton-item varient="p" class="w-[120px]" />
              </template>
            </el-skeleton>
          </div>
        </el-table-column>

        <el-table-column :label="translate('Regions')">
          <div class="table-cell">
            <el-skeleton animated>
              <template #template>
                <el-skeleton-item varient="p" class="w-[150px]" />
              </template>
            </el-skeleton>
          </div>
        </el-table-column>

        <el-table-column :label="translate('Shipping Methods')" width="180">
          <div class="table-cell">
            <el-skeleton animated>
              <template #template>
                <el-skeleton-item varient="p" class="w-[200px]" />
              </template>
            </el-skeleton>
          </div>
        </el-table-column>
        <el-table-column :label="translate('Actions')" width="200" align="right">
          <div class="table-cell">
            <el-skeleton animated>
              <template #template>
                <el-skeleton-item varient="p" class="w-[120px]" />
              </template>
            </el-skeleton>
          </div>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>

<script setup>
import translate from "@/utils/translator/Translator";

const props = defineProps({
  shippingZoneTable: {
    type: Object,
    required: true
  },
  nextPageCount: {
    type: Number,
    default: 0
  }
});

const nextPageCount = Array.from({length: props.nextPageCount}, (_, i) => i + 1);
</script>
